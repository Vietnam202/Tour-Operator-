<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/VtaPriceMatrixAudit.php';
function must(bool $pass,string $note):void{if(!$pass)throw new RuntimeException($note);}
function issue(array $report,string $code):bool{return in_array($code,array_column($report['warnings'],'code'),true);}

$quote=['id'=>77,'company_id'=>12,'total_guests'=>7,'paying_pax'=>6,'foc'=>1,
    'adults'=>6,'children'=>0,'infants'=>0,'version_status'=>'DRAFT','fx_rate'=>'25400.000000','costing_revision'=>4];
$matrix=['id'=>91,'quote_version_id'=>77,'generation'=>3,'status'=>'LOCKED',
    'dependency_hash'=>str_repeat('a',64),
    'inputs_json'=>json_encode(['graph'=>['version'=>['costing_revision'=>4]]],JSON_THROW_ON_ERROR),
    'fx_snapshot_json'=>json_encode(['value'=>'25400.000000','currency_pair'=>'USD/VND','source'=>'MANUAL'],JSON_THROW_ON_ERROR)];
$result=['method'=>'EXACT_PAX','mode'=>'PRIVATE','hotel_level'=>3,'cruise_level'=>5,
    'scenarios'=>[['pax'=>6,'cost'=>['cost_total_vnd'=>'20000000.00','rate_sources'=>[['rate_version_id'=>17]],'guests'=>['foc'=>1]],
    'pricing'=>['selling_per_pax'=>'199.00']]],'failures'=>[]];
$cell=['id'=>101,'matrix_id'=>91,'generation'=>3,'variant_id'=>3,'channel'=>'B2B',
    'is_selected'=>1,'min_pax'=>6,'max_pax'=>6,'status'=>'VALID',
    'selling_currency'=>'USD','selling_per_pax'=>'199.00','result_json'=>json_encode($result,JSON_THROW_ON_ERROR)];
$context=['company_id'=>12,'review_groups'=>[['channel'=>'B2B','mode'=>'PRIVATE','paying_pax'=>6]]];
$before=hash('sha256',json_encode([$quote,$matrix,$cell,$context],JSON_THROW_ON_ERROR));
$report=VtaPriceMatrixAudit::inspect($quote,$matrix,[$cell],$context);
must($report['verdict']==='ADVISORY_PASS_NOT_OWNER_APPROVAL','a verified candidate may only pass static advisory, never owner');
must($report['selected_cells']===1 && $report['safe_cells'][0]['hotel_level']===3 && $report['safe_cells'][0]['cruise_level']===5,'real 3-star hotel / 5-star cruise scenario preserved');
must($report['safe_cells'][0]['costing_mode']==='PRIVATE','selected private mode preserved');
must(!issue($report,'PAX_SEGMENT_REVIEW_REQUIRED'),'7 total = 6 paying + 1 FOC is allowed');
must(hash('sha256',json_encode([$quote,$matrix,$cell,$context],JSON_THROW_ON_ERROR))===$before,'immutable source snapshot');
$safe=json_encode($report,JSON_THROW_ON_ERROR);
must(!str_contains($safe,'20000000')&&!str_contains($safe,'rate_version_id')&&!str_contains($safe,'199.00'),'do not expose cost, supplier provenance or commercial amount in safe projection');

$other=$context;$other['company_id']=99;
try{VtaPriceMatrixAudit::inspect($quote,$matrix,[$cell],$other);throw new RuntimeException('company access leaked');}
catch(DomainException $e){must($e->getMessage()==='SCOPED_MATRIX_SNAPSHOT_REQUIRED','company mismatch rejected');}
$foreign=$cell;$foreign['matrix_id']=1000;
try{VtaPriceMatrixAudit::inspect($quote,$matrix,[$foreign],$context);throw new RuntimeException('foreign matrix accepted');}
catch(DomainException $e){must($e->getMessage()==='MATRIX_CELL_PARENT_OR_GENERATION_MISMATCH','matrix parent mismatch rejected');}
$stale=$cell;$stale['generation']=2;
try{VtaPriceMatrixAudit::inspect($quote,$matrix,[$stale],$context);throw new RuntimeException('stale matrix generation accepted');}
catch(DomainException $e){must($e->getMessage()==='MATRIX_CELL_PARENT_OR_GENERATION_MISMATCH','stale generation rejected');}

