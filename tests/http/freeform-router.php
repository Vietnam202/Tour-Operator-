<?php
declare(strict_types=1);
// Local acceptance fixture only; no database and no staging/prod credentials.
if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true))exit;
require_once __DIR__.'/../../api/lib/QuoteOptions.php';
require_once __DIR__.'/../../api/lib/DocumentParser.php';
require_once __DIR__.'/../../api/lib/ProposalOutput.php';
require_once __DIR__.'/../../api/lib/RuntimeGuard.php';
$dir=getenv('FREEFORM_FIXTURE_DIR')?:throw new RuntimeException('Set private FREEFORM_FIXTURE_DIR');RuntimeGuard::privatePath($dir);
if(!is_dir($dir))mkdir($dir,0770,true);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$route=$_GET['route']??'';
$stateFile=$dir.'/state.json';
if(!is_file($stateFile))file_put_contents($stateFile,MediaLibrary::json(['costing_revision'=>1,'immutable'=>false,'settings'=>QuoteProposal::settings([]),'days'=>[['day'=>1,'day_key'=>'legacy-day','title'=>'Legacy Hanoi','description'=>'Existing itinerary remains separate','date'=>'2027-01-02','meals'=>'B','overnight'=>'Hanoi']],'links'=>[],'selling_options'=>[['label'=>'3 star','hotel_level'=>'3*','cruise_level'=>'3','currency'=>'USD','selling_per_pax'=>'100'],['label'=>'4 star','hotel_level'=>'4*','cruise_level'=>'4','currency'=>'USD','selling_per_pax'=>'150'],['label'=>'5 star','hotel_level'=>'5*','cruise_level'=>'5','currency'=>'USD','selling_per_pax'=>'200']]]));
$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
$respond=function($data){header('Content-Type: application/json');echo MediaLibrary::json($data);exit;};
if($path==='/document-fixture'){
 header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/styles.css"><link rel="stylesheet" href="/document-editor.css"></head><body><div class="app-shell"><aside class="sidebar">Existing sidebar</aside><header class="topbar">App navigation</header><main id="workspace" class="workspace"></main></div><div id="modals"></div><script src="/assets/document-engine.js"></script><script src="/visual-proposal.js"></script><script src="/document-editor.js"></script><script>
 const api={request:async(route,opt={})=>{const res=await fetch("/api/index.php?route="+encodeURIComponent(route),{method:opt.method||"GET",headers:opt.body?{"Content-Type":"application/json"}:{},body:opt.form|| (opt.body?JSON.stringify(opt.body):undefined)});const data=await res.json();if(!res.ok)throw Error(data.message||"API failure");return data;}};
 window.acceptanceReady=window.mountVisualProposal(document.querySelector("main"),{api,quoteData:{quote:{id:2,quote_ref:"LOCAL-ACCEPTANCE"},version:{id:12,version_no:1,version_status:new URLSearchParams(location.search).get("locked")?"SENT":"DRAFT"}},can:()=>true,modal:(title,html)=>{const m=document.createElement("section");m.innerHTML="<h2>"+title+"</h2>"+html;document.querySelector("#modals").replaceChildren(m);return m;},closeModal:()=>{},toast:()=>{},onBack:()=>{}}).then(h=>window.acceptance=h);
 </script></body></html>';exit;
}
try{
 if($route==='quote-versions/12/proposal'){
  if($_SERVER['REQUEST_METHOD']==='PUT'){
   $b=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(($b['expected_revision']??null)!==$state['costing_revision'])throw new DomainException('STALE_REVISION');
   if($state['immutable'])throw new DomainException('IMMUTABLE');if(isset($b['days']))throw new RuntimeException('Freeform save must not mutate itinerary');
   $state['settings']=QuoteProposal::settings($b['settings']);$state['links']=$b['links'];$state['costing_revision']++;file_put_contents($stateFile,MediaLibrary::json($state));
  }
  if($_GET['locked']??false)$state['immutable']=true;$respond($state);
 }
 if($route==='quote-versions/12/proposal/document-import')$respond(FreeformDocument::upload($_FILES['file']??[]));
 if($route==='media/upload'){
  $file=$_FILES['file']??[];if(!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Image upload missing');$info=getimagesize($file['tmp_name']);if(!$info)throw new InvalidArgumentException('Invalid image');$id=count(glob($dir.'/asset-*'))+1;copy($file['tmp_name'],$dir.'/asset-'.$id.'.jpg');$respond(['asset'=>['id'=>$id,'title'=>$file['name'],'width'=>$info[0],'height'=>$info[1]]]);
 }
 if(preg_match('#^media/(\d+)/image$#',$route,$m)){header('Content-Type: image/jpeg');readfile($dir.'/asset-'.$m[1].'.jpg');exit;}
 if(preg_match('#^quote-versions/12/proposal/(html|pdf|docx)$#',$route,$m)){
  $media=[];foreach($state['links'] as $l){$info=getimagesize($dir.'/asset-'.$l['asset_id'].'.jpg');$media[]=$l+['width'=>$info[0],'height'=>$info[1]];}
  $s=['quote_ref'=>'LOCAL-ACCEPTANCE','tour_name'=>'Test document','presentation'=>['settings'=>$state['settings'],'media'=>$media]];
  ProposalOutput::respond($m[1],$s,['storage'=>['local_path'=>$dir.'/storage']],fn($id)=>'/api/index.php?route=media/'.$id.'/image',fn($id)=>$dir.'/asset-'.$id.'.jpg',false);
 }
 if($route!==''){$respond(['error'=>'Not found']);}
}catch(Throwable $e){http_response_code($e instanceof DomainException?409:422);$respond(['message'=>$e->getMessage()]);}
return false;