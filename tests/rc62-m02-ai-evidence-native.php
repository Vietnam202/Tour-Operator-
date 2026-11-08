<?php
declare(strict_types=1);

/** RC6.2 M02 — native real validator + tenant-scoped opt-in route fixture.
 * Uses disposable in-memory SQLite; NO provider, network, session issuance,
 * customer data, supplier rate, Master write or AI draft creation.
 */
final class SyntheticResponse extends RuntimeException {
    public function __construct(public array $payload, public int $status){
        parent::__construct((string)($payload['error']??'OK'));
    }
}
final class Http {
    public static array $request=[];
    public static function body():array{return self::$request;}
    public static function json(array $payload,int $status=200):never{
        throw new SyntheticResponse($payload,$status);
    }
}
final class Auth {
    public static ?array $session=null;
    public static array $permissions=[];
    public static bool $validCsrf=true;
    public static function requireUser(PDO $db):array{
        if(self::$session===null)Http::json(['error'=>'AUTH_REQUIRED'],401);
        return self::$session;
    }
    public static function can(PDO $db,int $id,string $permission):bool{
        return in_array($permission,self::$permissions[$id]??[],true);
    }
    public static function requirePermission(PDO $db,array $u,string $permission):void{
        if(!self::can($db,(int)$u['id'],$permission))Http::json(['error'=>'FORBIDDEN'],403);
    }
    public static function checkCsrf():void{
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!self::$validCsrf)
            Http::json(['error'=>'CSRF_REJECTED'],403);
    }
}
require_once __DIR__.'/../api/lib/ScheduleImport.php';
require_once __DIR__.'/../api/lib/TourLibrary.php';
require_once __DIR__.'/../api/lib/VtaAdvancedQualityApi.php';

