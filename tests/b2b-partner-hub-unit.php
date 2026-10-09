<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/ScheduleImport.php';
require_once __DIR__.'/../api/lib/PartnerHub.php';

function assertB2b(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: ".$message."\n";
}
$sample=[
 'title'=>'Danang – Hoi An – Phu Quoc Island 5D4N','destination'=>'Danang / Phu Quoc',
 'language'=>'en','tour_code'=>'VTA507','tour_type'=>'SIC','overview'=>'Published narrative',
 'days'=>[['day'=>1,'title'=>'Arrival Danang','description'=>'Travel to Hoi An','meals'=>'D','overnight'=>'Danang']],
 'included_text'=>'Hotel','excluded_text'=>'Flights','terms_text'=>'On request',
 'highlights'=>['Golden Bridge'],'hotels'=>[['destination'=>'Danang','three'=>'Hotel A','four'=>'Hotel B','five'=>'Hotel C']],
 'policies'=>['children'=>'Subject to approval','payment'=>'','cancellation'=>'','notes'=>'']
];
$input=$sample+['supplier_costs'=>[100000],'markup_secret'=>'confidential'];
$out=PartnerHub::normalizeCopy($input);
assertB2b($out['days'][0]['title']==='Arrival Danang','V5 day survives normalized agency editing');
assertB2b(!isset($out['supplier_costs'])&&!isset($out['markup_secret']),'Unexpected supplier or secret values never persisted to agency content');
$source=$sample+['source_text'=>'PRIVATE SUPPLIER COST', 'proposal'=>[
 'tour_code'=>'VTA507','tour_type'=>'SIC','overview'=>'Published narrative',
 'highlights'=>['Golden Bridge'],'hotels'=>$sample['hotels'],'policies'=>$sample['policies'],
 'group_prices'=>[['hotel'=>'3','price'=>'345','single'=>'']], 'cost_internal'=>'DO NOT PUBLISH'
]];
$m=new ReflectionMethod(PartnerHub::class,'publicContent');
$safe=$m->invoke(null,$source);
assertB2b(!isset($safe['source_text'])&&!isset($safe['proposal'])&&!isset($safe['group_prices']),'Imported historical NET and raw source excluded from publication');
$quoteMethod=new ReflectionMethod(PartnerHub::class,'quote');
$work=['markup_type'=>'PERCENT','markup_value'=>'15'];
$pub=['status'=>'PUBLISHED','version_no'=>1,'rate_json'=>json_encode(['valid_until'=>'2099-12-31','cells'=>[
 ['mode'=>'SIC','hotel'=>'4','cruise'=>'','pax_min'=>2,'pax_max'=>22,'net_per_pax'=>100.00,'currency'=>'USD']
]])];
$params=['pax'=>8,'mode'=>'SIC','hotel'=>'4','cruise'=>''];
$quote=$quoteMethod->invoke(null,$work,$pub,$params);
assertB2b($quote['status']==='QUOTABLE'&&$quote['selling_per_pax']===115.0&&$quote['selling_total']===920.0,'Server-side NET plus markup calculation uses approved snapshot');
$missing=$quoteMethod->invoke(null,$work,$pub,['pax'=>8,'mode'=>'PRIVATE','hotel'=>'4','cruise'=>'']);
assertB2b($missing['status']==='REQUEST_NET','Unmatched configuration cannot invent prices');
$expired=$pub;$expired['rate_json']=json_encode(['valid_until'=>'2001-01-01','cells'=>json_decode($pub['rate_json'],true)['cells']]);
assertB2b($quoteMethod->invoke(null,$work,$expired,$params)['status']==='REQUEST_NET','Expired NET cannot be quoted');
$outputMethod=new ReflectionMethod(PartnerHub::class,'output');
$agency=['brand_name'=>'Partner Agency','email'=>'agent@example.test','whatsapp'=>'','brand_color'=>'#1768B0','logo_path'=>null];
$doc=$outputMethod->invoke(null,['id'=>2,'content_json'=>json_encode($out),'markup_type'=>'PERCENT','markup_value'=>'15'],$agency,$pub,$params,'client');
$serialized=json_encode($doc);
assertB2b(str_contains($serialized,'115.00')&&!str_contains($serialized,'net_per_pax')&&!str_contains($serialized,'100.00'),'Client Word/PDF source blocks contain only selling price, no NET');
$itinerary=$outputMethod->invoke(null,['id'=>2,'content_json'=>json_encode($out),'markup_type'=>'PERCENT','markup_value'=>'15'],$agency,['status'=>'PUBLISHED','version_no'=>1,'rate_json'=>'{"cells":[]}'],[],'itinerary');
assertB2b(!str_contains(json_encode($itinerary),'Client Price Offer'),'Unpriced itinerary exports allowed without a rate');
echo "B2B partner hub domain assertions passed.\n";