$broken=$quote;$broken['total_guests']=8;
must(issue(VtaPriceMatrixAudit::inspect($broken,$matrix,[$cell],$context),'PAX_BREAKDOWN_CONFLICT'),'8/6/1 must not pass if only 6 adults');
$price=$cell;$price['selling_per_pax']='0.00';
$notFree=VtaPriceMatrixAudit::inspect($quote,$matrix,[$price],$context);
must(issue($notFree,'SELLING_PRICE_MISSING_OR_ZERO')&&$notFree['safe_cells'][0]['price_status']==='ON_REQUEST','zero is On request, never Free');
$fx=$matrix;$fx['fx_snapshot_json']='{"value":"0","source":"UNKNOWN","currency_pair":"USD/VND"}';
must(issue(VtaPriceMatrixAudit::inspect($quote,$fx,[$cell],$context),'FX_SOURCE_UNVERIFIED'),'unknown FX not guessed');
$changedFx=$quote;$changedFx['fx_rate']='26000.000000';
must(issue(VtaPriceMatrixAudit::inspect($changedFx,$matrix,[$cell],$context),'FX_SNAPSHOT_DIFFERS_FROM_QUOTE'),'changed FX must not silently inherit old matrix');
$changedCost=$quote;$changedCost['costing_revision']=5;
must(issue(VtaPriceMatrixAudit::inspect($changedCost,$matrix,[$cell],$context),'COSTING_REVISION_SNAPSHOT_DRIFT'),'changed costing must not be treated as current matrix');
$missingInput=$matrix;unset($missingInput['inputs_json']);
must(issue(VtaPriceMatrixAudit::inspect($quote,$missingInput,[$cell],$context),'COSTING_REVISION_SNAPSHOT_UNVERIFIED'),'missing source snapshot must fail advisory freshness');
$foc=$result;$foc['scenarios'][0]['cost']['guests']['foc']=0;
$bad=$cell;$bad['result_json']=json_encode($foc,JSON_THROW_ON_ERROR);
must(issue(VtaPriceMatrixAudit::inspect($quote,$matrix,[$bad],$context),'SCENARIO_FOC_DRIFT'),'FOC drift flagged');
$unpriced=$result;$unpriced['scenarios'][0]['cost']['rate_sources']=[];
$bad=$cell;$bad['result_json']=json_encode($unpriced,JSON_THROW_ON_ERROR);
must(issue(VtaPriceMatrixAudit::inspect($quote,$matrix,[$bad],$context),'RATE_PROVENANCE_UNVERIFIED'),'unknown supplier rate source flagged');
$scenarios=$context;$scenarios['review_groups']=[];
foreach([4,6,8,10,12] as $pax)$scenarios['review_groups'][]=['channel'=>'B2B','mode'=>'PRIVATE','paying_pax'=>$pax];
$bands=VtaPriceMatrixAudit::inspect($quote,$matrix,[$cell],$scenarios);
must(issue($bands,'REQUIRED_PAYING_PAX_CHANNEL_NOT_COVERED'),'4/6/8/10/12 are optional coverage checks, no pricing is invented');
$none=$cell;$none['is_selected']=0;
must(issue(VtaPriceMatrixAudit::inspect($quote,$matrix,[$none],$context),'NO_SELECTED_PRICE_MATRIX_CELL'),'missing selection not treated as a valid price');
$infants=$quote;$infants['total_guests']=8;$infants['infants']=1;
$infantReport=VtaPriceMatrixAudit::inspect($infants,$matrix,[$cell],$context);
must(!issue($infantReport,'PAX_SEGMENT_REVIEW_REQUIRED')&&!issue($infantReport,'PAX_BREAKDOWN_CONFLICT'),'additional nonpaying infant follows existing QuoteVs2Domain rules');
must(issue($infantReport,'OTHER_NONPAYING_GUESTS_REQUIRE_REVIEW'),'nonpaying infants should prompt review');
must(!method_exists(VtaPriceMatrixAudit::class,'save'),'audit helper is immutable');
echo "PASS M04 read-only PriceMatrix audit: 7/6/1 and infant semantics, selected snapshots, FX/source/FOC parity, optional bands, tenant and data redaction\n";
