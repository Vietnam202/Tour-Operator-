<?php
declare(strict_types=1);
require __DIR__.'/quote-options.php';
require_once __DIR__.'/../api/lib/CoreOS.php';
require_once __DIR__.'/../api/lib/QuoteReuse.php';
$db->prepare("UPDATE trips SET start_date='2027-01-02',end_date='2027-01-02',adults=10,children=0,infants=0 WHERE id=?")->execute([$conversion['trip_id']]);
$before=$db->query('SELECT * FROM quote_versions WHERE id='.$qvid)->fetch();
$c=QuoteReuse::clone($db,$user,$qvid,$conversion['inquiry_id'],'FULL_DRAFT');
$v=QuoteOptions::version($db,1,$c['version_id']);$options=$db->query('SELECT * FROM quote_options WHERE quote_version_id='.$v['id'])->fetchAll();
check(count($options)===3&&$v['version_status']==='DRAFT','full reuse retains three options as draft');
check((float)$v['total_selling']>0,'cloned option headline pricing recalculated');
rejects(fn()=>QuoteOptions::approve($db,$user,(int)$v['id'],'Try approval'),DomainException::class,'copied option rates block approval');
foreach($options as $o){
 $s=json_decode($o['snapshot_json'],true);
 check($s['review_required']&&count(array_filter($s['lines'],fn($l)=>empty($l['rate_version_id'])&&empty($l['rate_snapshot'])))===count($s['lines']),'option clone strips rate approvals and snapshots');
 $b=['hotel_level'=>$o['hotel_level'],'label'=>$o['label'],'fx_rate'=>$s['fx_rate'],'pricing_mode'=>'MARKUP','pricing_value'=>20,'lines'=>$s['lines']];
 // Even omission of client markers must not silently clear the persisted review gate.
 foreach($b['lines'] as &$l){unset($l['review_required']);$l['reason']='Supplier cost checked';}unset($l);
 QuoteOptions::save($db,$user,(int)$v['id'],$b);
 check(!empty(json_decode($db->query('SELECT snapshot_json FROM quote_options WHERE id='.$o['id'])->fetchColumn(),true)['review_required']),'option save preserves review gate without explicit acknowledgement');
 $b['review_copied']=true;$b['review_reason']='All copied rates checked for target dates';
 QuoteOptions::save($db,$user,(int)$v['id'],$b);
}
QuoteOptions::approve($db,$user,(int)$v['id'],'All target-date manual costs approved');
$sent=QuoteOptions::send($db,$user,(int)$v['id']);
check(!str_contains(json_encode($sent),'rate_snapshot')&&!str_contains(json_encode($sent),'review_reason'),'issued cloned options remain customer-safe');
check($db->query('SELECT * FROM quote_versions WHERE id='.$qvid)->fetch()===$before,'option reuse never rewrites confirmed source');
echo "RC1 option reuse checks complete.\n";


require_once __DIR__.'/../api/lib/TravelDocuments.php';
$raw=$db->query('SELECT public_snapshot_json FROM booking_quote_snapshots WHERE booking_id='.(int)$booking['id'])->fetchColumn();
$legacy=json_decode($raw,true);unset($legacy['document_language']);
$db->prepare('UPDATE booking_quote_snapshots SET public_snapshot_json=? WHERE booking_id=?')->execute([json_encode($legacy),$booking['id']]);
$oldDoc=TravelDocuments::snapshot($db,1,(int)$booking['id'],'FULL_ITINERARY','HIDE_PRICE');
check(!array_key_exists('document_language',$oldDoc),'legacy travel-document snapshot shape preserved without new language key');
$db->prepare('UPDATE booking_quote_snapshots SET public_snapshot_json=? WHERE booking_id=?')->execute([$raw,$booking['id']]);
$newDoc=TravelDocuments::snapshot($db,1,(int)$booking['id'],'FULL_ITINERARY','HIDE_PRICE');
check(($newDoc['document_language']??null)==='en','new travel-document snapshots carry language hook');

