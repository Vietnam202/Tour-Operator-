<?php
declare(strict_types=1);
require_once __DIR__.'/MarketingRules.php';

/** Editorial planning only. No provider transport, token storage or publishing worker. */
final class MarketingStudio {
 private static function q(PDO $db,string $sql,array $args=[]):PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
 private static function campaign(PDO $db,int $cid,int $id):void {
  if(!self::q($db,"SELECT id FROM campaigns WHERE company_id=? AND id=? AND status='ACTIVE'",[$cid,$id])->fetchColumn())throw new OutOfBoundsException('Active campaign not found');
 }
 private static function owner(PDO $db,int $cid,int $id):void {
  if(!self::q($db,"SELECT id FROM users WHERE company_id=? AND id=? AND status='ACTIVE'",[$cid,$id])->fetchColumn())throw new InvalidArgumentException('Owner must be an active user in this company');
 }
 private static function inbox(PDO $db,int $cid,int $id):array {
  $r=self::q($db,'SELECT * FROM marketing_inbox WHERE company_id=? AND id=? FOR UPDATE',[$cid,$id])->fetch();if(!$r)throw new OutOfBoundsException('Conversation not found');return $r;
 }
 public static function createContent(PDO $db,array $u,array $c):int {
  $cid=(int)$u['company_id'];self::campaign($db,$cid,$c['campaign_id']);
  if($c['parent_content_id']&&!self::q($db,'SELECT id FROM marketing_content WHERE company_id=? AND id=? AND campaign_id=?',[$cid,$c['parent_content_id'],$c['campaign_id']])->fetchColumn())throw new OutOfBoundsException('Original content not found');
  self::q($db,'INSERT INTO marketing_content(company_id,campaign_id,channel,body,created_by,topic,content_format,tags,asset_url,rights_note,planned_at,parent_content_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$cid,$c['campaign_id'],$c['channel'],$c['body'],$u['id'],$c['topic'],$c['content_format'],$c['tags'],$c['asset_url'],$c['rights_note'],$c['planned_at'],$c['parent_content_id']]);return (int)$db->lastInsertId();
 }
 public static function createBatch(PDO $db,array $u,array $body):array {
  $v=MarketingRules::batch($body);$cid=(int)$u['company_id'];
  // Unique-key insertion waits for an in-flight transaction with the same key.
  self::q($db,'INSERT IGNORE INTO marketing_batches(company_id,request_key,payload_hash) VALUES(?,?,?)',[$cid,$v['key'],$v['hash']]);
  $r=self::q($db,'SELECT payload_hash,result_json FROM marketing_batches WHERE company_id=? AND request_key=? FOR UPDATE',[$cid,$v['key']])->fetch();
  if(!$r||!hash_equals($r['payload_hash'],$v['hash']))throw new DomainException('Request key already used for different drafts');
  if($r['result_json']!==null)return ['ids'=>json_decode($r['result_json'],true,512,JSON_THROW_ON_ERROR),'replayed'=>true];
  $ids=[];foreach($v['entries'] as $c)$ids[]=self::createContent($db,$u,$c);
  self::q($db,'UPDATE marketing_batches SET result_json=? WHERE company_id=? AND request_key=?',[json_encode($ids,JSON_THROW_ON_ERROR),$cid,$v['key']]);
  return ['ids'=>$ids,'replayed'=>false];
 }
 /** Called inside the mutation transaction after lead.manage authorization. */
 public static function handoff(PDO $db,array $u,array $b):array {
  $cid=(int)$u['company_id'];$id=MarketingRules::id($b['id']??null);$r=self::inbox($db,$cid,$id);
  if($r['lead_request_id'])return ['id'=>$id,'request_id'=>(int)$r['lead_request_id'],'replayed'=>true];
  self::campaign($db,$cid,(int)$r['campaign_id']);
  $v=LeadHub::validateRequest(array_merge($b,['contact_name'=>$r['contact_name'],'email'=>$r['email'],'phone'=>$r['phone'],'message'=>$r['message']]));
  $attr=['source_type'=>'MANUAL_SOCIAL','marketing_inbox_id'=>$id,'channel'=>$r['channel']];
  self::q($db,'INSERT INTO lead_requests(company_id,campaign_id,source,submission_key,payload_hash,contact_name,email,phone,travel_date,total_guests,paying_pax,foc,destination,hotel_level,message,attribution_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$cid,$r['campaign_id'],$r['channel'],'marketing-inbox-'.$id,hash('sha256',json_encode($v,JSON_THROW_ON_ERROR)),$v['contact_name'],$v['email']?:null,$v['phone']?:null,$v['travel_date']?:null,$v['total_guests'],$v['paying_pax'],$v['foc'],$v['destination'],$v['hotel_level'],$v['message'],json_encode($attr,JSON_THROW_ON_ERROR)]);
  $request=(int)$db->lastInsertId();self::q($db,"UPDATE marketing_inbox SET lead_request_id=?,status='FOLLOW_UP',version_no=version_no+1,updated_at=NOW() WHERE company_id=? AND id=?",[$request,$cid,$id]);
  return ['id'=>$id,'request_id'=>$request,'replayed'=>false];
 }
 private static function studio(PDO $db,array $u):array {
  $cid=(int)$u['company_id'];
  $campaigns=self::q($db,"SELECT c.*,b.market,b.offer,b.audience,b.budget,b.currency,
   (SELECT COUNT(*) FROM lead_requests r WHERE r.company_id=c.company_id AND r.campaign_id=c.id) request_count,
   (SELECT COUNT(*) FROM leads l JOIN lead_requests r ON r.id=l.request_id AND r.company_id=l.company_id WHERE r.company_id=c.company_id AND r.campaign_id=c.id) qualified_count,
   (SELECT COUNT(*) FROM leads l JOIN lead_requests r ON r.id=l.request_id AND r.company_id=l.company_id WHERE r.company_id=c.company_id AND r.campaign_id=c.id AND l.inquiry_id IS NOT NULL) converted_count
   FROM campaigns c LEFT JOIN marketing_briefs b ON b.company_id=c.company_id AND b.campaign_id=c.id WHERE c.company_id=? ORDER BY c.id DESC LIMIT 300",[$cid])->fetchAll();
  $items=self::q($db,'SELECT m.*,c.name campaign_name,u.full_name creator_name,d.full_name reviewer_name FROM marketing_content m JOIN campaigns c ON c.company_id=m.company_id AND c.id=m.campaign_id LEFT JOIN users u ON u.id=m.created_by AND u.company_id=m.company_id LEFT JOIN users d ON d.id=m.decided_by AND d.company_id=m.company_id WHERE m.company_id=? ORDER BY m.id DESC LIMIT 500',[$cid])->fetchAll();
  $library=self::q($db,'SELECT * FROM marketing_library WHERE company_id=? ORDER BY id DESC LIMIT 300',[$cid])->fetchAll();
  $inbox=self::q($db,'SELECT m.*,c.name campaign_name,u.full_name owner_name FROM marketing_inbox m JOIN campaigns c ON c.id=m.campaign_id AND c.company_id=m.company_id JOIN users u ON u.id=m.owner_user_id AND u.company_id=m.company_id WHERE m.company_id=? ORDER BY m.updated_at DESC,m.id DESC LIMIT 200',[$cid])->fetchAll();
  $users=self::q($db,"SELECT id,full_name FROM users WHERE company_id=? AND status='ACTIVE' ORDER BY full_name LIMIT 300",[$cid])->fetchAll();
  $counts=self::q($db,'SELECT status,COUNT(*) total FROM marketing_content WHERE company_id=? GROUP BY status',[$cid])->fetchAll();
  return ['ok'=>true,'campaigns'=>$campaigns,'items'=>$items,'library'=>$library,'inbox'=>$inbox,'users'=>$users,'counts'=>$counts,'publishing_enabled'=>false,'inbox_sync_enabled'=>false,'timezone'=>'Asia/Ho_Chi_Minh','limits'=>['campaigns'=>300,'items'=>500,'library'=>300,'inbox'=>200]];
 }
 public static function handle(string $route,string $method,PDO $db,array $u):void {
  if(!in_array($route,['marketing/studio','marketing/content','marketing/batch','marketing/library','marketing/inbox','marketing/inbox-update','marketing/inbox-lead'],true))return;
  $permission=$method==='GET'?'lead.view':(str_starts_with($route,'marketing/inbox')?'lead.manage':'campaign.manage');Auth::requirePermission($db,$u,$permission);
  $cid=(int)$u['company_id'];$uid=(int)$u['id'];
  try{
   if($route==='marketing/studio'&&$method==='GET')Http::json(self::studio($db,$u));
   if($method!=='POST'||$route==='marketing/studio')Http::json(['ok'=>false,'message'=>'Method not allowed'],405);
   $b=Http::body();$db->beginTransaction();$result=[];$entity='marketing_content';$event='MARKETING_DRAFT_CREATED';$id=0;
   if($route==='marketing/content'){$id=self::createContent($db,$u,MarketingRules::content($b));$result=['id'=>$id];}
   elseif($route==='marketing/batch'){$result=self::createBatch($db,$u,$b);$id=(int)$result['ids'][0];$event='MARKETING_BATCH_DRAFTED';}
   elseif($route==='marketing/library'){
    $m=MarketingRules::metadata($b);$title=MarketingRules::text($b,'title',190,true);$text=MarketingRules::text($b,'body',12000,true);
    self::q($db,'INSERT INTO marketing_library(company_id,title,topic,body,tags,asset_url,rights_note,created_by) VALUES(?,?,?,?,?,?,?,?)',[$cid,$title,$m['topic'],$text,$m['tags'],$m['asset_url'],$m['rights_note'],$uid]);$id=(int)$db->lastInsertId();$result=['id'=>$id];$entity='marketing_library';$event='MARKETING_TEMPLATE_CREATED';
   }
   elseif($route==='marketing/inbox'){
    $campaign=MarketingRules::id($b['campaign_id']??null);self::campaign($db,$cid,$campaign);$owner=MarketingRules::id($b['owner_user_id']??$uid);self::owner($db,$cid,$owner);
    $name=MarketingRules::text($b,'contact_name',190,true);$channel=MarketingRules::choice($b,'channel',MarketingRules::CHANNELS);$message=MarketingRules::text($b,'message',8000,true);$email=MarketingRules::text($b,'email',190);$phone=MarketingRules::text($b,'phone',64);
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid email');
    self::q($db,"INSERT INTO marketing_inbox(company_id,campaign_id,channel,contact_name,email,phone,message,reply_draft,owner_user_id,created_by) VALUES(?,?,?,?,?,?,?,'',?,?)",[$cid,$campaign,$channel,$name,$email,$phone,$message,$owner,$uid]);$id=(int)$db->lastInsertId();$result=['id'=>$id];$entity='marketing_inbox';$event='MANUAL_SOCIAL_NOTE_CREATED';
   }
   elseif($route==='marketing/inbox-update'){
    $id=MarketingRules::id($b['id']??null);$r=self::inbox($db,$cid,$id);if((int)$r['version_no']!==MarketingRules::id($b['version_no']??null))throw new DomainException('Conversation changed. Reload before saving.');
    $owner=MarketingRules::id($b['owner_user_id']??null);self::owner($db,$cid,$owner);$status=MarketingRules::choice($b,'status',['NEW','FOLLOW_UP','CLOSED']);$reply=MarketingRules::text($b,'reply_draft',8000);$phone=MarketingRules::text($b,'phone',64);$email=MarketingRules::text($b,'email',190);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid email');
    self::q($db,'UPDATE marketing_inbox SET owner_user_id=?,status=?,reply_draft=?,phone=?,email=?,version_no=version_no+1,updated_at=NOW() WHERE company_id=? AND id=?',[$owner,$status,$reply,$phone,$email,$cid,$id]);$result=['id'=>$id];$entity='marketing_inbox';$event='SOCIAL_REPLY_DRAFT_SAVED';
   }
   elseif($route==='marketing/inbox-lead'){$result=self::handoff($db,$u,$b);$id=$result['id'];$entity='marketing_inbox';$event='SOCIAL_NOTE_TO_REQUEST';}
   if(empty($result['replayed']))Audit::log($db,$cid,$uid,$event,$entity,$id,null,['publishing_enabled'=>false,'record_count'=>isset($result['ids'])?count($result['ids']):1]);
   $db->commit();Http::json(['ok'=>true,'publishing_enabled'=>false]+$result);
  }catch(Throwable $e){
   if($db->inTransaction())$db->rollBack();
   $status=$e instanceof OutOfBoundsException?404:($e instanceof DomainException?409:($e instanceof InvalidArgumentException?422:503));
   $message=$status===503?'Marketing Studio is unavailable. Ask the administrator to check migration 019 and server logs.':$e->getMessage();Http::json(['ok'=>false,'message'=>$message],$status);
  }
 }
}
