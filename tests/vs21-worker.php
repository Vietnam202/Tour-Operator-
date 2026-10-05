<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/Http.php';require_once __DIR__.'/../api/lib/Auth.php';require_once __DIR__.'/../api/lib/Audit.php';require_once __DIR__.'/../api/lib/QuoteOptions.php';
if(!preg_match('/^vta_test_[a-f0-9]{10}$/D',$argv[1]??''))throw new RuntimeException('Disposable native fixture only');
$db=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$argv[1].';charset=utf8mb4','root',getenv('VTA_TEST_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
try{$u=['id'=>1,'company_id'=>1];$id=(int)$argv[2];$revision=(int)$argv[3];$action=$argv[4]??'EDIT';$extra=json_decode($argv[5]??'{}',true);
if($action==='SEND'){QuoteOptions::send($db,$u,$id,$revision);echo 'PASS';}
elseif($action==='ACCEPT'){QuoteOptions::confirm($db,$u,$id,(int)$extra['option_id'],(int)$extra['variant_id'],$extra['hash'],$revision);echo 'PASS';}
elseif($action==='BOOK'){$b=QuoteOptions::booking($db,$u,$id,$revision);echo 'BOOK:'.$b['id'];}
else{QuoteVs2::mutate($db,$u,$id,['expected_revision'=>$revision],function($v)use($db){QuoteVs2Repository::q($db,'SELECT SLEEP(0.25)');return [];},'VS21_CONCURRENCY_PROBE');echo 'PASS';}
}catch(DomainException $e){if($e->getMessage()!=='STALE_REVISION')throw $e;echo 'STALE_REVISION';}
