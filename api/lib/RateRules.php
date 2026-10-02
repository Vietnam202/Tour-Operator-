<?php
declare(strict_types=1);

final class RateRules {
    private static function text(array $b,string $key,int $max=190): ?string {
        $s=trim((string)($b[$key]??''));
        if($s==='') return null;
        if(function_exists('mb_substr')) return mb_substr($s,0,$max,'UTF-8');
        return substr($s,0,$max);
    }
    private static function num(array $b,string $key): ?float {
        if(!array_key_exists($key,$b) || $b[$key]==='' || $b[$key]===null) return null;
        if(!is_numeric($b[$key])) return null;
        return (float)$b[$key];
    }
    private static function intOrNull(array $b,string $key): ?int {
        $v=self::num($b,$key); return $v===null?null:max(0,(int)$v);
    }
    private static function bool(array $b,string $key): int { return !empty($b[$key])?1:0; }
    private static function enum(array $b,string $key,array $allowed,string $fallback): string {
        $v=strtoupper(trim((string)($b[$key]??$fallback)));
        return in_array($v,$allowed,true)?$v:$fallback;
    }

    public static function save(PDO $db,int $rateVersionId,string $category,array $b): void {
        if($category==='HOTEL'){
            $sql="INSERT INTO hotel_rate_rules(rate_version_id,room_type,meal_plan,season_name,single_rate,twin_double_rate,triple_rate,extra_bed_rate,child_no_bed_rate,child_with_bed_rate,weekend_surcharge,peak_surcharge,gala_dinner,minimum_stay,blackout_text)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE room_type=VALUES(room_type),meal_plan=VALUES(meal_plan),season_name=VALUES(season_name),single_rate=VALUES(single_rate),twin_double_rate=VALUES(twin_double_rate),triple_rate=VALUES(triple_rate),extra_bed_rate=VALUES(extra_bed_rate),child_no_bed_rate=VALUES(child_no_bed_rate),child_with_bed_rate=VALUES(child_with_bed_rate),weekend_surcharge=VALUES(weekend_surcharge),peak_surcharge=VALUES(peak_surcharge),gala_dinner=VALUES(gala_dinner),minimum_stay=VALUES(minimum_stay),blackout_text=VALUES(blackout_text)";
            $db->prepare($sql)->execute([$rateVersionId,self::text($b,'room_type'),self::text($b,'meal_plan',64),self::text($b,'season_name',120),self::num($b,'single_rate'),self::num($b,'twin_double_rate'),self::num($b,'triple_rate'),self::num($b,'extra_bed_rate'),self::num($b,'child_no_bed_rate'),self::num($b,'child_with_bed_rate'),self::num($b,'weekend_surcharge'),self::num($b,'peak_surcharge'),self::num($b,'gala_dinner'),self::intOrNull($b,'minimum_stay'),self::text($b,'blackout_text',4000)]);
        } elseif($category==='TRANSPORT'){
            $service=self::enum($b,'service_type',['AIRPORT_TRANSFER','POINT_TO_POINT','FULL_DAY','HALF_DAY','INTERCITY','OTHER'],'OTHER');
            $sql="INSERT INTO transport_rate_rules(rate_version_id,route_from,route_to,vehicle_type,capacity,service_type,hours_included,km_included,overtime_rate,extra_km_rate,driver_overnight,parking_fee,toll_fee,airport_fee,holiday_surcharge)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE route_from=VALUES(route_from),route_to=VALUES(route_to),vehicle_type=VALUES(vehicle_type),capacity=VALUES(capacity),service_type=VALUES(service_type),hours_included=VALUES(hours_included),km_included=VALUES(km_included),overtime_rate=VALUES(overtime_rate),extra_km_rate=VALUES(extra_km_rate),driver_overnight=VALUES(driver_overnight),parking_fee=VALUES(parking_fee),toll_fee=VALUES(toll_fee),airport_fee=VALUES(airport_fee),holiday_surcharge=VALUES(holiday_surcharge)";
            $db->prepare($sql)->execute([$rateVersionId,self::text($b,'route_from'),self::text($b,'route_to'),self::text($b,'vehicle_type',120),self::intOrNull($b,'capacity'),$service,self::num($b,'hours_included'),self::num($b,'km_included'),self::num($b,'overtime_rate'),self::num($b,'extra_km_rate'),self::num($b,'driver_overnight'),self::num($b,'parking_fee'),self::num($b,'toll_fee'),self::num($b,'airport_fee'),self::num($b,'holiday_surcharge')]);
        } elseif($category==='CRUISE'){
            $sql="INSERT INTO cruise_rate_rules(rate_version_id,cruise_name,route_name,duration_code,cabin_type,deck,single_rate,double_twin_rate,triple_rate,child_rate,single_supplement,transfer_included,entrance_included,kayak_included,holiday_surcharge,gala_dinner)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE cruise_name=VALUES(cruise_name),route_name=VALUES(route_name),duration_code=VALUES(duration_code),cabin_type=VALUES(cabin_type),deck=VALUES(deck),single_rate=VALUES(single_rate),double_twin_rate=VALUES(double_twin_rate),triple_rate=VALUES(triple_rate),child_rate=VALUES(child_rate),single_supplement=VALUES(single_supplement),transfer_included=VALUES(transfer_included),entrance_included=VALUES(entrance_included),kayak_included=VALUES(kayak_included),holiday_surcharge=VALUES(holiday_surcharge),gala_dinner=VALUES(gala_dinner)";
            $db->prepare($sql)->execute([$rateVersionId,self::text($b,'cruise_name'),self::text($b,'route_name'),self::text($b,'duration_code',32),self::text($b,'cabin_type'),self::text($b,'deck',64),self::num($b,'single_rate'),self::num($b,'double_twin_rate'),self::num($b,'triple_rate'),self::num($b,'child_rate'),self::num($b,'single_supplement'),self::bool($b,'transfer_included'),self::bool($b,'entrance_included'),self::bool($b,'kayak_included'),self::num($b,'holiday_surcharge'),self::num($b,'gala_dinner')]);
        } elseif($category==='GUIDE'){
            $scope=self::enum($b,'service_scope',['FULL_DAY','HALF_DAY','TRANSFER','MULTI_DAY','OTHER'],'OTHER');
            $sql="INSERT INTO guide_rate_rules(rate_version_id,language,destination,service_scope,overtime_rate,meal_allowance,accommodation_allowance,intercity_allowance,holiday_surcharge)
                  VALUES(?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE language=VALUES(language),destination=VALUES(destination),service_scope=VALUES(service_scope),overtime_rate=VALUES(overtime_rate),meal_allowance=VALUES(meal_allowance),accommodation_allowance=VALUES(accommodation_allowance),intercity_allowance=VALUES(intercity_allowance),holiday_surcharge=VALUES(holiday_surcharge)";
            $db->prepare($sql)->execute([$rateVersionId,self::text($b,'language',120),self::text($b,'guide_destination',160),$scope,self::num($b,'overtime_rate'),self::num($b,'meal_allowance'),self::num($b,'accommodation_allowance'),self::num($b,'intercity_allowance'),self::num($b,'holiday_surcharge')]);
        } elseif($category==='TOUR'){
            $mode=self::enum($b,'tour_mode',['SIC','PRIVATE','BOTH','OTHER'],'OTHER');
            $mealTime=self::text($b,'departure_time',8);
            if($mealTime && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$mealTime)) $mealTime=null;
            $sql="INSERT INTO tour_rate_rules(rate_version_id,tour_name,tour_mode,adult_rate,child_rate,pickup_zone,pickup_surcharge,departure_days,departure_time,cutoff_hours,minimum_pax,guide_language,included_text,excluded_text)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE tour_name=VALUES(tour_name),tour_mode=VALUES(tour_mode),adult_rate=VALUES(adult_rate),child_rate=VALUES(child_rate),pickup_zone=VALUES(pickup_zone),pickup_surcharge=VALUES(pickup_surcharge),departure_days=VALUES(departure_days),departure_time=VALUES(departure_time),cutoff_hours=VALUES(cutoff_hours),minimum_pax=VALUES(minimum_pax),guide_language=VALUES(guide_language),included_text=VALUES(included_text),excluded_text=VALUES(excluded_text)";
            $db->prepare($sql)->execute([$rateVersionId,self::text($b,'tour_name'),$mode,self::num($b,'adult_rate'),self::num($b,'child_rate'),self::text($b,'pickup_zone'),self::num($b,'pickup_surcharge'),self::text($b,'departure_days'),$mealTime,self::num($b,'cutoff_hours'),self::intOrNull($b,'minimum_pax'),self::text($b,'guide_language',120),self::text($b,'included_text',4000),self::text($b,'excluded_text',4000)]);
        } elseif($category==='ATTRACTION'){
            $sql="INSERT INTO attraction_rate_rules(rate_version_id,attraction_name,adult_rate,child_rate,infant_rate,senior_rate,age_rule_text,height_rule_text,group_rule_text,meal_option,combo_option,holiday_surcharge)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE attraction_name=VALUES(attraction_name),adult_rate=VALUES(adult_rate),child_rate=VALUES(child_rate),infant_rate=VALUES(infant_rate),senior_rate=VALUES(senior_rate),age_rule_text=VALUES(age_rule_text),height_rule_text=VALUES(height_rule_text),group_rule_text=VALUES(group_rule_text),meal_option=VALUES(meal_option),combo_option=VALUES(combo_option),holiday_surcharge=VALUES(holiday_surcharge)";
            $db->prepare($sql)->execute([$rateVersionId,self::text($b,'attraction_name'),self::num($b,'adult_rate'),self::num($b,'child_rate'),self::num($b,'infant_rate'),self::num($b,'senior_rate'),self::text($b,'age_rule_text',500),self::text($b,'height_rule_text',500),self::text($b,'group_rule_text',500),self::text($b,'meal_option'),self::text($b,'combo_option'),self::num($b,'holiday_surcharge')]);
        } elseif($category==='MEAL'){
            $type=self::enum($b,'meal_type',['BREAKFAST','LUNCH','DINNER','OTHER'],'OTHER');
            $sql="INSERT INTO meal_rate_rules(rate_version_id,restaurant_name,cuisine,meal_type,menu_level,adult_rate,child_rate,beverage_included,guide_meal_included,guide_meal_rate,notes)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE restaurant_name=VALUES(restaurant_name),cuisine=VALUES(cuisine),meal_type=VALUES(meal_type),menu_level=VALUES(menu_level),adult_rate=VALUES(adult_rate),child_rate=VALUES(child_rate),beverage_included=VALUES(beverage_included),guide_meal_included=VALUES(guide_meal_included),guide_meal_rate=VALUES(guide_meal_rate),notes=VALUES(notes)";
            $db->prepare($sql)->execute([$rateVersionId,self::text($b,'restaurant_name'),self::text($b,'cuisine',120),$type,self::text($b,'menu_level',120),self::num($b,'adult_rate'),self::num($b,'child_rate'),self::bool($b,'beverage_included'),self::bool($b,'guide_meal_included'),self::num($b,'guide_meal_rate'),self::text($b,'meal_notes',1000)]);
        }
    }

