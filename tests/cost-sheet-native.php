<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/Http.php';require_once __DIR__.'/../api/lib/Auth.php';require_once __DIR__.'/../api/lib/Audit.php';
require_once __DIR__.'/../api/lib/QuoteOptions.php';require_once __DIR__.'/../api/lib/CoreOS.php';
require_once __DIR__.'/../api/lib/RateEngine.php';require_once __DIR__.'/../api/lib/Procurement.php';require_once __DIR__.'/../api/lib/QuoteReuse.php';
function sheetCheck(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$output=getenv('VS22_OUTPUT_DIR')?:throw new RuntimeException('Run document-cost-native.php first and set VS22_OUTPUT_DIR');
$f=json_decode(file_get_contents($output.'/ux-fixture.json'),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^vta_test_[a-f0-9]{10}$/D',$f['database']))throw new RuntimeException('Disposable database required');
$db=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$f['database'].';charset=utf8mb4','root',getenv('VTA_TEST_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$u=['id'=>1,'company_id'=>1];
$q=fn($sql,$args=[])=>QuoteVs2Repository::q($db,$sql,$args);
$source=QuoteOptions::version($db,1,(int)$f['version_id']);$before=$q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll();
$clone=QuoteReuse::clone($db,$u,(int)$source['id'],(int)$source['inquiry_id'],'FULL_DRAFT');$id=(int)$clone['version_id'];
$get=fn()=>QuoteOptions::version($db,1,$id);
$cmd=fn($b)=>QuoteVs2::mutate($db,$u,$id,['expected_revision'=>$get()['costing_revision']],fn($v)=>QuoteVs2::sheet($db,$u,$v,$b),'UX_MATCH_TEST');
$ids=$cmd(['action'=>'init','mode'=>'PRIVATE'])['variant_ids'];
$q("UPDATE rate_versions rv JOIN rates r ON r.id=rv.rate_id SET rv.valid_to='2026-01-01' WHERE r.company_id=1 AND r.category='VISA'");
$make=function(string $amount)use($db,$q,$u){$key='UX-VISA-'.bin2hex(random_bytes(5));$q("INSERT INTO rates(company_id,rate_ref,supplier_id,category,product_name,rate_type,source_origin,status,created_by,updated_by) VALUES(1,?,1,'VISA',?,'MANUAL_OVERRIDE','MANUAL','ACTIVE',1,1)",[$key,$key]);$rid=(int)$db->lastInsertId();$q("INSERT INTO rate_versions(rate_id,version_no,amount,currency,rate_basis,tax_basis,approval_status,valid_from,valid_to,created_by) VALUES(?,1,?,'VND','PER_PAX','NET','APPROVED','2026-01-01','2028-12-31',1)",[$rid,$amount]);$rate=(int)$db->lastInsertId();Vs2RateTerms::save($db,$u,$rate,['formula_code'=>'VISA_PAX','rate_eligibility_source'=>'TOTAL_GUESTS','basis_evidence'=>['evidence'=>'Synthetic approved supplier contract'],'publish'=>true]);return $rate;};
$approved=$make('175000');
$req=['category'=>'VISA','service_name'=>'Visa A','service_date'=>$get()['start_date'],'service_units'=>1,'default_quantity_source'=>'VISA_PAX','service_mode'=>'BOTH','requirement_state'=>'REQUIRED','scope'=>['destination'=>'Hanoi']];
$first=$cmd(['variant_ids'=>$ids,'requirement'=>$req,'line'=>[]]);$lines=$q('SELECT * FROM quote_variant_cost_lines WHERE requirement_id=? ORDER BY variant_id',[$first['requirement_id']])->fetchAll();$lines=array_values(array_filter($lines,fn($l)=>in_array((int)$l['variant_id'],$ids)));
sheetCheck(count($lines)===3&&count(array_filter($lines,fn($l)=>(int)$l['rate_version_id']===$approved))===3,'UX unique approved rate automatically selected for all three variants');
sheetCheck(!$first['warnings'],'UX expired unrelated rate does not block an eligible unique contract');
$make('200000');$req['service_name']='Visa B';$second=$cmd(['variant_ids'=>$ids,'requirement'=>$req,'line'=>[]]);
sheetCheck(count($second['warnings'])===3&&array_unique(array_column($second['warnings'],'code'))===['RATE_CONFLICT'],'UX multiple approved matches stay unresolved with conflict');
$pending=$q('SELECT * FROM quote_variant_cost_lines WHERE requirement_id=?',[$second['requirement_id']])->fetchAll();sheetCheck(!array_filter($pending,fn($l)=>$l['unit_amount_original']!==null||$l['rate_version_id']!==null),'UX ambiguity never invents or selects a supplier price');
$q("UPDATE rates SET category='OTHER' WHERE category='VISA' AND company_id=1");$req['service_name']='Visa missing';$third=$cmd(['variant_ids'=>$ids,'requirement'=>$req,'line'=>[]]);sheetCheck(array_unique(array_column($third['warnings'],'code'))===['RATE_NEEDED'],'UX missing contract returns rate-needed');
$old=$get();try{QuoteVs2::mutate($db,$u,$id,['expected_revision'=>0],fn($v)=>[],'STALE');throw new RuntimeException('Accepted stale sheet');}catch(DomainException $e){sheetCheck($e->getMessage()==='STALE_REVISION','UX stale sheet revision rejected');}
$cmd(['action'=>'remove','variant_ids'=>$ids,'requirement_id'=>$first['requirement_id'],'reason'=>'Synthetic removal review']);sheetCheck($q('SELECT requirement_state FROM quote_service_requirements WHERE id=?',[$first['requirement_id']])->fetchColumn()==='NOT_APPLICABLE','UX remove retains requirement with evidence');
sheetCheck($q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll()===$before,'UX rate matching and removal never rewrite historical sent rows');
echo "Cost Sheet rate matching acceptance complete.\n";
