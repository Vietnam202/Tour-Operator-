<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MarketingTourAdvisor.php';
require __DIR__.'/website-chat-delivery-db-test.php';

echo PHP_EOL."VTA Tour Advisor P3 integration tests".PHP_EOL;
function programDays(string $city):string {
    return json_encode([['title'=>'Arrival '.$city],['title'=>'City tour '.$city],['title'=>'Departure '.$city]],JSON_THROW_ON_ERROR);
}
// Existing P2 test fixture builds disposable companies, users, campaign and chat tables.
$programs=[
 [100,1,'ACTIVE','Hanoi – Ha Long – Ninh Binh','Hanoi, Ha Long, Ninh Binh','en',programDays('Hanoi')],
 [101,1,'ACTIVE','Da Nang – Hoi An','Da Nang, Hoi An','en',programDays('Da Nang')],
 [200,2,'ACTIVE','Phu Quoc Discovery','Phu Quoc','en',programDays('Phu Quoc')],
 [300,1,'DRAFT','Draft Confidential Cruise','Ha Long','en',programDays('Ha Long')]
];
$st=$db->prepare('INSERT INTO tour_library_programs(id,company_id,status,title,destination,language,tags_json,days_json) VALUES(?,?,?,?,?,?,?,?)');
foreach($programs as $p)$st->execute([$p[0],$p[1],$p[2],$p[3],$p[4],$p[5],'[]',$p[6]]);

$conv=(int)$first['conversation_id'];
$team=['company_id'=>1,'id'=>1];
$managerListings=MarketingTourAdvisor::programs($db,$team,$conv,'Ha Long',true);
checked(count($managerListings)===1,'manager sees matching ACTIVE programs but not DRAFT or other-tenant records');
$publicListings=MarketingTourAdvisor::programs($db,$team,$conv,'',false);
checked(count($publicListings)===0,'Marketing cannot share ACTIVE tours without separate approval');
$target=array_values(array_filter($managerListings,fn($p)=>$p['id']===100))[0];
$approved=MarketingTourAdvisor::approve($db,$team,['program_id'=>100,'review_hash'=>$target['review_hash']]);
checked($approved['approved']===true,'explicit public-share approval allowed');
$visible=MarketingTourAdvisor::programs($db,$team,$conv,'',false);
checked(count($visible)===1&&$visible[0]['id']===100,'unapproved and other-tenant tours excluded from replies');
$notMine=false;
try{MarketingTourAdvisor::approve($db,$team,['program_id'=>200,'review_hash'=>str_repeat('a',64)]);}
catch(OutOfBoundsException $e){$notMine=true;}
checked($notMine,'cannot approve another tenant program');

$input=['conversation_id'=>$conv,'program_id'=>100,'language'=>'en','request_key'=>'tour_response_key_00000001'];
$reply=MarketingTourAdvisor::draft($db,$team,$input);
checked($reply['id']>0&&!$reply['replayed'],'approved tour draft stored');
checked(str_contains($reply['text'],'Hanoi')&&str_contains($reply['text'],'subject')===false,'English sample itinerary generated from real metadata');
checked(!str_contains(strtolower($reply['text']),'net rate')&&!str_contains(strtolower($reply['text']),'supplier'),'no supplier cost exposed');
$replay=MarketingTourAdvisor::draft($db,$team,$input);
checked($replay['replayed']===true&&$replay['id']===$reply['id'],'draft idempotency');
$changedKey=false;
try{MarketingTourAdvisor::draft($db,$team,array_replace($input,['program_id'=>101]));}
catch(DomainException $e){$changedKey=true;}
checked($changedKey,'draft request key rejects different tour');

$vi=MarketingTourAdvisor::draft($db,$team,array_replace($input,['language'=>'vi','request_key'=>'tour_response_key_00000002']));
checked(str_contains($vi['text'],'Xin chào')&&str_contains($vi['text'],'Ngày 1'),'Vietnamese tour draft works');

$db->prepare("UPDATE social_conversations SET status='NEW' WHERE company_id=1 AND id=?")->execute([$conv]);
$sendInput=['conversation_id'=>$conv,'request_key'=>'approved_send_0000000001','body'=>$reply['text'],'tour_advisor_draft_id'=>$reply['id']];
$queued=WebsiteChatDelivery::send($db,$team,$sendInput);
checked($queued['status']==='QUEUED','staff can explicitly queue a reviewed tour draft');
$wrongBody=false;
try{WebsiteChatDelivery::send($db,$team,array_replace($sendInput,['request_key'=>'approved_send_0000000002','body'=>'Modified brochure with unreviewed claims']));}
catch(DomainException $e){$wrongBody=true;}
checked($wrongBody,'edited response cannot masquerade as approved tour draft');
$otherConv=(int)$other['conversation_id'];
$crossDraft=false;
try{MarketingTourAdvisor::validateDraftForSend($db,1,$otherConv,(int)$reply['id'],$reply['text']);}
catch(DomainException $e){$crossDraft=true;}
checked($crossDraft,'approved draft cannot be reused for another conversation');
$crossTenant=false;
try{MarketingTourAdvisor::validateDraftForSend($db,2,$cross['conversation_id'],(int)$reply['id'],$reply['text']);}
catch(DomainException $e){$crossTenant=true;}
checked($crossTenant,'draft tenant isolation');

$db->exec("UPDATE tour_library_programs SET title='Changed itinerary title' WHERE id=100");
$stale=false;
try{MarketingTourAdvisor::validateDraftForSend($db,1,$conv,(int)$reply['id'],$reply['text']);}
catch(DomainException $e){$stale=true;}
checked($stale,'tour edit invalidates sharing approval and previous draft');
$draftDenied=false;
try{MarketingTourAdvisor::draft($db,$team,array_replace($input,['request_key'=>'tour_response_key_00000003']));}
catch(DomainException $e){$draftDenied=true;}
checked($draftDenied,'no new drafts from stale ACTIVE tours');
$notApproved=MarketingTourAdvisor::programs($db,$team,$conv,'Ha Long',false);
checked(count($notApproved)===0,'stale tour removed from customer-facing search');
$afterQueued=WebsiteChatDelivery::relay($db,1,'site-main',[
 'action'=>'pull','timestamp'=>time(),'external_conversation_id'=>'visitor_site_000001',
 'after_id'=>(int)$sent['id'],'message_ids'=>[]
]);
checked(count($afterQueued['messages'])===0,'stale approved tour is not delivered by website relay');
$status=$db->prepare('SELECT status FROM website_chat_outbound WHERE company_id=1 AND id=?');
$status->execute([$queued['id']]);
checked($status->fetchColumn()==='BLOCKED','stale queued reply moves to BLOCKED state');
echo "P3 Tour Advisor MariaDB integration tests completed.".PHP_EOL;
