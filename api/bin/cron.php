<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$configPath=getenv('VTA_CONFIG_FILE') ?: dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($configPath)){fwrite(STDERR,"Missing config: $configPath\n");exit(1);}
$config=require $configPath;
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
try{RuntimeGuard::config($configPath,$config);}catch(Throwable $e){fwrite(STDERR,"Refused: unsafe staging configuration\n");exit(2);}
date_default_timezone_set($config['app']['timezone']??'Asia/Ho_Chi_Minh');
require_once dirname(__DIR__).'/lib/Http.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/BookingReadiness.php';
require_once dirname(__DIR__).'/lib/ServiceTravelDetails.php';
$db=Database::connect($config['db']);

function setting(PDO $db,int $cid,string $key,string $default): string {
    try{$st=$db->prepare('SELECT setting_value FROM company_settings WHERE company_id=? AND setting_key=?');$st->execute([$cid,$key]);$v=$st->fetchColumn();return $v===false?$default:(string)$v;}catch(Throwable){return $default;}
}
function ownerByRoles(PDO $db,int $cid,array $roles): int {
    if(!$roles)return 0;$ph=implode(',',array_fill(0,count($roles),'?'));
    $sql="SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.company_id=? AND u.status='ACTIVE' AND r.code IN ($ph) ORDER BY FIELD(r.code,".implode(',',array_map(fn($x)=>$db->quote($x),$roles))."),u.id LIMIT 1";
    $st=$db->prepare($sql);$st->execute(array_merge([$cid],$roles));return (int)$st->fetchColumn();
}
function ensureTask(PDO $db,int $cid,int $owner,string $title,string $etype,int $eid,?string $due,string $priority,string $rule): bool {
    if(!$owner)return false;
    $st=$db->prepare("SELECT id FROM tasks WHERE company_id=? AND entity_type=? AND entity_id=? AND rule_code=? AND status IN ('OPEN','SNOOZED') LIMIT 1");
    $st->execute([$cid,$etype,$eid,$rule]);if($st->fetchColumn())return false;
    $ins=$db->prepare("INSERT INTO tasks(company_id,title,entity_type,entity_id,owner_user_id,due_at,priority,status,source,rule_code) VALUES(?,?,?,?,?,?,?,'OPEN','AUTOMATION',?)");
    $ins->execute([$cid,$title,$etype,$eid,$owner,$due,$priority,$rule]);return true;
}
function closeTask(PDO $db,int $cid,string $etype,int $eid,string $rule): int {
    $st=$db->prepare("UPDATE tasks SET status='DONE',completed_at=NOW() WHERE company_id=? AND entity_type=? AND entity_id=? AND rule_code=? AND status IN ('OPEN','SNOOZED')");$st->execute([$cid,$etype,$eid,$rule]);return $st->rowCount();
}
function createAlert(PDO $db,int $cid,string $type,string $severity,string $title,string $etype,int $eid,string $key,string $detail=''): bool {
    try{
        $st=$db->prepare("SELECT id FROM alerts WHERE company_id=? AND alert_key=? AND status='OPEN' LIMIT 1");$st->execute([$cid,$key]);if($st->fetchColumn())return false;
        $db->prepare("INSERT INTO alerts(company_id,alert_type,severity,title,entity_type,entity_id,alert_key,status,detail) VALUES(?,?,?,?,?,?,?,'OPEN',?)")->execute([$cid,$type,$severity,$title,$etype,$eid,$key,$detail]);return true;
    }catch(Throwable){return false;}
}
function resolveAlert(PDO $db,int $cid,string $key): int {
    try{$st=$db->prepare("UPDATE alerts SET status='RESOLVED',resolved_at=NOW() WHERE company_id=? AND alert_key=? AND status='OPEN'");$st->execute([$cid,$key]);return $st->rowCount();}catch(Throwable){return 0;}
}

