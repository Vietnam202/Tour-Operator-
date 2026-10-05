<?php
declare(strict_types=1);

/** VS2 arithmetic only. Rate resolution belongs to the authenticated server adapter. */
final class SmartCosting {
    public const CATEGORIES=['TRANSPORT','GUIDE','HOTEL','CRUISE','VISA','MEAL','ATTRACTION','TOUR','OTHER'];
    public const SOURCES=['TOTAL_GUESTS','PAYING_PAX','VISA_PAX','MEAL_PAX','HOTEL_PAX','TICKET_PAX','CUSTOM_QTY'];
    public static function integer($value,int $max=10000): int {
        if((!is_int($value)&&!is_string($value))||!preg_match('/^\d+$/D',(string)$value)||(int)$value>$max)throw new InvalidArgumentException('Whole non-negative quantity required');
        return (int)$value;
    }
    public static function money($value): float {
        if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>100000000)throw new InvalidArgumentException('Invalid supplier rate');
        return round((float)$value,2);
    }
    public static function guests(array $v,array $profile=[]): array {
        $g=[];foreach(['adults','children','infants','foc','total_guests','paying_pax'] as $k)$g[$k]=self::integer($v[$k]??null);
        // Preserve RC6 VS1: FOC is a separate segment, not counted again in adults/children.
        if($g['total_guests']<1||$g['paying_pax']<1||$g['adults']+$g['children']+$g['infants']+$g['foc']!==$g['total_guests']||$g['paying_pax']+$g['foc']>$g['total_guests'])throw new InvalidArgumentException('Review guest segments: adults + children + infants + separate FOC = total guests');
        foreach(['visa_pax','meal_pax','hotel_pax','ticket_pax'] as $k){$g[$k]=isset($profile[$k])?self::integer($profile[$k]):$g['total_guests'];if($g[$k]>$g['total_guests'])throw new InvalidArgumentException($k.' exceeds total guests');}
        return $g;
    }
    public static function scopeHash(array $v): string {
                $days=[];foreach(json_decode($v['schedule_json']??'[]',true) as $day){$route=[];foreach(['date','title','description','overnight'] as $k)$route[$k]=(string)($day[$k]??'');$days[]=$route;}
        return hash('sha256',json_encode([$v['start_date'],$v['end_date'],$days],JSON_THROW_ON_ERROR));
    }
    public static function requirements(array $items,array $v): array {
        if(!array_is_list($items)||count($items)>200)throw new InvalidArgumentException('Maximum 200 service requirements');
        $out=[];$seen=[];
        foreach($items as $i=>$r){
            $key=$r['key']??$r['requirement_key']??'';if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key)||isset($seen[$key]))throw new InvalidArgumentException('Unique service key required');$seen[$key]=true;
            $category=$r['category']??'';$mode=$r['mode']??$r['service_mode']??'BOTH';
            if(!in_array($category,self::CATEGORIES,true)||!in_array($mode,['PRIVATE','SIC','BOTH'],true))throw new InvalidArgumentException('Invalid category or service mode');
            $name=$r['service_name']??'';$scope=$r['scope']??'TOUR';if(!is_string($name)||trim($name)===''||strlen($name)>255||!is_string($scope)||strlen($scope)>120||$scope==='')throw new InvalidArgumentException('Service name and scope required');
            $date=$r['service_date']??$v['start_date'];ScheduleImport::date($date);
            if($date<$v['start_date']||$date>$v['end_date'])throw new InvalidArgumentException('Service date outside travel dates');
            $qty=self::integer($r['quantity']??1,90);if($qty<1)throw new InvalidArgumentException('Positive service days/nights/meals required');
            $out[]=['key'=>$key,'category'=>$category,'service_name'=>trim($name),'service_date'=>$date,'quantity'=>$qty,'mode'=>$mode,'scope'=>$scope,'meal_type'=>substr((string)($r['meal_type']??''),0,40),'dietary'=>substr((string)($r['dietary']??''),0,255)];
        }
        return $out;
    }
    public static function defaults(string $mode,array $v): array {
        $categories=$mode==='SIC'?['TRANSPORT','HOTEL','CRUISE','TOUR']:['TRANSPORT','GUIDE','HOTEL','CRUISE','VISA','MEAL','ATTRACTION'];
        return array_map(fn($c)=>['key'=>strtolower($c),'category'=>$c,'service_name'=>['TRANSPORT'=>'Full-tour transfer package','GUIDE'=>'Guide','HOTEL'=>'Hotel','CRUISE'=>'Halong Cruise','VISA'=>'Visa','MEAL'=>'Meals','ATTRACTION'=>'Destination ticket','TOUR'=>'SIC Tour'][$c],'service_date'=>$v['start_date'],'quantity'=>1,'mode'=>$mode==='HYBRID'?'BOTH':$mode,'scope'=>'TOUR'], $categories);
    }
    public static function template(string $mode,array $requirements): array {
        if(!in_array($mode,['PRIVATE','SIC','HYBRID'],true))throw new InvalidArgumentException('Invalid costing mode');
        $order=$mode==='SIC'?['TRANSPORT','HOTEL','CRUISE','TOUR']:['TRANSPORT','GUIDE','HOTEL','CRUISE','VISA','MEAL','ATTRACTION','TOUR','OTHER'];
        $r=array_values(array_filter($requirements,fn($r)=>$mode==='HYBRID'||$r['mode']==='BOTH'||$r['mode']===$mode));
        usort($r,fn($a,$b)=>(array_search($a['category'],$order)===false?99:array_search($a['category'],$order))<=>(array_search($b['category'],$order)===false?99:array_search($b['category'],$order)));
        return array_map(fn($r)=>['requirement_key'=>$r['key'],'category'=>$r['category'],'service_name'=>$r['service_name'],'service_date'=>$r['service_date'],'qty'=>in_array($r['category'],['GUIDE','HOTEL','MEAL'],true)?$r['quantity']:1,'quantity_source'=>match($r['category']){'TRANSPORT','GUIDE','OTHER'=>'CUSTOM_QTY','HOTEL'=>'HOTEL_PAX','VISA'=>'VISA_PAX','MEAL'=>'MEAL_PAX','ATTRACTION'=>'TICKET_PAX',default=>'TOTAL_GUESTS'},'custom_qty'=>1,'scope'=>$r['scope'],'rate_status'=>'RATE NEEDED'],$r);
    }
    public static function calculate(array $input,array $g,array $v,array $requirements,string $mode,string $hotel,string $cruise,float $fx,array $pricing): array {
        if(!in_array($hotel,['3*','4*','5*'],true)||!in_array($cruise,['3*','4*','5*'],true)||!in_array($mode,['PRIVATE','SIC','HYBRID'],true)||$fx<=0||!is_finite($fx))throw new InvalidArgumentException('Valid mode, stars and FX required');
        if(!array_is_list($input)||count($input)>500)throw new InvalidArgumentException('Maximum 500 cost lines');
        $req=[];foreach($requirements as $r)$req[$r['key']]=$r;
        $lines=[];$errors=[];$warnings=[];$coverage=[];$duplicates=[];$included=[];$cost=0;
        foreach($input as $i=>$l){
            if(!is_array($l))throw new InvalidArgumentException('Invalid cost line');
            $category=$l['category']??'';if(!in_array($category,self::CATEGORIES,true))throw new InvalidArgumentException('Invalid cost category');
            $key=$l['requirement_key']??'';$r=$req[$key]??null;if($key!==''&&!$r)throw new InvalidArgumentException('Unknown requirement key');
            if($r&&$r['category']!==$category)throw new InvalidArgumentException('Cost category differs from service requirement');
            $date=$l['service_date']??$r['service_date']??$v['start_date'];ScheduleImport::date($date);if($date<$v['start_date']||$date>$v['end_date'])$errors[]='SERVICE_DATE_OUTSIDE_TOUR: line '.($i+1);
            $source=$l['quantity_source']??match($category){'TRANSPORT','GUIDE','OTHER'=>'CUSTOM_QTY','HOTEL'=>'HOTEL_PAX','VISA'=>'VISA_PAX','MEAL'=>'MEAL_PAX','ATTRACTION'=>'TICKET_PAX',default=>'TOTAL_GUESTS'};
            if(!in_array($source,self::SOURCES,true))throw new InvalidArgumentException('Invalid quantity source');
            if(in_array($category,['TRANSPORT','GUIDE'],true)&&$source!=='CUSTOM_QTY')throw new InvalidArgumentException('Transfer/guide quantity is vehicle/guide count, never guest count');
            $q1=$source==='CUSTOM_QTY'?self::integer($l['custom_qty']??1):$g[strtolower($source)];
            $q2=in_array($category,['HOTEL','GUIDE','MEAL'],true)?self::integer($l['qty']??$r['quantity']??1,90):1;
            $manualBasis=$l['cost_basis']??'STANDARD';if(!in_array($manualBasis,['STANDARD','LUMP_SUM','CUSTOM'],true))throw new InvalidArgumentException('Invalid cost basis');
            $reason=trim((string)($l['manual_reason']??$l['reason']??''));
            $status=$l['_rate_status']??'RATE NEEDED';$noCost=!empty($l['no_cost']);
            if($noCost){if($reason==='')throw new InvalidArgumentException('Included/no-cost line needs reason');$status='MANUAL COST';}
            if($manualBasis!=='STANDARD'){if($reason==='')throw new InvalidArgumentException('Custom or lump-sum basis needs explicit review reason');$q1=self::integer($l['custom_qty']??1);$q2=$manualBasis==='LUMP_SUM'?1:self::integer($l['qty']??1,90);}
            if($q2<1)$errors[]='INVALID_QUANTITY: line '.($i+1);
            if($r&&in_array($category,['HOTEL','GUIDE','MEAL'],true)&&$manualBasis==='STANDARD'&&$q2!==$r['quantity'])$errors[]=($category==='HOTEL'?'HOTEL_NIGHTS_MISMATCH':($category==='GUIDE'?'GUIDE_DAYS_MISMATCH':'MEAL_COUNT_MISMATCH')).': '.$key;
            if(($source==='CUSTOM_QTY'&&!in_array($category,['TRANSPORT','GUIDE'],true))||$manualBasis!=='STANDARD'){
                if(($l['quantity_context']??null)!==$g){$status='NEEDS REVIEW';$warnings[]='MANUAL_QUANTITY_REVIEW: line '.($i+1);}
            }
            $scope=$l['scope']??$r['scope']??'TOUR';
            if($category==='TRANSPORT'&&!$noCost){
                $capacity=self::integer($l['_capacity']??$l['capacity']??0);
                if($capacity*$q1<$g['total_guests']||($l['route_scope_hash']??'')!==self::scopeHash($v))$status='REPRICING REQUIRED';
            }
            if(in_array($category,['HOTEL','CRUISE'],true)&&!$noCost&&($l['_star_level']??$l['star_level']??'')!==($category==='HOTEL'?$hotel:$cruise))$status='NEEDS REVIEW';
            $name=$l['service_name']??$r['service_name']??'';if(!is_string($name)||trim($name)===''||strlen($name)>255)throw new InvalidArgumentException('Cost service name required');
            $identity=$key?:$category.'|'.$date.'|'.$scope.'|'.strtolower(trim($name));if(isset($duplicates[$identity]))$errors[]='DUPLICATE_COST: '.$identity;$duplicates[$identity]=true;
            $unit=$noCost?0:self::money($l['unit_price']??0);$total=round($q1*$q2*$unit,2);
            if(!in_array($status,['APPROVED RATE','CONTRACT RATE','MANUAL COST'],true))$errors[]=$status.': line '.($i+1);
            $inc=$l['included_keys']??[];if(!array_is_list($inc))throw new InvalidArgumentException('Invalid package inclusion list');
            if($inc&&!in_array($category,['TOUR','CRUISE'],true))throw new InvalidArgumentException('Only SIC/cruise packages may declare included services');
            foreach($inc as $k){if(!isset($req[$k])||$k===$key||($req[$k]['scope']!==$scope))throw new InvalidArgumentException('Package inclusion must reference another service in the same scope');if(isset($included[$k]))$errors[]='DUPLICATE_PACKAGE_INCLUSION: '.$k;$included[$k]=$i;}
            $coverage[$key]=true;
            $basis=$manualBasis!=='STANDARD'?$manualBasis:match($category){'TRANSPORT'=>'FULL_TOUR_PACKAGE','GUIDE'=>'PER_GUIDE_DAY','HOTEL'=>'PER_PAX_NIGHT','MEAL'=>'PER_PAX_MEAL',default=>'PER_PAX'};
            $origin=!empty($l['edited'])?'EDITED':(!empty($l['rate_version_id'])?'AUTO':'MANUAL');
            $lines[]=array_replace($l,['category'=>$category,'requirement_key'=>$key,'service_name'=>trim($name),'service_date'=>$date,'quantity_source'=>$source,'cost_basis'=>$manualBasis,'charge_basis'=>$basis,'pax'=>$q1,'qty'=>$q2,'unit_price'=>$unit,'total'=>$total,'currency'=>'VND','source_type'=>!empty($l['rate_version_id'])?'APPROVED_RATE':'MANUAL','origin'=>$origin,'rate_status'=>$status,'manual_reason'=>$reason,'scope'=>$scope,'included_keys'=>$inc,'included'=>false,'trace'=>"$q1 × $q2 × ".number_format($unit,2,'.',',')." VND = ".number_format($total,2,'.',',').' VND']);
        }
        // Resolve package coverage using exact service keys/scopes, never blanket category suppression.
        foreach($lines as $i=>&$l){$key=$l['requirement_key'];if(isset($included[$key])&&$included[$key]!==$i){$l['included']=true;$l['total']=0;$l['trace']='Included in '.$lines[$included[$key]]['service_name'].' = 0 VND';$l['rate_status']='INCLUDED';$errors=array_values(array_filter($errors,fn($e)=>!in_array($e,array_map(fn($status)=>$status.': line '.($i+1),['RATE NEEDED','EXPIRED RATE','NEEDS REVIEW','REPRICING REQUIRED']),true)));}if(!$l['included'])$cost+=$l['total'];}unset($l);
        foreach($requirements as $r){if($mode!=='HYBRID'&&$r['mode']!=='BOTH'&&$r['mode']!==$mode)continue;if(!isset($coverage[$r['key']])&&!isset($included[$r['key']]))$errors[]='MISSING_SERVICE_COST: '.$r['key'];}
        if(!$requirements)$errors[]='SERVICE_REQUIREMENTS_NEEDED';if(!$lines)$errors[]='COST_LINES_NEEDED';
        $cost=round($cost,2);$p=QuoteOptions::pricing($cost/$fx,$g['paying_pax'],$pricing['pricing_mode']??'MARKUP',(float)($pricing['pricing_value']??15),(float)($pricing['rounding_step']??0));
        if($p['total_selling']<=0)$errors[]='SELLING_PRICE_NEEDED';
        return $g+['engine'=>'VS2.1','costing_mode'=>$mode,'hotel_level'=>$hotel,'cruise_level'=>$cruise,'fx_rate'=>$fx,'selling_currency'=>'USD','total_supplier_cost_vnd'=>$cost,'cost_per_paying_pax_vnd'=>round($cost/$g['paying_pax'],2),'lines'=>$lines,'pricing'=>$p,'validation'=>['ready'=>!$errors,'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings))],'context_hash'=>self::scopeHash($v),'manual_review_required'=>count(array_filter($lines,fn($l)=>$l['source_type']==='MANUAL'&&!$l['included']))>0];
    }
}
