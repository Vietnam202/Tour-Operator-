<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/QuoteOptions.php';
require_once __DIR__.'/../api/lib/DocumentParser.php';
require_once __DIR__.'/../api/lib/ScheduleImport.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
function rejects(callable $fn,string $class,string $name):void{try{$fn();}catch(Throwable $e){check($e instanceof $class,$name);return;}throw new RuntimeException('FAIL '.$name);}
$text="# Vietnam 7D6N\nTour type: PRIVATE\nGuests: 16 adults + 1 FOC\nDuration: 7D6N\nSchedule\n";
for($i=1;$i<=7;$i++)$text.="Day $i — Journey $i (B/L/D)\n";
$text.="Detailed Itinerary\n";for($i=1;$i<=7;$i++)$text.="Day $i — Journey $i (B/L/D)\nOvernight: Hanoi\nMorning **visit** and *relax*.\n- Reviewed activity\n";
$text.="Included\n- Accommodation\nExcluded\n- Flights\nChildren Policy\nSupplier-specific policy\nBooking / Payment Terms\nPay as agreed\nCancellation Policy\nContract applies\nTerms & Conditions\nReviewed terms\nPrice\nReference only 999 USD\n";
$parsed=ScheduleImport::documentPreview($text);check(count($parsed['days'])===7&&$parsed['source_numbers']===range(1,7),'UX paste detects seven detailed days without duplicate schedule days');
check($parsed['days'][0]['meals']==='B/L/D'&&isset($parsed['sections']['included'],$parsed['sections']['excluded'],$parsed['sections']['payment'],$parsed['sections']['children'],$parsed['sections']['cancellation']),'UX meals and public policies classified');
check(str_contains(QuoteProposal::blockText($parsed['days'][0]['blocks']),'visit'),'UX editable typed content preserves prose');
$duplicate=ScheduleImport::documentPreview(str_replace('Day 7','Day 6',$text));check((bool)array_filter($duplicate['warnings'],fn($s)=>str_contains($s,'numbering')),'UX duplicate day flagged without silent correction');
rejects(fn()=>QuoteProposal::richBlocks([['type'=>'html','text'=>'<script>']]),InvalidArgumentException::class,'UX uncontrolled HTML rejected');
rejects(fn()=>QuoteProposal::richBlocks([['type'=>'table','rows'=>[[[]],[[],[]]]]]),InvalidArgumentException::class,'UX malformed tables rejected');