function okay(bool $yes,string $message):void{if(!$yes)throw new RuntimeException('M02 FAILED: '.$message);}
function code(array $warnings,string $needle):bool{return in_array($needle,array_column($warnings,'code'),true);}
function route(PDO $db,string $path,string $method,array $caller,array $body):SyntheticResponse{
    $_SERVER['REQUEST_METHOD']=$method;
    Http::$request=$body;
    try{VtaAdvancedQualityApi::handle($path,$method,$db,$caller);}
    catch(SyntheticResponse $response){return $response;}
    throw new RuntimeException('Unexpected route fallthrough');
}
function p(string $value):array{
    return ['type'=>'paragraph','content'=>[['type'=>'text','text'=>$value]]];
}
function makeSource(int $id,int $company):array{
    $days=ScheduleImport::normalize([
        ['title'=>'Hanoi City','date'=>'2027-01-01','description'=>'Hanoi city walk','meals'=>'B','overnight'=>'Hanoi'],
        ['title'=>'Halong Bay','date'=>'2027-01-02','description'=>'Day cruise','meals'=>'B','overnight'=>''],
    ]);
    $document=['schema'=>'VTA_DOC_2','title'=>'Vietnam 2D1N','content'=>['type'=>'doc','content'=>[
        ['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>'Day 1 - Hanoi City']]],
        p('Confirmed as illustrative source wording only'),
        ['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>'Day 2 - Halong Bay']]],
        p('No tickets, availability or supplier guarantee'),
    ]]];
    $prices=array_map(static fn(int $pax)=>['min'=>$pax,'max'=>$pax,'three'=>'','four'=>'','five'=>''],[4,6,8,10,12]);
    return [
        'id'=>$id,'company_id'=>$company,'title'=>'Vietnam 2D1N','status'=>'ACTIVE',
        'tags_json'=>'[]','days_json'=>json_encode($days,JSON_THROW_ON_ERROR),
        'proposal_json'=>json_encode(['document'=>$document,'tour_type'=>'PRIVATE',
            'duration_days'=>2,'private_prices'=>$prices],JSON_THROW_ON_ERROR),
        'destination'=>'Hanoi, Halong','language'=>'en','source_type'=>'TEXT',
        'source_name'=>'synthetic-source.txt','source_text'=>'Synthetic source only',
        'included_text'=>'','excluded_text'=>'','terms_text'=>'',
    ];
}
function request(array $source):array{
    $doc=json_decode($source['proposal_json'],true,512,JSON_THROW_ON_ERROR)['document'];
    $days=json_decode($source['days_json'],true,512,JSON_THROW_ON_ERROR);
    $days[1]['meals']='B/L';
    $days[1]['description']='Proposed early transfer and lunch, subject to verification.';
    return [
        'schema'=>VtaAiItinerary::SCHEMA,
        'source_program_id'=>(int)$source['id'],
        'source_revision'=>TourProgramWorkspace::revision($source),
        'currency'=>'USD',
        'title'=>'Vietnam 2D1N',
        'document'=>$doc,
        'days'=>$days,
        'private_groups'=>VtaAiItinerary::defaultGrid(),
        'selling_prices_reviewed'=>false,
        'agent'=>'chatgpt',
        'layout'=>'brochure',
    ];
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE tour_library_programs(
    id INTEGER PRIMARY KEY,company_id INTEGER,title TEXT,status TEXT,
    tags_json TEXT,days_json TEXT,proposal_json TEXT,destination TEXT,
    language TEXT,source_type TEXT,source_name TEXT,source_text TEXT,
    included_text TEXT,excluded_text TEXT,terms_text TEXT)');
$insert=$db->prepare('INSERT INTO tour_library_programs(
    id,company_id,title,status,tags_json,days_json,proposal_json,destination,
    language,source_type,source_name,source_text,included_text,excluded_text,terms_text)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$fields=['id','company_id','title','status','tags_json','days_json','proposal_json','destination',
'language','source_type','source_name','source_text','included_text','excluded_text','terms_text'];
foreach([[31,9],[32,10]] as [$id,$company]){
    $src=makeSource($id,$company);
    $insert->execute(array_map(static fn($name)=>$src[$name],$fields));
}
$source=makeSource(31,9);
$body=request($source);
$before=json_encode($source,JSON_THROW_ON_ERROR);
$validated=VtaAiItinerary::validate($body,$source);
okay($validated['changed']['days']===true && count($validated['private_groups'])===5,
    'Existing generator validator accepts valid source-bound candidate with five pax rows');
okay(VtaAiItinerary::LAYOUTS===['detailed','brochure','quick','b2b'],
    'Original four layouts unaffected');
$public=TourLibrary::publicProgram($source,true);
$review=[
    'source_program_id'=>31,'source_revision'=>TourProgramWorkspace::revision($source),
    'change_reasons'=>[],
    'evidence'=>[],
];
$report=VtaAiEvidenceReview::inspect($public,$validated,$review);
okay($report['state']==='ADVISORY_ONLY_NO_REVIEW_TOKEN_NO_COMMIT','M02 cannot issue original approval token');
okay($report['verdict']==='REVIEW_REQUIRED' && $report['changed_fields']===2,
    'Only 2 edited day fields; no invented title/Word/price differences');
okay($report['changes_needing_explanation_or_evidence']===2,'missing explanation and sources require review');
okay(code($report['warnings'],'MEALS_NOT_VERIFIED_BY_A_SUPPLIER'),'meal promise requires real confirmation');
okay(code($report['warnings'],'NO_VERIFIED_PRICE_KEEP_ON_REQUEST'),'null groups remain On request');
okay($report['private_group_pax']===[4,6,8,10,12],'private pax bands retained 4/6/8/10/12');
okay(!str_contains(json_encode($report),'Proposed early transfer')&&!str_contains(json_encode($report),'Hanoi city walk'),
    'diff contains only changed path, not raw guest/itinerary text');
$check=hash('sha256',$before);
okay($check===hash('sha256',json_encode($source,JSON_THROW_ON_ERROR)),
    'Original program unmodified by validation and review');
$reasons=[
    ['path'=>'day:2:meals','code'=>'SUPPLIER_UPDATE','note'=>'New meal is requested, no supplier confirmation yet.'],
    ['path'=>'day:2:description','code'=>'ROUTE_OPTIMIZATION','note'=>'Avoid repeated transfers and review actual travel feasibility.'],
];
$evidence=[
    ['path'=>'day:2:meals','source_kind'=>'SUPPLIER_DOCUMENT','reference'=>'SYNTHETIC-DOC-73',
        'source_sha256'=>str_repeat('a',64)],
    ['path'=>'day:2:description','source_kind'=>'OPERATOR_NOTE','reference'=>'SYNTHETIC-REVIEW-12',
        'source_sha256'=>str_repeat('b',64)],
];
$review['change_reasons']=$reasons;$review['evidence']=$evidence;
$documented=VtaAiEvidenceReview::inspect($public,$validated,$review);
okay($documented['changes_needing_explanation_or_evidence']===0,
    'specific reason/evidence references mapped to exact changed fields');
okay(code($documented['warnings'],'EVIDENCE_REQUIRES_INDEPENDENT_VERIFICATION'),
    'self-declared proof NEVER becomes verified supplier/official evidence');
okay(!isset($documented['review_token'])&&!isset($documented['creation_key']),
    'not a preview/commit token generator');
// Explicitly verify paragraph/block-level edits inside the existing
// editable Word/Tiptap document, with no paragraph text in the report.
$wordEdited=$body;
$wordEdited['document']['content']['content'][1]['content'][0]['text']='Changed illustrative paragraph, no supplier facts asserted.';
$validatedWord=VtaAiItinerary::validate($wordEdited,$source);
$wordReport=VtaAiEvidenceReview::inspect($public,$validatedWord,[
    'source_program_id'=>31,'source_revision'=>TourProgramWorkspace::revision($source),
]);
okay(in_array('word:document',array_column($wordReport['changes'],'path'),true),
    'whole saved Word document changed');
okay(in_array('word:block:2',array_column($wordReport['changes'],'path'),true),
    'specific Word paragraph changed at block 2');
okay(code($wordReport['warnings'],'WORD_BLOCK_DIFF_IS_POSITIONAL_NOT_VISUAL_PARITY'),
    'paragraph-level diff does not imply Word/PDF pixel parity');
okay(!str_contains(json_encode($wordReport),'Changed illustrative paragraph'),
    'Word text remains absent from safe diff response');
okay(isset($wordReport['master_public_snapshot_sha256'])
    && !isset($wordReport['source_sha256']),
    'Master JSON digest is not misrepresented as original uploaded file hash');
$bad=$review;$bad['change_reasons'][]=$reasons[0];
try{VtaAiEvidenceReview::inspect($public,$validated,$bad);throw new RuntimeException('Duplicate reasons wrongly accepted');}
catch(InvalidArgumentException $e){okay(str_contains($e->getMessage(),'Duplicate'),'reason dedup is fail closed');}
$stale=$body;$stale['source_revision']=str_repeat('0',64);
try{VtaAiItinerary::validate($stale,$source);throw new RuntimeException('Stale source wrongly accepted');}
catch(DomainException $e){okay(str_contains($e->getMessage(),'STALE_REVISION'),'original revision gate retained');}
$unreviewed=$body;$unreviewed['private_groups'][1]['prices']['3star']='125.00';
try{VtaAiItinerary::validate($unreviewed,$source);throw new RuntimeException('Unapproved selling price accepted');}
catch(DomainException $e){okay(str_contains($e->getMessage(),'approval'),'unreviewed AI price blocked');}
$priced=$unreviewed;$priced['selling_prices_reviewed']=true;
$validatedPrice=VtaAiItinerary::validate($priced,$source);
$priceAudit=VtaAiEvidenceReview::inspect($public,$validatedPrice,['source_program_id'=>31,
    'source_revision'=>TourProgramWorkspace::revision($source)]);
okay(code($priceAudit['warnings'],'SELLING_PRICES_NOT_VERIFIED_AGAINST_SUPPLIER_OR_FX'),
    'even operator-acknowledged synthetic USD cannot assert supplier/FX truth');
okay(count(array_filter($priceAudit['changes'],fn($c)=>str_starts_with($c['path'],'price:')))>0,
    'price differences highlighted without revealing amount');

$user=['id'=>501,'company_id'=>9];
Auth::$session=$user;
Auth::$permissions[501]=['tour_library.manage'];
putenv(VtaAdvancedQualityApi::FLAG);
putenv('VTA_RC62_AI_EVIDENCE_REVIEW');
okay(route($db,'quality/tour-programs/31/ai-evidence','POST',$user,$body)->status===404,
    'M02 disabled when quality bridge flag OFF');
putenv(VtaAdvancedQualityApi::FLAG.'=1');
okay(route($db,'quality/tour-programs/31/ai-evidence','POST',$user,$body)->status===404,
    'M02 independently disabled when its own flag OFF');
putenv('VTA_RC62_AI_EVIDENCE_REVIEW=1');
Auth::$validCsrf=false;
okay(route($db,'quality/tour-programs/31/ai-evidence','POST',$user,$body)->status===403,
    'POST requires CSRF');
Auth::$validCsrf=true;
Auth::$permissions[501]=[];
okay(route($db,'quality/tour-programs/31/ai-evidence','POST',$user,$body)->status===403,
    'tour_library.manage permission required');
Auth::$permissions[501]=['tour_library.manage'];
okay(route($db,'quality/tour-programs/31/ai-evidence','GET',$user,$body)->status===405,
    'GET cannot run M02 JSON review');
okay(route($db,'quality/tour-programs/32/ai-evidence','POST',$user,$body)->status===404,
    'other company source ID denied');
$badCompany=$user;$badCompany['company_id']=10;
okay(route($db,'quality/tour-programs/31/ai-evidence','POST',$badCompany,$body)->status===401,
    'forged company context rejected against authenticated session');
Auth::$session=null;
okay(route($db,'quality/tour-programs/31/ai-evidence','POST',$user,$body)->status===401,
    'anonymous request refused');
Auth::$session=$user;
$ok=route($db,'quality/tour-programs/31/ai-evidence','POST',$user,$body);
okay($ok->status===200&&($ok->payload['module']??'')==='M02',
    'tenant-scoped authorized advisory works');
okay(($ok->payload['quality']['state']??'')==='ADVISORY_ONLY_NO_REVIEW_TOKEN_NO_COMMIT',
    'authenticated response cannot authorize draft');
$bodyWithClaims=$body;
$bodyWithClaims['change_reasons']=$reasons;
$bodyWithClaims['evidence']=$evidence;
$withClaims=route($db,'quality/tour-programs/31/ai-evidence','POST',$user,$bodyWithClaims);
okay($withClaims->status===200 && $withClaims->payload['quality']['changes_needing_explanation_or_evidence']===0,
    'end-to-end request maps claims to exact changed fields');
$after=$db->query('SELECT days_json,proposal_json FROM tour_library_programs WHERE id=31')->fetch(PDO::FETCH_ASSOC);
okay($after['days_json']===$source['days_json']&&$after['proposal_json']===$source['proposal_json'],
    'end-to-end M02 review leaves Master Word, prices and days byte-identical');
okay((int)$db->query('SELECT COUNT(*) FROM tour_library_programs')->fetchColumn()===2,
    'no draft/auto-publish created by M02');
putenv(VtaAdvancedQualityApi::FLAG);
putenv('VTA_RC62_AI_EVIDENCE_REVIEW');
echo "PASS M02 AI source-linked evidence: exact day/Word/price diff, reasons, unverified source refs, source SHA, real VtaAiItinerary validation, 4 layouts/5 pax, session/RBAC/CSRF/tenant, no mutation or provider calls\n";
