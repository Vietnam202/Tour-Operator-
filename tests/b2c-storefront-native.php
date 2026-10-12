<?php
declare(strict_types=1);
// Included after b2c-native-assertions.php; only a synthetic random disposable MariaDB.
require_once __DIR__.'/../api/lib/B2CStorefront.php';
if(!preg_match('/^vta_test_[a-f0-9]{10}$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('DISPOSABLE_STOREFRONT_REQUIRED');
B2CStudio::transition($db,$user,$b2cId,'review',['expected_revision'=>2]);B2CStudio::transition($db,$user,$b2cId,'approve',['expected_revision'=>2,'public_content_reviewed'=>true]);B2CStudio::transition($db,$user,$b2cId,'publish',['expected_revision'=>2]);
$sfEvent=(int)$db->query('SELECT MAX(id) FROM b2c_publication_events WHERE program_id='.$b2cId)->fetchColumn();
$sfToken=bin2hex(random_bytes(32));$db->prepare("INSERT INTO campaigns(company_id,name,source,created_by) VALUES(?,'Native B2C fixture','B2C_NATIVE',?)")->execute([$user['company_id'],$user['id']]);$sfCampaign=(int)$db->lastInsertId();
$db->prepare("INSERT INTO lead_forms(company_id,campaign_id,name,public_token,created_by) VALUES(?,?,'Native B2C fixture',?,?)")->execute([$user['company_id'],$sfCampaign,$sfToken,$user['id']]);
$sfCfg=['integrations'=>['b2c_storefront'=>['enabled'=>true,'company_id'=>(int)$user['company_id'],'form_token'=>$sfToken]]];
$sfInput=['consent'=>true,'request_id'=>'native-staging-fixture-0001','contact_name'=>'Native Visitor','email'=>'native@example.invalid','phone'=>'+84000000000','total_guests'=>2,'travel_date'=>'2026-11-01','travel_date_end'=>'2026-11-08','message'=>'Test inquiry','attribution'=>['utm_source'=>'native-fixture','referrer'=>'https://example.invalid/']];
$sfBefore=(int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn();
check(B2CStorefront::inquire($db,$sfCfg,$sfEvent,$sfInput)['accepted'],'native storefront accepts inquiry without WordPress');
check(B2CStorefront::inquire($db,$sfCfg,$sfEvent,$sfInput)['accepted']&&(int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn()===$sfBefore+1,'native inquiry retry creates exactly one lead');
$sfLead=$db->query('SELECT * FROM lead_requests ORDER BY id DESC LIMIT 1')->fetch();
check($sfLead['travel_date']==='2026-11-01'&&$sfLead['phone']===$sfInput['phone']&&str_contains($sfLead['message'],'2026-11-08')&&json_decode($sfLead['attribution_json'],true)['utm_source']==='native-fixture','native intake persists dates/contact/UTM in Lead Hub');
check((int)$db->query('SELECT publication_event_id FROM b2c_lead_links WHERE lead_request_id='.(int)$sfLead['id'])->fetchColumn()===$sfEvent,'native intake preserves publication provenance');
rejects(fn()=>B2CStorefront::inquire($db,$sfCfg,$sfEvent,array_replace($sfInput,['message'=>'Changed'])) ,DomainException::class,'native request ID cannot be reused for different payload');
rejects(fn()=>B2CStorefront::inquire($db,$sfCfg,$sfEvent,array_replace($sfInput,['consent'=>false])),InvalidArgumentException::class,'native server enforces consent');
$sfWrong=$sfCfg;$sfWrong['integrations']['b2c_storefront']['company_id']=2;
rejects(fn()=>B2CStorefront::inquire($db,$sfWrong,$sfEvent,$sfInput),OutOfBoundsException::class,'native publication cannot cross company binding');
check(in_array($sfEvent,array_column(B2CStorefront::listing($db,(int)$user['company_id'])['items'],'publication_id'),true),'native catalogue lists current approved publication');
$sfRollback=(int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn();
rejects(fn()=>LeadHub::submit($db,$sfToken,array_merge(B2CStorefront::lead($sfInput),['submission_key'=>'native-callback-rollback-0001']),static function(){throw new OutOfBoundsException('Withdraw race');}),OutOfBoundsException::class,'failed publication linkage rolls back intake');
check((int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn()===$sfRollback,'failed linkage leaves no orphan lead');
B2CStudio::transition($db,$user,$b2cId,'withdraw',['expected_revision'=>2]);
check(!in_array($sfEvent,array_column(B2CStorefront::listing($db,(int)$user['company_id'])['items'],'publication_id'),true),'withdrawal immediately hides native catalogue item');
rejects(fn()=>B2CStorefront::inquire($db,$sfCfg,$sfEvent,$sfInput),OutOfBoundsException::class,'withdrawn publication rejects native inquiry');
// Leave one approved synthetic event for the following HTTP/browser fixture.

$sfCtx=B2CStudio::context($db,$user,B2CStudio::row($db,$user,$b2cId));$sfCtx['settings']['document']['content']['content'][]=['type'=>'image','attrs'=>['assetId'=>$companyId,'alt'=>'Synthetic approved image']];B2CStudio::save($db,$user,$b2cId,['expected_revision'=>2,'settings'=>$sfCtx['settings']]);
B2CStudio::transition($db,$user,$b2cId,'review',['expected_revision'=>3]);B2CStudio::transition($db,$user,$b2cId,'approve',['expected_revision'=>3,'public_content_reviewed'=>true]);B2CStudio::transition($db,$user,$b2cId,'publish',['expected_revision'=>3]);
