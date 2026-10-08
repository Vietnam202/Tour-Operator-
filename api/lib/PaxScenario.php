<?php
declare(strict_types=1);
require_once __DIR__.'/GroupVehiclePricing.php';

/** Scenario guest inputs are projections. FOC and unrelated fixed resource counts survive. */
final class PaxScenario {
    public const DEFAULT_BANDS=[['min'=>2,'max'=>2],['min'=>3,'max'=>4],['min'=>5,'max'=>9],['min'=>10,'max'=>14],['min'=>15,'max'=>20]];
    public static function bands(array $b,string $method): array {
        if(!in_array($method,['EXACT_PAX','REPRESENTATIVE_PAX','SAFE_PRICE'],true)||!array_is_list($b)||!$b||count($b)>30)throw new InvalidArgumentException('Choose a pricing method and 1–30 bands');
        $out=[];$end=0;$count=0;usort($b,fn($a,$c)=>(int)($a['min']??0)<=>(int)($c['min']??0));
        foreach($b as $r){$lo=QuoteVs2Domain::count($r['min']??0);$hi=QuoteVs2Domain::count($r['max']??0);if($lo<1||$hi<$lo||$hi>500||$lo<=$end)throw new InvalidArgumentException('Pax bands must be non-overlapping inclusive ranges (max 500)');
            if($method==='EXACT_PAX'&&$lo!==$hi)throw new InvalidArgumentException('EXACT_PAX requires single-pax bands');
            $rep=QuoteVs2Domain::count($r['representative']??$lo);if($rep<$lo||$rep>$hi)throw new InvalidArgumentException('Representative pax must be inside its band');
            $count+=$hi-$lo+1;if($count>250)throw new InvalidArgumentException('Use at most 250 pax scenarios per matrix');
            $out[]=['key'=>$lo.'-'.$hi,'min'=>$lo,'max'=>$hi,'representative'=>$rep];$end=$hi;
        }return $out;
    }
    public static function guests(array $v,array $profile,int $pay,array $config,?array $confirmed=null): array {
        $base=QuoteVs2Domain::guests($v,$profile);$delta=$pay-$base['paying_pax'];$explicit=$confirmed??($config['scenario_profiles'][(string)$pay]??null);
        $g=$base;$g['adults']=$base['adults']+$delta;$g['total_guests']=$base['total_guests']+$delta;$g['paying_pax']=$pay;
        if($explicit!==null){if(!is_array($explicit))throw new InvalidArgumentException('Scenario profile must be structured');foreach(QuoteVs2Domain::BASE as $k)if(array_key_exists($k,$explicit))$g[$k]=QuoteVs2Domain::count($explicit[$k]);if($g['paying_pax']!==$pay||$g['foc']!==$base['foc'])throw new DomainException('PAX_COMPOSITION_REVIEW: confirmed pax/FOC must match');}
        foreach(QuoteVs2Domain::PROFILE as $k){$rule=$config['quantity_rules'][$k]??['mode'=>'FOLLOW_BASE_OFFSET'];$mode=$rule['mode']??'';
            $g[$k]=match($mode){'FOLLOW_BASE_OFFSET'=>$base[$k]===null?null:$base[$k]+$delta,'PAYING_PAX'=>$pay,'TOTAL_GUESTS'=>$g['total_guests'],'FIXED'=>QuoteVs2Domain::count($rule['quantity']??null,true),default=>throw new InvalidArgumentException('Invalid service-population rule')};
            if($explicit!==null&&array_key_exists($k,$explicit))$g[$k]=QuoteVs2Domain::count($explicit[$k],true);
        }
        return QuoteVs2Domain::guests($g,$g);
    }
    /** Calls the same resolver/formulas/inclusion checks, with zero persistent writes. */
    public static function calculate(PDO $db,array $graph,array $variant,int $pay,array $config,string $fx,?array $confirmed=null): array {
        $v=$graph['version'];$g=self::guests($v,$graph['profile'],$pay,$config,$confirmed);$v=array_replace($v,array_intersect_key($g,array_flip(QuoteVs2Domain::BASE)),['fx_rate'=>$fx]);
        $lines=$variant['lines'];$reqs=array_column($graph['requirements'],null,'id');$base=QuoteVs2Domain::guests($graph['version'],$graph['profile']);$review=[];
        foreach($lines as &$l){
            if(($config['vehicle_bands']??[])&&$l['line_kind']==='SERVICE'&&$l['formula_code']==='TRANSFER_PACKAGE'){
                $rateId=GroupVehiclePricing::rate($config,(int)$l['id'],$pay);
                $l['rate_version_id']=$rateId;$l['unit_amount_original']=null;
                $l['manual_reason']=null;$l['manual_contract_json']='{}';
                // Scenario supplier rate and contract are validated by Vs2RateResolver per pax.
            }
            $resource=$config['resource_counts'][(string)$l['id']]??null;
            if($resource!==null){if($l['quantity_source']!=='CUSTOM_QTY'||!in_array($l['formula_code'],['TRANSFER_PACKAGE','GUIDE_DAY'],true)||!is_array($resource))throw new InvalidArgumentException('Resource scenarios need an explicit vehicle/guide count');
                if(isset($resource['counts'][(string)$pay])){$n=QuoteVs2Domain::count($resource['counts'][(string)$pay]);if($n<1)throw new InvalidArgumentException('Resource count must be positive');QuoteVs2Domain::text($resource['reason']??'',1000);$l['quantity_override']=null;$l['custom_quantity']=$n;}
            }
            if($g!==$base&&$l['quantity_override']!==null){$reason=$config['override_reviews'][(string)$l['id']]??'';$review[]=['line_id'=>(int)$l['id'],'quantity'=>$l['quantity_override'],'reviewed'=>$reason!==''];if($reason==='')throw new DomainException('MANUAL_OVERRIDE_REVIEW_REQUIRED');QuoteVs2Domain::text($reason,1000);}
        }unset($l);
        $r=SmartCosting::scenario($db,$v,$g,$graph['requirements'],$variant,$lines);
        return $r+['paying_pax'=>$pay,'guests'=>$g,'manual_override_review'=>$review];
    }
}
