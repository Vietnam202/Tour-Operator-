<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/QuoteOptions.php';
require_once __DIR__.'/../api/lib/PriceMatrix.php';
function checkGroup(bool $b,string $text):void{if(!$b)throw new RuntimeException('FAIL '.$text);echo 'PASS '.$text.PHP_EOL;}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE quote_price_matrix_cells (id INTEGER PRIMARY KEY,matrix_id INTEGER,generation INTEGER,status TEXT,selling_per_pax TEXT,net_per_pax TEXT,policy_snapshot_json TEXT,result_json TEXT,context_hash TEXT)');
$policy=CommercialPolicy::validate([
 'policy_key'=>'AGENT','name'=>'Test B2B 20%','channel'=>'B2B_AGENT','policy_mode'=>'B2B_MARKUP',
 'pricing_value'=>'20','minimum_margin_pct'=>'10','warning_margin_pct'=>'15','commission_pct'=>'0',
 'deposit_pct'=>'30','rounding_step'=>'0','selling_currency'=>'USD','validity_days'=>14,
 'payment_terms'=>'','cancellation_policy'=>''
]);
$cost='5100000.00';
$result=['scenarios'=>[['pax'=>6,'cost'=>['cost_total_vnd'=>$cost],'pricing'=>[]]],'failures'=>[]];
$hash=str_repeat('a',64);
$db->prepare('INSERT INTO quote_price_matrix_cells(id,matrix_id,generation,status,selling_per_pax,net_per_pax,policy_snapshot_json,result_json,context_hash) VALUES(1,1,1,?,?,?,?,?,?)')
   ->execute(['VALID','240.00','240.00',QuoteVs2Repository::json($policy),QuoteVs2Repository::json($result),$hash]);
$m=['id'=>1,'generation'=>1,'status'=>'DRAFT','config_json'=>QuoteVs2Repository::json(['fx_rate'=>'25500'])];
$fn=new ReflectionMethod(PriceMatrix::class,'manualPrice');
$apply=function(string $price,string $reason)use($fn,$db,$m){
 $fn->invoke(null,$db,['id'=>1],[],$m,['cell_id'=>1,'selling_per_pax'=>$price,'reason'=>$reason]);
 return $db->query('SELECT * FROM quote_price_matrix_cells WHERE id=1')->fetch();
};
$valid=$apply('250.00','B2B supplier review and selling decision');
checkGroup($valid['status']==='VALID'&&$valid['selling_per_pax']==='250.00','RC6 direct markup selling price accepted at safe margin');
checkGroup($valid['context_hash']!==$hash,'RC6 manual selling update changes calculation hash');
$decoded=QuoteVs2Repository::decode($valid['result_json']);
checkGroup($decoded['manual_price']['reason']==='B2B supplier review and selling decision','RC6 reason persisted to internal commercial trace');
$below=$apply('190.00','Negotiated below minimum threshold');
checkGroup($below['status']==='MARGIN_BELOW_POLICY','RC6 below-minimum manual selling price flagged before lock');
try{$apply('0','Invalid zero sale');throw new RuntimeException('FAIL accepted zero sale');}
catch(InvalidArgumentException $e){checkGroup(true,'RC6 zero manual price blocked');}
echo "RC6 direct group selling override tests complete.\n";
