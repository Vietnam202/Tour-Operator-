<?php
declare(strict_types=1);
// Reuse an explicitly named disposable localhost fixture, or create one with the existing quote acceptance suite.
$fixture=getenv('ROADMAP_FLOW_FIXTURE');
if(!$fixture){require __DIR__.'/quote-confirmation-native.php';$fixture=(getenv('ROADMAP_TEST_OUTPUT')?:sys_get_temp_dir()).'/flow-fixture.json';}
$flow=json_decode(file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR);$database=$flow['database'];
if(!preg_match('/^vta_test_[a-f0-9]{10}$/D',$database))throw new RuntimeException('Disposable fixture database required');
foreach(['Auth','Audit','Http','CoreOS','RateEngine','QuoteOptions','ServiceTravelDetails','TravelDocuments','QuoteVs2Projection'] as $lib)require_once __DIR__.'/../api/lib/'.$lib.'.php';
$connect=fn()=>new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$database.';charset=utf8mb4','root',getenv('VTA_TEST_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db=$connect();
function opCheck(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";}
function opReject(callable $fn,string $name):void{try{$fn();}catch(InvalidArgumentException|DomainException|OutOfBoundsException $e){echo "PASS $name\n";return;}throw new RuntimeException('FAIL '.$name);}
$user=$flow['actors']['ADMIN'];$booking=(int)$flow['made']['ADMIN']['booking_id'];
$q=fn(string $sql,array $args=[])=>BookingIntegrity::query($db,$sql,$args);
$snapshot=$q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll();$sent=$q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll();
$costs=$q('SELECT id,planned_cost,confirmed_cost,actual_cost,cost_currency FROM booking_services WHERE booking_id=? ORDER BY id',[$booking])->fetchAll();
$services=$q('SELECT * FROM booking_services WHERE booking_id=? ORDER BY id',[$booking])->fetchAll();
foreach($services as $s){$id=(int)$s['id'];$current=ServiceTravelDetails::operations($db,1,$id);$body=['expected_service_hash'=>$current['service_hash'],'supplier_id'=>1,'booking_status'=>'CONFIRMED','confirmation_no'=>'OP-TEST-'.$s['category'],'operation'=>['language'=>'English','instructions'=>'INTERNAL_DISPATCH_ONLY'],'details'=>['guest_instructions'=>'Meet in lobby']];
 if($s['category']==='HOTEL')$body['details']+=['check_in'=>'2027-01-02','check_out'=>'2027-01-03','room_type'=>'Deluxe twin'];
 if($s['category']==='TRANSPORT')$body+=['start_time'=>'08:30','end_time'=>'09:30','pickup_location'=>'Airport','dropoff_location'=>'Hotel','vehicle_type'=>'16 seats','driver_name'=>'Test Driver','driver_mobile'=>'000000'];
 if($s['category']==='GUIDE')$body+=['start_time'=>'09:00','guide_name'=>'Test Guide','guide_mobile'=>'000001'];
 $saved=ServiceTravelDetails::saveOperations($db,$user,$id,$body);$again=ServiceTravelDetails::operations($connect(),1,$id);
 opCheck($saved===$again&&$again['service']['booking_status']==='CONFIRMED','independent database reopen persists '.$s['category'].' details, assignment and status');
 opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,$body),'stale '.$s['category'].' save rejected');
 $fresh=['expected_service_hash'=>$again['service_hash']];
 opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,$fresh+['planned_cost'=>1]),'operational edit cannot change supplier cost');
 opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,$fresh+['supplier_id'=>999999]),'foreign or absent supplier rejected');
 opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,$fresh+['service_date'=>'2027-02-30']),'invalid or out-of-travel date rejected');
 ServiceTravelDetails::put($db,$user,$id,['guest_instructions'=>'Public voucher instructions']);
 opCheck(ServiceTravelDetails::operations($db,1,$id)['operation']['instructions']==='INTERNAL_DISPATCH_ONLY','existing voucher editor preserves operational notes');
 opReject(fn()=>ServiceTravelDetails::operations($db,999,$id),'foreign tenant cannot read service');
}
opCheck($q('SELECT id,planned_cost,confirmed_cost,actual_cost,cost_currency FROM booking_services WHERE booking_id=? ORDER BY id',[$booking])->fetchAll()===$costs,'all service financial values unchanged');
opCheck($q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll()===$snapshot&&$q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll()===$sent,'accepted and Sent snapshots remain byte-identical');
foreach(['HOTEL_VOUCHER','TRANSFER_VOUCHER','TRAVEL_PACK'] as $kind){$doc=TravelDocuments::snapshot($db,1,$booking,$kind,'HIDE_PRICE');$text=json_encode($doc);opCheck(!str_contains($text,'INTERNAL_DISPATCH_ONLY')&&!str_contains($text,'planned_cost')&&str_contains($text,'Public voucher instructions'),'existing '.$kind.' uses saved operational fields without internal notes or costs');}
$id=(int)$services[0]['id'];$fresh=ServiceTravelDetails::operations($db,1,$id);
$public=QuoteVs2Projection::filter($db,$flow['actors']['OPERATIONS'],$fresh+['planned_cost'=>123,'profit'=>456],'services/'.$id.'/operations');
opCheck(isset($public['service']['supplier_name'])&&!isset($public['planned_cost'])&&!isset($public['profit']),'Operations projection preserves dispatch supplier while stripping financial fields');
$q('INSERT INTO supplier_orders(company_id,order_ref,booking_id,supplier_id,created_by,updated_by) VALUES(1,?,?,1,?,?)',['OP-LOCK-'.bin2hex(random_bytes(5)),$booking,$user['id'],$user['id']]);$order=(int)$db->lastInsertId();$q('INSERT INTO supplier_order_services(order_id,service_id) VALUES(?,?)',[$order,$id]);
opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,['expected_service_hash'=>$fresh['service_hash'],'supplier_id'=>null]),'ordered service cannot silently switch supplier');
opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,['expected_service_hash'=>$fresh['service_hash'],'booking_status'=>'CANCELLED']),'ordered service cancellation uses existing order workflow');
$q("UPDATE supplier_orders SET status='CANCELLED' WHERE id=?",[$order]);
opReject(fn()=>ServiceTravelDetails::saveOperations($db,$flow['actors']['MARKETING'],$id,['expected_service_hash'=>$fresh['service_hash'],'booking_status'=>'CANCELLED']),'missing supplier confirmation permission blocks terminal status');
$q("UPDATE bookings SET finance_closed_at=NOW() WHERE id=?",[$booking]);opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,['expected_service_hash'=>$fresh['service_hash']]),'finance-closed booking stays read-only');$q('UPDATE bookings SET finance_closed_at=NULL WHERE id=?',[$booking]);
$q("UPDATE bookings SET operations_status='CANCELLED' WHERE id=?",[$booking]);opReject(fn()=>ServiceTravelDetails::saveOperations($db,$user,$id,['expected_service_hash'=>$fresh['service_hash']]),'cancelled booking stays read-only');$q("UPDATE bookings SET operations_status='NEW_BOOKING' WHERE id=?",[$booking]);
echo "Operations persistence and integrity acceptance PASS.\n";