$created=0;$closed=0;$alerts=0;
$companies=$db->query('SELECT id FROM companies')->fetchAll();
foreach($companies as $co){
    $cid=(int)$co['id'];
    $admin=ownerByRoles($db,$cid,['ADMIN']);
    $sales=ownerByRoles($db,$cid,['SALES','ADMIN']);
    $product=ownerByRoles($db,$cid,['PRODUCT','ADMIN']);
    $ops=ownerByRoles($db,$cid,['OPERATIONS','ADMIN']);
    $finance=ownerByRoles($db,$cid,['FINANCE','ADMIN']);

    $rateDays=max(1,(int)setting($db,$cid,'rate_expiry_days','30'));
    $q=$db->prepare("SELECT r.id,r.product_name,rv.valid_to FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id WHERE r.company_id=? AND r.status='ACTIVE' AND rv.approval_status='APPROVED' AND rv.valid_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL $rateDays DAY)");$q->execute([$cid]);
    $activeRateIds=[];
    foreach($q->fetchAll() as $r){$activeRateIds[]=(int)$r['id'];if(ensureTask($db,$cid,$product,'Rate expires soon: '.$r['product_name'],'rate',(int)$r['id'],$r['valid_to'].' 09:00:00','HIGH','RATE_EXPIRY'))$created++;}
    $st=$db->prepare("SELECT id,entity_id FROM tasks WHERE company_id=? AND entity_type='rate' AND rule_code='RATE_EXPIRY' AND status IN ('OPEN','SNOOZED')");$st->execute([$cid]);foreach($st->fetchAll() as $t)if(!in_array((int)$t['entity_id'],$activeRateIds,true))$closed+=closeTask($db,$cid,'rate',(int)$t['entity_id'],'RATE_EXPIRY');

    // Sales follow-up: sent quotes that still have no open follow-up task.
    $followDays=max(1,(int)setting($db,$cid,'quote_followup_days','2'));
    $q=$db->prepare("SELECT q.id,q.quote_ref,q.updated_at,t.sales_owner_id FROM quotes q JOIN trips t ON t.id=q.trip_id WHERE q.company_id=? AND q.status IN ('SENT','FOLLOW_UP')");$q->execute([$cid]);
    foreach($q->fetchAll() as $r){$due=date('Y-m-d H:i:s',strtotime((string)$r['updated_at']." +$followDays days"));if(ensureTask($db,$cid,(int)($r['sales_owner_id']?:$sales),'Follow up quotation: '.$r['quote_ref'],'quote',(int)$r['id'],$due,'NORMAL','QUOTE_FOLLOWUP'))$created++;}
    $q=$db->prepare("SELECT id FROM quotes WHERE company_id=? AND status IN ('CONFIRMED','LOST','CANCELLED')");$q->execute([$cid]);foreach($q->fetchAll() as $r)$closed+=closeTask($db,$cid,'quote',(int)$r['id'],'QUOTE_FOLLOWUP');

    // Supplier follow-up.
    $q=$db->prepare("SELECT so.id,so.order_ref,so.followup_due_at,b.start_date,b.operations_owner_id FROM supplier_orders so JOIN bookings b ON b.id=so.booking_id WHERE so.company_id=? AND so.status IN ('REQUESTED','ON_REQUEST','WAITLIST')");$q->execute([$cid]);
    foreach($q->fetchAll() as $r){$due=$r['followup_due_at']?:date('Y-m-d H:i:s',strtotime('+24 hours'));$days=$r['start_date']?(strtotime($r['start_date'])-time())/86400:999;$priority=$days<=2?'CRITICAL':($days<=7?'HIGH':'NORMAL');if(ensureTask($db,$cid,(int)($r['operations_owner_id']?:$ops),'Follow up supplier: '.$r['order_ref'],'supplier_order',(int)$r['id'],$due,$priority,'SUPPLIER_FOLLOWUP'))$created++;}
    $q=$db->prepare("SELECT id FROM supplier_orders WHERE company_id=? AND status IN ('CONFIRMED','REJECTED','CANCELLED')");$q->execute([$cid]);foreach($q->fetchAll() as $r)$closed+=closeTask($db,$cid,'supplier_order',(int)$r['id'],'SUPPLIER_FOLLOWUP');

    // Customer receivables overdue.
    $q=$db->prepare("SELECT i.id,i.invoice_ref,i.due_date,i.balance,b.booking_ref FROM customer_invoices i JOIN bookings b ON b.id=i.booking_id WHERE i.company_id=? AND i.status NOT IN ('PAID','CANCELLED','DRAFT') AND i.balance>0 AND i.due_date IS NOT NULL AND i.due_date<=CURDATE()");$q->execute([$cid]);
    foreach($q->fetchAll() as $r){if(ensureTask($db,$cid,$finance,'Customer payment overdue: '.$r['invoice_ref'],'invoice',(int)$r['id'],$r['due_date'].' 09:00:00','HIGH','AR_OVERDUE'))$created++;if(createAlert($db,$cid,'FINANCE','WARNING','Customer payment overdue','invoice',(int)$r['id'],'AR_OVERDUE_'.$r['id'],'Booking '.$r['booking_ref'].' · balance '.$r['balance']))$alerts++;}
    $q=$db->prepare("SELECT id FROM customer_invoices WHERE company_id=? AND (status IN ('PAID','CANCELLED') OR balance<=0 OR due_date IS NULL OR due_date>CURDATE())");$q->execute([$cid]);foreach($q->fetchAll() as $r){$closed+=closeTask($db,$cid,'invoice',(int)$r['id'],'AR_OVERDUE');resolveAlert($db,$cid,'AR_OVERDUE_'.$r['id']);}

    // Supplier payments due within 3 days.
    $q=$db->prepare("SELECT p.id,p.payable_ref,p.due_date,p.balance,b.booking_ref FROM supplier_payables p JOIN bookings b ON b.id=p.booking_id WHERE p.company_id=? AND p.status IN ('UNPAID','PART_PAID') AND p.balance>0 AND p.due_date IS NOT NULL AND p.due_date<=DATE_ADD(CURDATE(),INTERVAL 3 DAY)");$q->execute([$cid]);
    foreach($q->fetchAll() as $r){$priority=$r['due_date']<=date('Y-m-d')?'CRITICAL':'HIGH';if(ensureTask($db,$cid,$finance,'Supplier payment due: '.$r['payable_ref'],'payable',(int)$r['id'],$r['due_date'].' 09:00:00',$priority,'AP_DUE'))$created++;}
    $q=$db->prepare("SELECT id FROM supplier_payables WHERE company_id=? AND (status IN ('PAID','CANCELLED') OR balance<=0)");$q->execute([$cid]);foreach($q->fetchAll() as $r)$closed+=closeTask($db,$cid,'payable',(int)$r['id'],'AP_DUE');

    // Departure readiness risk.
    $warnDays=max(1,(int)setting($db,$cid,'departure_warning_days','7'));
    $q=$db->prepare("SELECT id FROM bookings WHERE company_id=? AND (start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL $warnDays DAY) OR id IN (SELECT entity_id FROM tasks WHERE company_id=? AND entity_type='booking' AND rule_code='DEPARTURE_CHECK' AND status IN ('OPEN','SNOOZED')) OR id IN (SELECT entity_id FROM alerts WHERE company_id=? AND entity_type='booking' AND alert_key LIKE 'DEPARTURE_%' AND status='OPEN'))");$q->execute([$cid,$cid,$cid]);
    foreach($q->fetchAll() as $row){
        BookingIntegrity::begin($db);try{
            $r=BookingIntegrity::lock($db,$cid,(int)$row['id']);$readiness=BookingReadiness::persist($db,$cid,(int)$r['id']);
            if(BookingReadiness::needsDepartureCheck($r,$readiness,date('Y-m-d'),$warnDays)){
                $days=max(0,(int)floor((strtotime($r['start_date'])-strtotime(date('Y-m-d')))/86400));$priority=$days<=1?'CRITICAL':'HIGH';$due=$r['start_date'].' 08:00:00';
                if(ensureTask($db,$cid,(int)($r['operations_owner_id']?:$ops),'Departure check: '.$r['booking_ref'],'booking',(int)$r['id'],$due,$priority,'DEPARTURE_CHECK'))$created++;
                if($priority==='CRITICAL'&&createAlert($db,$cid,'OPERATIONS','CRITICAL','Departure not ready','booking',(int)$r['id'],'DEPARTURE_'.$r['id'],'Readiness '.$readiness['readiness_pct'].'% · '.$r['lead_guest_name']))$alerts++;
            }else{$closed+=closeTask($db,$cid,'booking',(int)$r['id'],'DEPARTURE_CHECK');resolveAlert($db,$cid,'DEPARTURE_'.$r['id']);}
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}

echo "VTA automation completed. Tasks created: $created; tasks auto-closed: $closed; alerts created: $alerts\n";
