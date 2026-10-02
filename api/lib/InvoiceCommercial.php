<?php
declare(strict_types=1);
require_once __DIR__.'/BookingIntegrity.php';
final class InvoiceCommercial {
 public static function issue(PDO $db,array $u,int $id,string $date):array {
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Valid issue date required');
  BookingIntegrity::begin($db);try{
   BookingIntegrity::financialFor($db,(int)$u['company_id'],'invoice',$id);
   $q=$db->prepare('SELECT i.*,s.public_snapshot_json FROM customer_invoices i JOIN invoice_issue_snapshots s ON s.invoice_id=i.id WHERE i.company_id=? AND i.id=? FOR UPDATE');$q->execute([$u['company_id'],$id]);$i=$q->fetch();if(!$i)throw new OutOfBoundsException('Issued invoice not found');
   $q=$db->prepare('SELECT document_ref,issue_date FROM invoice_commercial_documents WHERE invoice_id=?');$q->execute([$id]);$old=$q->fetch();if($old){if($old['issue_date']!==$date)throw new DomainException('Commercial document already issued with a different date');$db->commit();return $old;}
   if(!in_array($i['invoice_type'],['PROFORMA','DEPOSIT_REQUEST','PAYMENT_REQUEST','FINAL_REQUEST'],true)||$i['status']==='CANCELLED'||$date<$i['issue_date'])throw new DomainException('Only an issued proforma/payment request can become a commercial document');
   $s=json_decode($i['public_snapshot_json'],true,512,JSON_THROW_ON_ERROR);$s['source_document_ref']=$i['invoice_ref'];$s['invoice_ref']=$i['invoice_ref'].'-C';$s['invoice_type']='CUSTOMER_INVOICE';$s['issue_date']=$date;$s['billing_note']='Commercial document for the same receivable. This does not create an additional amount due. Existing payments remain allocated to this receivable.';
   $json=json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$json);$q=$db->prepare('INSERT INTO invoice_commercial_documents(invoice_id,document_ref,issue_date,public_snapshot_json,content_hash,issued_by) VALUES(?,?,?,?,?,?)');$q->execute([$id,$s['invoice_ref'],$date,$json,$hash,$u['id']]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'COMMERCIAL_DOCUMENT_ISSUED','customer_invoice',$id,null,['hash'=>$hash,'source_ref'=>$i['invoice_ref']]);$db->commit();return ['document_ref'=>$s['invoice_ref'],'issue_date'=>$date];
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function handle(string $route,string $method,PDO $db,array $u):void {
  if(!preg_match('#^invoices/(\d+)/(commercial|commercial-html)$#',$route,$m))return;
  try{
   if($method==='POST'&&$m[2]==='commercial'){Auth::requirePermission($db,$u,'customer_payment.record');Http::json(['ok'=>true]+self::issue($db,$u,(int)$m[1],(string)(Http::body()['issue_date']??date('Y-m-d'))),201);}
   if($method==='GET'&&$m[2]==='commercial-html'){
    Auth::requirePermission($db,$u,'customer_ar.view');$q=$db->prepare('SELECT d.* FROM invoice_commercial_documents d JOIN customer_invoices i ON i.id=d.invoice_id WHERE i.company_id=? AND i.id=?');$q->execute([$u['company_id'],(int)$m[1]]);$r=$q->fetch();if(!$r)throw new OutOfBoundsException('Commercial document not found');$s=json_decode($r['public_snapshot_json'],true,512,JSON_THROW_ON_ERROR);$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>'.$e($s['invoice_ref']).'</title><style>body{font:16px/1.6 system-ui;max-width:850px;margin:40px auto;padding:24px}td,th{padding:12px;text-align:left}</style><h1>Vietnam Travel Advisor</h1><h2>Commercial invoice '.$e($s['invoice_ref']).'</h2><p>Reference '.$e($s['source_document_ref']).' · Booking '.$e($s['booking_ref']).'</p><p>'.$e($s['customer_name']).' · Issued '.$e($s['issue_date']).'</p><table><tr><th>Description</th><th>Quantity</th><th>Amount</th></tr>';
    foreach($s['items'] as $line)echo '<tr><td>'.$e($line['description']).'</td><td>'.$e($line['quantity']).'</td><td>'.$e($line['total']).'</td></tr>';
    echo '</table><h3>Total '.$e($s['currency']).' '.$e($s['total']).'</h3><p>'.$e($s['billing_note']).'</p><p>'.$e($s['disclaimer']).'</p><small>Verification '.$e($r['content_hash']).'</small></html>';exit;
   }
  }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
 }
}
