<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MetaReplies.php';
require_once __DIR__.'/../api/lib/MessengerSender.php';
require __DIR__.'/marketing-meta-inbox-p7-db-test.php';

function p8check(bool $yes,string $label):void{
 if(!$yes)throw new RuntimeException('FAIL: '.$label);
 echo "PASS: ".$label."\n";
}
echo "\nP8 Messenger standard window + outbound integration\n";
$company=['company_id'=>1,'id'=>1];
$cid=(int)$fbConversation;
$cfg=$config;
$cfg['integrations']['meta_inbox']['accounts']['vta_page']+= [
 'page_access_token'=>str_repeat('T',60),
 'permissions'=>['pages_messaging'],
 'messaging_enabled'=>true,
 'messaging_permission_approved'=>true,
 'message_task_confirmed'=>true,
 'graph_version'=>'v26.0'
];
$cfg['integrations']['meta_inbox']['accounts']['vta_instagram']+= [
 'page_access_token'=>str_repeat('I',60),
 'permissions'=>['instagram_manage_messages'],
 'messaging_enabled'=>true,'messaging_permission_approved'=>true,'message_task_confirmed'=>true
];
$before=MetaReplies::thread($db,$cfg,1,$cid);
p8check(!$before['can_send']&&$before['reason']==='OUTSIDE_24H_WINDOW','old webhook event cannot authorize Messenger reply');
$igId=$db->query("SELECT id FROM social_conversations WHERE company_id=2 AND source_code LIKE 'meta_ig_%'")->fetchColumn();
$igStatus=MetaReplies::thread($db,$cfg,2,(int)$igId);
p8check(!$igStatus['can_send']&&$igStatus['reason']==='MESSENGER_ONLY','Instagram Facebook Login remains draft-only in P8');

$event=[
 'object'=>'page','entry'=>[['id'=>'11223344556677','messaging'=>[[
    'sender'=>['id'=>'66554433221100'],'recipient'=>['id'=>'11223344556677'],
    'timestamp'=>time()*1000,
    'message'=>['mid'=>'m_latest_signed_000000001','text'=>'Could you share a Hanoi itinerary?']
 ]]]]
];
$inbound=MetaInbox::extract(json_encode($event,JSON_THROW_ON_ERROR),MetaInbox::accounts($cfg));
p8check(count($inbound)===1&&$inbound[0]['provider_timestamp_ms']!==null,'signed Meta timestamp extracted');
MetaInbox::enqueue($db,$inbound);
$processed=MetaInbox::processOne($db);
p8check($processed['status']==='DONE','new inbound event moved into the website-independent Inbox');
$ready=MetaReplies::thread($db,$cfg,1,$cid);
p8check($ready['can_send']===true&&$ready['reason']===null,'recent genuine customer message opens Messenger standard reply window');

$req=['conversation_id'=>$cid,'request_key'=>'messenger_out_key_00000001','body'=>'Thank you! Could you share your travel dates?'];
$r=MetaReplies::enqueue($db,$cfg,$company,$req);
p8check($r['status']==='QUEUED'&&!$r['replayed'],'staff can queue eligible Messenger reply without sending');
$retry=MetaReplies::enqueue($db,$cfg,$company,$req);
p8check($retry['replayed']&&$retry['id']===$r['id'],'retries do not create duplicate outbound');
$conflict=false;
try{MetaReplies::enqueue($db,$cfg,$company,array_replace($req,['body'=>'A completely different reply']));}
catch(DomainException $e){$conflict=true;}
p8check($conflict,'request key cannot be reused for different content');
$wrongTenant=false;
try{MetaReplies::thread($db,$cfg,2,$cid);}catch(OutOfBoundsException $e){$wrongTenant=true;}
p8check($wrongTenant,'other tenant cannot read thread');

