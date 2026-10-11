<?php
declare(strict_types=1);

/**
 * Adds real repository-level MariaDB persistence to the existing single-DB
 * Golden Path fixture. This is synthetic CI, never authorized staging UAT.
 *
 * The imported test recursively provisions a disposable vta_test_* database.
 * NEVER run this suite against a staging, production, or restored clone DB.
 */
require __DIR__.'/rc62-golden-path-continuity-native.php';

$company=(int)$user['company_id'];
$beforeCount=(int)v2q('SELECT COUNT(*) FROM tour_library_programs WHERE company_id=?',[$company])->fetchColumn();

// A reviewed source program may have rich Word content and provenance. Save
// the original first, then pass the same shape as V5 Save-as-Template's UI POST.
$sourceBody=[
    'title'=>'V5 Native Source: Hanoi and Ha Long',
    'destination'=>'Hanoi, Ha Long',
    'language'=>'en',
    'tags'=>['Culture','Cruise'],
    'status'=>'ACTIVE',
    'days'=>[
        ['day'=>1,'date'=>'','title'=>'Hanoi arrival','description'=>'Explore Hanoi Old Quarter',
            'meals'=>'L/D','overnight'=>'Hanoi','notes'=>'Internal meet-and-greet'],
        ['day'=>2,'date'=>'','title'=>'Ha Long','description'=>'Scenic cruise on the bay',
            'meals'=>'B/L/D','overnight'=>'Ha Long','notes'=>'']
    ],
    'included_text'=>'Private guide',
    'excluded_text'=>'Flights',
    'terms_text'=>'Review deposit before sending',
    'source_name'=>'original-itinerary.txt',
    'source_type'=>'TEXT',
    'source_text'=>"Day 1: Hanoi arrival\nDay 2: Ha Long cruise",
    'proposal'=>[
        'tour_code'=>'VTA-V5-NATIVE',
        'tour_type'=>'PRIVATE',
        'overview'=>'Northern Vietnam source program',
        'highlights'=>['Hanoi culture','Ha Long cruise'],
        'group_prices'=>[['hotel'=>'3','price'=>'350','single'=>'80']],
        'private_prices'=>[
            ['min'=>2,'max'=>2,'three'=>'550','four'=>'650','five'=>'750'],
            ['min'=>3,'max'=>4,'three'=>'500','four'=>'600','five'=>'700']
        ],
        'hotels'=>[['destination'=>'Hanoi','three'=>'3-star property','four'=>'4-star property','five'=>'5-star property']],
        'policies'=>['children'=>'Reviewed','payment'=>'Deposit required','cancellation'=>'Reviewed','notes'=>''],
        'document'=>$doc
    ]
];
$sourceSaved=TourLibrary::save($db,$user,$sourceBody);
$sourceId=(int)$sourceSaved['id'];
$sourceBefore=v2q('SELECT * FROM tour_library_programs WHERE company_id=? AND id=?',
    [$company,$sourceId])->fetch();
check((bool)$sourceBefore && $sourceBefore['source_type']==='TEXT'
    && $sourceBefore['source_name']==='original-itinerary.txt'
    && str_contains((string)$sourceBefore['source_text'],'Hanoi arrival'),
    'V5 template: original imported-text provenance is persisted before cloning');

// Mirrors the V5 UI saveAsTemplate request: remove identity/import references,
// do not transfer original source claims, preserve customer-facing content.
$templateBody=$sourceSaved['program'];
$templateBody['title'].=' (Template)';
$templateBody['tags']=array_values(array_unique([...$templateBody['tags'],'VTA_TEMPLATE']));
$templateBody['status']='DRAFT';
$templateBody['source_text']='';
$templateBody['source_name']='';
$templateBody['source_type']='MANUAL';
$templateBody['source_url']='';
unset($templateBody['id'],$templateBody['creation_key'],$templateBody['import_token'],
    $templateBody['has_source']);
