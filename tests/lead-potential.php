<?php
declare(strict_types=1);
require __DIR__.'/lead-hub.php';
$potentialToken=str_repeat('b',64);
$db->prepare('INSERT INTO lead_forms(company_id,campaign_id,name,public_token,created_by) VALUES(1,1,?,?,1)')->execute(['Potential fixture',$potentialToken]);
LeadHub::submit($db,$potentialToken,array_replace($body,['submission_key'=>'not-potential-fixture-001']));
$request=(int)$db->query('SELECT MAX(id) FROM lead_requests')->fetchColumn();
$close=['expected_version'=>1,'action_key'=>'close-potential-fixture-001','reason'=>'BUDGET_MISMATCH','note'=>'Budget below available services'];
rejects(fn()=>LeadSalesHandover::requestPotential($db,$user,$request,array_replace($close,['reason'=>''])),InvalidArgumentException::class,'closure reason required');
rejects(fn()=>LeadSalesHandover::requestPotential($db,['id'=>2,'company_id'=>2],$request,$close),OutOfBoundsException::class,'other company cannot close request');
$closed=LeadSalesHandover::requestPotential($db,$user,$request,$close);
check($closed['status']==='REJECTED'&&$closed['version_no']===2,'not potential removes request from NEW queue');
check(LeadSalesHandover::requestPotential($db,$user,$request,$close)===$closed,'closure retry is idempotent');
rejects(fn()=>fixtureQualifyLead($db,$user,$request,'Cannot qualify closed request'),DomainException::class,'closed request cannot qualify');
$restore=['expected_version'=>2,'action_key'=>'restore-potential-fixture-001','reason'=>'Customer returned'];
$restored=LeadSalesHandover::requestPotential($db,$user,$request,$restore,true);
check($restored['status']==='NEW'&&$restored['version_no']===3,'restore preserves request identity and advances version');
check((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='lead_request' AND entity_id=$request AND action_code IN ('LEAD_REQUEST_NOT_POTENTIAL','LEAD_REQUEST_RESTORED')")->fetchColumn()===2,'close and restore retain audit history');
