<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/WebsiteInbox.php';
require_once __DIR__.'/../api/lib/WebhookCenter.php';
function verify(bool $condition,string $label):void {
 if(!$condition)throw new RuntimeException('FAIL '.$label);
 echo 'PASS '.$label.PHP_EOL;
}
$sample=['event_type'=>'conversation.message','event_id'=>'evt_website_000001','conversation'=>[
 'external_id'=>'visitor_000001','contact_name'=>'Example customer','email'=>'person@example.test'
], 'message'=>['message_id'=>'msg_website_000001','text'=>'Hello. Please send a Vietnam 7D6N program.'],
 'attribution'=>['utm_source'=>'website','utm_campaign'=>'northern-vietnam']];
$raw=json_encode($sample,JSON_THROW_ON_ERROR);
$r=WebsiteInbox::parseMessage($raw);
verify($r['external_id']==='visitor_000001'&&$r['message_id']==='msg_website_000001','parse valid website conversation');
verify($r['attribution']['utm_campaign']==='northern-vietnam','capture marketing attribution');
verify($r['email']==='person@example.test','capture typed email');
$secret=str_repeat('z',48);$sig='sha256='.hash_hmac('sha256',$raw,$secret);
verify(WebhookCenter::validSignature($raw,$secret,$sig),'use signed event transport');
verify(!WebhookCenter::validSignature($raw.' ',$secret,$sig),'tampering rejected');
$bad=[
 [],
 ['event_type'=>'conversation.deleted'],
 array_replace_recursive($sample,['event_id'=>'x']),
 array_replace_recursive($sample,['conversation'=>['external_id'=>'visitor!bad']]),
 array_replace_recursive($sample,['conversation'=>['email'=>'not-an-email']]),
 array_replace_recursive($sample,['message'=>['message_id'=>'short']]),
 array_replace_recursive($sample,['message'=>['text'=>'']]),
 array_replace_recursive($sample,['message'=>['text'=>str_repeat('a',8001)]]),
 array_replace_recursive($sample,['attribution'=>['utm_source'=>str_repeat('b',1001)]]),
];
foreach($bad as $index=>$item) {
 $rejected=false;try{WebsiteInbox::parseMessage(json_encode($item,JSON_THROW_ON_ERROR));}
 catch(InvalidArgumentException $e){$rejected=true;}
 verify($rejected,'reject unsafe inbound case '.($index+1));
}
