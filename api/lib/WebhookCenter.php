<?php
declare(strict_types=1);

/** P0: signed server-to-server website leads only; no social provider transport. */
final class WebhookCenter {
 private static function sources(array $cfg):array {
  $v=$cfg['integrations']['website_webhooks']??[];
  return is_array($v)?$v:[];
 }
 public static function validSignature(string $raw,string $secret,string $header):bool {
  return strlen($secret)>=32 && preg_match('/^sha256=[a-f0-9]{64}$/D',$header)===1
   && hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),$header);
 }
 public static function eventKey(string $source,string $id):string {
  return hash('sha256',$source.':'.$id);
 }
 public static function parseEvent(string $raw):array {
  if($raw===''||strlen($raw)>65536)throw new InvalidArgumentException('Invalid payload size');
  $v=json_decode($raw,true);
  if(!is_array($v)||array_is_list($v)||($v['event_type']??'')!=='lead.created')
   throw new InvalidArgumentException('Unsupported event');
  $id=$v['event_id']??null;
  if(!is_string($id)||preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$id)!==1)
   throw new InvalidArgumentException('Invalid event_id');
  if(!isset($v['lead'])||!is_array($v['lead'])||array_is_list($v['lead']))
   throw new InvalidArgumentException('Invalid lead');
  return ['event_id'=>$id,'lead'=>$v['lead']];
 }
 public static function publicHandle(string $route,string $method,PDO $db,array $cfg):void {
  if($route!=='webhooks/website-lead')return;
  if($method!=='POST')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
  $source=(string)($_SERVER['HTTP_X_VTA_WEBHOOK_SOURCE']??'');
  $spec=self::sources($cfg)[$source]??null;
  if(!preg_match('/^[a-z0-9_-]{3,64}$/D',$source)||!is_array($spec))
   Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
  $secret=$spec['secret']??null;$token=$spec['form_token']??null;
  if(!is_string($secret)||strlen($secret)<32||!is_string($token)||strlen($token)!==64)
   Http::json(['ok'=>false,'error'=>'SOURCE_NOT_READY'],503);
  $raw=file_get_contents('php://input');
  if(!is_string($raw)||strlen($raw)>65536)Http::json(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
  if(!self::validSignature($raw,$secret,(string)($_SERVER['HTTP_X_VTA_SIGNATURE_256']??'')))
   Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
  try {
   $event=self::parseEvent($raw);
   $key=self::eventKey($source,$event['event_id']);
   $submission='wh_'.$key;
   $q=$db->prepare("SELECT f.company_id,f.id FROM lead_forms f JOIN campaigns c ON c.id=f.campaign_id AND c.company_id=f.company_id WHERE f.public_token=? AND f.status='ACTIVE' AND c.status='ACTIVE' LIMIT 1");
   $q->execute([$token]);$form=$q->fetch(PDO::FETCH_ASSOC);
   if(!$form)Http::json(['ok'=>false,'error'=>'SOURCE_NOT_READY'],503);
   $cid=(int)$form['company_id'];
   $lead=$event['lead'];unset($lead['submission_key'],$lead['website']);
   $lead['submission_key']=$submission;
   $fingerprint=hash('sha256',json_encode($lead,JSON_THROW_ON_ERROR));
   $q=$db->prepare('SELECT payload_hash FROM marketing_webhook_events WHERE company_id=? AND source_code=? AND event_key=?');
   $q->execute([$cid,$source,$key]);$old=$q->fetchColumn();
   if($old!==false) {
    if(!hash_equals((string)$old,$fingerprint))Http::json(['ok'=>false,'error'=>'EVENT_ID_REUSED'],409);
    Http::json(['ok'=>true,'accepted'=>true,'replayed'=>true]);
   }
   LeadHub::submit($db,$token,$lead);
   $q=$db->prepare('SELECT id FROM lead_requests WHERE company_id=? AND form_id=? AND submission_key=?');
   $q->execute([$cid,(int)$form['id'],$submission]);$leadId=(int)$q->fetchColumn();
   if(!$leadId)throw new RuntimeException('Accepted lead missing');
   $q=$db->prepare("INSERT IGNORE INTO marketing_webhook_events(company_id,source_code,event_key,payload_hash,event_type,lead_request_id,status) VALUES(?,?,?,?,'lead.created',?,'ACCEPTED')");
   $q->execute([$cid,$source,$key,$fingerprint,$leadId]);
   Http::json(['ok'=>true,'accepted'=>true]);
  }catch(OverflowException $e){Http::json(['ok'=>false,'error'=>'RATE_LIMITED'],429);}
   catch(DomainException $e){Http::json(['ok'=>false,'error'=>'EVENT_ID_REUSED'],409);}
   catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);}
   catch(Throwable $e){error_log('VTA website webhook failed: '.get_class($e));Http::json(['ok'=>false,'error'=>'PROCESSING_FAILED'],503);}
 }
 public static function adminHandle(string $route,string $method,PDO $db,array $cfg,array $user):void {
  if($route!=='marketing/webhooks')return;
  if($method!=='GET')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
  Auth::requirePermission($db,$user,'campaign.manage');
  $cid=(int)$user['company_id'];$sources=[];
  $check=$db->prepare("SELECT id FROM lead_forms WHERE company_id=? AND public_token=? AND status='ACTIVE'");
  foreach(self::sources($cfg) as $code=>$spec) {
   if(!is_string($code)||!is_array($spec)||!is_string($spec['form_token']??null))continue;
   $check->execute([$cid,$spec['form_token']]);if(!$check->fetchColumn())continue;
   $sources[]=['source_code'=>$code,'ready'=>is_string($spec['secret']??null)&&strlen($spec['secret'])>=32,'kind'=>'WEBSITE_LEAD'];
  }
  $q=$db->prepare('SELECT source_code,event_type,status,lead_request_id,created_at FROM marketing_webhook_events WHERE company_id=? ORDER BY id DESC LIMIT 30');
  $q->execute([$cid]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
  $q=$db->prepare('SELECT COUNT(*) FROM marketing_webhook_events WHERE company_id=?');$q->execute([$cid]);
  Http::json(['ok'=>true,'sources'=>$sources,'events'=>$events,'total_events'=>(int)$q->fetchColumn(),'social_connections_enabled'=>false]);
 }
}
