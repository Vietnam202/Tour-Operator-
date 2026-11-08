<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/VtaTourItineraryQuality.php';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function warning(array $result,string $code):bool {return in_array($code,array_column($result['warnings'],'code'),true);}
function word(array $headings):array {
    return ['schema'=>'VTA_DOC_2','title'=>'Verified Master','content'=>[
        'type'=>'doc','content'=>array_map(
            static fn($h)=>['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>$h]]],
            $headings
        )
    ]];
}

// A documented 8D7N itinerary is compared to its existing, saved Word headings.
$days=[];
for($n=1;$n<=8;$n++)$days[]=[
    'day'=>$n,'title'=>'Verified leg '.$n,'description'=>'Existing source',
    'meals'=>'B','overnight'=>$n<8?'Verified City '.$n:''
];
$program=['title'=>'Vietnam Highlights 8D7N','day_count'=>8,'days'=>$days];
$document=word(array_map(static fn($i)=>'Day '.$i.' · Existing destination',range(1,8)));
$inputChecksum=hash('sha256',json_encode([$program,$document],JSON_THROW_ON_ERROR));
$okay=VtaTourItineraryQuality::review($program,$document);
check($okay['verdict']==='NO_WARNINGS','valid source-to-Word parity should not warn');
check($okay['source_day_count']===8 && count($okay['document_days'])===8,'day count must be source bound');
check(hash('sha256',json_encode([$program,$document],JSON_THROW_ON_ERROR))===$inputChecksum,'review must not mutate source');

$mismatch=$program;
$mismatch['days']=array_slice($days,0,7);
$review=VtaTourItineraryQuality::review($mismatch,word(['Day 1 · Hanoi','Day 1 · Duplicate','Day 8 · Outside']));
foreach(['SOURCE_DAY_COUNT_CONFLICT','TITLE_DAY_COUNT_CONFLICT','DOCUMENT_DAY_DUPLICATE','DOCUMENT_DAY_OUTSIDE_SOURCE','DOCUMENT_DAY_NOT_FOUND'] as $code){
    check(warning($review,$code),'expected provenance warning '.$code);
}
check($review['verdict']==='REVIEW_REQUIRED','conflicts must not be approved');

// Unconfirmed meals, nights, and missing source never create fictitious data.
$empty=VtaTourItineraryQuality::review(['title'=>'Unknown','days'=>[]]);
check(warning($empty,'SOURCE_DAYS_EMPTY') && $empty['document_checked']===false,'empty source is not generated');
$unconfirmed=VtaTourItineraryQuality::review(['title'=>'3D2N','days'=>[
    ['title'=>'Arrival','meals'=>'','overnight'=>''],
    ['title'=>'Sightseeing','meals'=>'','overnight'=>''],
    ['title'=>'Departure','meals'=>'']
]]);
check($unconfirmed['verdict']==='ADVISORY_ONLY','unconfirmed supplier detail is advisory, not auto blocker');
check(warning($unconfirmed,'DAY_MEALS_UNVERIFIED'),'missing meals explicitly reported');
check(warning($unconfirmed,'DAY_OVERNIGHT_UNVERIFIED'),'missing overnight explicitly reported');

$unsafe=VtaTourItineraryQuality::review(['title'=>'3D2N','days'=>[
    ['title'=>'<script>leak</script>'],['title'=>'Second'],['title'=>'Third']
]],word(['Day 1 <img onerror=alert(1)>']));
check(strpos(json_encode($unsafe,JSON_THROW_ON_ERROR),'leak')===false,'no untrusted source title in warnings');
check($unsafe['schema']==='VTA_ITINERARY_QA_1','versioned opt-in advisory output');

$huge=VtaTourItineraryQuality::review(['title'=>'Oversize','days'=>array_fill(0,125,['title'=>'Existing'])]);
check(warning($huge,'SOURCE_DAY_LIMIT'),'bounded processing for large source');
check($huge['source_day_count']===125,'report actual source count, do not truncate original');
$legacy=VtaTourItineraryQuality::review($program,['schema'=>'VTA_DOC_1','content'=>['type'=>'doc']]);
check(warning($legacy,'DOCUMENT_SCHEMA_NOT_SUPPORTED'),'do not reinterpret or rewrite legacy docs');

echo "PASS M01 read-only itinerary QA: 8D7N parity, stale source, duplicated/outside days, no fictional meals, no mutation, bounded review\n";
