<?php
declare(strict_types=1);

final class Vs2RateResolver {
    public static function resolve(PDO $db,array $v,array $guests,array $req,array $variant,array $line): array {
        $id=(int)($line['rate_version_id']??0);$q=fn($sql,$args=[])=>QuoteVs2Repository::q($db,$sql,$args);
        if(!$id){
            if(($line['unit_amount_original']??null)===null)throw new DomainException('RATE_NEEDED');
            if(!$q("SELECT 1 FROM suppliers WHERE company_id=? AND id=? AND status='ACTIVE'",[$v['company_id'],$line['supplier_id']])->fetchColumn())throw new DomainException('SUPPLIER_UNAVAILABLE');
            QuoteVs2Domain::text($line['manual_reason']??'');$contract=QuoteVs2Repository::decode($line['manual_contract_json']??null);
            if(empty($contract['evidence'])||empty($contract['formula_code']))throw new DomainException('MANUAL_CONTRACT_REVIEW');
            if(isset($contract['document_id'])&&!$q('SELECT 1 FROM documents WHERE company_id=? AND id=? AND (supplier_id=? OR supplier_id IS NULL)',[$v['company_id'],$contract['document_id'],$line['supplier_id']])->fetchColumn())throw new DomainException('DOCUMENT_NOT_IN_COMPANY');
            $rate=['amount'=>$line['unit_amount_original'],'currency'=>$line['original_currency'],'supplier_id'=>$line['supplier_id'],'rate_version_id'=>null,'tax_basis'=>$contract['tax_basis']??'UNKNOWN','terms'=>$contract,'inclusions'=>[],'manual'=>true];
        }else{
            $terms=$q('SELECT * FROM rate_version_vs2_terms WHERE rate_version_id=?',[$id])->fetch();if(!$terms||$terms['approval_state']!=='APPROVED')throw new DomainException('RATE_TERMS_NEEDED');
            $eligible=$guests[strtolower($terms['rate_eligibility_source'])]??null;if(!$eligible)throw new DomainException('RATE_ELIGIBILITY_NEEDED');
            $identity=$q('SELECT r.category,r.destination FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id WHERE r.company_id=? AND rv.id=?',[$v['company_id'],$id])->fetch();if(!$identity||$identity['category']!==$req['category'])throw new DomainException('RATE_CATEGORY_MISMATCH');
            $dates=QuoteVs2Repository::decode($req['scope_json'])['dates']??[$req['service_date']];if(!$dates||!is_array($dates))throw new DomainException('SERVICE_DATE_NEEDED');
            $amount=null;$rate=null;
            foreach($dates as $date){
                $date=QuoteVs2Domain::date($date);if(!$date)throw new DomainException('SERVICE_DATE_NEEDED');
                $matched=RateEngine::match($db,(int)$v['company_id'],['category'=>$identity['category'],'destination'=>$identity['destination'],'travel_date'=>$date,'market'=>$v['market']??'','pax'=>$eligible,'trip_ref'=>$v['trip_ref']??'']);
                $selected=null;foreach($matched as $candidate)if((int)$candidate['rate_version_id']===$id)$selected=$candidate;
                if(!$selected||$selected['conflict'])throw new DomainException('RATE_UNAVAILABLE');
                $cents=Vs2Decimal::parse((string)$selected['amount']);
                $periods=$q("SELECT adjustment_type,adjustment_value FROM rate_date_periods WHERE rate_version_id=? AND period_type<>'BLACKOUT' AND ? BETWEEN start_date AND end_date ORDER BY id",[$id,$date])->fetchAll();
                if(count($periods)>1)throw new DomainException('RATE_CONFLICT');
                foreach($periods as $p){if($p['adjustment_type']==='FIXED')$cents=Vs2Decimal::add($cents,Vs2Decimal::parse((string)$p['adjustment_value']));if($p['adjustment_type']==='PERCENT')$cents=Vs2Decimal::ratio($cents,Vs2Decimal::add(1000000,Vs2Decimal::parse((string)$p['adjustment_value'],4)),1000000);}
                if($amount!==null&&$amount!==$cents)throw new DomainException('SEGMENTED_RATE_REVIEW_REQUIRED');$amount=$cents;$rate=$selected;
            }
            $rate['amount']=Vs2Decimal::format($amount);$rate['terms']=$terms;$rate['manual']=false;
            $rate['terms']['package_scope']=QuoteVs2Repository::decode($terms['package_scope_json']);$rate['terms']['basis_evidence']=QuoteVs2Repository::decode($terms['basis_evidence_json']);
            $rate['inclusions']=$q('SELECT * FROM rate_version_inclusions WHERE rate_version_id=? ORDER BY sort_order,id',[$id])->fetchAll();
            $basis=['TRANSFER_PACKAGE'=>['PER_VEHICLE','PER_TRANSFER','PER_SERVICE'],'GUIDE_DAY'=>['PER_DAY','PER_GUIDE_DAY'],'HOTEL_PAX_NIGHT'=>['PER_PAX'],'CRUISE_PAX'=>['PER_PAX'],'VISA_PAX'=>['PER_PAX'],'MEAL_PAX_COUNT'=>['PER_PAX'],'TICKET_PAX'=>['PER_PAX'],'SIC_PAX'=>['PER_PAX']];
            if(!in_array($line['formula_code'],['CUSTOM','LUMP_SUM'],true)&&!in_array($rate['rate_basis'],$basis[$line['formula_code']]??[],true))throw new DomainException('UNSUPPORTED_RATE_BASIS');
            if(empty($rate['terms']['basis_evidence']['evidence']))throw new DomainException('RATE_BASIS_EVIDENCE_NEEDED');
        }
        if($rate['terms']['formula_code']!==$line['formula_code'])throw new DomainException('RATE_FORMULA_MISMATCH');
        if(!in_array($rate['tax_basis'],['NET','TAX_INCLUDED'],true))throw new DomainException('TAX_REVIEW_REQUIRED');
        if(Vs2Decimal::parse((string)$rate['amount'])<0)throw new DomainException('NEGATIVE_UNIT_RATE');
        if($req['category']==='HOTEL'&&(int)($rate['terms']['star_level']??0)!==(int)$variant['hotel_level'])throw new DomainException('HOTEL_STAR_MISMATCH');
        if($req['category']==='CRUISE'&&(int)($rate['terms']['star_level']??0)!==(int)$variant['cruise_level'])throw new DomainException('CRUISE_STAR_MISMATCH');
        if($line['formula_code']==='TRANSFER_PACKAGE'){
            $qty=QuoteVs2Domain::quantity($line,$guests);$capacity=(int)($rate['terms']['capacity']??0);
            if(!$qty||!$capacity||$qty*$capacity<$guests['total_guests'])throw new DomainException('REPRICING_REQUIRED: TRANSFER_CAPACITY');
            $scope=$rate['terms']['package_scope']??($rate['terms']['package_scope_json']??[]);if(is_string($scope))$scope=QuoteVs2Repository::decode($scope);
            if(empty($scope['itinerary_scope'])||QuoteVs2Domain::hash($scope['itinerary_scope'])!==QuoteVs2Domain::hash(QuoteVs2Domain::itineraryScope($v)))throw new DomainException('REPRICING_REQUIRED: TRANSFER_SCOPE');
        }
        $rate['source_hash']=QuoteVs2Domain::hash([$rate['rate_version_id'],$rate['amount'],$rate['currency'],$rate['tax_basis'],$rate['terms'],$rate['inclusions']]);return $rate;
    }
}