$templateSaved=TourLibrary::save($db,$user,$templateBody);
$templateId=(int)$templateSaved['id'];
check($templateId>0 && $templateId!==$sourceId
    && (int)v2q('SELECT COUNT(*) FROM tour_library_programs WHERE company_id=?',[$company])->fetchColumn()
        ===$beforeCount+2,
    'V5 template: real MariaDB creates one independent new program instead of updating master');

$templateReopened=TourLibrary::publicProgram(TourProgramWorkspace::program($db,$user,$templateId));
check($templateReopened['id']===$templateId
    && $templateReopened['status']==='DRAFT'
    && in_array('VTA_TEMPLATE',$templateReopened['tags'],true)
    && $templateReopened['source_type']==='MANUAL'
    && $templateReopened['source_name']===''
    && $templateReopened['source_text']===''
    && $templateReopened['has_source']===false,
    'V5 template: reopen persisted DRAFT and template tag without copying source provenance');

foreach(['days','proposal','included_text','excluded_text','terms_text'] as $field) {
    check($templateReopened[$field]===$sourceSaved['program'][$field],
        'V5 template: reopened '.$field.' retains original approved customer-facing content');
}
check(FreeformDocument::imageIds($templateReopened['proposal']['document'])===[$companyId],
    'V5 template: reopened Word content retains existing approved company image ID');
check(v2q('SELECT * FROM tour_library_programs WHERE company_id=? AND id=?',[$company,$sourceId])->fetch()
        ===$sourceBefore,
    'V5 template: master bytes and source text remain unchanged after Save-as-Template');

// Modify the new template and reload again. The former master, issued quote,
// accepted sent version and booking snapshot must remain byte-for-byte intact.
$editedTemplate=$templateReopened;
$editedTemplate['title']='Independently edited V5 template';
$editedTemplate['days'][0]['description']='Revised reusable Hanoi introduction';
TourLibrary::save($db,$user,$editedTemplate,$templateId);
$editedReopened=TourLibrary::publicProgram(TourProgramWorkspace::program($db,$user,$templateId));
check($editedReopened['title']==='Independently edited V5 template'
    && $editedReopened['days'][0]['description']==='Revised reusable Hanoi introduction',
    'V5 template: edited clone is saved and reopened on actual MariaDB');
check(v2q('SELECT * FROM tour_library_programs WHERE company_id=? AND id=?',[$company,$sourceId])->fetch()
        ===$sourceBefore,
    'V5 template: editing a new template never mutates its original master');

$cloneBeforeInvalid=v2q('SELECT * FROM tour_library_programs WHERE company_id=? AND id=?',
    [$company,$templateId])->fetch();
$overlap=$editedTemplate;
$overlap['proposal']['private_prices'][1]['min']=2;
rejects(fn()=>TourLibrary::save($db,$user,$overlap,$templateId),
    InvalidArgumentException::class,
    'V5 template: backend rejects overlapping passenger bands without writing');
check(v2q('SELECT * FROM tour_library_programs WHERE company_id=? AND id=?',
    [$company,$templateId])->fetch()===$cloneBeforeInvalid,
    'V5 template: invalid overlapping prices leave saved template bytes unchanged');

$foreign=['id'=>(int)$user['id'],'company_id'=>$company+1000];
rejects(fn()=>TourProgramWorkspace::program($db,$foreign,$templateId),
    OutOfBoundsException::class,'V5 template: cross-company read is denied');
rejects(fn()=>TourLibrary::save($db,$foreign,$editedTemplate,$templateId),
    OutOfBoundsException::class,'V5 template: cross-company update is denied');
check(v2q('SELECT * FROM tour_library_programs WHERE company_id=? AND id=?',
    [$company,$templateId])->fetch()===$cloneBeforeInvalid,
    'V5 template: cross-company failures leave template bytes unchanged');
check(v2q('SELECT * FROM booking_quote_snapshots WHERE booking_id=?',[$bookingId])->fetch()
        ===$bookingSnapshot
    && v2q('SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$versionId])->fetch()
        ===$sentBundle,
    'V5 template: saving and revising does not change historical SENT or Booking snapshots');

echo "RC6.2 V5 native template Save/Reopen/Isolation acceptance complete; CI fixture only.\n";
