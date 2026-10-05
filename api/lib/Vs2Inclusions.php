<?php
declare(strict_types=1);

final class Vs2Inclusions {
    public static function covers(array $rule,array $req,array $line,array $package,array $guests): bool {
        if($rule['included_category']!==$req['category'])return false;
        $scope=QuoteVs2Repository::decode($rule['scope_rule_json']);$wanted=QuoteVs2Repository::decode($req['scope_json']);
        // Explicit binding is still only a proposal: every scope and population must match.
        if($req['package_component_key']&&$req['package_component_key']!==$rule['component_key'])return false;
        if(!$scope||!isset($scope['service_date'])||$scope['service_date']!==$req['service_date'])return false;
        foreach(['day_key','route','attraction_key','meal_key','eligibility_group','transport_leg'] as $key){$actual=$key==='day_key'?$req['day_key']:($wanted[$key]??null);if(($scope[$key]??null)!==$actual)return false;}
        if(($scope['dates']??[])!==($wanted['dates']??[]))return false;
        $coverage=QuoteVs2Repository::decode($rule['coverage_rule_json']);
        $quantity=QuoteVs2Domain::quantity($line,$guests);$units=(int)($line['units_override']??$req['service_units']??1);
        $coveredQty=isset($coverage['quantity'])?QuoteVs2Domain::count($coverage['quantity']):QuoteVs2Domain::quantity($package,$guests);
        return $quantity!==null&&$coveredQty===$quantity&&($coverage['units']??null)===$units;
    }
    /** Exact matching only. Partial coverage must be explicitly split by the user. */
    public static function apply(PDO $db,array $v): void {
        $g=QuoteVs2Repository::graph($db,$v);$guests=QuoteVs2Domain::guests($v,$g['profile']);$reqs=array_column($g['requirements'],null,'id');
        foreach($g['variants'] as $variant){$packages=[];
            foreach($variant['lines'] as $line){$req=$reqs[$line['requirement_id']];if(!in_array($req['category'],['TOUR','CRUISE'],true)||!QuoteVs2Domain::applies($req,$variant,$line)||$line['coverage_state']!=='PRICED')continue;
                try{$rate=Vs2RateResolver::resolve($db,$v,$guests,$req,$variant,$line);if(!$rate['manual'])$packages[]=['line'=>$line,'rate'=>$rate];}catch(DomainException $e){}
            }
            foreach($variant['lines'] as $line){$req=$reqs[$line['requirement_id']];if($line['line_kind']==='ADJUSTMENT'||!QuoteVs2Domain::applies($req,$variant,$line))continue;$matches=[];
                foreach($packages as $package){if((int)$package['line']['id']===(int)$line['id'])continue;if($req['package_requirement_id']&&(int)$req['package_requirement_id']!==(int)$package['line']['requirement_id'])continue;
                    foreach($package['rate']['inclusions'] as $rule)if(self::covers($rule,$req,$line,$package['line'],$guests))$matches[]=[$package['line'],$rule];
                }
                if(count($matches)>1)throw new DomainException('DUPLICATE_PACKAGE_COVERAGE');
                if(count($matches)===1){[$parent,$rule]=$matches[0];$trace=['sentence'=>'Included in approved package '.$parent['line_key'],'package_line_id'=>(int)$parent['id'],'inclusion_rule_id'=>(int)$rule['id'],'rule_hash'=>QuoteVs2Domain::hash($rule)];
                    if($line['coverage_state']!=='INCLUDED'||(int)$line['included_by_line_id']!==(int)$parent['id']||(int)$line['inclusion_rule_id']!==(int)$rule['id'])QuoteVs2Repository::q($db,"UPDATE quote_variant_cost_lines SET coverage_state='INCLUDED',included_by_line_id=?,inclusion_rule_id=?,total_vnd=0,rate_status='APPROVED_INCLUSION',calculation_trace_json=?,input_hash=?,calculated_at=NOW(),review_required=1 WHERE id=?",[$parent['id'],$rule['id'],QuoteVs2Repository::json($trace),QuoteVs2Domain::hash($trace),$line['id']]);
                }elseif($line['coverage_state']==='INCLUDED')QuoteVs2Repository::q($db,"UPDATE quote_variant_cost_lines SET coverage_state='UNRESOLVED',included_by_line_id=NULL,inclusion_rule_id=NULL,total_vnd=NULL,rate_status='RATE_NEEDED',review_required=1 WHERE id=?",[$line['id']]);
            }
            SmartCosting::aggregate($db,$v,$variant,$reqs,$guests);
        }
    }
}