    public static function saveShared(PDO $db,int $rateVersionId,array $b): void {
        if(array_key_exists('date_periods',$b) && is_array($b['date_periods'])){
            $db->prepare('DELETE FROM rate_date_periods WHERE rate_version_id=?')->execute([$rateVersionId]);
            $ins=$db->prepare('INSERT INTO rate_date_periods(rate_version_id,period_type,name,start_date,end_date,adjustment_type,adjustment_value,notes) VALUES(?,?,?,?,?,?,?,?)');
            foreach(array_slice($b['date_periods'],0,50) as $r){if(!is_array($r))continue;$start=trim((string)($r['start_date']??''));$end=trim((string)($r['end_date']??''));if(!$start||!$end)continue;$ptype=self::enum($r,'period_type',['SEASON','BLACKOUT','PEAK','HOLIDAY'],'SEASON');$adj=self::enum($r,'adjustment_type',['NONE','FIXED','PERCENT'],'NONE');$ins->execute([$rateVersionId,$ptype,self::text($r,'name'),$start,$end,$adj,self::num($r,'adjustment_value'),self::text($r,'notes',1000)]);}
        }
        if(array_key_exists('child_rules',$b) && is_array($b['child_rules'])){
            $db->prepare('DELETE FROM rate_child_rules WHERE rate_version_id=?')->execute([$rateVersionId]);
            $ins=$db->prepare('INSERT INTO rate_child_rules(rate_version_id,measure_type,label,min_value,max_value,price_mode,price_value,notes) VALUES(?,?,?,?,?,?,?,?)');
            foreach(array_slice($b['child_rules'],0,50) as $r){if(!is_array($r))continue;$label=trim((string)($r['label']??''));$notes=trim((string)($r['notes']??''));if($label==='' && $notes==='')continue;$measure=self::enum($r,'measure_type',['AGE_YEARS','HEIGHT_CM','TEXT'],'TEXT');$mode=self::enum($r,'price_mode',['FREE','FIXED','PERCENT_ADULT','ADULT_RATE','TEXT_ONLY'],'TEXT_ONLY');$ins->execute([$rateVersionId,$measure,self::text($r,'label'),self::num($r,'min_value'),self::num($r,'max_value'),$mode,self::num($r,'price_value'),self::text($r,'notes',1000)]);}
        }
        if(array_key_exists('foc_rules',$b) && is_array($b['foc_rules'])){
            $db->prepare('DELETE FROM rate_foc_rules WHERE rate_version_id=?')->execute([$rateVersionId]);
            $ins=$db->prepare('INSERT INTO rate_foc_rules(rate_version_id,paying_pax_threshold,foc_units,max_foc,applies_to,conditions) VALUES(?,?,?,?,?,?)');
            foreach(array_slice($b['foc_rules'],0,20) as $r){if(!is_array($r))continue;$threshold=(int)($r['paying_pax_threshold']??0);if($threshold<=0)continue;$ins->execute([$rateVersionId,$threshold,max(1,(int)($r['foc_units']??1)),isset($r['max_foc'])&&$r['max_foc']!==''?max(0,(int)$r['max_foc']):null,self::text($r,'applies_to',120),self::text($r,'conditions',1000)]);}
        }
    }

