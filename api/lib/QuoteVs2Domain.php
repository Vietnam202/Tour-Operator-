<?php
declare(strict_types=1);

final class QuoteVs2Domain {
    public const SOURCES=['TOTAL_GUESTS','PAYING_PAX','HOTEL_PAX','CRUISE_PAX','VISA_PAX','MEAL_PAX','TICKET_PAX','CUSTOM_QTY'];
    public const BASE=['adults','children','infants','foc','total_guests','paying_pax'];
    public const PROFILE=['hotel_pax','cruise_pax','visa_pax','meal_pax','ticket_pax'];
    public const OPERATIONAL_SCOPE=['destination','dates','occurrences','route','attraction_key','meal_key','eligibility_group','transport_leg'];
    public const FORMULAS=['TRANSPORT'=>'TRANSFER_PACKAGE','GUIDE'=>'GUIDE_DAY','HOTEL'=>'HOTEL_PAX_NIGHT','CRUISE'=>'CRUISE_PAX','VISA'=>'VISA_PAX','MEAL'=>'MEAL_PAX_COUNT','ATTRACTION'=>'TICKET_PAX','TOUR'=>'SIC_PAX','OTHER'=>'CUSTOM'];
    public static function count($value,bool $nullable=false): ?int {
        if($nullable&&$value===null)return null;
        if((!is_int($value)&&!is_string($value))||!preg_match('/^\d{1,5}$/',(string)$value)||(int)$value>10000)throw new InvalidArgumentException('Integer quantity from 0 to 10000 required');return (int)$value;
    }
    public static function guests(array $v,array $profile): array {
        $g=[];foreach(self::BASE as $key)$g[$key]=self::count($v[$key]??null);
        if($g['total_guests']<1||$g['paying_pax']<1||$g['paying_pax']+$g['foc']>$g['total_guests']||$g['adults']+$g['children']+$g['infants']+$g['foc']!==$g['total_guests'])throw new DomainException('PAX_SEGMENT_REVIEW_REQUIRED');
        foreach(self::PROFILE as $key){$g[$key]=self::count($profile[$key]??null,true);if($g[$key]!==null&&$g[$key]>$g['total_guests'])throw new DomainException('SERVICE_POPULATION_EXCEEDS_TOTAL');}
        return $g;
    }
    public static function quantity(array $line,array $guests): ?int {
        $source=$line['quantity_source'];if(!in_array($source,self::SOURCES,true))throw new InvalidArgumentException('Invalid quantity source');
        if(($line['quantity_override']??null)!==null)return self::count($line['quantity_override']);
        return self::count($source==='CUSTOM_QTY'?($line['custom_quantity']??null):($guests[strtolower($source)]??null),true);
    }
    public static function affected(array $line,array $changed): bool {
        return ($line['quantity_override']??null)===null&&in_array($line['quantity_source'],$changed,true);
    }
    public static function applies(array $req,array $variant,?array $line=null): bool {
        if($req['requirement_state']==='NOT_APPLICABLE')return false;
        // Completeness is a property of requirements, even when their only line was removed.
        if($variant['costing_mode']==='HYBRID'&&$line===null)return true;
        $mode=$variant['costing_mode']==='HYBRID'?($line['service_mode']??'PRIVATE'):$variant['costing_mode'];
        return $req['service_mode']==='BOTH'||$req['service_mode']===$mode;
    }
    public static function destination(array $scope): string {
        return self::text($scope['destination']??'',160,true);
    }
    public static function serviceDates(array $req,array $days): bool {
        $scope=QuoteVs2Repository::decode($req['scope_json']);$dates=$scope['dates']??null;
        if(!is_array($dates)||!array_is_list($dates)||!$dates||count($dates)!==(int)$req['service_units'])return false;
        $itinerary=[];
        foreach($days as $day){$date=$day['date']??null;
            try{$date=self::date($date);}catch(InvalidArgumentException){return false;}
            if($date){
            if(isset($itinerary[$date]))return false;
            $itinerary[$date]=$day;
        }}
        $seen=[];
        foreach($dates as $date){
            try{$date=self::date($date);}catch(InvalidArgumentException){return false;}
            if(!$date||isset($seen[$date])||!isset($itinerary[$date]))return false;
            $seen[$date]=true;$day=$itinerary[$date];
            if($req['category']==='HOTEL'&&($day['overnight_type']??'')!=='HOTEL')return false;
            if($req['category']==='GUIDE'&&empty($day['guide_required']))return false;
        }
        return true;
    }
    public static function text($value,int $max=1000,bool $empty=false): string {
        if(!is_string($value)||strlen($value)>$max||(!$empty&&trim($value)===''))throw new InvalidArgumentException('Valid text required');return trim($value);
    }
    public static function date($value): ?string {
        if($value===null||$value==='')return null;
        $d=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
        if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Valid date required');return $value;
    }
    public static function schedule(array $days): array {
        if(count($days)>100)throw new InvalidArgumentException('Too many days');$out=[];$seen=[];
        foreach($days as $i=>$day){if(!is_array($day))throw new InvalidArgumentException('Invalid day');$key=self::text($day['day_key']??('day-'.bin2hex(random_bytes(8))),80);if(isset($seen[$key]))throw new InvalidArgumentException('Duplicate day key');$seen[$key]=true;
            $clean=['day'=>$i+1,'day_key'=>$key];foreach(['date','title','description','meals','overnight','notes','route','activities','transport_mode','cruise','special_requests'] as $field)$clean[$field]=self::text($day[$field]??'',10000,true);
            $clean['date']=self::date($clean['date'])??'';$clean['guide_required']=!empty($day['guide_required']);
            $overnightType=$day['overnight_type']??'UNREVIEWED';if(!in_array($overnightType,['UNREVIEWED','HOTEL','CRUISE','OTHER','NONE'],true))throw new InvalidArgumentException('Invalid overnight type');$clean['overnight_type']=$overnightType;$out[]=$clean;
        }return $out;
    }
    /** Cosmetic prose deliberately excluded from transport scope. */
    public static function itineraryScope(array $v): array {
        $days=QuoteVs2Repository::decode($v['schedule_json']??'[]');$legs=[];
        foreach($days as $day)$legs[]=['day_key'=>$day['day_key']??null,'date'=>$day['date']??null,'route'=>$day['route']??'','transport_mode'=>$day['transport_mode']??''];
        return ['start_date'=>$v['start_date'],'end_date'=>$v['end_date'],'legs'=>$legs];
    }
    public static function hash($value): string {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as &$item)$item=$sort($item);return $v;};return hash('sha256',QuoteVs2Repository::json($sort($value)));
    }
}
