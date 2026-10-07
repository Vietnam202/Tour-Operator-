<?php
declare(strict_types=1);
// Uses the existing isolated localhost fixture; never reads a staging configuration.
require __DIR__.'/quote-options.php';
require_once __DIR__.'/../api/lib/QuoteReuse.php';
require_once __DIR__.'/../api/lib/CoreOS.php';
$original=$db->query('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll();
$sourceBooking=$booking;$sourceVersion=$qvid;
$v=QuoteOptions::version($db,1,$sourceVersion);$fields=['start_date','end_date','adults','children','infants','foc','total_guests','paying_pax'];
$db->prepare('UPDATE trips SET '.implode(',',array_map(fn($k)=>$k.'=?',$fields)).' WHERE id=?')->execute([...array_map(fn($k)=>$v[$k],$fields),$v['trip_id']]);
foreach(['SALES','OPERATIONS','MARKETING'] as $role){
    $db->prepare('INSERT INTO roles(company_id,code,name) VALUES(1,?,?)')->execute([$role,$role]);
    $rid=(int)$db->lastInsertId();$db->prepare('INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,?,?,?,?)')->execute([$rid,$role,strtolower($role).'@example.invalid','unused']);
}
$db->exec(file_get_contents(__DIR__.'/../api/migrations/035_quote_confirmation_roles.sql'));
$actors=[];foreach($db->query('SELECT u.id,u.company_id,r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.company_id=1')->fetchAll() as $row)$actors[$row['code']]=['id'=>(int)$row['id'],'company_id'=>1];
foreach(['ADMIN','SALES','OPERATIONS'] as $role)check(Auth::can($db,$actors[$role]['id'],'quote.confirm'),'quote.confirm enabled for '.$role);
check(!Auth::can($db,$actors['OPERATIONS']['id'],'sales.view')&&!Auth::can($db,$actors['OPERATIONS']['id'],'quote.view_cost')&&!Auth::can($db,$actors['OPERATIONS']['id'],'quote.edit'),'confirmation grants no Sales, cost or editing access');
check(!Auth::can($db,$actors['MARKETING']['id'],'quote.confirm'),'Marketing cannot confirm quotations');
$made=[];
function issuedFlowQuote(PDO $db,array $user,int $sourceVersion): array {
    $clone=QuoteReuse::clone($db,$user,$sourceVersion,(int)QuoteOptions::version($db,1,$sourceVersion)['inquiry_id'],'FULL_DRAFT');$version=(int)$clone['version_id'];
    foreach([3,4,5] as $star){$lines=[];foreach(['TRANSPORT','GUIDE','HOTEL'] as $cat)$lines[]=['category'=>$cat,'service_name'=>'Synthetic '.$cat,'service_date'=>'2027-01-02','supplier_id'=>1,'pax'=>1,'qty'=>1,'unit_price'=>$cat==='HOTEL'?$star*100000:100000,'currency'=>'VND','reason'=>'Test-only documented supplier cost'];
        $selected=QuoteOptions::save($db,$user,$version,['label'=>$star.' star','hotel_level'=>$star.'*','pricing_mode'=>'MARKUP','pricing_value'=>20,'review_copied'=>true,'review_reason'=>'Synthetic copy reviewed','lines'=>$lines]);if($star===4)$option=(int)$selected['id'];}
    QuoteOptions::approve($db,$user,$version,'Reviewed test commercial terms');QuoteOptions::send($db,$user,$version);
    return $clone+['selected_option'=>$option];
}
foreach(['ADMIN','SALES','OPERATIONS'] as $role){
    $clone=issuedFlowQuote($db,$user,$sourceVersion);$version=(int)$clone['version_id'];$option=$clone['selected_option'];
    $dto=QuoteOptions::confirmation($db,$actors[$role],$version);
    check(count($dto['choices'])===3&&$dto['version_status']==='SENT','issued three-option confirmation loads for '.$role);
    $text=json_encode($dto,JSON_THROW_ON_ERROR);check(!preg_match('/unit_price|total_cost|rate_snapshot|supplier_id|margin_pct|profit_amount/',$text),'confirmation DTO excludes supplier cost for '.$role);
    QuoteOptions::confirm($db,$actors[$role],$version,$option);$b=QuoteOptions::booking($db,$user,$version);$summary=SalesHandover::bookingSummary($db,1,(int)$b['id']);
    check($summary['hotel_level']==='4*'&&count($summary['schedule'])===1&&$summary['quote_ref']===$clone['quote_ref'],'booking reuses selected quote, itinerary and commercial data for '.$role);
    $snapshot=$db->query('SELECT * FROM booking_quote_snapshots WHERE booking_id='.(int)$b['id'])->fetch();
    SalesHandover::create($db,$user,(int)$b['id']);$ctx=SalesHandover::context($db,$user,(int)$b['id']);check(count($ctx['services'])===3,'handover copies Hotel, Transport and Guide without re-entry');
    $checklist=array_fill_keys(SalesHandover::CHECKLIST,true);$checklist['flights']='NOT_PROVIDED';
    $ctx=SalesHandover::transition($db,$user,(int)$b['id'],'prepare',['expected_revision'=>(int)$ctx['handover']['revision'],'checklist'=>$checklist]);
    $ctx=SalesHandover::transition($db,$user,(int)$b['id'],'submit',['expected_revision'=>(int)$ctx['handover']['revision']]);
    $ctx=SalesHandover::transition($db,$user,(int)$b['id'],'accept',['expected_revision'=>(int)$ctx['handover']['revision']]);
    check($ctx['handover']['intake_status']==='READY_TO_BOOK','Operations receives the confirmed booking');
    check($db->query('SELECT * FROM booking_quote_snapshots WHERE booking_id='.(int)$b['id'])->fetch()===$snapshot,'handover preserves accepted snapshot bytes');
    $made[$role]=['quote_id'=>(int)$clone['id'],'version_id'=>$version,'booking_id'=>(int)$b['id']];
}
$op=$actors['OPERATIONS'];$deny=$db->prepare("INSERT INTO user_permissions(user_id,permission_id,effect) SELECT ?,id,'DENY' FROM permissions WHERE code='quote.confirm'");$deny->execute([$op['id']]);check(!Auth::can($db,$op['id'],'quote.confirm'),'explicit per-user DENY overrides new role grant');
$db->prepare("DELETE FROM user_permissions WHERE user_id=? AND permission_id=(SELECT id FROM permissions WHERE code='quote.confirm')")->execute([$op['id']]);
$historical=$db->query('SELECT * FROM quote_sent_bundles WHERE quote_version_id<='.(int)$sourceVersion.' ORDER BY quote_version_id')->fetchAll();check($historical===$original,'historical Sent snapshots untouched by new role workflow');
// Deliberately preserve disposable test fixtures for local HTTP/browser acceptance.
$out=getenv('ROADMAP_TEST_OUTPUT')?:sys_get_temp_dir();if(!is_dir($out))mkdir($out,0770,true);
$browser=issuedFlowQuote($db,$user,$sourceVersion);
file_put_contents($out.'/flow-fixture.json',json_encode(['database'=>$database,'actors'=>$actors,'made'=>$made,'browser'=>$browser,'source_version'=>$sourceVersion],JSON_THROW_ON_ERROR));
echo "Quote confirmation roles and Quote → Booking → Handover targeted acceptance PASS.\n";
