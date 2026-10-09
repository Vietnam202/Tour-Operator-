<?php
declare(strict_types=1);

/**
 * VTA Website Chat P2 — PHP server-side relay for each VTA website.
 * Deploy on a website origin ONLY after staging review. Needs ext-curl and PHP sessions.
 * NEVER configure VTA_CHAT_SECRET in public HTML, JS or git.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(array $value,int $code=200):never {
 http_response_code($code);
 echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 exit;
}
function setting(string $name):string {
 $v=getenv($name);
 if(!is_string($v)||trim($v)==='')reply(['ok'=>false,'error'=>'CHAT_NOT_CONFIGURED'],503);
 return trim($v);
}
$target=setting('VTA_CHAT_RC6_ENDPOINT');
$source=setting('VTA_CHAT_SOURCE');
$secret=setting('VTA_CHAT_SECRET');
if(!preg_match('#^https://[A-Za-z0-9.-]+(?::443)?/api/index\.php$#D',$target)
    ||!preg_match('/^[a-z0-9_-]{3,64}$/D',$source)||strlen($secret)<32)
 reply(['ok'=>false,'error'=>'CHAT_NOT_CONFIGURED'],503);
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')
 reply(['ok'=>false,'error'=>'HTTPS_REQUIRED'],503);
if(($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin')==='cross-site')
 reply(['ok'=>false,'error'=>'FORBIDDEN'],403);
session_name('vta_website_chat');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$_SESSION['chat_csrf']??=bin2hex(random_bytes(24));
$_SESSION['chat_thread']??='visitor_'.bin2hex(random_bytes(16));
$_SESSION['chat_history']??=[];
$_SESSION['chat_cursor']??=0;

function callVta(string $target,string $source,string $secret,string $route,array $data):array {
 $raw=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $sig='sha256='.hash_hmac('sha256',$raw,$secret);
 $url=$target.'?route='.rawurlencode($route);
 $ch=curl_init($url);
 if($ch===false)throw new RuntimeException('cURL initialization failed');
 curl_setopt_array($ch,[
  CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$raw,CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,
  CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
  CURLOPT_HTTPHEADER=>[
    'Content-Type: application/json','X-VTA-Webhook-Source: '.$source,
    'X-VTA-Signature-256: '.$sig
  ]]);
 $response=curl_exec($ch);
 $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
 curl_close($ch);
 $data=is_string($response)?json_decode($response,true):null;
 if($code<200||$code>=300||!is_array($data)||($data['ok']??false)!==true)
  throw new RuntimeException('Chat upstream unavailable');
 return $data;
}
function compactHistory():array {
 $items=$_SESSION['chat_history'];
 return is_array($items)?array_values(array_slice($items,-100)):[];
}
function sameOrigin():void {
 $origin=$_SERVER['HTTP_ORIGIN']??'';
 if($origin==='')return; // Non-browser callers still require a CSRF token for POST.
 $self='https://'.($_SERVER['HTTP_HOST']??'');
 if(!hash_equals($self,$origin))reply(['ok'=>false,'error'=>'ORIGIN_DENIED'],403);
}
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
$action=$_GET['action']??'';
if($action==='init'&&$method==='GET')
 reply(['ok'=>true,'csrf'=>$_SESSION['chat_csrf'],'messages'=>compactHistory()]);
if($action==='send'&&$method==='POST') {
 sameOrigin();
 $raw=file_get_contents('php://input',false,null,0,14000);
 if(!is_string($raw)||strlen($raw)>13000)reply(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
 $input=json_decode($raw,true);
 if(!is_array($input))reply(['ok'=>false,'error'=>'INVALID_JSON'],400);
 $csrf=$input['csrf']??null;
 if(!is_string($csrf)||!hash_equals($_SESSION['chat_csrf'],$csrf))
  reply(['ok'=>false,'error'=>'CSRF_DENIED'],419);
 $text=$input['text']??null;
 if(!is_string($text)||trim($text)===''||strlen($text)>8000)
  reply(['ok'=>false,'error'=>'INVALID_TEXT'],422);
 $messageId=$input['message_id']??null;
 if(!is_string($messageId)||!preg_match('/^[a-f0-9-]{36}$/D',$messageId))
  reply(['ok'=>false,'error'=>'INVALID_MESSAGE_ID'],422);
 $name=$input['contact_name']??'';
 $email=$input['email']??'';
 if(!is_string($name)||strlen($name)>190||!is_string($email)||strlen($email)>190
    ||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))
  reply(['ok'=>false,'error'=>'INVALID_CONTACT'],422);
 // Request rate limiting is per HTTP session, with RC6 source/thread limiting as defense in depth.
 $window=intdiv(time(),60);
 $counter=$_SESSION['chat_window']??[];
 $count=(int)($counter['minute']??-1)===$window?(int)($counter['count']??0):0;
 if($count>=12)reply(['ok'=>false,'error'=>'RATE_LIMITED'],429);
 $_SESSION['chat_window']=['minute'=>$window,'count'=>$count+1];
 $event=[
  'event_type'=>'conversation.message','event_id'=>'evt_'.$messageId,
  'conversation'=>[
    'external_id'=>$_SESSION['chat_thread'],
    'contact_name'=>trim($name),'email'=>trim($email)
  ],
  'message'=>['message_id'=>$messageId,'text'=>trim($text)]
 ];
 $attribution=$input['attribution']??[];
 if(is_array($attribution)&&!array_is_list($attribution)){
  $selected=[];
  foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id','referrer','landing_url'] as $key)
   if(isset($attribution[$key])&&is_string($attribution[$key])&&strlen($attribution[$key])<=1000)
    $selected[$key]=$attribution[$key];
  if($selected)$event['attribution']=$selected;
 }
 try{
  $result=callVta($target,$source,$secret,'webhooks/website-message',$event);
  $exists=false;
  foreach($_SESSION['chat_history'] as $m)if(($m['id']??'')===$messageId){$exists=true;break;}
  if(!$exists)$_SESSION['chat_history'][]=['id'=>$messageId,'direction'=>'outgoing','text'=>trim($text)];
  $_SESSION['chat_history']=compactHistory();
  reply(['ok'=>true,'accepted'=>(bool)($result['accepted']??false),'messages'=>compactHistory()]);
 }catch(Throwable $e){
  error_log('VTA website chat send: '.get_class($e));
  reply(['ok'=>false,'error'=>'TEMPORARILY_UNAVAILABLE'],503);
 }
}
if($action==='poll'&&$method==='GET') {
 try{
  $data=callVta($target,$source,$secret,'webhooks/website-chat-relay',[
    'action'=>'pull','timestamp'=>time(),'external_conversation_id'=>$_SESSION['chat_thread'],
    'after_id'=>(int)$_SESSION['chat_cursor']
  ]);
  $messages=$data['messages']??[];
  if(!is_array($messages))throw new RuntimeException('Invalid upstream messages');
  $received=[];
  foreach($messages as $m){
    if(!is_array($m)||!is_int($m['id'])||!is_string($m['text']))continue;
    $id=$m['id'];
    if($id<1||strlen($m['text'])>8000)continue;
    $exists=false;
    foreach($_SESSION['chat_history'] as $old)if(($old['id']??'')==='rc6_'.$id){$exists=true;break;}
    if(!$exists)$_SESSION['chat_history'][]=['id'=>'rc6_'.$id,'direction'=>'incoming','text'=>$m['text']];
    $received[]=$id;
    $_SESSION['chat_cursor']=max((int)$_SESSION['chat_cursor'],$id);
  }
  $_SESSION['chat_history']=compactHistory();
  if($received) {
    // At this point messages are staged into the website's PHP session.
    // RELAYED does NOT mean that the guest has seen or read the messages.
    try{callVta($target,$source,$secret,'webhooks/website-chat-relay',[
      'action'=>'ack','timestamp'=>time(),'external_conversation_id'=>$_SESSION['chat_thread'],
      'message_ids'=>$received
    ]);}catch(Throwable $e){error_log('VTA chat relay ack retryable: '.get_class($e));}
  }
  reply(['ok'=>true,'messages'=>compactHistory()]);
 }catch(Throwable $e){
  error_log('VTA website chat poll: '.get_class($e));
  reply(['ok'=>false,'error'=>'TEMPORARILY_UNAVAILABLE'],503);
 }
}
reply(['ok'=>false,'error'=>'NOT_FOUND'],404);
