<?php
declare(strict_types=1);

final class RateEngine {
    public static function match(PDO $db,int $company,array $criteria): array {
        $date=$criteria['travel_date']??'';$book=$criteria['booking_date']??date('Y-m-d');
        foreach([$date,$book] as $value){
            if(!is_string($value))throw new InvalidArgumentException('Date required');
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Valid travel and booking dates required');
        }
        $pax=filter_var($criteria['pax']??0,FILTER_VALIDATE_INT);
        if($pax===false||$pax<1||$pax>10000)throw new InvalidArgumentException('Positive pax count required');
        $sql="SELECT r.id rate_id,r.supplier_id,r.product_name,r.option_name,r.category,r.destination,r.market,r.rate_type,rv.id rate_version_id,rv.version_no,rv.amount,rv.currency,rv.rate_basis,rv.tax_basis,rv.source_document_id
            FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id JOIN suppliers s ON s.id=r.supplier_id AND s.company_id=r.company_id
            WHERE r.company_id=? AND r.status='ACTIVE' AND s.status='ACTIVE' AND rv.approval_status='APPROVED'
            AND r.category=? AND (r.destination IS NULL OR r.destination='' OR LOWER(r.destination)=LOWER(?))
            AND (r.market IS NULL OR r.market='' OR LOWER(r.market)=LOWER(?))
            AND (r.min_pax IS NULL OR r.min_pax<=?) AND (r.max_pax IS NULL OR r.max_pax>=?)
            AND (rv.valid_from IS NULL OR rv.valid_from<=?) AND (rv.valid_to IS NULL OR rv.valid_to>=?)
            AND (rv.booking_valid_from IS NULL OR rv.booking_valid_from<=?) AND (rv.booking_valid_to IS NULL OR rv.booking_valid_to>=?)
            AND (r.rate_type<>'SPECIAL_QUOTE' OR r.linked_trip_ref=?)
            AND NOT EXISTS(SELECT 1 FROM rate_date_periods p WHERE p.rate_version_id=rv.id AND p.period_type='BLACKOUT' AND ? BETWEEN p.start_date AND p.end_date)
            AND (NOT EXISTS(SELECT 1 FROM rate_date_periods p WHERE p.rate_version_id=rv.id AND p.period_type='SEASON')
                OR EXISTS(SELECT 1 FROM rate_date_periods p WHERE p.rate_version_id=rv.id AND p.period_type='SEASON' AND ? BETWEEN p.start_date AND p.end_date))";
        $args=[$company,$criteria['category']??'',$criteria['destination']??'',$criteria['market']??'',$pax,$pax,$date,$date,$book,$book,$criteria['trip_ref']??'',$date,$date];
        if(!empty($criteria['product_name'])){$sql.=' AND LOWER(r.product_name)=LOWER(?)';$args[]=$criteria['product_name'];}
        $sql.=' ORDER BY r.id,rv.version_no DESC LIMIT 200';$s=$db->prepare($sql);$s->execute($args);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){
            $p=$db->prepare("SELECT period_type,adjustment_type,adjustment_value FROM rate_date_periods WHERE rate_version_id=? AND period_type<>'BLACKOUT' AND ? BETWEEN start_date AND end_date ORDER BY id");$p->execute([$row['rate_version_id'],$date]);$periods=$p->fetchAll(PDO::FETCH_ASSOC);
            // Overlapping adjustments require a contracting decision; never silently choose the cheapest.
            $row['conflict']=count($periods)>1;
            $amount=(float)$row['amount'];
            if(!$row['conflict'] && $periods){$period=$periods[0];$value=(float)$period['adjustment_value'];if($period['adjustment_type']==='FIXED')$amount+=$value;if($period['adjustment_type']==='PERCENT')$amount*=1+$value/100;}
            $row['effective_amount']=round($amount,2);
        }unset($row);
        $counts=array_count_values(array_column($rows,'rate_id'));
        foreach($rows as &$row){if($counts[$row['rate_id']]>1)$row['conflict']=true;}unset($row);
        return $rows;
    }
    public static function transportCost(array $rate,array $rules,array $usage): array {
        foreach(['capacity','hours_included','km_included','overtime_rate','extra_km_rate','driver_overnight','parking_fee','toll_fee','airport_fee','holiday_surcharge'] as $key){if(isset($rules[$key])&&(!is_numeric($rules[$key])||!is_finite((float)$rules[$key])||(float)$rules[$key]<0))throw new DomainException('Invalid transport rule '.$key);}
        foreach(['vehicles','days','km','hours','pax'] as $key){$v=$usage[$key]??0;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)throw new InvalidArgumentException('Invalid '.$key);}
        $vehicles=(float)($usage['vehicles']??0);$pax=(float)($usage['pax']??0);
        if($vehicles<1||floor($vehicles)!==$vehicles||$pax<1)throw new InvalidArgumentException('Vehicle and pax counts required');
        if(empty($rules['capacity']) || $vehicles*(int)$rules['capacity']<$pax)throw new DomainException('Vehicle capacity is missing or insufficient');
        $basis=$rate['rate_basis'];$quantity=match($basis){'PER_VEHICLE','PER_TRANSFER','PER_SERVICE'=>1.0,'PER_DAY'=>(float)($usage['days']??0),'PER_KM'=>(float)($usage['km']??0),default=>throw new InvalidArgumentException('Unsupported transport basis')};
        if($quantity<=0)throw new InvalidArgumentException('Positive costing quantity required');
        // hours/km usage is per vehicle for the entire service. Per-day allowances scale with days.
        $allowanceUnits=$basis==='PER_DAY'?$quantity:1;
        $extraHours=max(0,(float)($usage['hours']??0)-(float)($rules['hours_included']??0)*$allowanceUnits);
        $extraKm=$basis==='PER_KM'?0:max(0,(float)($usage['km']??0)-(float)($rules['km_included']??0)*$allowanceUnits);
        if($extraHours>0 && (!isset($rules['hours_included'])||!isset($rules['overtime_rate'])))throw new DomainException('Overtime terms missing');
        if($extraKm>0 && (!isset($rules['km_included'])||!isset($rules['extra_km_rate'])))throw new DomainException('Extra kilometre terms missing');
        $unit=(float)($rate['effective_amount']??$rate['amount']);
        $base=round($vehicles*$quantity*$unit,2);
        $extra=round($vehicles*($extraHours*(float)($rules['overtime_rate']??0)+$extraKm*(float)($rules['extra_km_rate']??0)),2);
        $overnights=$usage['driver_nights']??0;if(!is_numeric($overnights)||(float)$overnights<0||floor((float)$overnights)!==(float)$overnights)throw new InvalidArgumentException('Invalid driver overnight count');
        if($overnights>0&&!isset($rules['driver_overnight']))throw new DomainException('Driver overnight rate missing');
        $extra+=round($vehicles*((float)$overnights*(float)($rules['driver_overnight']??0)+(float)($rules['parking_fee']??0)+(float)($rules['toll_fee']??0)+(float)($rules['airport_fee']??0)+(!empty($usage['holiday'])?(float)($rules['holiday_surcharge']??0):0)),2);
        return ['pax'=>$vehicles,'qty'=>$quantity,'unit_price'=>$unit,'base_total'=>$base,'extras'=>$extra,'total'=>$base+$extra,'currency'=>$rate['currency']];
    }
}
