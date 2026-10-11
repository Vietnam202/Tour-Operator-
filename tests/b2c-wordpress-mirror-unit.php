<?php
declare(strict_types=1);
// Isolated WordPress contract shim: no live site, network or credentials.
define('VTA_CONNECT_API_URL','https://example.invalid/api/index.php');define('VTA_B2C_SECRET',str_repeat('t',48));define('VTA_B2C_SOURCE','fixture_b2c');define('OBJECT','OBJECT');
$options=[];$posts=[];$meta=[];$events=[];$fail=false;$calls=0;
function wp_parse_url($v){return parse_url($v);}function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function add_option($k,$v,...$args){if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}
function update_option($k,$v,...$args){$GLOBALS['options'][$k]=$v;return true;}function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function wp_json_encode($v){return json_encode($v,JSON_THROW_ON_ERROR);}function add_query_arg($k,$v,$u){return $u.'?'.$k.'='.$v;}
function wp_safe_remote_post($url,$args){
 $GLOBALS['calls']++;if($GLOBALS['fail'])return ['code'=>503,'body'=>'','signature'=>''];
 $raw=$args['body'];if(!hash_equals('sha256='.hash_hmac('sha256',$raw,VTA_B2C_SECRET),$args['headers']['X-VTA-SIGNATURE-256']))throw new RuntimeException('Unsigned request');
 if($args['sslverify']!==true||$args['redirection']!==0)throw new RuntimeException('Unsafe transport');
 $request=json_decode($raw,true);$events=array_values(array_filter($GLOBALS['events'],fn($e)=>$e['event_id']>$request['after_id']));
 $response=wp_json_encode(['ok'=>true,'source'=>VTA_B2C_SOURCE,'nonce'=>$request['nonce'],'after_id'=>$request['after_id'],'generated_at'=>time(),'events'=>$events,'has_more'=>false]);
 return ['code'=>200,'body'=>$response,'signature'=>'sha256='.hash_hmac('sha256',$response,VTA_B2C_SECRET)];
}
function is_wp_error($v){return false;}function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}function wp_remote_retrieve_header($r,$k){return $r['signature'];}
function get_posts($args){return array_values(array_filter($GLOBALS['posts'],function($p)use($args){return $p->post_type===($args['post_type']??'')&&(($args['post_status']??'any')==='any'||$p->post_status===$args['post_status'])&&(!isset($args['meta_key'])||get_post_meta($p->ID,$args['meta_key'],true)===$args['meta_value']);}));}
function get_page_by_path($slug,$output,$type){foreach($GLOBALS['posts'] as $p)if($p->post_name===$slug&&$p->post_type===$type)return $p;return null;}
function wp_insert_post($p,$error=false){$id=$p['ID']??count($GLOBALS['posts'])+1;$GLOBALS['posts'][$id]=(object)array_merge(isset($GLOBALS['posts'][$id])?(array)$GLOBALS['posts'][$id]:[],$p,['ID'=>$id]);return $id;}
function wp_update_post($p,$error=false){return wp_insert_post($p,$error);}function get_post_meta($id,$k,$single){return $GLOBALS['meta'][$id][$k]??'';}function update_post_meta($id,$k,$v){$GLOBALS['meta'][$id][$k]=$v;return true;}function delete_post_meta($id,$k){unset($GLOBALS['meta'][$id][$k]);return true;}function clean_post_cache($id){}
function sanitize_text_field($s){return trim(strip_tags($s));}function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_html($s);}function nocache_headers(){}function get_permalink($p){return 'https://example.invalid/journeys/'.$p->post_name;}
function get_post_type($id){return $GLOBALS['posts'][$id]->post_type??'';}function get_post_status($id){return $GLOBALS['posts'][$id]->post_status??'';}
function get_the_ID(){return 1;}function wp_enqueue_script(...$args){}function plugins_url($p,$f){return 'https://example.invalid/plugin/'.$p;}function rest_url($p){return 'https://example.invalid/wp-json/'.$p;}
function checkMirror($condition,$label){if(!$condition)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
require __DIR__.'/../integrations/wordpress/vta-connect/includes/class-vta-b2c-publications.php';
$doc=['schema'=>'VTA_DOC_2','title'=>'Hanoi','content'=>['type'=>'doc','content'=>[['type'=>'paragraph','content'=>[['type'=>'text','text'=>'<script>unsafe()</script>']]]]]];
$public=['schema'=>'VTA_B2C_PUBLICATION_V1','title'=>'Hanoi','destination'=>'Vietnam','language'=>'en','document'=>$doc,'pricing'=>'REQUEST_QUOTE'];
$events=[['event_id'=>1,'program_id'=>8,'revision'=>1,'status'=>'PUBLISHED','snapshot'=>$public]];
checkMirror(VTA_B2C_Publications::sync(),'signed sync succeeds');checkMirror(count($posts)===1&&$posts[1]->post_status==='publish'&&$options['vta_b2c_cursor']===1,'mirror creates one published post and advances cursor');
checkMirror(VTA_B2C_Publications::sync()&&count($posts)===1,'mirror repeat is idempotent');$html=VTA_B2C_Publications::detail(['id'=>1]);checkMirror(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'public Word text is escaped');checkMirror(str_contains($html,'data-publication="1"'),'inquiry uses published event ID');
$events[]=['event_id'=>2,'program_id'=>8,'revision'=>1,'status'=>'WITHDRAWN','snapshot'=>[]];checkMirror(VTA_B2C_Publications::sync()&&$posts[1]->post_status==='draft'&&!isset($meta[1]['_vta_b2c_snapshot']),'withdrawal hides post and removes snapshot');checkMirror(VTA_B2C_Publications::detail(['id'=>1])==='','withdrawn detail is empty');
$events[]=['event_id'=>3,'program_id'=>8,'revision'=>2,'status'=>'PUBLISHED','snapshot'=>$public];checkMirror(VTA_B2C_Publications::sync()&&count($posts)===1&&$meta[1]['_vta_b2c_event']===3,'new revision reuses post and replaces publication');
$options['vta_b2c_sync_lock']=time();$before=$calls;checkMirror(!VTA_B2C_Publications::sync()&&$calls===$before,'atomic mutex rejects concurrent sync');unset($options['vta_b2c_sync_lock']);
$options['vta_b2c_verified_at']=time()-121;$fail=true;checkMirror(str_contains(VTA_B2C_Publications::detail(['id'=>1]),'temporarily unavailable'),'unavailable source fails closed after freshness deadline');checkMirror($options['vta_b2c_cursor']===3&&!isset($options['vta_b2c_sync_lock']),'failed sync preserves cursor and releases mutex');
$fail=false;$options['vta_b2c_binding']='different-source';checkMirror(!VTA_B2C_Publications::sync(),'source change requires reconciliation');
// A signed but out-of-order response must still be rejected.
$request=['nonce'=>str_repeat('a',32),'after_id'=>0];$raw=wp_json_encode(['ok'=>true,'source'=>VTA_B2C_SOURCE,'nonce'=>$request['nonce'],'after_id'=>0,'generated_at'=>time(),'events'=>[$events[2],$events[0]],'has_more'=>false]);
$reversedRejected=false;try{VTA_B2C_Publications::verify($raw,'sha256='.hash_hmac('sha256',$raw,VTA_B2C_SECRET),$request,['secret'=>VTA_B2C_SECRET,'source'=>VTA_B2C_SOURCE]);}catch(RuntimeException $e){$reversedRejected=true;}checkMirror($reversedRejected,'signed reversed feed rejected');
