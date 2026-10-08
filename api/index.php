<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$route = trim((string)($_GET['route'] ?? 'health'), '/');
$method = Http::method();

function pageParams(): array {
    $page=max(1,(int)($_GET['page']??1));
    $size=min(100,max(1,(int)($_GET['page_size']??30)));
    return [$page,$size,($page-1)*$size];
}
function cleanText($v, int $max=190): string { $s=trim((string)$v); if(function_exists('mb_substr')) return mb_substr($s,0,$max,'UTF-8'); if(function_exists('iconv_substr')) return (string)iconv_substr($s,0,$max,'UTF-8'); return substr($s,0,$max); }
function nullableText($v, int $max=190): ?string { $s=cleanText($v,$max); return $s===''?null:$s; }
function validEnum(string $value, array $allowed, string $field): string {
    if(!in_array($value,$allowed,true)) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>"Invalid $field."],422);
    return $value;
}
function recordRef(string $prefix, int $id): string { return sprintf('%s-%s-%06d',$prefix,date('Y'),$id); }
function fetchSupplier(PDO $db, int $companyId, int $id): array {
    $st=$db->prepare('SELECT * FROM suppliers WHERE company_id=? AND id=? LIMIT 1'); $st->execute([$companyId,$id]);
    $r=$st->fetch(); if(!$r) Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404); return $r;
}
function fetchDocument(PDO $db, int $companyId, int $id): array {
    $st=$db->prepare('SELECT * FROM documents WHERE company_id=? AND id=? LIMIT 1'); $st->execute([$companyId,$id]);
    $r=$st->fetch(); if(!$r) Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404); return $r;
}
function fetchRate(PDO $db, int $companyId, int $id): array {
    $st=$db->prepare('SELECT * FROM rates WHERE company_id=? AND id=? LIMIT 1'); $st->execute([$companyId,$id]);
    $r=$st->fetch(); if(!$r) Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404); return $r;
}

function createRateDraft(PDO $db,int $companyId,int $userId,array $b): array {
    $sid=(int)($b['supplier_id']??0);fetchSupplier($db,$companyId,$sid);
    $product=cleanText($b['product_name']??'');if(!$product) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Product/service name is required.'],422);
    $category=validEnum(strtoupper(cleanText($b['category']??'OTHER',32)),['HOTEL','TRANSPORT','GUIDE','CRUISE','TOUR','ATTRACTION','MEAL','VISA','OTHER'],'category');
    $type=validEnum(strtoupper(cleanText($b['rate_type']??'CONTRACT',32)),['CONTRACT','PROMOTION','SPECIAL_QUOTE','SERIES','MANUAL_OVERRIDE'],'rate type');
    $basis=validEnum(strtoupper(cleanText($b['rate_basis']??'',32)),['PER_PAX','PER_ROOM_NIGHT','PER_VEHICLE','PER_TRANSFER','PER_DAY','PER_GUIDE_DAY','PER_CABIN','PER_GROUP','PER_SERVICE'],'rate basis');
    $amount=(float)($b['amount']??-1);if($amount<0) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Rate amount must be zero or greater.'],422);
    $currency=strtoupper(cleanText($b['currency']??'VND',3));if(strlen($currency)!==3) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Currency must use a 3-letter code.'],422);
    $origin=validEnum(strtoupper(cleanText($b['source_origin']??'',32)) ?: (!empty($b['source_document_id'])?'SUPPLIER_DOCUMENT':($type==='MANUAL_OVERRIDE'?'MANUAL':'SUPPLIER_DOCUMENT')),['SUPPLIER_DOCUMENT','MANUAL','LEGACY'],'source origin');
    $sourceId=(int)($b['source_document_id']??0);if($sourceId){$src=fetchDocument($db,$companyId,$sourceId);if(!empty($src['supplier_id'])&&(int)$src['supplier_id']!==$sid)Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Source document supplier must match the rate supplier.'],422);} elseif($type!=='MANUAL_OVERRIDE' && $origin!=='LEGACY') Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Source document is required unless this is a Manual Override or a Legacy rate awaiting review.'],422);
    if($type==='SPECIAL_QUOTE' && empty($b['linked_trip_ref'])) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Special Quote rates must be linked to a Trip/Inquiry reference.'],422);
    $tmp='PENDING-'.bin2hex(random_bytes(8));
    $st=$db->prepare('INSERT INTO rates(company_id,rate_ref,supplier_id,category,destination,product_name,option_name,rate_type,market,min_pax,max_pax,reusable,linked_trip_ref,source_origin,status,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'DRAFT\',?,?)');
    $reusable=$type==='SPECIAL_QUOTE'?0:(!empty($b['reusable'])?1:0);
    $st->execute([$companyId,$tmp,$sid,$category,nullableText($b['destination']??'',160),$product,nullableText($b['option_name']??'',190),$type,nullableText($b['market']??'',120),isset($b['min_pax'])&&$b['min_pax']!==''?(int)$b['min_pax']:null,isset($b['max_pax'])&&$b['max_pax']!==''?(int)$b['max_pax']:null,$reusable,nullableText($b['linked_trip_ref']??'',64),$origin,$userId,$userId]);
    $id=(int)$db->lastInsertId();$ref=recordRef('RATE',$id);$db->prepare('UPDATE rates SET rate_ref=? WHERE id=?')->execute([$ref,$id]);
    $tax=validEnum(strtoupper(cleanText($b['tax_basis']??'UNKNOWN',32)),['UNKNOWN','NET','TAX_INCLUDED','TAX_EXCLUDED','PLUS_PLUS'],'tax basis');
    $rv=$db->prepare('INSERT INTO rate_versions(rate_id,version_no,amount,currency,rate_basis,valid_from,valid_to,booking_valid_from,booking_valid_to,tax_basis,source_document_id,source_locator,terms,internal_notes,approval_status,created_by) VALUES(?,1,?,?,?,?,?,?,?,?,?,?,?,?,\'UNREVIEWED\',?)');
    $rv->execute([$id,$amount,$currency,$basis,nullableText($b['valid_from']??'',10),nullableText($b['valid_to']??'',10),nullableText($b['booking_valid_from']??'',10),nullableText($b['booking_valid_to']??'',10),$tax,$sourceId?:null,nullableText($b['source_locator']??'',255),nullableText($b['terms']??'',4000),nullableText($b['internal_notes']??'',4000),$userId]);
    $rateVersionId=(int)$db->lastInsertId(); RateRules::save($db,$rateVersionId,$category,$b); RateRules::saveShared($db,$rateVersionId,$b);
    Audit::activity($db,$companyId,'rate',$id,'RATE_CREATED',"Rate $ref created for $product",$userId);Audit::log($db,$companyId,$userId,'RATE_CREATED','rate',$id,null,['rate_ref'=>$ref,'amount'=>$amount,'currency'=>$currency,'source_document_id'=>$sourceId?:null,'source_origin'=>$origin]);
    return ['id'=>$id,'rate_ref'=>$ref,'rate_version_id'=>$rateVersionId,'version_no'=>1];
}


