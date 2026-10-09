<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/QuoteVs2Domain.php';
require_once __DIR__.'/../api/lib/GroupVehiclePricing.php';
function gpOk(bool $condition,string $label):void{if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function gpReject(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException|DomainException $e){echo 'PASS '.$label.' '.$e->getMessage().PHP_EOL;return;}throw new RuntimeException('FAIL '.$label);}
$bands=[['min'=>2,'max'=>2,'key'=>'2-2'],['min'=>3,'max'=>4,'key'=>'3-4'],['min'=>5,'max'=>9,'key'=>'5-9'],['min'=>10,'max'=>14,'key'=>'10-14'],['min'=>15,'max'=>20,'key'=>'15-20']];
$vehicles=GroupVehiclePricing::DEFAULT_VEHICLES;
$vehicles=array_map(fn($v)=>$v+['reason'=>'Reviewed supplier vehicle and luggage rules'],$vehicles);
$graph=['variants'=>[['lines'=>[['id'=>17,'line_kind'=>'SERVICE','formula_code'=>'TRANSFER_PACKAGE'],['id'=>18,'line_kind'=>'SERVICE','formula_code'=>'HOTEL_PAX_NIGHT']]]]];
$rates=['17'=>['2-2'=>31,'3-4'=>31,'5-9'=>32,'10-14'=>32,'15-20'=>33]];
$r=GroupVehiclePricing::normalize($vehicles,$rates,$bands,$graph);
gpOk(count($r['vehicles'])===5&&count($r['rates']['17'])===5,'Five exact group vehicle choices');
$config=['vehicle_bands'=>$r['vehicles'],'transport_rate_versions'=>$r['rates']];
gpOk(GroupVehiclePricing::rate($config,17,2)===31&&GroupVehiclePricing::rate($config,17,20)===33,'Pax-dependent approved rate selection');
gpReject(fn()=>GroupVehiclePricing::normalize($vehicles,['18'=>['2-2'=>31]],$bands,$graph),'Hotel rate cannot be passed as transfer price');
gpReject(fn()=>GroupVehiclePricing::normalize(array_slice($vehicles,1),$rates,$bands,$graph),'All vehicle bands required');
gpReject(fn()=>GroupVehiclePricing::normalize($vehicles,['17'=>['2-3'=>31]],$bands,$graph),'Unknown band rate rejected');
gpReject(fn()=>GroupVehiclePricing::rate(['vehicle_bands'=>array_replace($vehicles,[0=>array_replace($vehicles[0],['reason'=>''])]),'transport_rate_versions'=>$rates],17,2),'Unreviewed source refused');
gpReject(fn()=>GroupVehiclePricing::rate(['vehicle_bands'=>$vehicles,'transport_rate_versions'=>[]],17,2),'Missing source refuses fabricated price');
gpOk(GroupVehiclePricing::normalize([],[],$bands,$graph)===['vehicles'=>[],'rates'=>[]],'Legacy matrix remains backward compatible');
echo "RC6 group pricing unit tests complete.\n";
