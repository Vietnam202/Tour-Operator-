<?php
declare(strict_types=1);

final class Vs2Templates {
    public const PRIVATE_ORDER=['TRANSPORT','GUIDE','HOTEL','CRUISE','VISA','MEAL','ATTRACTION'];
    public const SIC_ORDER=['TRANSPORT','HOTEL','CRUISE','TOUR'];
    public static function requirements(PDO $db,array $u,array $v,array $modes): array {
        $names=['TRANSPORT'=>'Full tour transfer','GUIDE'=>'Guide','HOTEL'=>'Hotel','CRUISE'=>'Halong Cruise','VISA'=>'Visa','MEAL'=>'Meals','ATTRACTION'=>'Destination ticket','TOUR'=>'SIC Tour'];
        $sources=['TRANSPORT'=>'CUSTOM_QTY','GUIDE'=>'CUSTOM_QTY','HOTEL'=>'HOTEL_PAX','CRUISE'=>'CRUISE_PAX','VISA'=>'VISA_PAX','MEAL'=>'MEAL_PAX','ATTRACTION'=>'TICKET_PAX','TOUR'=>'TOTAL_GUESTS'];$created=[];
        $categories=[];foreach($modes as $mode){if(!in_array($mode,['PRIVATE','SIC','HYBRID'],true))throw new InvalidArgumentException('Invalid costing mode');$categories=array_values(array_unique([...$categories,...($mode==='SIC'?self::SIC_ORDER:($mode==='HYBRID'?[...self::PRIVATE_ORDER,'TOUR']:self::PRIVATE_ORDER))]));}
        foreach($categories as $i=>$category){$key='default-'.strtolower($category);if(QuoteVs2Repository::q($db,'SELECT id FROM quote_service_requirements WHERE quote_version_id=? AND requirement_key=?',[$v['id'],$key])->fetchColumn())continue;
            $created[]=QuoteSmartCosting::requirement($db,$u,(int)$v['id'],['requirement_key'=>$key,'sort_order'=>$i*10,'category'=>$category,'service_name'=>$names[$category],'service_date'=>$v['start_date'],'default_quantity_source'=>$sources[$category],'service_mode'=>in_array($category,['TRANSPORT','HOTEL','CRUISE'],true)?'BOTH':($category==='TOUR'?'SIC':'PRIVATE'),'scope'=>[],'metadata'=>['generated_template'=>true]]);
        }return $created;
    }
}