    public static function copy(PDO $db,int $oldVersionId,int $newVersionId,string $category): void {
        $map=[
          'HOTEL'=>['hotel_rate_rules','room_type,meal_plan,season_name,single_rate,twin_double_rate,triple_rate,extra_bed_rate,child_no_bed_rate,child_with_bed_rate,weekend_surcharge,peak_surcharge,gala_dinner,minimum_stay,blackout_text'],
          'TRANSPORT'=>['transport_rate_rules','route_from,route_to,vehicle_type,capacity,service_type,hours_included,km_included,overtime_rate,extra_km_rate,driver_overnight,parking_fee,toll_fee,airport_fee,holiday_surcharge'],
          'CRUISE'=>['cruise_rate_rules','cruise_name,route_name,duration_code,cabin_type,deck,single_rate,double_twin_rate,triple_rate,child_rate,single_supplement,transfer_included,entrance_included,kayak_included,holiday_surcharge,gala_dinner'],
          'GUIDE'=>['guide_rate_rules','language,destination,service_scope,overtime_rate,meal_allowance,accommodation_allowance,intercity_allowance,holiday_surcharge'],
          'TOUR'=>['tour_rate_rules','tour_name,tour_mode,adult_rate,child_rate,pickup_zone,pickup_surcharge,departure_days,departure_time,cutoff_hours,minimum_pax,guide_language,included_text,excluded_text'],
          'ATTRACTION'=>['attraction_rate_rules','attraction_name,adult_rate,child_rate,infant_rate,senior_rate,age_rule_text,height_rule_text,group_rule_text,meal_option,combo_option,holiday_surcharge'],
          'MEAL'=>['meal_rate_rules','restaurant_name,cuisine,meal_type,menu_level,adult_rate,child_rate,beverage_included,guide_meal_included,guide_meal_rate,notes'],
        ];
        if(isset($map[$category])){[$table,$cols]=$map[$category];$db->prepare("INSERT INTO $table(rate_version_id,$cols) SELECT ?,$cols FROM $table WHERE rate_version_id=?")->execute([$newVersionId,$oldVersionId]);}
        self::copyShared($db,$oldVersionId,$newVersionId);
    }

