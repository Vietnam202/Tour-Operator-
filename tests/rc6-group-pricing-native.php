<?php
declare(strict_types=1);
// Disposable DB fixture only: VS23 native test itself provisions vta_test_* on localhost.
require __DIR__.'/vs23-native.php';
$m=PriceMatrix::row($db,v2v());
$snapshot=QuoteVs2Repository::decode($m['inputs_json']);
$graph=$snapshot['graph'];$config=$snapshot['config'];$variant=$graph['variants'][0];
$transfer=null;foreach($variant['lines'] as $line)if($line['formula_code']==='TRANSFER_PACKAGE'&&$line['line_kind']==='SERVICE'){$transfer=$line;break;}
check((bool)$transfer,'RC6 source draft contains a reviewed transfer line');
$scope=['itinerary_scope'=>QuoteVs2Domain::itineraryScope($graph['version'])];
function groupRate(int $capacity,string $amount,array $scope):int{
  global $db;
  $id=v2rate('TRANSPORT',$amount,'TRANSFER_PACKAGE',null,$scope);
  v2q('UPDATE rate_version_vs2_terms SET capacity=? WHERE rate_version_id=?',[$capacity,$id]);
  return $id;
}
$seven=groupRate(7,'700000',$scope);
$sixteen=groupRate(16,'1600000',$scope);
$twentynine=groupRate(29,'2900000',$scope);
$vehicles=array_map(fn($r)=>$r+['reason'=>'Synthetic supplier contract for vehicle capacity and luggage reviewed'],GroupVehiclePricing::DEFAULT_VEHICLES);
$bandRows=PaxScenario::bands(PaxScenario::DEFAULT_BANDS,'SAFE_PRICE');
$rates=[(string)$transfer['id']=>['2-2'=>$seven,'3-4'=>$seven,'5-9'=>$sixteen,'10-14'=>$sixteen,'15-20'=>$twentynine]];
$checked=GroupVehiclePricing::normalize($vehicles,$rates,$bandRows,$graph);
$config['vehicle_bands']=$checked['vehicles'];$config['transport_rate_versions']=$checked['rates'];
$before=v2q('SELECT * FROM quote_variant_cost_lines WHERE variant_id=?',[$variant['id']])->fetchAll();
$two=PaxScenario::calculate($db,$graph,$variant,2,$config,'25500');
$twenty=PaxScenario::calculate($db,$graph,$variant,20,$config,'25500');
$tline2=array_column($two['cost_lines'],null,'id')[$transfer['id']];
$tline20=array_column($twenty['cost_lines'],null,'id')[$transfer['id']];
check((int)$tline2['resolved_quantity']===1&&(int)$tline20['resolved_quantity']===1,'RC6 supplier capacity selects one reviewed vehicle for 2 and 20 paying pax plus FOC');
check($tline2['unit_amount_original']==='700000.00'&&$tline20['unit_amount_original']==='2900000.00','RC6 vehicle upgrades change supplier unit cost, never reuse sedan rate for 29-seater');
check((int)$two['guests']['foc']===2&&(int)$twenty['guests']['total_guests']===22,'RC6 preserves FOC and uses total traveling guests for fleet sizing');
check(v2q('SELECT * FROM quote_variant_cost_lines WHERE variant_id=?',[$variant['id']])->fetchAll()===$before,'RC6 2–20 pax simulation never persists supplier cost changes');
$missing=$config;unset($missing['transport_rate_versions'][(string)$transfer['id']]['15-20']);
rejects(fn()=>PaxScenario::calculate($db,$graph,$variant,20,$missing,'25500'),DomainException::class,'RC6 absent 29-seater supplier rate blocks invented selling price');
echo "RC6 group vehicle native MariaDB checks complete.\n";
