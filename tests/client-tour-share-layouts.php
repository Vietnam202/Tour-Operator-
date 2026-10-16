<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/ClientTourPresentation.php';
function ok(bool $condition,string $msg):void {
    if(!$condition)throw new RuntimeException('FAILED: '.$msg);
    echo 'PASS '.$msg.PHP_EOL;
}
$s=['schema'=>'VTA_PUBLIC_TOUR_OUTLINE_V1','title'=>'Vietnam 7D6N','destination'=>'Da Nang → Ha Long Bay → Phu Quoc',
    'language'=>'en','day_titles'=>['Son Tra and Hoi An','Golden Bridge and flight to Hanoi','Ha Long Bay day cruise','Hanoi and flight to Phu Quoc','Safari / VinWonders','Four islands and Hon Thom','Departure']];
$token=str_repeat('a',64);
foreach(ClientTourPresentation::STYLES as $style){
    $html=ClientTourPresentation::render($s,$style,$token);
    ok(str_contains($html,'vta-theme-'.$style),'layout: '.$style);
    ok(substr_count($html,'class="vta-day"')===7,'same seven days: '.$style);
    ok(str_contains($html,'Hotel &amp; Cruise Options') && str_contains($html,'Tour Price'),'hotel/cruise AND selling-price table in '.$style);
    ok(str_contains($html,'Request quote') && !str_contains($html,'USD 520'),'no unpublished rates '.$style);
    ok(str_contains($html,'name="robots" content="noindex,nofollow,noarchive"'),'search noindex '.$style);
    ok(str_contains($html,'href="#book-tour"'),'enquiry anchor '.$style);
}
$dirty=$s;
$dirty['title']='<script>alert("x")</script>';
$dirty['day_titles'][0]='"><img src=x onerror=alert(1)>';
$html=ClientTourPresentation::render($dirty,'brochure',$token);
ok(!str_contains($html,'<script>')&&!str_contains($html,'<img src=x'),'untrusted text cannot inject tags');
ok(str_contains($html,'&lt;script&gt;'),'HTML escaping in title');
try{ClientTourPresentation::render(['title'=>'invalid'],'quick',$token);throw new RuntimeException('missing approved schema accepted');}
catch(InvalidArgumentException $e){ok(true,'unapproved snapshot rejected');}
try{ClientTourPresentation::render($s,'quick','wrong_token');throw new RuntimeException('invalid token accepted');}
catch(InvalidArgumentException $e){ok(true,'invalid bearer token rejected');}
ok(ClientTourPresentation::allowedLayout('evil')==='detailed','unsupported style defaults safe');
echo 'Client public layout + share integrity contract PASS'.PHP_EOL;