    private static function copyShared(PDO $db,int $oldVersionId,int $newVersionId): void {
        $db->prepare("INSERT INTO rate_date_periods(rate_version_id,period_type,name,start_date,end_date,adjustment_type,adjustment_value,notes) SELECT ?,period_type,name,start_date,end_date,adjustment_type,adjustment_value,notes FROM rate_date_periods WHERE rate_version_id=?")->execute([$newVersionId,$oldVersionId]);
        $db->prepare("INSERT INTO rate_child_rules(rate_version_id,measure_type,label,min_value,max_value,price_mode,price_value,notes) SELECT ?,measure_type,label,min_value,max_value,price_mode,price_value,notes FROM rate_child_rules WHERE rate_version_id=?")->execute([$newVersionId,$oldVersionId]);
        $db->prepare("INSERT INTO rate_foc_rules(rate_version_id,paying_pax_threshold,foc_units,max_foc,applies_to,conditions) SELECT ?,paying_pax_threshold,foc_units,max_foc,applies_to,conditions FROM rate_foc_rules WHERE rate_version_id=?")->execute([$newVersionId,$oldVersionId]);
    }

    public static function fetch(PDO $db,int $rateVersionId,string $category): ?array {
        $table=match($category){
          'HOTEL'=>'hotel_rate_rules','TRANSPORT'=>'transport_rate_rules','CRUISE'=>'cruise_rate_rules','GUIDE'=>'guide_rate_rules','TOUR'=>'tour_rate_rules','ATTRACTION'=>'attraction_rate_rules','MEAL'=>'meal_rate_rules',default=>null
        };
        if(!$table) return null;
        $st=$db->prepare("SELECT * FROM $table WHERE rate_version_id=? LIMIT 1");$st->execute([$rateVersionId]);$r=$st->fetch();return $r?:null;
    }