$job=MetaReplies::claim($db,$cfg);
p8check($job['status']==='SENDING'&&$job['id']===$r['id'],'worker claims approved manual reply exactly once');
p8check($job['recipient_id']==='66554433221100'&&$job['page_id']==='11223344556677','sender identity maps to correct Page and PSID');
p8check(MetaReplies::claim($db,$cfg)===null,'simultaneous claim cannot resend SENDING item');
$sentData=null;
$mid=MessengerSender::request($job['page_id'],$job['recipient_id'],$job['body'],$job['account'],
 static function(string $url,array $payload,string $token)use(&$sentData):array {
    $sentData=['url'=>$url,'payload'=>$payload,'token_bytes'=>strlen($token)];
    return ['recipient_id'=>$payload['recipient']['id'],'message_id'=>'m_mock_sent_00000001'];
 });
p8check($mid==='m_mock_sent_00000001','mock Meta API confirms a real-shaped message ID');
p8check($sentData['url']==='https://graph.facebook.com/v26.0/11223344556677/messages'
    &&$sentData['payload']['messaging_type']==='RESPONSE'
    &&$sentData['payload']['recipient']['id']===$job['recipient_id'],'sends only Messenger RESPONSE via fixed Meta Graph endpoint');
MetaReplies::complete($db,$r['id'],$mid);
$log=MetaReplies::list($db,$company,$cid);
p8check($log[0]['status']==='SENT','confirmed Meta response marked SENT');
p8check(!array_key_exists('page_access_token',$log[0]),'staff-visible log contains no Meta token');

$expired=MetaReplies::enqueue($db,$cfg,$company,array_replace($req,[
  'request_key'=>'messenger_out_key_00000002','body'=>'Another pending reply'
]));
$db->prepare("UPDATE social_conversations SET last_meta_inbound_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR) WHERE company_id=1 AND id=?")->execute([$cid]);
$blocking=MetaReplies::claim($db,$cfg);
p8check($blocking['status']==='BLOCKED'&&$blocking['id']===$expired['id'],'window expiry at dispatch blocks queued response');
$provider=false;
try{MessengerSender::endpoint('https://foreign.example','v26.0');}catch(InvalidArgumentException $e){$provider=true;}
p8check($provider,'provider URL injection rejected');
$missing=false;
try{MetaReplies::enqueue($db,$cfg,$company,array_replace($req,['request_key'=>'messenger_out_key_00000003']));}
catch(DomainException $e){$missing=true;}
p8check($missing,'expired customer response window rejects new staff send');

$db->prepare("UPDATE social_conversations SET last_meta_inbound_at=UTC_TIMESTAMP() WHERE company_id=1 AND id=?")->execute([$cid]);
$uncertain=MetaReplies::enqueue($db,$cfg,$company,array_replace($req,[
 'request_key'=>'messenger_out_key_00000004','body'=>'Confirming itinerary options.'
]));
$unknown=MetaReplies::claim($db,$cfg);
p8check($unknown['id']===$uncertain['id'],'unknown-result test claims new reply');
MetaReplies::complete($db,$uncertain['id'],null);
p8check($db->query("SELECT status FROM marketing_meta_outbound WHERE id=".$uncertain['id'])->fetchColumn()==='UNCERTAIN',
    'ambiguous provider result enters UNCERTAIN');
p8check(MetaReplies::claim($db,$cfg)===null,'ambiguous result never automatically resent');
$unready=$cfg;
$unready['integrations']['meta_inbox']['accounts']['vta_page']['permissions']=[];
$cap=MetaReplies::thread($db,$unready,1,$cid);
p8check(!$cap['can_send']&&$cap['reason']==='ACCOUNT_NOT_AUTHORIZED','missing pages_messaging permission prevents send');

$db->prepare("UPDATE social_conversations SET status='CLOSED' WHERE company_id=1 AND id=?")->execute([$cid]);
p8check(MetaReplies::thread($db,$cfg,1,$cid)['can_send']===false,'closed conversation cannot receive a new reply');
echo "P8 Messenger reply integration completed.\n";
