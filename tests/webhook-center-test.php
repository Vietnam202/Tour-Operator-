<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/WebhookCenter.php';
function check(bool $condition,string $name):void {
 if(!$condition)throw new RuntimeException('FAIL '.$name);
 echo 'PASS '.$name.PHP_EOL;
}
$raw=json_encode(['event_type'=>'lead.created','event_id'=>'site_event_00000001','lead'=>['contact_name'=>'Test']],JSON_THROW_ON_ERROR);
$secret=str_repeat('S',48);$sig='sha256='.hash_hmac('sha256',$raw,$secret);
check(WebhookCenter::validSignature($raw,$secret,$sig),'valid HMAC');
check(!WebhookCenter::validSignature($raw.' ',$secret,$sig),'tampered body');
check(!WebhookCenter::validSignature($raw,'short',$sig),'weak secret');
check(!WebhookCenter::validSignature($raw,$secret,'sha256=invalid'),'bad signature');
check(WebhookCenter::parseEvent($raw)['event_id']==='site_event_00000001','valid event');
check(WebhookCenter::eventKey('site-main','site_event_00000001')!==WebhookCenter::eventKey('site-landing','site_event_00000001'),'source scoped event keys');
foreach(['{}','[]','{"event_type":"lead.deleted","event_id":"site_event_00000001","lead":{}}','{"event_type":"lead.created","event_id":"short","lead":{}}'] as $bad){
 $rejected=false;try{WebhookCenter::parseEvent($bad);}catch(InvalidArgumentException $e){$rejected=true;}
 check($rejected,'reject invalid event');
}