    public static function fetchShared(PDO $db,int $rateVersionId): array {
        $q=$db->prepare('SELECT period_type,name,start_date,end_date,adjustment_type,adjustment_value,notes FROM rate_date_periods WHERE rate_version_id=? ORDER BY start_date,period_type');$q->execute([$rateVersionId]);$periods=$q->fetchAll();
        $q=$db->prepare('SELECT measure_type,label,min_value,max_value,price_mode,price_value,notes FROM rate_child_rules WHERE rate_version_id=? ORDER BY id');$q->execute([$rateVersionId]);$children=$q->fetchAll();
        $q=$db->prepare('SELECT paying_pax_threshold,foc_units,max_foc,applies_to,conditions FROM rate_foc_rules WHERE rate_version_id=? ORDER BY paying_pax_threshold');$q->execute([$rateVersionId]);$foc=$q->fetchAll();
        return ['date_periods'=>$periods,'child_rules'=>$children,'foc_rules'=>$foc];
    }

    public static function evaluate(PDO $db,int $rateVersionId,float $baseAmount,?string $travelDate,?int $payingPax,?float $childAgeYears,?float $childHeightCm): array {
        $result=['base_amount'=>$baseAmount,'effective_amount'=>$baseAmount,'blackout'=>false,'adjustments'=>[],'matched_periods'=>[],'child_price'=>null,'child_rule'=>null,'foc_eligible'=>0,'foc_rule'=>null];
        if($travelDate){
            $q=$db->prepare("SELECT * FROM rate_date_periods WHERE rate_version_id=? AND start_date<=? AND end_date>=? ORDER BY FIELD(period_type,'BLACKOUT','PEAK','HOLIDAY','SEASON'),start_date");
            $q->execute([$rateVersionId,$travelDate,$travelDate]);
            foreach($q->fetchAll() as $r){
                $result['matched_periods'][]=$r;
                if($r['period_type']==='BLACKOUT'){$result['blackout']=true;continue;}
                $type=$r['adjustment_type'];$value=$r['adjustment_value']!==null?(float)$r['adjustment_value']:0.0;
                $delta=0.0;if($type==='FIXED')$delta=$value;elseif($type==='PERCENT')$delta=$baseAmount*$value/100.0;
                if($delta!=0.0){$result['effective_amount']+=$delta;$result['adjustments'][]=['name'=>$r['name']?:$r['period_type'],'type'=>$type,'value'=>$value,'delta'=>$delta];}
            }
        }
        if($payingPax!==null && $payingPax>=0){
            $q=$db->prepare("SELECT * FROM rate_foc_rules WHERE rate_version_id=? AND paying_pax_threshold<=? ORDER BY paying_pax_threshold DESC LIMIT 1");$q->execute([$rateVersionId,$payingPax]);$r=$q->fetch();
            if($r){$units=(int)floor($payingPax/max(1,(int)$r['paying_pax_threshold']))*(int)$r['foc_units'];if($r['max_foc']!==null)$units=min($units,(int)$r['max_foc']);$result['foc_eligible']=$units;$result['foc_rule']=$r;}
        }
        $measure=null;$value=null;if($childHeightCm!==null){$measure='HEIGHT_CM';$value=$childHeightCm;}elseif($childAgeYears!==null){$measure='AGE_YEARS';$value=$childAgeYears;}
        if($measure!==null){
            $q=$db->prepare("SELECT * FROM rate_child_rules WHERE rate_version_id=? AND measure_type=? AND (min_value IS NULL OR min_value<=?) AND (max_value IS NULL OR max_value>=?) ORDER BY COALESCE(min_value,0) DESC LIMIT 1");
            $q->execute([$rateVersionId,$measure,$value,$value]);$r=$q->fetch();
            if($r){$price=null;$pv=$r['price_value']!==null?(float)$r['price_value']:null;switch($r['price_mode']){case 'FREE':$price=0.0;break;case 'FIXED':$price=$pv;break;case 'PERCENT_ADULT':$price=$pv===null?null:$result['effective_amount']*$pv/100.0;break;case 'ADULT_RATE':$price=$result['effective_amount'];break;}$result['child_price']=$price;$result['child_rule']=$r;}
        }
        return $result;
    }


