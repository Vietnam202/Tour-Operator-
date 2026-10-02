<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/ScheduleImport.php';
function expectSame($actual,$expected,string $name):void {
 if($actual!==$expected)throw new RuntimeException($name.' expected '.var_export($expected,true).' got '.var_export($actual,true));
 echo "PASS $name\n";
}
$p=ScheduleImport::parse("Day 1: Hanoi\nWalking tour.\nMeals: B/L\nOvernight: Hanoi\nINCLUDED:\nHotel and breakfast\nEXCLUDED:\nInternational flights\nBooking Terms:\nSubject to confirmation.",'2026-11-01');
expectSame($p['included_text']??null,'Hotel and breakfast','extract included');
expectSame($p['excluded_text']??null,'International flights','extract excluded');
expectSame($p['terms_text']??null,'Subject to confirmation.','extract booking terms');
expectSame($p['days'][0]['description'],"Walking tour.\nMeals: B/L\nOvernight: Hanoi",'commercial sections excluded from day body');
expectSame($p['days'][0]['date'],'2026-11-01','travel date preserved');
expectSame($p['days'][0]['meals'],'B/L','meals preserved');
expectSame($p['sections_found'],['included_text'=>true,'excluded_text'=>true,'terms_text'=>true],'section presence explicit');
$p=ScheduleImport::parse("Giá bao gồm： Xe riêng\nKhông bao gồm\nVé máy bay\nNgày 1: Hà Nội\nĐón khách.\nĐiều kiện thanh toán:\nĐặt cọc 30%.\nĐiều kiện hủy:\nTheo xác nhận.");
expectSame($p['included_text'],'Xe riêng','Vietnamese inline section before itinerary');
expectSame($p['excluded_text'],'Vé máy bay','Vietnamese standalone section');
expectSame($p['days'][0]['description'],'Đón khách.','Vietnamese sections keep day separate');
expectSame($p['terms_text'],"Điều kiện thanh toán\nĐặt cọc 30%.\n\nĐiều kiện hủy\nTheo xác nhận.",'terms subheadings retain context');
$p=ScheduleImport::parse("Day 1: Hanoi\nIncluded experiences are described by the guide.\nThe word excluded is ordinary prose.");
expectSame($p['sections_found'],['included_text'=>false,'excluded_text'=>false,'terms_text'=>false],'ordinary prose is not a heading');
expectSame($p['days'][0]['description'],"Included experiences are described by the guide.\nThe word excluded is ordinary prose.",'ordinary prose preserved');
$p=ScheduleImport::parse("Day 1: Hanoi\nTour.\nIncluded:\nExcluded: Flights");
expectSame($p['included_text'],'','explicit empty section is empty');
expectSame($p['sections_found']['included_text'],true,'empty section still detected');
expectSame(count(array_filter($p['warnings'],fn($w)=>str_contains($w,'included_text')))>0,true,'empty section warning');
$p=ScheduleImport::parse("Price Includes: Transfer\nDay 1: Hanoi\nTour.\nDay 2: Halong\nCruise.\nTerms and Conditions: Review before booking.");
expectSame(count($p['days']),2,'days after preamble section preserved');
expectSame($p['terms_text'],'Review before booking.','terms and conditions alias');
expectSame($p['source_text'],"Price Includes: Transfer\nDay 1: Hanoi\nTour.\nDay 2: Halong\nCruise.\nTerms and Conditions: Review before booking.",'source text preserved');
try {ScheduleImport::parse('Included: Hotel');throw new RuntimeException('Missing days accepted');}catch(InvalidArgumentException $e){echo "PASS sections alone cannot replace itinerary\n";}
