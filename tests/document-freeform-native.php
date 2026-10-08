<?php
declare(strict_types=1);
// Fresh local database only; includes the existing document/costing regression once.
require __DIR__.'/document-cost-native.php';
$issuedSnapshots=v2q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll();$bookings=v2q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll();$source=v2v();
$cloned=QuoteReuse::clone($db,$user,(int)$source['id'],(int)$source['inquiry_id'],'FULL_DRAFT');$qvid=(int)$cloned['version_id'];
$before=v2v();$schedule=$before['schedule_json'];$costs=v2q('SELECT l.* FROM quote_variant_cost_lines l JOIN quote_option_variants c ON c.id=l.variant_id JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? ORDER BY l.id',[$qvid])->fetchAll();$prices=v2q('SELECT c.* FROM quote_option_variants c JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? ORDER BY c.id',[$qvid])->fetchAll();
$legacy=QuoteProposal::config($source)['document']??null;$document=['schema'=>'VTA_DOC_2','title'=>'Independent letter','content'=>['type'=>'doc','content'=>[['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>'Owner letter']]],['type'=>'paragraph','content'=>[['type'=>'text','text'=>'  Letter text with preserved spaces  ','marks'=>[['type'=>'bold']]]]]]]];if($legacy)$document['legacy']=$legacy;
$body=['expected_revision'=>(int)$before['costing_revision'],'settings'=>(QuoteProposal::config($before)??QuoteProposal::settings([])),'links'=>[]];$body['settings']['document']=$document;$saved=QuoteProposal::save($db,$user,$qvid,$body);
check($saved['settings']['document']['schema']==='VTA_DOC_2'&&QuoteProposal::context($db,$user,v2v())['settings']===$saved['settings'],'FF native existing proposal API saves and independently reopens unified document');
check(v2v()['schedule_json']===$schedule&&v2q('SELECT l.* FROM quote_variant_cost_lines l JOIN quote_option_variants c ON c.id=l.variant_id JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? ORDER BY l.id',[$qvid])->fetchAll()===$costs&&v2q('SELECT c.* FROM quote_option_variants c JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? ORDER BY c.id',[$qvid])->fetchAll()===$prices,'FF freeform save changes no itinerary, 3/4/5-star lines, rates or selling results');
rejects(fn()=>QuoteProposal::save($db,$user,$qvid,$body),DomainException::class,'FF stale revision blocks overwrite');
$other=$user;$other['company_id']=2;$body['expected_revision']=(int)v2v()['costing_revision'];rejects(fn()=>QuoteProposal::save($db,$other,$qvid,$body),OutOfBoundsException::class,'FF cross-tenant write refused');
$html=ProposalOutput::html(QuoteProposal::publicQuote($db,v2v()),fn($id)=>'test-image');check(str_contains($html,'Owner letter')&&!str_contains($html,'Detailed Itinerary')&&!str_contains($html,'PRIVATE_CANARY'),'FF draft output has only chosen document content and no hidden operational/cost fields');
if($legacy)check($saved['settings']['document']['legacy']===QuoteProposal::document($legacy),'FF typed legacy document remains available after save');
$sourceId=$qvid;$qvid=(int)$source['id'];$locked=QuoteProposal::context($db,$user,v2v());$body['expected_revision']=(int)v2v()['costing_revision'];rejects(fn()=>QuoteProposal::save($db,$user,$qvid,$body),DomainException::class,'FF existing sent/accepted quotation cannot be replaced by freeform save');
check(v2q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll()===$issuedSnapshots&&v2q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll()===$bookings,'FF all historical Sent/Accepted/Booking snapshot bytes unchanged');
$qvid=$sourceId;
// Clone deliberately starts with no offered variants and no rate reviews.
// Complete those existing commercial steps before testing the new document snapshot.
$ffLabels=array_column(QuoteProposal::context($db,$user,$source)['selling_options'],'label');$ffGraph=QuoteVs2Repository::graph($db,v2v());$ffIds=array_map(fn($v)=>(int)$v['id'],array_values(array_filter($ffGraph['variants'],fn($v)=>in_array($v['label'],$ffLabels,true))));
$ffBody=['expected_revision'=>(int)v2v()['costing_revision'],'action'=>'variant','variant_ids'=>$ffIds,'changes'=>['is_offered'=>true]];
QuoteVs2::mutate($db,$user,$qvid,$ffBody,fn($v)=>QuoteVs2::sheet($db,$user,$v,$ffBody),'TEST_FF_OFFER');
SmartCosting::refresh($db,v2v(),[],true);$ffGraph=QuoteVs2Repository::graph($db,v2v());$ffReqs=array_column($ffGraph['requirements'],null,'id');
foreach($ffGraph['variants'] as $ffVariant)if(in_array((int)$ffVariant['id'],$ffIds,true))foreach($ffVariant['lines'] as $ffLine)if(QuoteVs2Domain::applies($ffReqs[$ffLine['requirement_id']],$ffVariant,$ffLine)){
 $ffBody=['expected_revision'=>(int)v2v()['costing_revision'],'review_reason'=>'Reviewed inherited synthetic contract and guest quantities'];
 QuoteVs2::mutate($db,$user,$qvid,$ffBody,fn($v)=>Vs2LineEditor::review($db,$user,$v,(int)$ffVariant['id'],(int)$ffLine['id'],$ffBody),'TEST_FF_REVIEW');
}
check(QuoteVs2Validator::validate($db,v2v())['valid'],'FF freeform document stays compatible with the existing central send validator');
QuoteOptions::approve($db,$user,$qvid,'Reviewed freeform letter and existing prices');$freeformSent=QuoteOptions::send($db,$user,$qvid);$ffPublic=$freeformSent['customer_safe_snapshot'];$ffFrozen=v2q('SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$qvid])->fetch();
check(($ffPublic['presentation']['settings']['document']['schema']??'')==='VTA_DOC_2'&&str_contains($ffPublic['presentation']['public_html'],'Owner letter'),'FF existing Send snapshot freezes the unified document and public HTML');
$body['expected_revision']=(int)v2v()['costing_revision'];rejects(fn()=>QuoteProposal::save($db,$user,$qvid,$body),DomainException::class,'FF new freeform Sent document is immutable');
check(v2q('SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$qvid])->fetch()===$ffFrozen,'FF rejected edits preserve new frozen snapshot bytes');
echo "Freeform native save/safety acceptance complete.\n";