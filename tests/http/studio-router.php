<?php
declare(strict_types=1);
// Loopback-only browser acceptance. File storage exercises the production
// document normalizer/codec/exporter without staging credentials or a database.
if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true))exit;
require_once __DIR__.'/../../api/lib/TourLibrary.php';
require_once __DIR__.'/../../api/lib/ScheduleImport.php';
require_once __DIR__.'/../../api/lib/ProposalOutput.php';
require_once __DIR__.'/../../api/lib/RuntimeGuard.php';
$dir=getenv('FREEFORM_FIXTURE_DIR')?:throw new RuntimeException('Set private FREEFORM_FIXTURE_DIR');RuntimeGuard::privatePath($dir);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$route=$_GET['route']??'';
if($path==='/studio-fixture'){
 header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/styles.css"><link rel="stylesheet" href="/document-editor.css"><link rel="stylesheet" href="/studio-workspace.css"></head><body><div class="app-shell vta-unified-shell"><aside class="sidebar">VTA Workspace</aside><header class="topbar">Local acceptance</header><main id="workspace" class="workspace" data-view="quote"></main></div><div id="modals"></div><script src="/assets/document-engine.js"></script><script src="/document-editor.js"></script><script src="/program-studio.js"></script><script>
 const api={request:async(route,opt={})=>{const split=route.search(/[?&]/),path=split<0?route:route.slice(0,split),query=new URLSearchParams(split<0?"":route.slice(split+1));query.set("route",path);const res=await fetch("/api/index.php?"+query,{method:opt.method||"GET",headers:opt.body?{"Content-Type":"application/json"}:{},body:opt.form||(opt.body?JSON.stringify(opt.body):undefined)});const data=await res.json();if(!res.ok)throw Error(data.message||"API failure");return data;}};
 const modal=(title,html)=>{const m=document.createElement("section");m.className="modal-backdrop";m.innerHTML="<div class=modal><div class=modal-head><h2>"+title+"</h2></div><div class=modal-body>"+html+"</div></div>";document.querySelector("#modals").replaceChildren(m);return m;};
 window.studioEnv={api,modal,closeModal:()=>document.querySelector("#modals").replaceChildren(),toast:()=>{},can:()=>true};
 window.acceptanceReady=window.mountVisualProposal(document.querySelector("main"),{...window.studioEnv,integrated:true,quoteData:{quote:{id:2,quote_ref:"LOCAL-ACCEPTANCE"},version:{id:12,version_no:1,version_status:"DRAFT",tour_name:"VTA Studio acceptance"}},onBack:()=>{}}).then(h=>window.acceptance=h);
 </script></body></html>';exit;
}
if(str_starts_with($route,'tour-library')){
 $respond=function($data){header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;};
 try{
  $store=$dir.'/studio-programs.json';$rows=is_file($store)?json_decode(file_get_contents($store),true,512,JSON_THROW_ON_ERROR):[];
  $method=$_SERVER['REQUEST_METHOD'];$body=$method==='GET'||isset($_FILES['file'])?[]:json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
  if($route==='tour-library/prepare-document')$respond(['document'=>FreeformDocument::validate($body['document'])]);
  if($route==='tour-library/document-import')$respond(FreeformDocument::upload($_FILES['file']??[]));
  if(preg_match('#^tour-library(?:/(\d+))?(?:/(html|docx|pdf))?$#',$route,$match)){
   $id=(int)($match[1]??0);$format=$match[2]??'';
   if($format){$p=TourLibrary::publicProgram($rows[$id-1]);$media=[];foreach(FreeformDocument::imageIds($p['document']) as $image){$info=getimagesize($dir.'/asset-'.$image.'.jpg');$media[]=['asset_id'=>$image,'width'=>$info[0],'height'=>$info[1]];}$snapshot=['quote_ref'=>'PROGRAM-'.$id,'presentation'=>['settings'=>['document'=>$p['document']],'media'=>$media]];ProposalOutput::respond($format,$snapshot,['storage'=>['local_path'=>$dir.'/storage']],fn($id)=>'/api/index.php?route=media/'.$id.'/image',fn($id)=>$dir.'/asset-'.$id.'.jpg',false);}
   if($method==='GET'){
    if($id)$respond(['program'=>TourLibrary::publicProgram($rows[$id-1])]);
    $q=strtolower($_GET['q']??'');$visible=array_values(array_filter($rows,fn($r)=>$q===''||str_contains(strtolower($r['title'].' '.$r['destination']),$q)));$respond(['items'=>array_map(fn($r)=>TourLibrary::publicProgram($r,false),$visible),'total'=>count($visible)]);
   }
   $clean=TourLibrary::normalize($body);if($id&&isset($body['expected_content_hash'])&&!hash_equals(TourLibrary::contentHash($rows[$id-1]),$body['expected_content_hash']))throw new DomainException('STALE_PROGRAM');
   $record=$clean;$record['source_text']=TourLibrary::documentSource($clean['source_text'],$clean['document']??null);unset($record['document'],$record['days'],$record['tags']);$record['days_json']=json_encode($clean['days']);$record['tags_json']=json_encode($clean['tags']);$record['id']=$id?:count($rows)+1;$record['updated_at']=date('Y-m-d H:i:s');
   if($id)$rows[$id-1]=$record;else $rows[]=$record;file_put_contents($store,json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX);$respond(['id'=>$record['id'],'program'=>TourLibrary::publicProgram($record)]);
  }
  throw new OutOfBoundsException('Fixture route unavailable');
 }catch(Throwable $e){http_response_code($e instanceof DomainException?409:422);$respond(['message'=>$e->getMessage()]);}
}
return require __DIR__.'/freeform-router.php';