function documentRateComparison(PDO $db,int $companyId,int $documentId): array {
    $doc=fetchDocument($db,$companyId,$documentId);
    if(empty($doc['supplier_id'])) return ['document'=>$doc,'items'=>[],'summary'=>['current'=>0,'matched'=>0,'increases'=>0,'decreases'=>0,'unchanged'=>0]];
    $st=$db->prepare("SELECT r.id rate_id,r.rate_ref,r.category,r.destination,r.product_name,r.option_name,r.market,rv.id rate_version_id,rv.version_no,rv.amount,rv.currency,rv.valid_from,rv.valid_to,rv.approval_status
                      FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id
                      WHERE r.company_id=? AND rv.source_document_id=?
                      ORDER BY r.category,r.product_name,r.option_name,rv.version_no DESC");
    $st->execute([$companyId,$documentId]);
    $current=$st->fetchAll();
    $seen=[];$items=[];$summary=['current'=>0,'matched'=>0,'increases'=>0,'decreases'=>0,'unchanged'=>0];
    $prev=$db->prepare("SELECT r2.id rate_id,r2.rate_ref,rv2.id rate_version_id,rv2.version_no,rv2.amount,rv2.currency,rv2.valid_from,rv2.valid_to,d.document_ref,d.original_filename
                        FROM rates r2 JOIN rate_versions rv2 ON rv2.rate_id=r2.id
                        LEFT JOIN documents d ON d.id=rv2.source_document_id
                        WHERE r2.company_id=? AND r2.supplier_id=? AND r2.category=?
                          AND LOWER(r2.product_name)=LOWER(?)
                          AND COALESCE(LOWER(r2.option_name),'')=COALESCE(LOWER(?),'')
                          AND COALESCE(LOWER(r2.destination),'')=COALESCE(LOWER(?),'')
                          AND COALESCE(LOWER(r2.market),'')=COALESCE(LOWER(?),'')
                          AND rv2.source_document_id<>?
                          AND rv2.approval_status IN ('APPROVED','SUPERSEDED')
                        ORDER BY COALESCE(rv2.valid_to,'9999-12-31') DESC,rv2.approved_at DESC,rv2.version_no DESC
                        LIMIT 1");
    foreach($current as $row){
        if(isset($seen[$row['rate_id']])) continue;
        $seen[$row['rate_id']]=true;$summary['current']++;
        $prev->execute([$companyId,(int)$doc['supplier_id'],$row['category'],$row['product_name'],$row['option_name'],$row['destination'],$row['market'],$documentId]);
        $old=$prev->fetch()?:null;
        $diff=null;$pct=null;$direction='NEW';
        if($old && strtoupper((string)$old['currency'])===strtoupper((string)$row['currency'])){
            $summary['matched']++;
            $diff=(float)$row['amount']-(float)$old['amount'];
            $pct=(float)$old['amount']!=0.0?($diff/(float)$old['amount']*100.0):null;
            if(abs($diff)<0.00001){$direction='UNCHANGED';$summary['unchanged']++;}
            elseif($diff>0){$direction='INCREASE';$summary['increases']++;}
            else{$direction='DECREASE';$summary['decreases']++;}
        } elseif($old) {
            $direction='CURRENCY_CHANGED';$summary['matched']++;
        }
        $items[]=['current'=>$row,'previous'=>$old,'difference'=>$diff,'percent_change'=>$pct,'direction'=>$direction];
    }
    return ['document'=>$doc,'items'=>$items,'summary'=>$summary];
}

try {
    if ($route === 'health' && $method === 'GET') {
        $db->query('SELECT 1');
        Http::json(['ok'=>true,'service'=>'VTA API','version'=>'3.4.0-RC6.1','time'=>date(DATE_ATOM)]);
    }

    if ($route === 'auth/login' && $method === 'POST') {
        $b=Http::body();
        $user=Auth::login($db, cleanText($b['email']??'',190), (string)($b['password']??''));
        Audit::log($db,(int)$user['company_id'],(int)$user['id'],'LOGIN','user',(int)$user['id'],null,['status'=>'success']);
        Http::json(['ok'=>true,'user'=>$user]);
    }
    if ($route === 'auth/logout' && $method === 'POST') {
        $u=Auth::requireUser($db); Auth::checkCsrf();
        Audit::log($db,(int)$u['company_id'],(int)$u['id'],'LOGOUT','user',(int)$u['id']);
        Auth::logout(); Http::json(['ok'=>true]);
    }
    if ($route === 'auth/me' && $method === 'GET') {
        Http::json(['ok'=>true,'user'=>Auth::requireUser($db)]);
    }

    QuoteProposal::publicHandle($route,$method,$db,$config);
    WebhookCenter::publicHandle($route,$method,$db,$config);

    $user=Auth::requireUser($db);
    $companyId=(int)$user['company_id'];
    $userId=(int)$user['id'];
    Auth::checkCsrf();

    // VTA v2.4 complete core routes (Sales → Booking → Operations → Finance → TODAY)
    LeadHub::handle($route,$method,$db,$user);
    WebhookCenter::adminHandle($route,$method,$db,$config,$user);
    LandingPages::handle($route,$method,$db,$user);
    MarketingStudio::handle($route,$method,$db,$user);
    CampaignPilot::handle($route,$method,$db,$user);
    AiChat::handle($route,$method,$db,$config,$user);
    TourInventory::handle($route,$method,$db,$user);
    MediaLibrary::handle($route,$method,$db,$config,$user);
    MediaDrive::handle($route,$method,$db,$config,$user);
    PriceMatrix::handle($route,$method,$db,$user);
    SalesHandover::handle($route,$method,$db,$user);
    QuoteProposal::handle($route,$method,$db,$config,$user);
    QuoteVs2::handle($route,$method,$db,$user);
    QuoteOptions::handle($route,$method,$db,$user);
    ScheduleImport::handle($route,$method,$db,$user);
    TourLibrary::handle($route,$method,$db,$config,$user);
    QuoteReuse::handle($route,$method,$db,$user);
    QuoteCostItems::handle($route,$method,$db,$user);
    Procurement::handle($route,$method,$db,$user);
    TravelDocuments::handle($route,$method,$db,$user);
    InvoiceCommercial::handle($route,$method,$db,$user);
    FinanceLedger::handle($route,$method,$db,$user);
    OperationsControl::handle($route,$method,$db,$user);
    ServiceTravelDetails::handle($route,$method,$db,$user);
    ControlCenter::handle($route,$method,$db,$user);
    WorkspaceCenter::handle($route,$method,$db,$user);
    QuoteExport::handle($route,$method,$db,$user);
    Customer360::handle($route,$method,$db,$user);
    CoreOS::handle($route,$method,$db,$config,$user);

    if ($route === 'dashboard' && $method === 'GET') {
        Auth::requirePermission($db,$user,'task.view');
        $stats=[];
        $stats['suppliers']=(int)$db->query("SELECT COUNT(*) FROM suppliers WHERE company_id=$companyId AND status='ACTIVE'")->fetchColumn();
        $stats['documents_new']=(int)$db->query("SELECT COUNT(*) FROM documents WHERE company_id=$companyId AND review_status IN ('NEW','EXTRACTED','NEEDS_REVIEW')")->fetchColumn();
        $stats['rates_active']=(int)$db->query("SELECT COUNT(*) FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id WHERE r.company_id=$companyId AND r.status='ACTIVE' AND rv.approval_status='APPROVED' AND (rv.valid_to IS NULL OR rv.valid_to>=CURDATE())")->fetchColumn();
        $stats['rates_expiring']=(int)$db->query("SELECT COUNT(*) FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id WHERE r.company_id=$companyId AND r.status='ACTIVE' AND rv.approval_status='APPROVED' AND rv.valid_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
        $stats['tasks_open']=(int)$db->query("SELECT COUNT(*) FROM tasks WHERE company_id=$companyId AND status='OPEN'")->fetchColumn();
        $stats['legacy_rates']=(int)$db->query("SELECT COUNT(*) FROM rates WHERE company_id=$companyId AND source_origin='LEGACY' AND status<>'ARCHIVED'")->fetchColumn();
        $stats['import_unresolved']=(int)$db->query("SELECT COUNT(*) FROM rate_import_rows rir JOIN rate_import_batches rib ON rib.id=rir.batch_id WHERE rib.company_id=$companyId AND rir.row_status IN ('SKIPPED','ERROR','WARNING')")->fetchColumn();
        $sql="SELECT t.*,u.full_name owner_name FROM tasks t JOIN users u ON u.id=t.owner_user_id WHERE t.company_id=? AND t.status='OPEN' ORDER BY FIELD(t.priority,'CRITICAL','HIGH','NORMAL'),COALESCE(t.due_at,'2999-12-31') ASC LIMIT 12";
        $st=$db->prepare($sql);$st->execute([$companyId]);
        Http::json(['ok'=>true,'stats'=>$stats,'tasks'=>$st->fetchAll()]);
    }

    if ($route === 'search' && $method === 'GET') {
        $q=cleanText($_GET['q']??'',120);
        if((function_exists('mb_strlen')?mb_strlen($q,'UTF-8'):strlen($q))<2) Http::json(['ok'=>true,'groups'=>['suppliers'=>[],'documents'=>[],'rates'=>[]]]);
        $like='%'.$q.'%';
        $groups=['suppliers'=>[],'documents'=>[],'rates'=>[]];
        if(Auth::can($db,$userId,'supplier.view')){
            $st=$db->prepare("SELECT id,supplier_ref,name,supplier_type,city FROM suppliers WHERE company_id=? AND status<>'ARCHIVED' AND (name LIKE ? OR supplier_ref LIKE ? OR city LIKE ?) ORDER BY preferred DESC,name LIMIT 6");$st->execute([$companyId,$like,$like,$like]);$groups['suppliers']=$st->fetchAll();
        }
        if(Auth::can($db,$userId,'document.view')){
            $st=$db->prepare("SELECT d.id,d.document_ref,d.original_filename,d.document_type,d.review_status,s.name supplier_name FROM documents d LEFT JOIN suppliers s ON s.id=d.supplier_id WHERE d.company_id=? AND (d.original_filename LIKE ? OR d.document_ref LIKE ? OR s.name LIKE ?) ORDER BY d.created_at DESC LIMIT 6");$st->execute([$companyId,$like,$like,$like]);$groups['documents']=$st->fetchAll();
        }
        if(Auth::can($db,$userId,'rate.view')){
            $st=$db->prepare("SELECT r.id,r.rate_ref,r.product_name,r.option_name,r.destination,s.name supplier_name,rv.amount,rv.currency,rv.approval_status FROM rates r JOIN suppliers s ON s.id=r.supplier_id LEFT JOIN rate_versions rv ON rv.rate_id=r.id AND rv.version_no=(SELECT MAX(x.version_no) FROM rate_versions x WHERE x.rate_id=r.id) WHERE r.company_id=? AND r.status<>'ARCHIVED' AND (r.rate_ref LIKE ? OR r.product_name LIKE ? OR r.option_name LIKE ? OR r.destination LIKE ? OR s.name LIKE ?) ORDER BY r.updated_at DESC LIMIT 8");$st->execute([$companyId,$like,$like,$like,$like,$like]);$groups['rates']=$st->fetchAll();
        }
        Http::json(['ok'=>true,'groups'=>$groups]);
    }

    // USERS / ROLES
    if ($route === 'roles' && $method === 'GET') {
        Auth::requirePermission($db,$user,'user.manage');
        $st=$db->prepare('SELECT id,code,name FROM roles WHERE company_id=? ORDER BY id'); $st->execute([$companyId]);
        Http::json(['ok'=>true,'items'=>$st->fetchAll()]);
    }
    if ($route === 'users' && $method === 'GET') {
        Auth::requirePermission($db,$user,'user.manage');
        $st=$db->prepare('SELECT u.id,u.full_name,u.email,u.mobile,u.status,u.last_login_at,u.created_at,r.id role_id,r.code role_code,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.company_id=? ORDER BY u.full_name'); $st->execute([$companyId]);
        Http::json(['ok'=>true,'items'=>$st->fetchAll()]);
    }
    if ($route === 'users' && $method === 'POST') {
        Auth::requirePermission($db,$user,'user.manage');
        $b=Http::body(); $name=cleanText($b['full_name']??''); $email=strtolower(cleanText($b['email']??'')); $pass=(string)($b['password']??''); $role=(int)($b['role_id']??0);
        if(!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<10 || !$role) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Name, valid email, role and password of at least 10 characters are required.'],422);
        $rs=$db->prepare('SELECT id FROM roles WHERE company_id=? AND id=?');$rs->execute([$companyId,$role]); if(!$rs->fetch()) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Invalid role.'],422);
        $st=$db->prepare('INSERT INTO users(company_id,role_id,full_name,email,mobile,password_hash,status) VALUES(?,?,?,?,?,?,\'ACTIVE\')');
        try{$st->execute([$companyId,$role,$name,$email,nullableText($b['mobile']??'',64),password_hash($pass,PASSWORD_DEFAULT)]);}catch(PDOException $e){ if($e->getCode()==='23000') Http::json(['ok'=>false,'error'=>'DUPLICATE','message'=>'Email already exists.'],409); throw $e; }
        $id=(int)$db->lastInsertId(); Audit::log($db,$companyId,$userId,'USER_CREATED','user',$id,null,['email'=>$email,'role_id'=>$role]);
        Http::json(['ok'=>true,'id'=>$id],201);
    }
    if (preg_match('#^users/(\d+)$#',$route,$m) && $method === 'PUT') {
        Auth::requirePermission($db,$user,'user.manage'); $id=(int)$m[1]; $b=Http::body();
        $role=(int)($b['role_id']??0); $status=validEnum(strtoupper(cleanText($b['status']??'ACTIVE',16)),['ACTIVE','INACTIVE','LOCKED'],'status');
        if($id===$userId && $status!=='ACTIVE') Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'You cannot deactivate your own current account.'],422);
        $rs=$db->prepare('SELECT id FROM roles WHERE company_id=? AND id=?');$rs->execute([$companyId,$role]); if(!$rs->fetch()) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Invalid role.'],422);
        $st=$db->prepare('UPDATE users SET role_id=?,status=? WHERE company_id=? AND id=?');$st->execute([$role,$status,$companyId,$id]); if(!$st->rowCount()) Http::json(['ok'=>false,'error'=>'NOT_FOUND_OR_UNCHANGED'],404);
        if(!empty($b['new_password'])){ if(strlen((string)$b['new_password'])<10) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Password must be at least 10 characters.'],422); $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash((string)$b['new_password'],PASSWORD_DEFAULT),$id]); }
        Audit::log($db,$companyId,$userId,'USER_UPDATED','user',$id,null,['role_id'=>$role,'status'=>$status]); Http::json(['ok'=>true]);
    }

    // SUPPLIERS
    if ($route === 'suppliers' && $method === 'GET') {
        Auth::requirePermission($db,$user,'supplier.view'); [$page,$size,$offset]=pageParams();
        $q=cleanText($_GET['q']??'',120); $status=cleanText($_GET['status']??'ACTIVE',16); $type=cleanText($_GET['type']??'',32);
        $where=['s.company_id=?'];$args=[$companyId];
        if($status!=='ALL'){ $where[]='s.status=?';$args[]=$status; }
        if($type){$where[]='s.supplier_type=?';$args[]=$type;}
        if($q){$where[]='(s.name LIKE ? OR s.city LIKE ? OR s.supplier_ref LIKE ? OR s.primary_email LIKE ?)';$like='%'.$q.'%';array_push($args,$like,$like,$like,$like);}
        $ws=implode(' AND ',$where);
        $cnt=$db->prepare("SELECT COUNT(*) FROM suppliers s WHERE $ws");$cnt->execute($args);$total=(int)$cnt->fetchColumn();
        $st=$db->prepare("SELECT s.*,(SELECT COUNT(*) FROM documents d WHERE d.supplier_id=s.id) document_count,(SELECT COUNT(*) FROM rates r WHERE r.supplier_id=s.id AND r.status<>'ARCHIVED') rate_count FROM suppliers s WHERE $ws ORDER BY s.preferred DESC,s.name LIMIT $size OFFSET $offset");$st->execute($args);
        Http::json(['ok'=>true,'items'=>$st->fetchAll(),'pagination'=>['page'=>$page,'page_size'=>$size,'total'=>$total]]);
    }
    if ($route === 'suppliers' && $method === 'POST') {
        Auth::requirePermission($db,$user,'supplier.create'); $b=Http::body();
        $name=cleanText($b['name']??''); if(!$name) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Supplier name is required.'],422);
        $type=validEnum(strtoupper(cleanText($b['supplier_type']??'OTHER',32)),['HOTEL','TRANSPORT','GUIDE','CRUISE','TOUR','ATTRACTION','RESTAURANT','VISA','OTHER'],'supplier type');
        $db->beginTransaction();
        $tmp='PENDING-'.bin2hex(random_bytes(8));
        $st=$db->prepare('INSERT INTO suppliers(company_id,supplier_ref,name,supplier_type,city,country,currency,primary_email,primary_whatsapp,payment_terms,preferred,status,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,\'ACTIVE\',?,?)');
        $st->execute([$companyId,$tmp,$name,$type,nullableText($b['city']??'',120),cleanText($b['country']??'Vietnam',120)?:'Vietnam',strtoupper(cleanText($b['currency']??'VND',3)),nullableText($b['primary_email']??'',190),nullableText($b['primary_whatsapp']??'',64),nullableText($b['payment_terms']??'',2000),!empty($b['preferred'])?1:0,$userId,$userId]);
        $id=(int)$db->lastInsertId();$ref=recordRef('SUP',$id);$db->prepare('UPDATE suppliers SET supplier_ref=? WHERE id=?')->execute([$ref,$id]);
        Audit::activity($db,$companyId,'supplier',$id,'SUPPLIER_CREATED',"Supplier $name created",$userId); Audit::log($db,$companyId,$userId,'SUPPLIER_CREATED','supplier',$id,null,['ref'=>$ref,'name'=>$name]);
        $db->commit(); Http::json(['ok'=>true,'id'=>$id,'supplier_ref'=>$ref],201);
    }
    if (preg_match('#^suppliers/(\d+)$#',$route,$m) && $method === 'PUT') {
        Auth::requirePermission($db,$user,'supplier.edit'); $id=(int)$m[1];$old=fetchSupplier($db,$companyId,$id);$b=Http::body();$expected=(int)($b['version_no']??0);
        if($expected!==(int)$old['version_no']) Http::json(['ok'=>false,'error'=>'VERSION_CONFLICT','message'=>'This supplier was updated by another user. Reload the latest version.','current_version'=>(int)$old['version_no']],409);
        $name=cleanText($b['name']??$old['name']); if(!$name) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Supplier name is required.'],422);
        $type=validEnum(strtoupper(cleanText($b['supplier_type']??$old['supplier_type'],32)),['HOTEL','TRANSPORT','GUIDE','CRUISE','TOUR','ATTRACTION','RESTAURANT','VISA','OTHER'],'supplier type');
        $st=$db->prepare('UPDATE suppliers SET name=?,supplier_type=?,city=?,country=?,currency=?,primary_email=?,primary_whatsapp=?,payment_terms=?,preferred=?,updated_by=?,version_no=version_no+1 WHERE company_id=? AND id=? AND version_no=?');
        $st->execute([$name,$type,nullableText($b['city']??'',120),cleanText($b['country']??'Vietnam',120)?:'Vietnam',strtoupper(cleanText($b['currency']??'VND',3)),nullableText($b['primary_email']??'',190),nullableText($b['primary_whatsapp']??'',64),nullableText($b['payment_terms']??'',2000),!empty($b['preferred'])?1:0,$userId,$companyId,$id,$expected]);
        if(!$st->rowCount()) Http::json(['ok'=>false,'error'=>'VERSION_CONFLICT'],409);
        $new=fetchSupplier($db,$companyId,$id); Audit::activity($db,$companyId,'supplier',$id,'SUPPLIER_UPDATED',"Supplier {$new['name']} updated",$userId);Audit::log($db,$companyId,$userId,'SUPPLIER_UPDATED','supplier',$id,$old,$new);
        Http::json(['ok'=>true,'item'=>$new]);
    }
    if (preg_match('#^suppliers/(\d+)/archive$#',$route,$m) && $method === 'POST') {
        Auth::requirePermission($db,$user,'supplier.archive');$id=(int)$m[1];$old=fetchSupplier($db,$companyId,$id);
        $db->prepare("UPDATE suppliers SET status='ARCHIVED',archived_at=NOW(),updated_by=?,version_no=version_no+1 WHERE company_id=? AND id=?")->execute([$userId,$companyId,$id]);
        Audit::activity($db,$companyId,'supplier',$id,'SUPPLIER_ARCHIVED',"Supplier {$old['name']} archived",$userId);Audit::log($db,$companyId,$userId,'SUPPLIER_ARCHIVED','supplier',$id,$old,['status'=>'ARCHIVED']);Http::json(['ok'=>true]);
    }

    // DOCUMENTS / SUPPLIER QUOTE VAULT
    if ($route === 'documents' && $method === 'GET') {
        Auth::requirePermission($db,$user,'document.view');[$page,$size,$offset]=pageParams();$q=cleanText($_GET['q']??'',120);$status=cleanText($_GET['status']??'',32);$supplierId=(int)($_GET['supplier_id']??0);
        $where=['d.company_id=?'];$args=[$companyId];if($status){$where[]='d.review_status=?';$args[]=$status;}if($supplierId){$where[]='d.supplier_id=?';$args[]=$supplierId;}if($q){$where[]='(d.original_filename LIKE ? OR d.document_ref LIKE ? OR s.name LIKE ?)';$l='%'.$q.'%';array_push($args,$l,$l,$l);} $ws=implode(' AND ',$where);
        $cnt=$db->prepare("SELECT COUNT(*) FROM documents d LEFT JOIN suppliers s ON s.id=d.supplier_id WHERE $ws");$cnt->execute($args);$total=(int)$cnt->fetchColumn();
        $st=$db->prepare("SELECT d.*,s.name supplier_name FROM documents d LEFT JOIN suppliers s ON s.id=d.supplier_id WHERE $ws ORDER BY d.created_at DESC LIMIT $size OFFSET $offset");$st->execute($args);
        Http::json(['ok'=>true,'items'=>$st->fetchAll(),'pagination'=>['page'=>$page,'page_size'=>$size,'total'=>$total]]);
    }
    if ($route === 'documents/upload' && $method === 'POST') {
        Auth::requirePermission($db,$user,'document.upload');
        if(empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Please choose a supplier file.'],422);
        $file=$_FILES['file'];$max=(int)($config['security']['max_upload_bytes']??15728640);if((int)$file['size']>$max) Http::json(['ok'=>false,'error'=>'FILE_TOO_LARGE'],413);
        $ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));$allowed=$config['security']['allowed_extensions']??[];if(!in_array($ext,$allowed,true)) Http::json(['ok'=>false,'error'=>'FILE_TYPE_NOT_ALLOWED'],415);
        $supplierId=(int)($_POST['supplier_id']??0);if($supplierId) fetchSupplier($db,$companyId,$supplierId);
        $docType=validEnum(strtoupper(cleanText($_POST['document_type']??'OTHER',32)),['CONTRACT','RATE_SHEET','PROMOTION','SPECIAL_QUOTE','SERIES_QUOTE','SUPPLEMENT','TERMS','OTHER'],'document type');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
        $parse=DocumentParser::extract($file['tmp_name'],$ext);
        $stored=Storage::store($config,$file['tmp_name'],basename((string)$file['name']),$mime);
        $db->beginTransaction();$tmp='PENDING-'.bin2hex(random_bytes(8));
        $initialStatus=trim((string)($parse['text']??''))!==''?'EXTRACTED':'NEEDS_REVIEW';
        $st=$db->prepare('INSERT INTO documents(company_id,document_ref,supplier_id,document_type,original_filename,mime_type,file_size,storage_driver,storage_file_id,storage_path,review_status,valid_from,valid_to,extracted_text,extraction_quality,notes,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$companyId,$tmp,$supplierId?:null,$docType,basename((string)$file['name']),$mime,(int)$file['size'],$stored['driver'],$stored['file_id'],$stored['path'],$initialStatus,nullableText($_POST['valid_from']??'',10),nullableText($_POST['valid_to']??'',10),$parse['text']??'',$parse['quality']??'NONE',cleanText(($parse['note']??'').' '.($_POST['notes']??''),4000),$userId]);
        $id=(int)$db->lastInsertId();$ref=recordRef('DOC-SUP',$id);$db->prepare('UPDATE documents SET document_ref=? WHERE id=?')->execute([$ref,$id]);
        Audit::activity($db,$companyId,'document',$id,'SUPPLIER_DOCUMENT_UPLOADED',"Supplier document {$file['name']} uploaded",$userId,['supplier_id'=>$supplierId?:null]);Audit::log($db,$companyId,$userId,'SUPPLIER_DOCUMENT_UPLOADED','document',$id,null,['ref'=>$ref,'filename'=>$file['name'],'storage'=>$stored['driver']]);
        $db->commit();Http::json(['ok'=>true,'id'=>$id,'document_ref'=>$ref,'extraction_quality'=>$parse['quality']??'NONE','extraction_note'=>$parse['note']??''],201);
    }
    if (preg_match('#^documents/(\d+)/download$#',$route,$m) && $method === 'GET') {
        Auth::requirePermission($db,$user,'document.view');$doc=fetchDocument($db,$companyId,(int)$m[1]);Storage::stream($config,$doc);
    }
    if (preg_match('#^documents/(\d+)/review$#',$route,$m) && $method === 'PUT') {
        Auth::requirePermission($db,$user,'document.review');$id=(int)$m[1];$old=fetchDocument($db,$companyId,$id);$b=Http::body();
        $status=validEnum(strtoupper(cleanText($b['review_status']??'',32)),['NEW','EXTRACTED','NEEDS_REVIEW','APPROVED','REJECTED','ARCHIVED'],'review status');
        $st=$db->prepare('UPDATE documents SET supplier_id=?,document_type=?,review_status=?,valid_from=?,valid_to=?,notes=?,version_no=version_no+1 WHERE company_id=? AND id=?');
        $sid=(int)($b['supplier_id']??($old['supplier_id']??0));if($sid) fetchSupplier($db,$companyId,$sid);
        $dtype=validEnum(strtoupper(cleanText($b['document_type']??$old['document_type'],32)),['CONTRACT','RATE_SHEET','PROMOTION','SPECIAL_QUOTE','SERIES_QUOTE','SUPPLEMENT','TERMS','OTHER'],'document type');
        $st->execute([$sid?:null,$dtype,$status,nullableText($b['valid_from']??$old['valid_from'],10),nullableText($b['valid_to']??$old['valid_to'],10),nullableText($b['notes']??$old['notes'],4000),$companyId,$id]);
        $new=fetchDocument($db,$companyId,$id);Audit::activity($db,$companyId,'document',$id,'DOCUMENT_REVIEW_UPDATED',"Document review status changed to $status",$userId);Audit::log($db,$companyId,$userId,'DOCUMENT_REVIEW_UPDATED','document',$id,$old,$new);Http::json(['ok'=>true,'item'=>$new]);
    }

    if (preg_match('#^documents/(\d+)/compare$#',$route,$m) && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.compare');
        $id=(int)$m[1];
        Http::json(['ok'=>true]+documentRateComparison($db,$companyId,$id));
    }

    // RATE MASTER
    if ($route === 'rates' && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.view');[$page,$size,$offset]=pageParams();$q=cleanText($_GET['q']??'',120);$category=cleanText($_GET['category']??'',32);$health=cleanText($_GET['health']??'',32);
        $where=['r.company_id=?','r.status<>\'ARCHIVED\''];$args=[$companyId];if($category){$where[]='r.category=?';$args[]=$category;}if($q){$where[]='(r.product_name LIKE ? OR r.option_name LIKE ? OR r.destination LIKE ? OR s.name LIKE ? OR r.rate_ref LIKE ?)';$l='%'.$q.'%';array_push($args,$l,$l,$l,$l,$l);} if($health==='EXPIRING'){$where[]="rv.approval_status='APPROVED' AND rv.valid_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)";} elseif($health==='EXPIRED'){$where[]="rv.approval_status='APPROVED' AND rv.valid_to<CURDATE()";} elseif($health==='DRAFT'){$where[]="rv.approval_status<>'APPROVED'";}
        $ws=implode(' AND ',$where);
        $base="FROM rates r JOIN suppliers s ON s.id=r.supplier_id LEFT JOIN rate_versions rv ON rv.rate_id=r.id AND rv.version_no=(SELECT MAX(rv2.version_no) FROM rate_versions rv2 WHERE rv2.rate_id=r.id) LEFT JOIN documents d ON d.id=rv.source_document_id WHERE $ws";
        $cnt=$db->prepare("SELECT COUNT(*) $base");$cnt->execute($args);$total=(int)$cnt->fetchColumn();
        $st=$db->prepare("SELECT r.*,s.name supplier_name,rv.id rate_version_id,rv.version_no rate_version_no,rv.amount,rv.currency,rv.rate_basis,rv.valid_from,rv.valid_to,rv.tax_basis,rv.approval_status,rv.approved_at,rv.source_document_id,d.document_ref,d.original_filename source_filename,CASE WHEN rv.approval_status<>'APPROVED' THEN 'NEEDS_REVIEW' WHEN rv.valid_to IS NOT NULL AND rv.valid_to<CURDATE() THEN 'EXPIRED' WHEN rv.valid_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 'EXPIRING' WHEN rv.valid_from>CURDATE() THEN 'FUTURE' ELSE 'ACTIVE' END health $base ORDER BY r.updated_at DESC LIMIT $size OFFSET $offset");$st->execute($args);
        Http::json(['ok'=>true,'items'=>$st->fetchAll(),'pagination'=>['page'=>$page,'page_size'=>$size,'total'=>$total]]);
    }
    if (preg_match('#^rates/(\d+)$#',$route,$m) && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.view');$id=(int)$m[1];$r=fetchRate($db,$companyId,$id);
        $st=$db->prepare("SELECT rv.*,d.document_ref,d.original_filename source_filename,u.full_name approved_by_name FROM rate_versions rv LEFT JOIN documents d ON d.id=rv.source_document_id LEFT JOIN users u ON u.id=rv.approved_by WHERE rv.rate_id=? ORDER BY rv.version_no DESC LIMIT 1");$st->execute([$id]);$rv=$st->fetch();if(!$rv) Http::json(['ok'=>false,'error'=>'RATE_VERSION_MISSING'],409);
        $rule=RateRules::fetch($db,(int)$rv['id'],$r['category']);$shared=RateRules::fetchShared($db,(int)$rv['id']);$conflicts=RateRules::conflicts($db,$companyId,$id,(int)$rv['version_no']);
        Http::json(['ok'=>true,'item'=>$r,'version'=>$rv,'service_rule'=>$rule,'shared_rules'=>$shared,'conflicts'=>$conflicts]);
    }
    if (preg_match('#^rates/(\d+)/evaluate$#',$route,$m) && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.view');$id=(int)$m[1];fetchRate($db,$companyId,$id);
        $st=$db->prepare('SELECT id,amount,version_no FROM rate_versions WHERE rate_id=? ORDER BY version_no DESC LIMIT 1');$st->execute([$id]);$rv=$st->fetch();if(!$rv)Http::json(['ok'=>false,'error'=>'RATE_VERSION_MISSING'],409);
        $date=nullableText($_GET['travel_date']??'',10);if($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'travel_date must be YYYY-MM-DD.'],422);
        $paying=isset($_GET['paying_pax'])&&$_GET['paying_pax']!==''?max(0,(int)$_GET['paying_pax']):null;
        $age=isset($_GET['child_age'])&&$_GET['child_age']!==''?(float)$_GET['child_age']:null;
        $height=isset($_GET['child_height_cm'])&&$_GET['child_height_cm']!==''?(float)$_GET['child_height_cm']:null;
        $evaluation=RateRules::evaluate($db,(int)$rv['id'],(float)$rv['amount'],$date,$paying,$age,$height);
        Http::json(['ok'=>true,'rate_id'=>$id,'version_no'=>(int)$rv['version_no'],'evaluation'=>$evaluation]);
    }

    if (preg_match('#^rates/(\d+)/conflicts$#',$route,$m) && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.view');$id=(int)$m[1];$r=fetchRate($db,$companyId,$id);$st=$db->prepare('SELECT version_no FROM rate_versions WHERE rate_id=? ORDER BY version_no DESC LIMIT 1');$st->execute([$id]);$version=(int)$st->fetchColumn();Http::json(['ok'=>true,'items'=>RateRules::conflicts($db,$companyId,$id,$version)]);
    }
    if ($route === 'rates/conflict-summary' && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.view');$st=$db->prepare("SELECT r.id,MAX(rv.version_no) version_no FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id WHERE r.company_id=? AND r.status<>'ARCHIVED' GROUP BY r.id");$st->execute([$companyId]);$items=[];foreach($st->fetchAll() as $x){$c=RateRules::conflicts($db,$companyId,(int)$x['id'],(int)$x['version_no']);if($c)$items[]=['rate_id'=>(int)$x['id'],'conflict_count'=>count($c)];}Http::json(['ok'=>true,'items'=>$items,'count'=>count($items)]);
    }
    if (preg_match('#^documents/(\d+)/bulk-rates$#',$route,$m) && $method === 'POST') {
        Auth::requirePermission($db,$user,'rate.create');$docId=(int)$m[1];$doc=fetchDocument($db,$companyId,$docId);$b=Http::body();$rows=is_array($b['rows']??null)?$b['rows']:[];if(!$rows || count($rows)>250) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Bulk import requires 1–250 reviewed rows.'],422);
        $common=is_array($b['common']??null)?$b['common']:[];$common['source_document_id']=$docId;$common['source_origin']='SUPPLIER_DOCUMENT';if(empty($common['supplier_id']))$common['supplier_id']=(int)($doc['supplier_id']??0);if(empty($common['supplier_id'])) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Assign the supplier before bulk rate import.'],422);
        $db->beginTransaction();$tmp='PENDING-'.bin2hex(random_bytes(6));$db->prepare("INSERT INTO rate_import_batches(company_id,batch_ref,import_type,source_document_id,supplier_id,status,row_count,created_by) VALUES(?,?,'SUPPLIER_MATRIX',?,?, 'PREVIEW',?,?)")->execute([$companyId,$tmp,$docId,(int)$common['supplier_id'],count($rows),$userId]);$batchId=(int)$db->lastInsertId();$batchRef=recordRef('RIMP',$batchId);$db->prepare('UPDATE rate_import_batches SET batch_ref=? WHERE id=?')->execute([$batchRef,$batchId]);
        $created=[];$rowNo=0;foreach($rows as $row){$rowNo++;if(!is_array($row))continue;$payload=array_merge($common,$row);$payload['source_locator']=cleanText($row['source_locator']??("Imported row ".$rowNo),255);$c=createRateDraft($db,$companyId,$userId,$payload);$created[]=$c;$db->prepare("INSERT INTO rate_import_rows(batch_id,row_no,product_name,option_name,amount,currency,row_status,payload_json,created_rate_id) VALUES(?,?,?,?,?,?,'CREATED',?,?)")->execute([$batchId,$rowNo,cleanText($payload['product_name']??'',190),nullableText($payload['option_name']??'',190),(float)$payload['amount'],strtoupper(cleanText($payload['currency']??'VND',3)),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$c['id']]);}
        $db->prepare("UPDATE rate_import_batches SET status='COMMITTED',ready_count=?,created_count=?,committed_at=NOW(),summary_json=? WHERE id=?")->execute([count($rows),count($created),json_encode(['document_ref'=>$doc['document_ref'],'created_refs'=>array_column($created,'rate_ref')],JSON_UNESCAPED_UNICODE),$batchId]);Audit::activity($db,$companyId,'document',$docId,'BULK_RATES_CREATED',count($created)." draft rates created from {$doc['document_ref']}",$userId);$db->commit();Http::json(['ok'=>true,'batch_ref'=>$batchRef,'created_count'=>count($created),'items'=>$created],201);
    }
    if ($route === 'rates/legacy-import' && $method === 'POST') {
        Auth::requirePermission($db,$user,'rate.create');$b=Http::body();$rows=is_array($b['rows']??null)?$b['rows']:[];if(!$rows || count($rows)>500) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Legacy import requires 1–500 reviewed rows.'],422);
        $db->beginTransaction();$tmp='PENDING-'.bin2hex(random_bytes(6));$db->prepare("INSERT INTO rate_import_batches(company_id,batch_ref,import_type,status,row_count,created_by) VALUES(?,?,'LEGACY_JSON','PREVIEW',?,?)")->execute([$companyId,$tmp,count($rows),$userId]);$batchId=(int)$db->lastInsertId();$batchRef=recordRef('LIMP',$batchId);$db->prepare('UPDATE rate_import_batches SET batch_ref=? WHERE id=?')->execute([$batchRef,$batchId]);
        $created=[];$skipped=[];$rowNo=0;foreach($rows as $row){$rowNo++;if(!is_array($row))continue;$supplierId=(int)($row['supplier_id']??0);if(!$supplierId && !empty($row['supplier_name'])){$q=$db->prepare('SELECT id FROM suppliers WHERE company_id=? AND LOWER(name)=LOWER(?) AND status<>\'ARCHIVED\' LIMIT 1');$q->execute([$companyId,cleanText($row['supplier_name'],190)]);$supplierId=(int)($q->fetchColumn()?:0);} $legacyCategory=strtoupper(trim((string)($row['category']??'OTHER')));$legacyBasis=strtoupper(trim((string)($row['rate_basis']??'')));$legacyType=strtoupper(trim((string)($row['rate_type']??'CONTRACT')));$legacyCurrency=strtoupper(trim((string)($row['currency']??'VND')));$validCategory=in_array($legacyCategory,['HOTEL','TRANSPORT','GUIDE','CRUISE','TOUR','ATTRACTION','MEAL','VISA','OTHER'],true);$validBasis=in_array($legacyBasis,['PER_PAX','PER_ROOM_NIGHT','PER_VEHICLE','PER_TRANSFER','PER_DAY','PER_GUIDE_DAY','PER_CABIN','PER_GROUP','PER_SERVICE'],true);$validType=in_array($legacyType,['CONTRACT','PROMOTION','SPECIAL_QUOTE','SERIES','MANUAL_OVERRIDE'],true);$required=trim((string)($row['product_name']??''))!=='' && is_numeric($row['amount']??null) && $validBasis && $validCategory && $validType && strlen($legacyCurrency)===3 && $supplierId>0 && ($legacyType!=='SPECIAL_QUOTE'||!empty($row['linked_trip_ref']));if(!$required){$skipped[]=['row_no'=>$rowNo,'reason'=>'Supplier, product, amount and rate basis are required.'];$db->prepare("INSERT INTO rate_import_rows(batch_id,row_no,product_name,option_name,amount,currency,row_status,message,payload_json) VALUES(?,?,?,?,?,?,'SKIPPED',?,?)")->execute([$batchId,$rowNo,nullableText($row['product_name']??'',190),nullableText($row['option_name']??'',190),is_numeric($row['amount']??null)?(float)$row['amount']:null,nullableText($row['currency']??'',3),'Missing or invalid supplier/product/amount/category/rate basis/currency mapping',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);continue;}
            $payload=$row;$payload['supplier_id']=$supplierId;$payload['source_origin']='LEGACY';$payload['rate_type']=$payload['rate_type']??'CONTRACT';$payload['reusable']=($payload['rate_type']??'')!=='SPECIAL_QUOTE';$c=createRateDraft($db,$companyId,$userId,$payload);$created[]=$c;$db->prepare("INSERT INTO rate_import_rows(batch_id,row_no,product_name,option_name,amount,currency,row_status,payload_json,created_rate_id) VALUES(?,?,?,?,?,?,'CREATED',?,?)")->execute([$batchId,$rowNo,cleanText($payload['product_name'],190),nullableText($payload['option_name']??'',190),(float)$payload['amount'],strtoupper(cleanText($payload['currency']??'VND',3)),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$c['id']]);}
        $status=$skipped?'PARTIAL':'COMMITTED';$db->prepare('UPDATE rate_import_batches SET status=?,ready_count=?,created_count=?,skipped_count=?,committed_at=NOW(),summary_json=? WHERE id=?')->execute([$status,count($created),count($created),count($skipped),json_encode(['created'=>count($created),'skipped'=>$skipped],JSON_UNESCAPED_UNICODE),$batchId]);Audit::activity($db,$companyId,'rate_import',$batchId,'LEGACY_RATE_IMPORT',count($created)." legacy draft rates imported; ".count($skipped)." skipped",$userId);$db->commit();Http::json(['ok'=>true,'batch_ref'=>$batchRef,'created_count'=>count($created),'skipped_count'=>count($skipped),'skipped'=>$skipped],201);
    }
    if ($route === 'rate-imports' && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.reconcile');
        [$page,$size,$offset]=pageParams();
        $st=$db->prepare("SELECT rib.*,s.name supplier_name,d.document_ref,d.original_filename,
                          (SELECT COUNT(*) FROM rate_import_rows x WHERE x.batch_id=rib.id AND x.row_status IN ('SKIPPED','ERROR','WARNING')) unresolved_count
                          FROM rate_import_batches rib
                          LEFT JOIN suppliers s ON s.id=rib.supplier_id
                          LEFT JOIN documents d ON d.id=rib.source_document_id
                          WHERE rib.company_id=?
                          ORDER BY rib.created_at DESC LIMIT $size OFFSET $offset");
        $st->execute([$companyId]);
        $cnt=$db->prepare('SELECT COUNT(*) FROM rate_import_batches WHERE company_id=?');$cnt->execute([$companyId]);
        Http::json(['ok'=>true,'items'=>$st->fetchAll(),'pagination'=>['page'=>$page,'page_size'=>$size,'total'=>(int)$cnt->fetchColumn()]]);
    }
    if (preg_match('#^rate-imports/(\d+)$#',$route,$m) && $method === 'GET') {
        Auth::requirePermission($db,$user,'rate.reconcile');$id=(int)$m[1];
        $st=$db->prepare("SELECT rib.*,s.name supplier_name,d.document_ref,d.original_filename FROM rate_import_batches rib LEFT JOIN suppliers s ON s.id=rib.supplier_id LEFT JOIN documents d ON d.id=rib.source_document_id WHERE rib.company_id=? AND rib.id=? LIMIT 1");
        $st->execute([$companyId,$id]);$batch=$st->fetch();if(!$batch)Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        $rows=$db->prepare('SELECT * FROM rate_import_rows WHERE batch_id=? ORDER BY row_no,id');$rows->execute([$id]);
        Http::json(['ok'=>true,'batch'=>$batch,'rows'=>$rows->fetchAll()]);
    }
    if (preg_match('#^rate-imports/(\d+)/rows/(\d+)$#',$route,$m) && $method === 'PUT') {
        Auth::requirePermission($db,$user,'rate.reconcile');$batchId=(int)$m[1];$rowId=(int)$m[2];$b=Http::body();
        $st=$db->prepare("SELECT rir.*,rib.company_id,rib.import_type,rib.source_document_id,rib.supplier_id batch_supplier_id FROM rate_import_rows rir JOIN rate_import_batches rib ON rib.id=rir.batch_id WHERE rib.company_id=? AND rib.id=? AND rir.id=? LIMIT 1");
        $st->execute([$companyId,$batchId,$rowId]);$row=$st->fetch();if(!$row)Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        if(in_array($row['row_status'],['CREATED','RECONCILED'],true)) Http::json(['ok'=>false,'error'=>'ALREADY_RESOLVED','message'=>'This import row already created a draft rate.'],409);
        $payload=json_decode((string)($row['payload_json']??'{}'),true);if(!is_array($payload))$payload=[];
        $patch=is_array($b['payload']??null)?$b['payload']:$b;$payload=array_merge($payload,$patch);
        if(empty($payload['supplier_id']) && !empty($row['batch_supplier_id']))$payload['supplier_id']=(int)$row['batch_supplier_id'];
        if(!empty($payload['source_document_id'])){$payload['source_document_id']=(int)$payload['source_document_id'];fetchDocument($db,$companyId,(int)$payload['source_document_id']);$payload['source_origin']='SUPPLIER_DOCUMENT';}
        elseif(!empty($row['source_document_id'])){$payload['source_document_id']=(int)$row['source_document_id'];$payload['source_origin']='SUPPLIER_DOCUMENT';}
        elseif(($row['import_type']??'')!=='SUPPLIER_MATRIX'){$payload['source_origin']='LEGACY';}
        $db->beginTransaction();
        $created=createRateDraft($db,$companyId,$userId,$payload);
        $db->prepare("UPDATE rate_import_rows SET product_name=?,option_name=?,amount=?,currency=?,row_status='RECONCILED',payload_json=?,created_rate_id=?,resolved_by=?,resolved_at=NOW(),resolution_note=? WHERE id=? AND batch_id=?")
           ->execute([cleanText($payload['product_name']??'',190),nullableText($payload['option_name']??'',190),(float)$payload['amount'],strtoupper(cleanText($payload['currency']??'VND',3)),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$created['id'],$userId,nullableText($b['resolution_note']??'Reconciled in Product workspace',500),$rowId,$batchId]);
        $counts=$db->prepare("SELECT COUNT(*) total,SUM(row_status IN ('CREATED','RECONCILED')) done,SUM(row_status IN ('SKIPPED','ERROR','WARNING')) unresolved FROM rate_import_rows WHERE batch_id=?");$counts->execute([$batchId]);$c=$counts->fetch();
        $status=((int)$c['unresolved']===0)?'RECONCILED':'PARTIAL';
        $db->prepare('UPDATE rate_import_batches SET status=?,created_count=?,skipped_count=?,error_count=? WHERE id=? AND company_id=?')
           ->execute([$status,(int)$c['done'],(int)$c['unresolved'],0,$batchId,$companyId]);
        Audit::activity($db,$companyId,'rate_import',$batchId,'IMPORT_ROW_RECONCILED',"Import row {$row['row_no']} reconciled to {$created['rate_ref']}",$userId);
        Audit::log($db,$companyId,$userId,'IMPORT_ROW_RECONCILED','rate_import_row',$rowId,$row,['created_rate_id'=>$created['id'],'rate_ref'=>$created['rate_ref']]);
        $db->commit();
        Http::json(['ok'=>true,'created'=>$created,'batch_status'=>$status],201);
    }

    if ($route === 'rates' && $method === 'POST') {
        Auth::requirePermission($db,$user,'rate.create');$b=Http::body();$db->beginTransaction();$created=createRateDraft($db,$companyId,$userId,$b);$db->commit();Http::json(['ok'=>true]+$created,201);
    }
    if (preg_match('#^rates/(\d+)/new-version$#',$route,$m) && $method === 'POST') {
        Auth::requirePermission($db,$user,'rate.edit');$id=(int)$m[1];$r=fetchRate($db,$companyId,$id);$b=Http::body();
        $cur=$db->prepare('SELECT * FROM rate_versions WHERE rate_id=? ORDER BY version_no DESC LIMIT 1');$cur->execute([$id]);$old=$cur->fetch();if(!$old) Http::json(['ok'=>false,'error'=>'RATE_VERSION_MISSING'],409);
        $amount=(float)($b['amount']??$old['amount']);$basis=validEnum(strtoupper(cleanText($b['rate_basis']??$old['rate_basis'],32)),['PER_PAX','PER_ROOM_NIGHT','PER_VEHICLE','PER_TRANSFER','PER_DAY','PER_GUIDE_DAY','PER_CABIN','PER_GROUP','PER_SERVICE'],'rate basis');$tax=validEnum(strtoupper(cleanText($b['tax_basis']??$old['tax_basis'],32)),['UNKNOWN','NET','TAX_INCLUDED','TAX_EXCLUDED','PLUS_PLUS'],'tax basis');$source=(int)($b['source_document_id']??($old['source_document_id']??0));if($source){$src=fetchDocument($db,$companyId,$source);if(!empty($src['supplier_id'])&&(int)$src['supplier_id']!==(int)$r['supplier_id'])Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Source document supplier must match the rate supplier.'],422);}
        $newVersion=(int)$old['version_no']+1;$st=$db->prepare('INSERT INTO rate_versions(rate_id,version_no,amount,currency,rate_basis,valid_from,valid_to,booking_valid_from,booking_valid_to,tax_basis,source_document_id,source_locator,terms,internal_notes,approval_status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'UNREVIEWED\',?)');
        $st->execute([$id,$newVersion,$amount,strtoupper(cleanText($b['currency']??$old['currency'],3)),$basis,nullableText($b['valid_from']??$old['valid_from'],10),nullableText($b['valid_to']??$old['valid_to'],10),nullableText($b['booking_valid_from']??$old['booking_valid_from'],10),nullableText($b['booking_valid_to']??$old['booking_valid_to'],10),$tax,$source?:null,nullableText($b['source_locator']??$old['source_locator'],255),nullableText($b['terms']??$old['terms'],4000),nullableText($b['internal_notes']??$old['internal_notes'],4000),$userId]);$newVersionId=(int)$db->lastInsertId();RateRules::copy($db,(int)$old['id'],$newVersionId,$r['category']);if(!empty($b['_update_rules']))RateRules::save($db,$newVersionId,$r['category'],$b);if(array_key_exists('date_periods',$b)||array_key_exists('child_rules',$b)||array_key_exists('foc_rules',$b))RateRules::saveShared($db,$newVersionId,$b);$db->prepare('UPDATE rates SET updated_by=?,updated_at=NOW(),version_no=version_no+1 WHERE id=?')->execute([$userId,$id]);
        Audit::activity($db,$companyId,'rate',$id,'RATE_VERSION_CREATED',"{$r['rate_ref']} version $newVersion created",$userId);Audit::log($db,$companyId,$userId,'RATE_VERSION_CREATED','rate',$id,$old,['version_no'=>$newVersion,'amount'=>$amount]);Http::json(['ok'=>true,'version_no'=>$newVersion],201);
    }
    if (preg_match('#^rates/(\d+)/approve$#',$route,$m) && $method === 'POST') {
        Auth::requirePermission($db,$user,'rate.approve');$id=(int)$m[1];$r=fetchRate($db,$companyId,$id);$b=Http::body();$version=(int)($b['version_no']??0);
        $st=$db->prepare('SELECT rv.*,d.id doc_exists FROM rate_versions rv LEFT JOIN documents d ON d.id=rv.source_document_id WHERE rv.rate_id=? AND rv.version_no=? LIMIT 1');$st->execute([$id,$version]);$rv=$st->fetch();if(!$rv) Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        $conflicts=RateRules::conflicts($db,$companyId,$id,$version);if($conflicts && empty($b['conflict_reviewed'])) Http::json(['ok'=>false,'error'=>'RATE_CONFLICT_REVIEW_REQUIRED','message'=>'Overlapping approved rate(s) were found. Review the conflicts before approval.','conflicts'=>$conflicts],409);
        if(!$rv['source_document_id'] && $r['rate_type']!=='MANUAL_OVERRIDE') Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'An approved rate must have a source document unless it is a Manual Override.'],422);
        if(!$rv['rate_basis']) Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>'Rate basis is required before approval.'],422);
        $db->beginTransaction();$db->prepare("UPDATE rate_versions SET approval_status='SUPERSEDED' WHERE rate_id=? AND approval_status='APPROVED' AND version_no<>?")->execute([$id,$version]);$db->prepare("UPDATE rate_versions SET approval_status='APPROVED',approved_by=?,approved_at=NOW() WHERE rate_id=? AND version_no=?")->execute([$userId,$id,$version]);$db->prepare("UPDATE rates SET status='ACTIVE',updated_by=?,updated_at=NOW() WHERE id=?")->execute([$userId,$id]);
        Audit::activity($db,$companyId,'rate',$id,'RATE_APPROVED',"{$r['rate_ref']} version $version approved",$userId);Audit::log($db,$companyId,$userId,'RATE_APPROVED','rate',$id,$rv,['version_no'=>$version,'status'=>'APPROVED']);$db->commit();Http::json(['ok'=>true]);
    }
    if (preg_match('#^rates/(\d+)/archive$#',$route,$m) && $method === 'POST') {
        Auth::requirePermission($db,$user,'rate.archive');$id=(int)$m[1];$old=fetchRate($db,$companyId,$id);$db->prepare("UPDATE rates SET status='ARCHIVED',archived_at=NOW(),updated_by=?,version_no=version_no+1 WHERE id=? AND company_id=?")->execute([$userId,$id,$companyId]);Audit::log($db,$companyId,$userId,'RATE_ARCHIVED','rate',$id,$old,['status'=>'ARCHIVED']);Http::json(['ok'=>true]);
    }

    // TASKS / TODAY
    if ($route === 'tasks' && $method === 'GET') {
        Auth::requirePermission($db,$user,'task.view');$scope=cleanText($_GET['scope']??'mine',16);$args=[$companyId];$where='t.company_id=? AND t.status IN (\'OPEN\',\'SNOOZED\')';if($scope!=='company'){$where.=' AND t.owner_user_id=?';$args[]=$userId;}
        $st=$db->prepare("SELECT t.*,u.full_name owner_name,CASE WHEN t.due_at<NOW() THEN 'OVERDUE' WHEN DATE(t.due_at)=CURDATE() THEN 'DUE_TODAY' WHEN t.due_at<=DATE_ADD(NOW(),INTERVAL 7 DAY) THEN 'UPCOMING' ELSE 'LATER' END due_state FROM tasks t JOIN users u ON u.id=t.owner_user_id WHERE $where ORDER BY FIELD(t.priority,'CRITICAL','HIGH','NORMAL'),COALESCE(t.due_at,'2999-12-31') LIMIT 100");$st->execute($args);Http::json(['ok'=>true,'items'=>$st->fetchAll()]);
    }

    // AUDIT
    if ($route === 'audit' && $method === 'GET') {
        Auth::requirePermission($db,$user,'audit.view');[$page,$size,$offset]=pageParams();$st=$db->prepare("SELECT a.id,a.action_code,a.entity_type,a.entity_id,a.ip_address,a.created_at,u.full_name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.company_id=? ORDER BY a.created_at DESC LIMIT $size OFFSET $offset");$st->execute([$companyId]);Http::json(['ok'=>true,'items'=>$st->fetchAll(),'pagination'=>['page'=>$page,'page_size'=>$size]]);
    }

    Http::json(['ok'=>false,'error'=>'ROUTE_NOT_FOUND','route'=>$route],404);
} catch (InvalidArgumentException $e) {
    if($db->inTransaction())$db->rollBack();Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);
} catch (DomainException $e) {
    if($db->inTransaction())$db->rollBack();Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);
} catch (Throwable $e) {
    if($db->inTransaction()) $db->rollBack();
    error_log('[VTA]['.Http::requestId().'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    Http::json(['ok'=>false,'error'=>'SERVER_ERROR','message'=>'The request could not be completed.','request_id'=>Http::requestId()],500);
}
