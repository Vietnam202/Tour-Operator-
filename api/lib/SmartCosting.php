<?php
declare(strict_types=1);
require_once __DIR__.'/Vs2Decimal.php';
require_once __DIR__.'/Vs2RateResolver.php';

final class SmartCosting {
    public static function calculate(array $line,array $req,array $guests,array $rate,string $fx): array {
        $quantity=QuoteVs2Domain::quantity($line,$guests);if($quantity===null)throw new DomainException('QUANTITY_NEEDED');
        $formula=$line['formula_code'];$units=in_array($formula,['GUIDE_DAY','HOTEL_PAX_NIGHT','MEAL_PAX_COUNT','CUSTOM'],true)?($line['units_override']??$req['service_units']):1;
        if($units===null||(int)$units<1)throw new DomainException('SERVICE_UNITS_NEEDED');$units=QuoteVs2Domain::count($units);
        $unit=Vs2Decimal::parse((string)$rate['amount']);$original=Vs2Decimal::mul(Vs2Decimal::mul($unit,$quantity),$units);$total=Vs2Decimal::convert($original,$rate['currency'],$fx);$unitVnd=Vs2Decimal::convert($unit,$rate['currency'],$fx);
        $inputs=['formula'=>$formula,'quantity_source'=>$line['quantity_source'],'quantity'=>$quantity,'units'=>$units,'amount'=>$rate['amount'],'currency'=>$rate['currency'],'fx_rate'=>$rate['currency']==='USD'?$fx:null,'source_hash'=>$rate['source_hash']];
        $sentence="$quantity × $units × ".$rate['amount'].' '.$rate['currency'].' = '.Vs2Decimal::format($original).' '.$rate['currency'];
        $trace=$inputs+['original_total'=>Vs2Decimal::format($original),'total_vnd'=>Vs2Decimal::format($total),'sentence'=>$sentence];
        return ['resolved_quantity'=>$quantity,'resolved_units'=>$units,'unit_rate_vnd'=>Vs2Decimal::format($unitVnd),'total_vnd'=>Vs2Decimal::format($total),'original_currency'=>$rate['currency'],'unit_amount_original'=>$rate['amount'],'supplier_id'=>$rate['supplier_id'],'coverage_state'=>'PRICED','rate_status'=>$rate['manual']?'MANUAL_COST':'APPROVED_RATE','calculation_trace_json'=>QuoteVs2Repository::json($trace),'input_hash'=>QuoteVs2Domain::hash($inputs)];
    }
    public static function pricing(int $cost,int $pay,string $fx,array $variant): array {
        if($pay<1||$cost<0)throw new DomainException('INVALID_COMMERCIAL_INPUT');$fxValue=Vs2Decimal::parse($fx,6);if($fxValue<1)throw new DomainException('FX_NEEDED');
        $currency=$variant['selling_currency'];$currencyCost=$currency==='USD'?Vs2Decimal::ratio($cost,1000000,$fxValue):$cost;
        if(!in_array($currency,['USD','VND'],true))throw new DomainException('UNSUPPORTED_CURRENCY');
        $value=Vs2Decimal::parse((string)$variant['pricing_value'],4);if($value<0)throw new DomainException('INVALID_PRICE');
        $per=match($variant['pricing_mode']){'MARKUP'=>Vs2Decimal::ratio($currencyCost,Vs2Decimal::add(1000000,$value),Vs2Decimal::mul($pay,1000000)),'TARGET_MARGIN'=>$value<1000000?Vs2Decimal::ratio($currencyCost,1000000,Vs2Decimal::mul($pay,1000000-$value)):throw new DomainException('INVALID_MARGIN'),'MANUAL'=>Vs2Decimal::ratio($value,1,100),default=>throw new DomainException('INVALID_PRICING_MODE')};
        $step=Vs2Decimal::parse((string)$variant['rounding_step']);if($step<0)throw new DomainException('INVALID_ROUNDING');if($step&&$variant['pricing_mode']!=='MANUAL')$per=Vs2Decimal::mul(intdiv(Vs2Decimal::add($per,$step-1),$step),$step);
        $selling=Vs2Decimal::mul($per,$pay);$sellingVnd=Vs2Decimal::convert($selling,$currency,$fx);$profit=Vs2Decimal::add($sellingVnd,-$cost);
        return ['cost_total_vnd'=>Vs2Decimal::format($cost),'cost_per_paying_pax_vnd'=>Vs2Decimal::format(Vs2Decimal::ratio($cost,1,$pay)),'selling_per_pax'=>Vs2Decimal::format($per),'total_selling'=>Vs2Decimal::format($selling),'selling_currency'=>$currency,'profit_vnd'=>Vs2Decimal::format($profit),'margin_pct'=>Vs2Decimal::format($sellingVnd?Vs2Decimal::ratio($profit,1000000,$sellingVnd):0,4),'markup_pct'=>Vs2Decimal::format($cost?Vs2Decimal::ratio($profit,1000000,$cost):0,4)];
    }
    public static function reviewHash(array $v,array $profile,array $req,array $variant,array $line,?array $rate): string {
        $input=array_intersect_key($line,array_flip(['requirement_id','formula_code','quantity_source','quantity_override','custom_quantity','units_override','override_reason','supplier_id','rate_version_id','unit_amount_original','original_currency','coverage_state','included_by_line_id','inclusion_rule_id','adjusts_line_id','adjustment_amount_vnd','manual_reason','manual_contract_json','service_mode']));
        return QuoteVs2Domain::hash([$input,QuoteVs2Domain::guests($v,$profile),array_intersect_key($req,array_flip(['category','service_date','service_end_date','service_units','scope_json','requirement_state'])),[$variant['hotel_level'],$variant['cruise_level'],$variant['costing_mode']],QuoteVs2Domain::itineraryScope($v),$rate['source_hash']??null]);
    }
    /** Guest-source writes never execute arithmetic for unbound or overridden lines. */
    public static function refresh(PDO $db,array $v,array $changed=[],bool $all=false,array $requirementEdits=[],array $lineIds=[]): array {
        $g=QuoteVs2Repository::graph($db,$v);$guests=QuoteVs2Domain::guests($v,$g['profile']);$requirements=array_column($g['requirements'],null,'id');$reqIds=array_column($requirementEdits,'id');$updated=[];
        foreach($g['variants'] as $variant){
            foreach($variant['lines'] as $line){$req=$requirements[$line['requirement_id']]??null;if(!$req||!QuoteVs2Domain::applies($req,$variant,$line)||$line['line_kind']==='ADJUSTMENT')continue;
                $target=$all||in_array($line['id'],$lineIds)||in_array($req['id'],$reqIds)||QuoteVs2Domain::affected($line,$changed);if(!$target)continue;
                if(in_array($line['coverage_state'],['NO_COST','INCLUDED'],true))continue;
                try{$rate=Vs2RateResolver::resolve($db,$v,$guests,$req,$variant,$line);$calc=self::calculate($line,$req,$guests,$rate,(string)$v['fx_rate']);
                    QuoteVs2Repository::q($db,'UPDATE quote_variant_cost_lines SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($calc))).',calculated_at=NOW(),review_required=1 WHERE id=?',[...array_values($calc),$line['id']]);
                }catch(DomainException $e){QuoteVs2Repository::q($db,"UPDATE quote_variant_cost_lines SET coverage_state='UNRESOLVED',rate_status=?,review_required=1,total_vnd=NULL,input_hash=NULL,calculation_trace_json=? WHERE id=?",[substr(strtok($e->getMessage(),':'),0,24),QuoteVs2Repository::json(['sentence'=>$e->getMessage()]),$line['id']]);}
                $updated[]=(int)$line['id'];
            }
            self::aggregate($db,$v,$variant,$requirements,$guests);
        }return $updated;
    }
    public static function aggregate(PDO $db,array $v,array $variant,array $requirements,array $guests): void {
        $lines=QuoteVs2Repository::q($db,'SELECT * FROM quote_variant_cost_lines WHERE variant_id=? ORDER BY sort_order,id',[$variant['id']])->fetchAll();$cost=0;$unresolved=false;
        foreach($lines as $line){$req=$requirements[$line['requirement_id']];if(!QuoteVs2Domain::applies($req,$variant,$line))continue;if($line['total_vnd']===null)$unresolved=true;else $cost=Vs2Decimal::add($cost,Vs2Decimal::parse((string)$line['total_vnd']));}
        $pricing=$unresolved?null:self::pricing($cost,$guests['paying_pax'],(string)$v['fx_rate'],$variant);
        QuoteVs2Repository::q($db,'UPDATE quote_option_variants SET pricing_result_json=?,input_hash=? WHERE id=?',[$pricing?QuoteVs2Repository::json($pricing):null,QuoteVs2Domain::hash([$pricing,$v['fx_rate'],$guests['paying_pax']]),$variant['id']]);
    }
}
