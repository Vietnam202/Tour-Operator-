<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/FreeformDocument.php';
require_once __DIR__.'/../api/lib/AdsLandingPresentation.php';
require_once __DIR__.'/../api/lib/AdsLandingPages.php';
function chk(bool $v,string $message):void{if(!$v)throw new RuntimeException('FAIL '.$message);echo "PASS $message\n";}
$doc=['schema'=>'VTA_DOC_2','title'=>'Approved Content','content'=>['type'=>'doc','content'=>[
 ['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>'Day 1: Hà Nội']]], 
 ['type'=>'paragraph','content'=>[['type'=>'text','text'=>'Approved itinerary B/L']]], 
 ['type'=>'table','content'=>[['type'=>'tableRow','content'=>[['type'=>'tableCell','attrs'=>['colspan'=>1,'rowspan'=>1],'content'=>[['type'=>'paragraph','content'=>[['type'=>'text','text'=>'3-star Hotel']]]]],['type'=>'tableCell','attrs'=>['colspan'=>1,'rowspan'=>1],'content'=>[['type'=>'paragraph','content'=>[['type'=>'text','text'=>'4-star Hotel']]]]]]]]]
]]];
$s=['schema'=>'VTA_B2C_PUBLICATION_V1','title'=>'Vietnam Discovery 7D6N','destination'=>'Hanoi → Ha Long','language'=>'en','document'=>$doc,'pricing'=>'REQUEST_QUOTE'];
$landing=['slug'=>'vietnam-7d6n-india','publication_event_id'=>87];
$nonce=base64_encode(str_repeat('a',16));
$csrf=str_repeat('a',48);
foreach(AdsLandingPresentation::LAYOUTS as $mode){
    $html=AdsLandingPresentation::html($s,$landing,$mode,$csrf,$nonce);
    chk(str_contains($html,'mode-'.$mode),'template '.$mode);
    chk(str_contains($html,'Day 1: Hà Nội'),'approved freeform day in '.$mode);
    chk(str_contains($html,'Hotel &amp; Cruise Options')&&str_contains($html,'Tour Price'),'common tables '.$mode);
    chk(str_contains($html,'Request Booking / Get a Quote'),'request form '.$mode);
    chk(str_contains($html,'name="analytics_consent"')&&str_contains($html,'name="consent"'),'separate contact and optional analytics consent '.$mode);
    chk(str_contains($html,'b2c-public-image/87/')===false,'no invented image '.$mode);
    chk(!str_contains($html,'net_per_pax')&&!str_contains($html,'supplier_cost')&&!str_contains($html,'profit_margin'),'no internal pricing '.$mode);
}
$mal=$s;$mal['title']='<script>alert("oops")</script>';
$out=AdsLandingPresentation::html($mal,$landing,'brochure',$csrf,$nonce);
chk(!str_contains($out,'<script>alert("oops")</script>')&&str_contains($out,'&lt;script&gt;'),'XSS escaping');
try{AdsLandingPresentation::html([...$s,'pricing'=>'NET'],$landing,'quick',$csrf,$nonce);throw new RuntimeException('unsafe snapshot accepted');}catch(DomainException $e){chk(true,'NET publication fails closed');}
try{AdsLandingPresentation::html(['schema'=>'OTHER'],$landing,'quick',$csrf,$nonce);throw new RuntimeException('unreviewed content accepted');}catch(Throwable $e){chk(true,'unapproved content fails closed');}
chk(AdsLandingPages::slug('vietnam-7d6n-india')==='vietnam-7d6n-india','public slug accepted');
foreach(['VietNam','a b','https://evil.test','x','a/b'] as $bad){try{AdsLandingPages::slug($bad);throw new RuntimeException('bad slug accepted');}catch(InvalidArgumentException $e){}}
chk(true,'invalid slug fails closed');
chk(AdsLandingPages::utmValue('facebook')==='facebook','UTM source');
chk(AdsLandingPages::utmValue('john@example.com')==='','email-like tracking rejected');
chk(AdsLandingPages::utmValue('abc/xyz')==='','path-like UTM rejected');
$attrs=AdsLandingPages::attribution(['utm_source'=>'facebook','utm_medium'=>'paid_social','utm_campaign'=>'oct_7d6n','ad_id'=>'ad_43'],$landing+['default_utm_campaign'=>'other']);
chk($attrs['utm_source']==='facebook'&&$attrs['utm_campaign']==='oct_7d6n'&&$attrs['ad_id']==='ad_43','ad attribution accepted');
chk(AdsLandingPages::publicUrl(['app'=>['base_url'=>'https://rc62-staging.vietnamtraveladvisor.com.vn']],'vietnam-7d6n-india')==='https://rc62-staging.vietnamtraveladvisor.com.vn/api/index.php?route=ads/landing/vietnam-7d6n-india','stable HTTPS link');
try{AdsLandingPages::publicUrl(['app'=>['base_url'=>'http://example.com']],'vietnam-7d6n-india');throw new RuntimeException('http link accepted');}catch(DomainException $e){chk(true,'non-HTTPS link rejected');}
echo "Ads landing source/UTM/security contract PASS\n";
