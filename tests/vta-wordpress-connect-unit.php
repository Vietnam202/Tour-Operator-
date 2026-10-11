<?php
declare(strict_types=1);
/* Disposable WordPress shim. No live WordPress, MariaDB or remote API. */
define('ABSPATH',__DIR__);
define('VTA_CONNECT_API_URL','https://v2quote.vietnamtraveladvisor.com.vn/api/index.php');
define('VTA_CONNECT_SECRET','test-only-secret-not-for-production-0123456789');
define('VTA_CONNECT_SOURCE','wordpress_vta');
$_SERVER['REMOTE_ADDR']='192.0.2.10';
$_SERVER['HTTP_ORIGIN']='https://vietnamtraveladvisor.vn';

final class WP_Error {
    public function __construct(public string $code,public string $message,public array $data=[]) {}
}
final class WP_REST_Request {
    public function __construct(private array $body) {}
    public function get_json_params():array {return $this->body;}
}
function add_action(...$v):void {}
function add_shortcode(...$v):void {}
function wp_parse_url(string $v):array|false {return parse_url($v);}
function home_url(string $v='/'):string {return 'https://vietnamtraveladvisor.vn'.$v;}
function wp_salt(string $context):string {return 'unit-test-wp-salt-keep-private';}
function is_ssl():bool {return true;}
function get_transient(string $k) {return $GLOBALS['_transients'][$k]??false;}
function set_transient(string $k,$v,int $seconds):bool {$GLOBALS['_transients'][$k]=$v;return true;}
function add_query_arg(string $key,string $value,string $url):string {
    return $url.'?'.urlencode($key).'='.urlencode($value);
}
function wp_safe_remote_post(string $url,array $opts):array {
    $GLOBALS['_remote_calls'][]=['url'=>$url,'options'=>$opts];
    $body=(string)$opts['body'];$sign=(string)$opts['headers']['X-VTA-SIGNATURE-256'];
    assertSame($sign,'sha256='.hash_hmac('sha256',$body,VTA_CONNECT_SECRET),'Signed body mismatch');
    assertSame($opts['redirection'],0,'Unsafe automatic redirect');
    if (str_contains($url,'website-chat-relay')) {
        $req=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        $out=$req['action']==='pull'?['ok'=>true,'messages'=>[['id'=>4,'text'=>'Approved tour link: https://example.test/itinerary']],'last_id'=>4]:['ok'=>true,'acknowledged'=>1];
    } else $out=['ok'=>true,'accepted'=>true,'replayed'=>false];
    return ['response'=>['code'=>202],'body'=>json_encode($out)];
}
function is_wp_error($x):bool {return $x instanceof WP_Error;}
function wp_remote_retrieve_response_code(array $r):int {return $r['response']['code'];}
function wp_remote_retrieve_body(array $r):string {return $r['body'];}
function assertSame($actual,$expected,string $message):void {
    if ($actual!==$expected) throw new RuntimeException($message.' | '.var_export($actual,true).' != '.var_export($expected,true));
}
function ok(bool $condition,string $message):void {if (!$condition)throw new RuntimeException($message);}

require __DIR__.'/../integrations/wordpress/vta-connect/vta-connect.php';
$external=VTA_Connect_Protocol::externalId(str_repeat('a',64));
ok(str_starts_with($external,'wp_')&&strlen($external)===67,'Private opaque session identifier');
$event=VTA_Connect_Protocol::chatEvent($external,[
    'request_id'=>str_repeat('b',32),'text'=>'Hello','contact_name'=>'Visitor'
]);
assertSame($event['event_type'],'conversation.message','Website inbox event type');
assertSame($event['event_id'],VTA_Connect_Protocol::chatEvent($external,[
    'request_id'=>str_repeat('b',32),'text'=>'Hello'
])['event_id'],'Stable event id on retry');
ok($event['message']['message_id']!==$event['event_id'],'Event and message ids separated');
try {VTA_Connect_Protocol::chatEvent($external,['request_id'=>'bad','text'=>'x']);throw new RuntimeException('Accepted forged request_id');}
catch(InvalidArgumentException $e){}
try {VTA_Connect_Protocol::leadEvent($external,['request_id'=>str_repeat('z',32),'contact_name'=>'A','total_guests'=>2]);throw new RuntimeException('Accepted lead without contact');}
catch(InvalidArgumentException $e){}
try {VTA_Connect_Protocol::leadEvent($external,['request_id'=>str_repeat('z',32),'contact_name'=>'A','email'=>'a@example.org','total_guests'=>-3]);throw new RuntimeException('Accepted negative guests');}
catch(InvalidArgumentException $e){}
$chat=VTA_Connect::chat(new WP_REST_Request([
  'request_id'=>str_repeat('c',32),'text'=>'Hanoi private tour','website'=>'',
  'external_id'=>'wp_forged_conversation','attribution'=>['utm_source'=>'google']
]));
ok(is_array($chat)&&($chat['ok']??false),'Website message should be accepted');
$wire=json_decode($GLOBALS['_remote_calls'][0]['options']['body'],true);
assertSame($wire['conversation']['external_id'],VTA_Connect_Protocol::externalId(hash_hmac('sha256',$_COOKIE['vta_connect_sid'],wp_salt('auth'))),'Never trust browser conversation ID');
assertSame($wire['attribution']['utm_source'],'google','Attribution mapping');
ok(!isset($wire['external_id']),'No client-controlled external ID in envelope');
$lead=VTA_Connect::lead(new WP_REST_Request([
  'request_id'=>str_repeat('d',32),'contact_name'=>'Alex','email'=>'alex@example.org',
  'total_guests'=>3,'destination'=>'Ha Long','website'=>''
]));
ok(is_array($lead)&&($lead['ok']??false),'Website lead accepted');
$payload=json_decode($GLOBALS['_remote_calls'][1]['options']['body'],true);
assertSame($payload['lead']['paying_pax'],3,'Server derives guest basis');
assertSame($payload['lead']['foc'],0,'No fabricated FOC');
assertSame($payload['event_type'],'lead.created','RC6 lead route contract');
$poll=VTA_Connect::poll(new WP_REST_Request(['after_id'=>0]));
assertSame(count($poll['messages']),1,'Manual staff reply relay');
$ack=VTA_Connect::ack(new WP_REST_Request(['message_ids'=>[4]]));
assertSame($ack['acknowledged'],1,'Relay acknowledgement (not read receipt)');
$_SERVER['HTTP_ORIGIN']='https://attacker.example';
$forbidden=VTA_Connect::chat(new WP_REST_Request(['request_id'=>str_repeat('e',32),'text'=>'blocked']));
ok($forbidden instanceof WP_Error&&($forbidden->data['status']??null)===403,'Cross-origin rejection');
echo "PASS VTA Connect protocol, HMAC, chat/lead/relay, tenant conversation, idempotency, origin guard\n";
