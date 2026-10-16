<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MarketingShareBooking.php';

function check(bool $condition,string $name):void {
    if(!$condition)throw new RuntimeException('FAILED: '.$name);
    echo 'PASS '.$name.PHP_EOL;
}
$clazz=new ReflectionClass(MarketingShareBooking::class);
$method=$clazz->getMethod('validToken');
$good=str_repeat('a',64);
check($method->invoke(null,$good)===$good,'64-digit opaque bearer accepted');
foreach(['','fake-token',str_repeat('x',64),str_repeat('a',63),'javascript:alert(1)'] as $bad){
    try{$method->invoke(null,$bad);throw new RuntimeException('Accepted invalid token');}
    catch(OutOfBoundsException $e){}
}
check(true,'malformed tokens fail closed');
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$method=$clazz->getMethod('form');
$html=$method->invoke(null,$good);
check(str_contains($html,'name="csrf"')&&str_contains($html,'name="request_id"'),'CSRF and idempotency keys are rendered');
check(str_contains($html,'name="consent"')&&str_contains($html,'name="website"'),'explicit contact consent and anti-spam honeypot');
check(str_contains($html,'name="contact_name"')&&str_contains($html,'name="travel_date"')&&str_contains($html,'name="total_guests"'),'booking-enquiry fields present');
check(str_contains($html,'REQUEST BOOKING · NOT A CONFIRMED RESERVATION'),'no confirmed booking claim');
check(!preg_match('/\$\s*(520|620|870)(?![0-9])|NET\s+USD/i',$html),'no B2B net prices in public form');
check(str_contains($html,'marketing/tour-share/request-booking'),'form targets isolated public lead endpoint');
echo 'Tour share booking contract PASS'.PHP_EOL;