    public static function conflicts(PDO $db,int $companyId,int $rateId,int $versionNo): array {
        $st=$db->prepare("SELECT r.*,rv.id rate_version_id,rv.version_no rate_version_no,rv.valid_from,rv.valid_to,rv.amount,rv.currency,rv.approval_status
                          FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id
                          WHERE r.company_id=? AND r.id=? AND rv.version_no=? LIMIT 1");
        $st->execute([$companyId,$rateId,$versionNo]);$base=$st->fetch();if(!$base)return [];
        $sql="SELECT r2.id,r2.rate_ref,r2.product_name,r2.option_name,r2.destination,r2.market,s.name supplier_name,rv2.version_no,rv2.amount,rv2.currency,rv2.valid_from,rv2.valid_to,rv2.approval_status
              FROM rates r2 JOIN suppliers s ON s.id=r2.supplier_id
              JOIN rate_versions rv2 ON rv2.rate_id=r2.id AND rv2.approval_status='APPROVED'
              WHERE r2.company_id=? AND r2.id<>? AND r2.status='ACTIVE'
                AND r2.supplier_id=? AND r2.category=?
                AND LOWER(r2.product_name)=LOWER(?)
                AND COALESCE(LOWER(r2.option_name),'')=COALESCE(LOWER(?),'')
                AND COALESCE(LOWER(r2.destination),'')=COALESCE(LOWER(?),'')
                AND COALESCE(LOWER(r2.market),'')=COALESCE(LOWER(?),'')
                AND COALESCE(rv2.valid_from,'1000-01-01')<=COALESCE(?,'9999-12-31')
                AND COALESCE(rv2.valid_to,'9999-12-31')>=COALESCE(?,'1000-01-01')
              ORDER BY rv2.valid_from DESC,r2.updated_at DESC LIMIT 50";
        $q=$db->prepare($sql);$q->execute([$companyId,$rateId,$base['supplier_id'],$base['category'],$base['product_name'],$base['option_name'],$base['destination'],$base['market'],$base['valid_to'],$base['valid_from']]);
        return $q->fetchAll();
    }
}
