<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/ScheduleImport.php';
require_once __DIR__.'/../api/lib/TourLibrary.php';

function ok(bool $condition,string $message):void {
 if(!$condition)throw new RuntimeException('FAILED '.$message);
 echo "PASS $message\n";
}
function rejects(callable $action,string $name):void {
 try{$action();}catch(InvalidArgumentException|DomainException $e){ok(true,$name);return;}
 throw new RuntimeException('FAILED '.$name);
}
function paragraph(string $text):array{return ['type'=>'paragraph','content'=>[['type'=>'text','text'=>$text]]];}
function heading(string $text):array{return ['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>$text]]];}
function doc(string $title,array $texts):array {
 return ['schema'=>'VTA_DOC_2','title'=>$title,'content'=>['type'=>'doc','content'=>array_merge([heading($title)],array_map('paragraph',$texts))]];
}
$baseDoc=doc('Tour 7D6N',['Day 1 Hanoi','Day 2 Halong']);
$source=['id'=>7,'title'=>'Tour 7D6N','destination'=>'Vietnam','language'=>'en','status'=>'ACTIVE','tags_json'=>'[]','days_json'=>json_encode([
 ['title'=>'Hanoi','description'=>'Old Quarter','meals'=>'B','overnight'=>'Hanoi']],JSON_THROW_ON_ERROR),
 'included_text'=>'','excluded_text'=>'','terms_text'=>'','source_type'=>'MANUAL','source_url'=>'','source_name'=>'','source_text'=>'',
 'proposal_json'=>json_encode(['document'=>$baseDoc,'tour_type'=>'SIC','tour_code'=>'VTA7','duration_days'=>7],JSON_THROW_ON_ERROR)
];
$grid=VtaAiItinerary::defaultGrid();
ok(count($grid)===5&&array_column($grid,'pax')===[4,6,8,10,12],'default 5 Private Group rows');
ok(VtaAiItinerary::priceGrid($grid)===$grid,'canonical empty group grid round-trip');
$altered=$grid;$altered[0]['prices']['3star']='250.50';
ok(VtaAiItinerary::priceGrid($altered)[0]['prices']['3star']==='250.50','reviewed selling USD decimals');
$bad=$grid;$bad[1]['pax']=4;rejects(fn()=>VtaAiItinerary::priceGrid($bad),'duplicate pax blocked');
$bad=$grid;$bad[4]['pax']=9;rejects(fn()=>VtaAiItinerary::priceGrid($bad),'unordered pax blocked');
$bad=$grid;$bad[0]['prices']['4star']='-25';rejects(fn()=>VtaAiItinerary::priceGrid($bad),'negative selling price blocked');
$bad=$grid;$bad[0]['prices']['5star']='100.999';rejects(fn()=>VtaAiItinerary::priceGrid($bad),'more than 2 decimal places blocked');
$bad=$grid;$bad[0]['prices']['5star']='1000 NET';rejects(fn()=>VtaAiItinerary::priceGrid($bad),'NET amount in grid blocked');
rejects(fn()=>VtaAiItinerary::priceGrid(array_slice($grid,1)),'must be exactly 5 groups');
$doc=doc('Tour 8D7N',['Day 1 Hanoi','Day 5 Ninh Binh: route not yet confirmed','Day 8 Departure']);
$days=[];for($i=1;$i<=8;$i++)$days[]=['title'=>'Day '.$i,'description'=>'Subject to review','meals'=>'','overnight'=>'','date'=>'','notes'=>''];
$data=['schema'=>VtaAiItinerary::SCHEMA,'source_program_id'=>7,'source_revision'=>TourProgramWorkspace::revision($source),
 'title'=>'Tour 8D7N','document'=>$doc,'days'=>$days,'private_groups'=>$grid,'currency'=>'USD',
 'agent'=>'chatgpt','selling_prices_reviewed'=>false];
$v=VtaAiItinerary::validate($data,$source);
ok(count($v['days'])===8&&$v['changed']['days'],'8D7N structured days validated');
ok($v['source_type']==='SIC'&&str_contains(implode(' ',$v['warnings']),'SIC'),'SIC to Private flagged, not assumed');
ok($source['title']==='Tour 7D6N'&&json_decode($source['days_json'],true)[0]['title']==='Hanoi','source remains immutable on preview');
foreach(VtaAiItinerary::LAYOUTS as $layout){
 $html=VtaAiItinerary::render($v['document'],$v['private_groups'],false,$layout);
 ok(str_contains($html,'mode-'.$layout)&&str_contains($html,'Private Group Selling Price'),'preview includes '.$layout.' and pricing');
 ok(substr_count($html,'On request')>=15,'unapproved prices show On request in '.$layout);
 ok(!str_contains($html,'Supplier NET rate is USD 50')&&!str_contains($html,'Cost price: USD 50'),'preview never exposes supplier NET amounts in '.$layout);
}
$approved=VtaAiItinerary::exportDocument($doc,$altered,true);
$approvedHTML=FreeformDocument::html($approved,static fn($id)=>'photo.jpg');
ok(str_contains($approvedHTML,'USD 250.50')&&str_contains($approvedHTML,'4 pax'),'approved price round-trips into export document');
$unapprovedHTML=FreeformDocument::html(VtaAiItinerary::exportDocument($doc,$altered,false),static fn($id)=>'photo.jpg');
ok(!str_contains($unapprovedHTML,'250.50')&&str_contains($unapprovedHTML,'On request'),'unapproved numeric values hidden in export');
$nonapproved=$data;$nonapproved['private_groups']=$altered;
rejects(fn()=>VtaAiItinerary::validate($nonapproved,$source),'unreviewed prices blocked on proposal');
$approvedData=$nonapproved;$approvedData['selling_prices_reviewed']=true;
ok(VtaAiItinerary::validate($approvedData,$source)['selling_prices_reviewed']===true,'price review acknowledgement accepted');
$stale=$data;$stale['source_revision']=str_repeat('0',64);
rejects(fn()=>VtaAiItinerary::validate($stale,$source),'stale Master revision blocked');
$wrong=$data;$wrong['source_program_id']=999;
rejects(fn()=>VtaAiItinerary::validate($wrong,$source),'wrong Master ID blocked');
$invalid=$data;$invalid['document']=doc('Unsafe',['Supplier NET rate is USD 50']);
rejects(fn()=>VtaAiItinerary::validate($invalid,$source),'NET content blocked');
$invalid=$data;$invalid['document']=doc('Unsafe',['<script>alert(1)</script>']);
$escaped=VtaAiItinerary::validate($invalid,$source);
$view=VtaAiItinerary::render($escaped['document'],$grid,false,'detailed');
ok(!str_contains($view,'<script>')&&str_contains($view,'&lt;script&gt;'),'freeform HTML renders escaped AI content');
$invalid=$data;$invalid['document']['content']['content'][]=['type'=>'image','attrs'=>['assetId'=>3333]];
rejects(fn()=>VtaAiItinerary::validate($invalid,$source),'AI cannot add unknown media asset IDs');
$invalid=$data;$invalid['document']['content']['content'][]=['type'=>'script'];
rejects(fn()=>VtaAiItinerary::validate($invalid,$source),'unknown document node blocked');
echo "AI Itinerary contract checks complete. DB/auth and staging checks are separate gates.\n";
