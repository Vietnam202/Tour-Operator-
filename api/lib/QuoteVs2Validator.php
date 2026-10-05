<?php
declare(strict_types=1);

final class QuoteVs2Validator {
    public static function policy(PDO $db,int $company): ?string {
        $value=QuoteVs2Repository::q($db,"SELECT setting_value FROM company_settings WHERE company_id=? AND setting_key='minimum_margin'",[$company])->fetchColumn();
        return $value===false||$value===null||$value===''?null:(string)$value;
    }
    public static function bookingLines(array $lines,array $reqs,string $fx): array {
        $out=[];$byId=array_column($lines,null,'id');
        foreach($lines as $line){if($line['line_kind']!=='SERVICE'||$line['coverage_state']!=='PRICED')continue;$req=$reqs[$line['requirement_id']];$trace=QuoteVs2Repository::decode($line['calculation_trace_json']);$net=Vs2Decimal::parse($trace['original_total']??'');
            foreach($lines as $adjustment)if((int)($adjustment['adjusts_line_id']??0)===(int)$line['id']){
                $delta=Vs2Decimal::parse((string)$adjustment['adjustment_amount_vnd']);
                $original=$line['original_currency']==='USD'?Vs2Decimal::ratio($delta,1000000,Vs2Decimal::parse($fx,6)):$delta;
                if(Vs2Decimal::convert($original,$line['original_currency'],$fx)!==$delta)throw new DomainException('CURRENCY_ADJUSTMENT_REVIEW');$net=Vs2Decimal::add($net,$original);
            }
            if($net<0)throw new DomainException('NEGATIVE_SERVICE_NET');if($net>10000000000)throw new DomainException('BOOKING_AMOUNT_LIMIT');
            $out[]=['category'=>$req['category'],'service_name'=>$req['service_name'],'service_date'=>$req['service_date'],'pax'=>$line['resolved_quantity'],'qty'=>$line['resolved_units'],'supplier_id'=>$line['supplier_id'],'total'=>Vs2Decimal::format($net),'currency'=>$line['original_currency'],'notes'=>QuoteVs2Repository::decode($req['metadata_json'])['internal_notes']??'', 'requirement_id'=>$req['id'],'line_id'=>$line['id']];
        }return $out;
    }
    public static function validate(PDO $db,array $v): array {
        $errors=[];$warnings=[];$add=function(string $code,?int $variant=null,?int $line=null,?int $req=null)use(&$errors){$errors[]=['code'=>$code,'severity'=>'BLOCKING','variant_id'=>$variant,'line_id'=>$line,'requirement_id'=>$req];};
        $g=QuoteVs2Repository::graph($db,$v);$reqs=array_column($g['requirements'],null,'id');
        try{$guests=QuoteVs2Domain::guests($v,$g['profile']);}catch(Throwable $e){$add('PAX_SEGMENT_REVIEW_REQUIRED');return ['valid'=>false,'errors'=>$errors,'warnings'=>[]];}
        if(!QuoteVs2Repository::decode($v['schedule_json']??'[]'))$add('ITINERARY_NEEDED');
        $policy=self::policy($db,(int)$v['company_id']);if($policy===null)$warnings[]=['code'=>'POLICY_NOT_CONFIGURED','severity'=>'WARNING'];
        $offered=array_filter($g['variants'],fn($variant)=>(bool)$variant['is_offered']);if(!$offered)$add('OFFERED_VARIANT_NEEDED');
        foreach($offered as $variant){$vid=(int)$variant['id'];$byId=array_column($variant['lines'],null,'id');$seen=[];$active=[];$rates=[];
            foreach($reqs as $req){if(!QuoteVs2Domain::applies($req,$variant))continue;
                if($req['requirement_state']!=='REQUIRED'){$add('REQUIREMENT_REVIEW_REQUIRED',$vid,null,(int)$req['id']);continue;}
                if(!$req['service_date'])$add('SERVICE_DATE_NEEDED',$vid,null,(int)$req['id']);
                $lines=array_filter($variant['lines'],fn($line)=>(int)$line['requirement_id']===(int)$req['id']&&$line['line_kind']==='SERVICE'&&QuoteVs2Domain::applies($req,$variant,$line));
                if(count($lines)!==1)$add(count($lines)?'DUPLICATE_COST':'MISSING_REQUIRED_SERVICE',$vid,null,(int)$req['id']);
                $scope=QuoteVs2Repository::decode($req['scope_json']);$dates=$scope['dates']??[];
                if(in_array($req['category'],['HOTEL','GUIDE'],true)&&(!$dates||count($dates)!==(int)$req['service_units']))$add($req['category']==='HOTEL'?'HOTEL_NIGHTS_MISMATCH':'GUIDE_DAYS_MISMATCH',$vid,null,(int)$req['id']);
                if($req['category']==='MEAL'&&count($scope['occurrences']??[])!==(int)$req['service_units'])$add('MEAL_COUNT_MISMATCH',$vid,null,(int)$req['id']);
                if($req['category']==='ATTRACTION'&&empty($scope['attraction_key']))$add('ATTRACTION_SCOPE_NEEDED',$vid,null,(int)$req['id']);
            }
            foreach($variant['lines'] as $line){$lid=(int)$line['id'];$req=$reqs[$line['requirement_id']]??null;if(!$req){$add('FOREIGN_REQUIREMENT',$vid,$lid);continue;}if(!QuoteVs2Domain::applies($req,$variant,$line))continue;$active[]=$line;
                if($req['category']==='CRUISE'&&$variant['cruise_level']===null)$add('CRUISE_OPTION_MISMATCH',$vid,$lid);
                if($line['line_kind']==='SERVICE'){
                    $scope=QuoteVs2Repository::decode($req['scope_json']);$sig=QuoteVs2Domain::hash([$req['category'],$req['service_date'],$req['service_end_date'],$scope]);
                    if(isset($seen[$sig]))$add('DUPLICATE_COST',$vid,$lid);$seen[$sig]=$lid;
                }
                $rate=null;
                try{
                    if($line['review_required']||!$line['reviewed_by'])throw new DomainException('REVIEW_REQUIRED');
                    if($line['line_kind']==='ADJUSTMENT'){
                        $parent=$byId[$line['adjusts_line_id']]??null;if(!$parent||$parent['line_kind']!=='SERVICE'||$parent['coverage_state']!=='PRICED'||(int)$parent['requirement_id']!==(int)$line['requirement_id']||(int)$parent['supplier_id']!==(int)$line['supplier_id'])throw new DomainException('INVALID_ADJUSTMENT_PARENT');
                        if(Vs2Decimal::parse((string)$line['total_vnd'])!==Vs2Decimal::parse((string)$line['adjustment_amount_vnd']))throw new DomainException('STALE_CALCULATION');
                    }elseif($line['coverage_state']==='PRICED'){
                        $rate=Vs2RateResolver::resolve($db,$v,$guests,$req,$variant,$line);$rates[$lid]=$rate;
                        $calc=SmartCosting::calculate($line,$req,$guests,$rate,(string)$v['fx_rate']);
                        if(!hash_equals($line['input_hash']??'',$calc['input_hash'])||$calc['total_vnd']!==$line['total_vnd'])throw new DomainException('STALE_CALCULATION');
                        if($line['formula_code']!==QuoteVs2Domain::FORMULAS[$req['category']]&&!in_array($line['formula_code'],['CUSTOM','LUMP_SUM'],true))throw new DomainException('FORMULA_CATEGORY_MISMATCH');
                        if(in_array($line['formula_code'],['CUSTOM','LUMP_SUM'],true)&&(!$rate['manual']||!$line['manual_reason']))throw new DomainException('CUSTOM_BASIS_REVIEW');
                        if($line['units_override']!==null&&(int)$line['units_override']!==(int)$req['service_units']&&!in_array($line['formula_code'],['CUSTOM','LUMP_SUM'],true))throw new DomainException('SERVICE_UNITS_MISMATCH');
                        if($calc['resolved_quantity']===0&&empty(QuoteVs2Repository::decode($req['metadata_json'])['zero_reason']))throw new DomainException('ELIGIBLE_ZERO_REVIEW');
                    }elseif($line['coverage_state']==='INCLUDED'){
                        $parent=$byId[$line['included_by_line_id']]??null;if(!$parent||$parent['coverage_state']!=='PRICED'||!in_array($reqs[$parent['requirement_id']]['category'],['TOUR','CRUISE'],true))throw new DomainException('INVALID_PACKAGE_COVERAGE');
                        $parentRate=Vs2RateResolver::resolve($db,$v,$guests,$reqs[$parent['requirement_id']],$variant,$parent);$rule=null;$matchCount=0;
                        foreach($parentRate['inclusions'] as $candidate)if((int)$candidate['id']===(int)$line['inclusion_rule_id'])$rule=$candidate;
                        if(!$rule||!Vs2Inclusions::covers($rule,$req,$line,$parent,$guests)||Vs2Decimal::parse((string)$line['total_vnd'])!==0)throw new DomainException('INVALID_PACKAGE_COVERAGE');
                        foreach($variant['lines'] as $package){if($package['coverage_state']!=='PRICED'||!in_array($reqs[$package['requirement_id']]['category'],['TOUR','CRUISE'],true))continue;$pr=Vs2RateResolver::resolve($db,$v,$guests,$reqs[$package['requirement_id']],$variant,$package);foreach($pr['inclusions'] as $ir)if(Vs2Inclusions::covers($ir,$req,$line,$package,$guests))$matchCount++;}
                        if($matchCount!==1)throw new DomainException('DUPLICATE_PACKAGE_COVERAGE');
                    }elseif($line['coverage_state']==='NO_COST'){
                        if(!$line['manual_reason']||empty(QuoteVs2Repository::decode($line['manual_contract_json'])['evidence'])||Vs2Decimal::parse((string)$line['total_vnd'])!==0)throw new DomainException('NO_COST_REVIEW');
                    }else throw new DomainException('RATE_NEEDED');
                    $review=SmartCosting::reviewHash($v,$g['profile'],$req,$variant,$line,$rate);if(!hash_equals($line['reviewed_context_hash']??'',$review))throw new DomainException('CONTEXT_REVIEW_REQUIRED');
                    $path=[];$cursor=$line;while($cursor['included_by_line_id']){if(isset($path[$cursor['id']]))throw new DomainException('INCLUSION_CYCLE');$path[$cursor['id']]=true;$cursor=$byId[$cursor['included_by_line_id']]??throw new DomainException('FOREIGN_PACKAGE_LINE');}
                }catch(Throwable $e){$add(strtok($e->getMessage(),':'),$vid,$lid,(int)$req['id']);}
            }
            foreach($rates as $lid=>$rate)if(($byId[$lid]['formula_code']??'')==='TRANSFER_PACKAGE'){
                $approved=$rate['terms']['package_scope']??[];
                foreach($rates as $packageId=>$packageRate)if(($byId[$packageId]['formula_code']??'')==='SIC_PAX')foreach($packageRate['inclusions'] as $rule)if($rule['included_category']==='TRANSPORT'){
                    $s=QuoteVs2Repository::decode($rule['scope_rule_json']);$key=QuoteVs2Domain::hash($s);
                    if(!in_array($key,$approved['excluded_sic_scope_hashes']??[],true))$add('TRANSFER_SCOPE_OVERLAP',$vid,(int)$lid);
                }
            }
            try{$cost=0;foreach($active as $line){if($line['total_vnd']===null)throw new DomainException('RATE_NEEDED');$cost=Vs2Decimal::add($cost,Vs2Decimal::parse((string)$line['total_vnd']));}
                $pricing=SmartCosting::pricing($cost,$guests['paying_pax'],(string)$v['fx_rate'],$variant);
                if(QuoteVs2Domain::hash($pricing)!==QuoteVs2Domain::hash(QuoteVs2Repository::decode($variant['pricing_result_json'])))throw new DomainException('STALE_PRICING');
                if(Vs2Decimal::parse($pricing['total_selling'])<=0)throw new DomainException('SELLING_PRICE_NEEDED');
                if($policy!==null&&Vs2Decimal::parse($pricing['margin_pct'],4)<Vs2Decimal::parse($policy,4))throw new DomainException('MINIMUM_MARGIN');
                self::bookingLines($active,$reqs,(string)$v['fx_rate']);
            }catch(Throwable $e){$add(strtok($e->getMessage(),':'),$vid);}
        }
        return ['valid'=>!$errors,'errors'=>$errors,'warnings'=>$warnings];
    }
    public static function assertValid(PDO $db,array $v): void {
        $report=self::validate($db,$v);if(!$report['valid'])throw new DomainException('VS21_VALIDATION: '.implode(', ',array_unique(array_column($report['errors'],'code'))));
    }
}
