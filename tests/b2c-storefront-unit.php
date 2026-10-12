<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/LeadHub.php';
require_once __DIR__.'/../api/lib/B2CStorefront.php';
require_once __DIR__.'/../integrations/wordpress/vta-connect/includes/class-vta-connect-protocol.php';
function sfCheck(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function sfDeny(callable $fn,string $label): void {try{$fn();}catch(InvalidArgumentException|OutOfBoundsException $e){sfCheck(true,$label);return;}throw new RuntimeException($label);}
$input=['consent'=>true,'request_id'=>'native-inquiry-fixture-0001','contact_name'=>'Fixture Visitor','email'=>'visitor@example.invalid','phone'=>'+84 000000000','total_guests'=>2,'travel_date'=>'2026-11-01','travel_date_end'=>'2026-11-08','message'=>'Travel preferences','attribution'=>['utm_campaign'=>'fixture','referrer'=>'https://example.invalid/']];
$lead=B2CStorefront::lead($input);
sfCheck($lead['travel_date']==='2026-11-01'&&$lead['phone']===$input['phone']&&$lead['utm_campaign']==='fixture'&&str_contains($lead['message'],'2026-11-08'),'native intake preserves dates, phone, attribution and consent');
foreach([['consent'=>false],['consent'=>'true'],['travel_date'=>'2026-02-30'],['travel_date_end'=>'2026-10-01'],['total_guests'=>0],['website'=>'spam']] as $patch)sfDeny(fn()=>B2CStorefront::lead(array_replace($input,$patch)),'invalid native inquiry rejected '.json_encode($patch));
sfDeny(fn()=>B2CStorefront::settings([]),'native storefront disabled by default');
$node=['type'=>'text','text'=>'<script>unsafe</script>','marks'=>[['type'=>'bold'],['type'=>'italic'],['type'=>'link','attrs'=>['href'=>'javascript:alert(1)']]]];
$html=B2CStorefront::node($node,'/api/');sfCheck(str_contains($html,'<strong>')&&str_contains($html,'<em>')&&!str_contains($html,'<script>')&&!str_contains($html,'javascript:'),'native renderer preserves formatting and rejects script URLs');
$node['marks']=[['type'=>'link','attrs'=>['href'=>'https://example.invalid/path?a=1&b=2']]];sfCheck(str_contains(B2CStorefront::node($node,'/api/'),'href="https://example.invalid/path?a=1&amp;b=2"'),'native renderer escapes safe link');
$input['b2c_publication_id']=7;$wp=VTA_Connect_Protocol::leadEvent('wp_fixture',$input)['lead'];sfCheck($wp['travel_date']===$lead['travel_date']&&$wp['utm_campaign']==='fixture'&&str_contains($wp['message'],'Contact consent: yes.'),'optional WordPress preserves same inquiry fields');
sfDeny(fn()=>VTA_Connect_Protocol::leadEvent('wp_fixture',array_replace($input,['consent'=>false])),'WordPress server rejects missing consent');
