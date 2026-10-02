<?php
declare(strict_types=1);
require_once __DIR__.'/BookingIntegrity.php';

final class FinanceLedger {
    public const DISCLAIMER='Commercial/proforma document or payment request only. This is not a Vietnamese VAT e-invoice unless separately issued through an authorized e-invoice system.';
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {return BookingIntegrity::query($db,$sql,$args);}
    private static function tx(PDO $db,callable $fn): array {BookingIntegrity::begin($db);try{$r=$fn();$db->commit();return $r;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    public static function cents($value): int {
        if(!is_scalar($value)||!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',(string)$value))throw new InvalidArgumentException('Amount must be nonnegative with at most two decimal places');
        $parts=explode('.',(string)$value);return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    }
    private static function decimal(int $cents): string {return sprintf('%d.%02d',intdiv($cents,100),$cents%100);}
    private static function date($v): string {$d=is_string($v)?DateTimeImmutable::createFromFormat('!Y-m-d',$v):false;if(!$d||$d->format('Y-m-d')!==$v)throw new InvalidArgumentException('Valid date required');return $v;}
    private static function text($v,int $max=190): string {if(!is_string($v)||trim($v)===''||strlen($v)>$max)throw new InvalidArgumentException('Required text missing or too long');return trim($v);}
    private static function booking(PDO $db,int $cid,int $id,bool $lock=false): array {if($lock)BookingIntegrity::financial($db,$cid,$id);$b=self::q($db,'SELECT b.*,t.customer_id,t.agent_id FROM bookings b JOIN trips t ON t.id=b.trip_id AND t.company_id=b.company_id WHERE b.company_id=? AND b.id=?',[$cid,$id])->fetch();if(!$b)throw new OutOfBoundsException('Booking not found');return $b;}
    private static function party(array $b): array {return $b['agent_id']?['kind'=>'AGENT','id'=>(int)$b['agent_id']]:($b['customer_id']?['kind'=>'CUSTOMER','id'=>(int)$b['customer_id']]:['kind'=>'BOOKING','id'=>(int)$b['id']]);}
    private static function invoice(PDO $db,int $cid,int $id,bool $lock=false): array {if($lock)BookingIntegrity::financialFor($db,$cid,'invoice',$id);$r=self::q($db,'SELECT * FROM customer_invoices WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$cid,$id])->fetch();if(!$r)throw new OutOfBoundsException('Invoice not found');return $r;}
    private static function allocated(PDO $db,int $invoice,?string $date=null): int {
        $date=$date??'9999-12-31';$a=self::q($db,'SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id=? AND allocation_date<=?',[$invoice,$date])->fetchColumn();
        $legacy=self::q($db,'SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE invoice_id=? AND payment_date<=?',[$invoice,$date])->fetchColumn();return self::cents($a)+self::cents($legacy);
    }
    public static function createInvoice(PDO $db,array $u,int $bid,array $body): array {
        return self::tx($db,function()use($db,$u,$bid,$body){$b=self::booking($db,(int)$u['company_id'],$bid,true);$currency=$body['currency']??$b['selling_currency'];if($currency!==$b['selling_currency'])throw new InvalidArgumentException('Invoice currency must match booking selling currency');
            $schedule=null;if(!empty($body['payment_schedule_id'])){$schedule=self::q($db,"SELECT * FROM payment_schedules WHERE booking_id=? AND id=? AND direction='AR' AND status<>'CANCELLED' FOR UPDATE",[$bid,(int)$body['payment_schedule_id']])->fetch();if(!$schedule)throw new InvalidArgumentException('Payment schedule not found');if(self::q($db,'SELECT 1 FROM invoice_payment_schedules WHERE schedule_id=?',[$schedule['id']])->fetchColumn())throw new DomainException('Schedule already has an invoice');$body['subtotal']=$body['subtotal']??$schedule['amount'];$body['due_date']=$body['due_date']??$schedule['due_date'];}
            $type=$body['invoice_type']??'PROFORMA';if(!in_array($type,['PROFORMA','CUSTOMER_INVOICE','DEPOSIT_REQUEST','PAYMENT_REQUEST','FINAL_REQUEST'],true))throw new InvalidArgumentException('Use the credit/refund workflow for adjustments; unsupported invoice type');
            $items=$body['items']??[['description'=>'Travel package '.$b['booking_ref'],'quantity'=>1,'unit_price'=>$body['subtotal']??$b['confirmed_selling']]];
            if(!is_array($items)||!$items||count($items)>100)throw new InvalidArgumentException('Invoice items required');$total=0;$safe=[];
            foreach($items as $item){$desc=self::text($item['description']??'',500);$qty=filter_var($item['quantity']??1,FILTER_VALIDATE_INT);if(!$qty||$qty<1||$qty>10000)throw new InvalidArgumentException('Positive integral quantity required');$unit=self::cents($item['unit_price']??'');$line=$unit*$qty;$total+=$line;$safe[]=['description'=>$desc,'quantity'=>$qty,'unit_price'=>self::decimal($unit),'total'=>self::decimal($line)];}
            if($total<=0||$total>1000000000000)throw new InvalidArgumentException('Positive invoice amount required');
            // Each invoice represents a distinct instalment. Duplicate proforma/commercial billing is rejected.
            $existing=self::cents(self::q($db,"SELECT COALESCE(SUM(total),0) FROM customer_invoices WHERE booking_id=? AND status<>'CANCELLED' AND invoice_type NOT IN ('RECEIPT','CREDIT_NOTE')",[$bid])->fetchColumn());
            if($existing+$total>self::cents($b['confirmed_selling']))throw new DomainException('Invoice total exceeds unbilled booking value; reconcile existing proforma/requests instead of double billing');
            $issue=self::date($body['issue_date']??date('Y-m-d'));$due=self::date($body['due_date']??$issue);if($due<$issue)throw new InvalidArgumentException('Due date precedes issue date');
            if($schedule&&($total!==self::cents($schedule['amount'])||$currency!==$schedule['currency']||$due!==$schedule['due_date']))throw new InvalidArgumentException('Invoice must match schedule amount, currency and due date');
            $ref='INV-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(6)));$amount=self::decimal($total);
            self::q($db,"INSERT INTO customer_invoices(company_id,invoice_ref,booking_id,invoice_type,status,issue_date,due_date,currency,subtotal,total,balance,line_items_json,notes,created_by) VALUES(?,?,?,?,'DRAFT',?,?,?,?,?,?,?,?,?)",[$u['company_id'],$ref,$bid,$type,$issue,$due,$currency,$amount,$amount,$amount,json_encode($safe,JSON_THROW_ON_ERROR),self::DISCLAIMER,$u['id']]);$id=(int)$db->lastInsertId();
            if($schedule)self::q($db,'INSERT INTO invoice_payment_schedules(invoice_id,schedule_id) VALUES(?,?)',[$id,$schedule['id']]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'INVOICE_DRAFT_CREATED','customer_invoice',$id,null,['total'=>$amount,'currency'=>$currency]);return ['id'=>$id,'invoice_ref'=>$ref,'status'=>'DRAFT'];
        });
    }
    public static function issueInvoice(PDO $db,array $u,int $id): array {
        return self::tx($db,function()use($db,$u,$id){$i=self::invoice($db,(int)$u['company_id'],$id,true);
            $old=self::q($db,'SELECT content_hash FROM invoice_issue_snapshots WHERE invoice_id=?',[$id])->fetchColumn();if($old)return ['id'=>$id,'content_hash'=>$old];if($i['status']!=='DRAFT')throw new DomainException('Legacy issued invoices need reconciliation before snapshot conversion');
            $b=self::booking($db,(int)$u['company_id'],(int)$i['booking_id']);$snapshot=['invoice_ref'=>$i['invoice_ref'],'invoice_type'=>$i['invoice_type'],'booking_ref'=>$b['booking_ref'],'customer_name'=>$b['lead_guest_name'],'issue_date'=>$i['issue_date'],'due_date'=>$i['due_date'],'currency'=>$i['currency'],'total'=>$i['total'],'items'=>json_decode($i['line_items_json'],true),'disclaimer'=>self::DISCLAIMER];$json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$json);
            self::q($db,'INSERT INTO invoice_issue_snapshots(invoice_id,public_snapshot_json,content_hash,issued_by) VALUES(?,?,?,?)',[$id,$json,$hash,$u['id']]);self::q($db,"UPDATE customer_invoices SET status='ISSUED',issued_by=?,issued_at=NOW() WHERE id=?",[$u['id'],$id]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'INVOICE_ISSUED','customer_invoice',$id,null,['hash'=>$hash]);return ['id'=>$id,'content_hash'=>$hash];
        });
    }
    public static function receipt(PDO $db,array $u,int $bid,array $body): array {
        return self::tx($db,function()use($db,$u,$bid,$body){$b=self::booking($db,(int)$u['company_id'],$bid,true);$party=self::party($b);$amount=self::cents($body['amount']??'');if($amount<=0)throw new InvalidArgumentException('Positive receipt amount required');
            $currency=$body['currency']??$b['selling_currency'];if($currency!==$b['selling_currency'])throw new InvalidArgumentException('Receipt currency must match the party booking currency');$date=self::date($body['payment_date']??date('Y-m-d'));$key=self::text($body['idempotency_key']??'',80);$ref=self::text($body['transaction_reference']??'');
            $hash=hash('sha256',json_encode([$party,$amount,$currency,$date,$ref],JSON_THROW_ON_ERROR));$old=self::q($db,'SELECT id,payload_hash FROM customer_receipts WHERE company_id=? AND idempotency_key=?',[$u['company_id'],$key])->fetch();if($old){if(!hash_equals($old['payload_hash'],$hash))throw new DomainException('Receipt key already used for different content');return ['id'=>(int)$old['id']];}
            self::q($db,'INSERT INTO customer_receipts(company_id,party_kind,party_id,receipt_ref,payment_date,amount,currency,transaction_reference,idempotency_key,payload_hash,recorded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$u['company_id'],$party['kind'],$party['id'],'PAY-'.strtoupper(bin2hex(random_bytes(8))),$date,self::decimal($amount),$currency,$ref,$key,$hash,$u['id']]);$id=(int)$db->lastInsertId();Audit::log($db,(int)$u['company_id'],(int)$u['id'],'CUSTOMER_RECEIPT_RECORDED','customer_receipt',$id,null,['amount'=>self::decimal($amount),'currency'=>$currency]);return ['id'=>$id];
        });
    }
    public static function allocate(PDO $db,array $u,int $receipt,array $body): array {
        return self::tx($db,function()use($db,$u,$receipt,$body){$r=self::q($db,'SELECT * FROM customer_receipts WHERE company_id=? AND id=?',[$u['company_id'],$receipt])->fetch();if(!$r)throw new OutOfBoundsException('Receipt not found');$key=self::text($body['request_key']??'',80);$date=self::date($body['allocation_date']??date('Y-m-d'));if($date<$r['payment_date'])throw new InvalidArgumentException('Allocation cannot precede receipt');
            $items=$body['items']??[];if(!is_array($items)||!$items||count($items)>100)throw new InvalidArgumentException('Allocation items required');$normalized=[];$sum=0;
            foreach($items as $item){$invoice=(int)($item['invoice_id']??0);$amount=self::cents($item['amount']??'');if($invoice<1||$amount<=0||isset($normalized[$invoice]))throw new InvalidArgumentException('Distinct invoice IDs and positive amounts required');$normalized[$invoice]=$amount;$sum+=$amount;}ksort($normalized);
            $bookings=[];foreach(array_keys($normalized) as $invoice)$bookings[]=BookingIntegrity::parentId($db,(int)$u['company_id'],'invoice',$invoice);$bookings=array_unique($bookings);sort($bookings,SORT_NUMERIC);foreach($bookings as $booking)BookingIntegrity::financial($db,(int)$u['company_id'],$booking);
            $r=self::q($db,'SELECT * FROM customer_receipts WHERE company_id=? AND id=? FOR UPDATE',[$u['company_id'],$receipt])->fetch();if(!$r)throw new OutOfBoundsException('Receipt not found');
            $old=self::q($db,'SELECT invoice_id,amount,allocation_date FROM payment_allocations WHERE receipt_id=? AND request_key=? ORDER BY invoice_id',[$receipt,$key])->fetchAll();if($old){$previous=[];foreach($old as $a){$previous[(int)$a['invoice_id']]=self::cents($a['amount']);if($a['allocation_date']!==$date)throw new DomainException('Allocation retry changed date');}if($previous!==$normalized)throw new DomainException('Allocation retry changed amounts');return ['receipt_id'=>$receipt,'allocated'=>self::decimal($sum)];}
            $used=self::cents(self::q($db,'SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE receipt_id=?',[$receipt])->fetchColumn());if($used+$sum>self::cents($r['amount']))throw new DomainException('Allocation exceeds unallocated receipt balance');
            foreach($normalized as $invoice=>$amount){$i=self::invoice($db,(int)$u['company_id'],$invoice,true);if(in_array($i['status'],['DRAFT','CANCELLED'],true)||in_array($i['invoice_type'],['RECEIPT','CREDIT_NOTE'],true))throw new DomainException('Only issued receivable invoices can receive allocations');
                if($i['currency']!==$r['currency'])throw new DomainException('Cross-currency allocation requires a separate reviewed conversion');if($i['issue_date']>$date)throw new InvalidArgumentException('Allocation precedes invoice date');$party=self::party(self::booking($db,(int)$u['company_id'],(int)$i['booking_id']));if($party['kind']!==$r['party_kind']||$party['id']!==(int)$r['party_id'])throw new DomainException('Invoice belongs to a different customer/partner');
                $paid=self::allocated($db,$invoice);if($paid+$amount>self::cents($i['total']))throw new DomainException('Allocation exceeds invoice balance');
                self::q($db,'INSERT INTO payment_allocations(receipt_id,invoice_id,amount,allocation_date,request_key,allocated_by) VALUES(?,?,?,?,?,?)',[$receipt,$invoice,self::decimal($amount),$date,$key,$u['id']]);$balance=self::cents($i['total'])-$paid-$amount;
                self::q($db,'UPDATE customer_invoices SET paid_amount=?,balance=?,status=? WHERE id=?',[self::decimal($paid+$amount),self::decimal($balance),$balance===0?'PAID':'PART_PAID',$invoice]);
                self::q($db,'UPDATE payment_schedules ps JOIN invoice_payment_schedules link ON link.schedule_id=ps.id SET ps.status=? WHERE link.invoice_id=?',[$balance===0?'PAID':'PART_PAID',$invoice]);
                $bookingPaid=self::cents(self::q($db,"SELECT COALESCE(SUM(paid_amount),0) FROM customer_invoices WHERE booking_id=? AND status NOT IN ('DRAFT','CANCELLED')",[$i['booking_id']])->fetchColumn());$bookingValue=self::cents(self::booking($db,(int)$u['company_id'],(int)$i['booking_id'])['confirmed_selling']);
                self::q($db,'UPDATE bookings SET customer_payment_status=? WHERE id=?',[$bookingPaid>=$bookingValue?'PAID':($bookingPaid>0?'PART_PAID':'UNPAID'),$i['booking_id']]);
            }
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'PAYMENT_ALLOCATED','customer_receipt',$receipt,null,['request_key'=>$key,'invoice_amounts'=>$normalized]);return ['receipt_id'=>$receipt,'allocated'=>self::decimal($sum)];
        });
    }
    public static function statement(PDO $db,int $cid,string $kind,int $party,string $from,string $asof): array {
        self::date($from);self::date($asof);if($from>$asof||!in_array($kind,['CUSTOMER','AGENT','BOOKING'],true))throw new InvalidArgumentException('Invalid statement period or party');
        $condition=match($kind){'AGENT'=>'t.agent_id=?','CUSTOMER'=>'t.agent_id IS NULL AND t.customer_id=?','BOOKING'=>'t.agent_id IS NULL AND t.customer_id IS NULL AND b.id=?'};
        $invoices=self::q($db,"SELECT i.*,b.booking_ref FROM customer_invoices i JOIN bookings b ON b.id=i.booking_id JOIN trips t ON t.id=b.trip_id WHERE b.company_id=? AND $condition AND i.status NOT IN ('DRAFT','CANCELLED') AND i.invoice_type NOT IN ('RECEIPT','CREDIT_NOTE') AND i.issue_date<=? ORDER BY i.issue_date,i.id",[$cid,$party,$asof])->fetchAll();
        $receipts=self::q($db,'SELECT * FROM customer_receipts WHERE company_id=? AND party_kind=? AND party_id=? AND payment_date<=? ORDER BY payment_date,id',[$cid,$kind,$party,$asof])->fetchAll();$currencies=[];$outstanding=[];
        $init=function(string $c)use(&$currencies){if(!isset($currencies[$c]))$currencies[$c]=['opening'=>0,'invoices'=>0,'payments'=>0,'closing'=>0,'unallocated_receipts'=>0,'aging'=>['current'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'90_plus'=>0]];};
        foreach($invoices as $i){$c=$i['currency'];$init($c);$amount=self::cents($i['total']);if($i['issue_date']<$from)$currencies[$c]['opening']+=$amount;else$currencies[$c]['invoices']+=$amount;
            $balance=max(0,$amount-self::allocated($db,(int)$i['id'],$asof));if($balance>0){$days=$i['due_date']?(int)(new DateTimeImmutable($i['due_date']))->diff(new DateTimeImmutable($asof))->format('%r%a'):0;$band=$days<=0?'current':($days<=30?'1_30':($days<=60?'31_60':($days<=90?'61_90':'90_plus')));$currencies[$c]['aging'][$band]+=$balance;$outstanding[]=['invoice_id'=>$i['id'],'invoice_ref'=>$i['invoice_ref'],'booking_ref'=>$i['booking_ref'],'currency'=>$c,'balance'=>self::decimal($balance),'aging'=>$band];}
        }
        foreach($receipts as $r){$c=$r['currency'];$init($c);$amount=self::cents($r['amount']);if($r['payment_date']<$from)$currencies[$c]['opening']-=$amount;else$currencies[$c]['payments']+=$amount;$allocated=self::cents(self::q($db,'SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE receipt_id=? AND allocation_date<=?',[$r['id'],$asof])->fetchColumn());$currencies[$c]['unallocated_receipts']+=$amount-$allocated;}
        // Preserve historical v2.4 receipts in the party ledger without inventing bank transactions.
        $legacy=self::q($db,"SELECT p.* FROM customer_payments p JOIN bookings b ON b.id=p.booking_id JOIN trips t ON t.id=b.trip_id WHERE b.company_id=? AND $condition AND p.payment_date<=?",[$cid,$party,$asof])->fetchAll();
        foreach($legacy as $r){$c=$r['currency'];$init($c);$amount=self::cents($r['amount']);if($r['payment_date']<$from)$currencies[$c]['opening']-=$amount;else$currencies[$c]['payments']+=$amount;if(!$r['invoice_id'])$currencies[$c]['unallocated_receipts']+=$amount;}
        foreach($currencies as &$c){$c['closing']=$c['opening']+$c['invoices']-$c['payments'];foreach(['opening','invoices','payments','closing','unallocated_receipts'] as $key)$c[$key]=$c[$key]/100;foreach($c['aging'] as &$value)$value/=100;unset($value);}unset($c);
        return ['party_kind'=>$kind,'party_id'=>$party,'from'=>$from,'as_of'=>$asof,'currencies'=>$currencies,'outstanding'=>$outstanding,'receipts'=>$receipts,'legacy_receipts'=>$legacy];
    }
    public static function reconcile(PDO $db,array $u,int $payable,array $body): array {
        return self::tx($db,function()use($db,$u,$payable,$body){BookingIntegrity::financialFor($db,(int)$u['company_id'],'payable',$payable);$p=self::q($db,'SELECT * FROM supplier_payables WHERE company_id=? AND id=? FOR UPDATE',[$u['company_id'],$payable])->fetch();if(!$p)throw new OutOfBoundsException('Payable not found');if($p['status']==='CANCELLED')throw new DomainException('Payable cancelled');
            $amount=self::cents($body['invoice_amount']??'');$number=self::text($body['supplier_invoice_no']??'',120);$reason=self::text($body['reason']??'',1000);if(($body['currency']??$p['currency'])!==$p['currency'])throw new InvalidArgumentException('Invoice currency mismatch');
            $old=self::q($db,'SELECT * FROM supplier_invoice_reconciliations WHERE payable_id=?',[$payable])->fetch();if($old){if(self::cents($old['invoice_amount'])!==$amount||$old['supplier_invoice_no']!==$number)throw new DomainException('Existing reconciliation must be reviewed before replacing supplier invoice');return ['id'=>(int)$old['id'],'status'=>$old['status']];}
            $confirmed=self::cents($p['total_amount']);$variance=($amount-$confirmed)/100;
            self::q($db,"INSERT INTO supplier_invoice_reconciliations(payable_id,supplier_invoice_no,currency,confirmed_amount,invoice_amount,variance,reason,recorded_by) VALUES(?,?,?,?,?,?,?,?)",[$payable,$number,$p['currency'],$p['total_amount'],self::decimal($amount),$variance,$reason,$u['id']]);$id=(int)$db->lastInsertId();Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SUPPLIER_INVOICE_REVIEW','supplier_reconciliation',$id,null,['variance'=>$variance]);return ['id'=>$id,'status'=>'REVIEW_REQUIRED','variance'=>$variance];
        });
    }
    public static function approveReconciliation(PDO $db,array $u,int $id): array {
        return self::tx($db,function()use($db,$u,$id){$booking=BookingIntegrity::financialFor($db,(int)$u['company_id'],'reconciliation',$id);$r=self::q($db,'SELECT r.*,p.company_id,p.service_id,p.id payable,p.total_amount,p.paid_amount FROM supplier_invoice_reconciliations r JOIN supplier_payables p ON p.id=r.payable_id WHERE p.company_id=? AND r.id=? FOR UPDATE',[$u['company_id'],$id])->fetch();if(!$r)throw new OutOfBoundsException('Reconciliation not found');
            if($r['service_id']&&!self::q($db,'SELECT id FROM booking_services WHERE id=? AND booking_id=?',[$r['service_id'],$booking['id']])->fetchColumn())throw new DomainException('Payable service does not belong to this booking');
            if($r['status']==='APPROVED')return ['id'=>$id,'status'=>'APPROVED'];
            $amount=self::cents($r['invoice_amount']);$paid=self::cents($r['paid_amount']);if($paid>$amount)throw new DomainException('Supplier overpayment requires credit/refund reconciliation');
            self::q($db,"UPDATE supplier_invoice_reconciliations SET status='APPROVED',approved_by=?,approved_at=NOW() WHERE id=?",[$u['id'],$id]);
            self::q($db,'UPDATE supplier_payables SET total_amount=?,balance=?,status=?,supplier_invoice_no=? WHERE id=?',[self::decimal($amount),self::decimal($amount-$paid),$amount===$paid?'PAID':($paid?'PART_PAID':'UNPAID'),$r['supplier_invoice_no'],$r['payable']]);
            if($r['service_id'])self::q($db,'UPDATE booking_services SET actual_cost=? WHERE id=?',[self::decimal($amount),$r['service_id']]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SUPPLIER_INVOICE_APPROVED','supplier_reconciliation',$id,null,['invoice_amount'=>$r['invoice_amount']]);return ['id'=>$id,'status'=>'APPROVED'];
        });
    }
    public static function supplierPayment(PDO $db,array $u,int $payable,array $body): array {
        return self::tx($db,function()use($db,$u,$payable,$body){BookingIntegrity::financialFor($db,(int)$u['company_id'],'payable',$payable);$p=self::q($db,'SELECT * FROM supplier_payables WHERE company_id=? AND id=? FOR UPDATE',[$u['company_id'],$payable])->fetch();if(!$p)throw new OutOfBoundsException('Payable not found');$key=self::text($body['idempotency_key']??'',80);$amount=self::cents($body['amount']??'');$date=self::date($body['payment_date']??date('Y-m-d'));$reference=self::text($body['transaction_reference']??'');$currency=$body['currency']??$p['currency'];
            if($currency!==$p['currency']||$amount<=0)throw new InvalidArgumentException('Positive same-currency supplier payment required');$hash=hash('sha256',json_encode([$payable,$amount,$date,$currency,$reference],JSON_THROW_ON_ERROR));$old=self::q($db,'SELECT * FROM supplier_payment_keys WHERE company_id=? AND request_key=?',[$u['company_id'],$key])->fetch();if($old){if(!hash_equals($old['payload_hash'],$hash))throw new DomainException('Payment key already used');return ['id'=>(int)$old['payment_id']];}
            if($p['status']==='CANCELLED'||$amount>self::cents($p['balance']))throw new DomainException('Payment exceeds payable balance');
            $ref='SPAY-'.strtoupper(bin2hex(random_bytes(8)));self::q($db,'INSERT INTO supplier_payments(company_id,payment_ref,payable_id,booking_id,supplier_id,payment_date,amount,currency,transaction_reference,recorded_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[$u['company_id'],$ref,$payable,$p['booking_id'],$p['supplier_id'],$date,self::decimal($amount),$currency,$reference,$u['id']]);$id=(int)$db->lastInsertId();
            self::q($db,'INSERT INTO supplier_payment_keys(company_id,request_key,payment_id,payload_hash) VALUES(?,?,?,?)',[$u['company_id'],$key,$id,$hash]);$paid=self::cents($p['paid_amount'])+$amount;$balance=self::cents($p['total_amount'])-$paid;
            self::q($db,'UPDATE supplier_payables SET paid_amount=?,balance=?,status=? WHERE id=?',[self::decimal($paid),self::decimal($balance),$balance===0?'PAID':'PART_PAID',$payable]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SUPPLIER_PAYMENT_RECORDED','supplier_payment',$id,null,['amount'=>self::decimal($amount),'currency'=>$currency]);return ['id'=>$id,'payment_ref'=>$ref];
        });
    }
    public static function profit(PDO $db,int $cid,int $bid): array {
        $b=self::booking($db,$cid,$bid);$fx=(float)self::q($db,'SELECT fx_rate FROM quote_versions WHERE quote_id=? AND version_no=?',[$b['quote_id'],$b['confirmed_quote_version_no']])->fetchColumn();
        $snap=self::q($db,'SELECT internal_snapshot_json FROM booking_quote_snapshots WHERE booking_id=?',[$bid])->fetchColumn();if($snap)$fx=(float)json_decode($snap,true)['accepted_option']['snapshot']['fx_rate'];if($fx<=0||$b['selling_currency']!=='USD')throw new DomainException('Reviewed USD/VND conversion snapshot required');
        $rows=self::q($db,"SELECT * FROM booking_services WHERE booking_id=? AND booking_status<>'CANCELLED'",[$bid])->fetchAll();$planned=$forecast=$actual=0.0;$complete=(bool)$rows;$confirmed=true;
        foreach($rows as $s){if(!in_array($s['cost_currency'],['USD','VND'],true))throw new DomainException('Unreviewed service currency');$factor=$s['cost_currency']==='USD'?1:1/$fx;$planned+=(float)$s['planned_cost']*$factor;$forecast+=(float)($s['confirmed_cost']??$s['planned_cost'])*$factor;
            if($s['confirmed_cost']===null)$confirmed=false;
            $reconciled=self::q($db,"SELECT 1 FROM supplier_invoice_reconciliations r JOIN supplier_payables p ON p.id=r.payable_id WHERE p.service_id=? AND p.booking_id=? AND r.status='APPROVED'",[$s['id'],$bid])->fetchColumn();
            if($s['actual_cost']===null||!$reconciled)$complete=false;else$actual+=(float)$s['actual_cost']*$factor;
        }
        $invoices=self::q($db,"SELECT * FROM customer_invoices WHERE booking_id=? AND status NOT IN ('DRAFT','CANCELLED') AND invoice_type NOT IN ('RECEIPT','CREDIT_NOTE')",[$bid])->fetchAll();$invoiced=$paid=0;$commercial=0;
        foreach($invoices as $i){if($i['currency']!==$b['selling_currency'])throw new DomainException('Invoice currency mismatch');$invoiced+=self::cents($i['total']);$paid+=self::allocated($db,(int)$i['id']);if($i['invoice_type']==='CUSTOMER_INVOICE'||self::q($db,'SELECT 1 FROM invoice_commercial_documents WHERE invoice_id=?',[$i['id']])->fetchColumn())$commercial+=self::cents($i['total']);}
        $apOpen=(int)self::q($db,"SELECT COUNT(*) FROM supplier_payables WHERE booking_id=? AND status<>'CANCELLED' AND balance>0",[$bid])->fetchColumn();$selling=self::cents($b['confirmed_selling']);$ar=max(0,$selling-$paid)/100;
        $final=$complete&&$confirmed&&$commercial===$selling&&$ar<=0.000001&&$apOpen===0&&!empty($b['operation_completed_at']);
        return ['currency'=>'USD','selling'=>$selling/100,'invoiced'=>$invoiced/100,'allocated_payments'=>$paid/100,'customer_balance'=>$ar,'expected_profit'=>round($selling/100-$planned,2),'forecast_profit'=>round($selling/100-$forecast,2),'forecast_all_confirmed'=>$confirmed,'actual_profit'=>$complete&&$commercial===$selling?round($commercial/100-$actual,2):null,'actual_final'=>$final,'actual_cost_complete'=>$complete,'supplier_payables_open'=>$apOpen,'fx_rate'=>$fx];
    }
    public static function compatibilitySummary(PDO $db,int $bid): array {
        $cid=self::q($db,'SELECT company_id FROM bookings WHERE id=?',[$bid])->fetchColumn();if(!$cid)return [];$p=self::profit($db,(int)$cid,$bid);$total=$paid=$balance=0.0;
        foreach(self::q($db,"SELECT currency,total_amount,paid_amount,balance FROM supplier_payables WHERE booking_id=? AND status<>'CANCELLED'",[$bid])->fetchAll() as $ap){
            if(!in_array($ap['currency'],['USD','VND'],true))throw new DomainException('Supplier currency requires reviewed conversion');$factor=$ap['currency']==='USD'?$p['fx_rate']:1;$total+=(float)$ap['total_amount']*$factor;$paid+=(float)$ap['paid_amount']*$factor;$balance+=(float)$ap['balance']*$factor;
        }
        return ['customer_total'=>$p['selling'],'customer_received'=>$p['allocated_payments'],'customer_balance'=>$p['customer_balance'],'supplier_total'=>round($total,2),'supplier_paid'=>round($paid,2),'supplier_balance'=>round($balance,2),'supplier_currency'=>'VND','planned_cost_usd'=>$p['selling']-$p['expected_profit'],'confirmed_cost_usd'=>$p['selling']-$p['forecast_profit'],'actual_cost_usd'=>$p['actual_profit']!==null?$p['selling']-$p['actual_profit']:null,'expected_profit'=>$p['expected_profit'],'forecast_profit'=>$p['forecast_profit'],'actual_profit'=>$p['actual_profit'],'actual_complete'=>$p['actual_final'],'actual_final'=>$p['actual_final'],'fx_rate'=>$p['fx_rate']];
    }
    public static function paymentSchedule(PDO $db,array $u,int $bid,array $body): array {
        return self::tx($db,function()use($db,$u,$bid,$body){$b=self::booking($db,(int)$u['company_id'],$bid,true);$items=$body['items']??[];if(!is_array($items)||!$items||count($items)>30)throw new InvalidArgumentException('One to 30 payment schedule items required');
            $existing=self::q($db,"SELECT * FROM payment_schedules WHERE booking_id=? AND direction='AR' AND status<>'CANCELLED' FOR UPDATE",[$bid])->fetchAll();$sum=0;
            if(!empty($body['replace'])){foreach($existing as $e)if(self::q($db,'SELECT 1 FROM invoice_payment_schedules WHERE schedule_id=?',[$e['id']])->fetchColumn())throw new DomainException('Invoiced schedules cannot be replaced');self::q($db,"UPDATE payment_schedules SET status='CANCELLED' WHERE booking_id=? AND direction='AR'",[$bid]);}
            else foreach($existing as $e){if($e['currency']!==$b['selling_currency'])throw new DomainException('Reconcile legacy schedule currency first');$sum+=self::cents($e['amount']);}
            $ids=[];foreach($items as $item){$amount=self::cents($item['amount']??'');$currency=$item['currency']??$b['selling_currency'];if($amount<=0||$currency!==$b['selling_currency'])throw new InvalidArgumentException('Positive same-currency instalments required');$sum+=$amount;if($sum>self::cents($b['confirmed_selling']))throw new DomainException('Scheduled amounts exceed confirmed selling');$date=self::date($item['due_date']??'');$label=self::text($item['label']??'Payment');
                self::q($db,"INSERT INTO payment_schedules(booking_id,direction,label,percentage,amount,currency,due_date,status) VALUES(?,'AR',?,?,?,?,?,'UNPAID')",[$bid,$label,round($amount/self::cents($b['confirmed_selling'])*100,4),self::decimal($amount),$currency,$date]);$ids[]=(int)$db->lastInsertId();}
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'PAYMENT_SCHEDULE_CREATED','booking',$bid,null,['schedule_ids'=>$ids,'scheduled_total'=>self::decimal($sum)]);return ['ids'=>$ids];
        });
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        try{
            if($method==='GET'&&$route==='finance/ar'){
                Auth::requirePermission($db,$u,'customer_ar.view');
                $rows=self::q($db,"SELECT b.id,b.booking_ref,b.lead_guest_name,b.start_date,b.confirmed_selling total,b.selling_currency currency,b.customer_payment_status status FROM bookings b WHERE b.company_id=? AND b.operations_status<>'CANCELLED' ORDER BY b.start_date LIMIT 500",[$u['company_id']])->fetchAll();
                foreach($rows as &$row){$paid=0;$due=null;foreach(self::q($db,"SELECT id,total,due_date FROM customer_invoices WHERE booking_id=? AND currency=? AND status NOT IN ('DRAFT','CANCELLED') AND invoice_type NOT IN ('CREDIT_NOTE','RECEIPT')",[$row['id'],$row['currency']])->fetchAll() as $invoice){$amount=self::allocated($db,(int)$invoice['id']);$paid+=$amount;if($amount<self::cents($invoice['total'])&&$invoice['due_date']&&($due===null||$invoice['due_date']<$due))$due=$invoice['due_date'];}$row['paid']=self::decimal($paid);$row['balance']=self::decimal(max(0,self::cents($row['total'])-$paid));$row['next_due']=$due;}unset($row);
                Http::json(['ok'=>true,'items'=>$rows]);
            }
            if($method==='POST'&&preg_match('#^bookings/(\d+)/payment-schedules$#',$route,$m)){Auth::requirePermission($db,$u,'customer_payment.record');Http::json(['ok'=>true]+self::paymentSchedule($db,$u,(int)$m[1],Http::body()),201);}
            if($method==='POST'&&preg_match('#^bookings/(\d+)/customer-payments$#',$route)){
                Auth::requirePermission($db,$u,'customer_payment.record');
                throw new DomainException('Use Customer Receipts and Payment Allocation so one bank transaction can cover multiple invoices');
            }
            if($method==='GET'&&preg_match('#^invoices/(\d+)/html$#',$route,$m)){
                Auth::requirePermission($db,$u,'customer_ar.view');self::invoice($db,(int)$u['company_id'],(int)$m[1]);
                $raw=self::q($db,'SELECT public_snapshot_json FROM invoice_issue_snapshots WHERE invoice_id=?',[(int)$m[1]])->fetchColumn();if(!$raw)throw new DomainException('Issue this invoice before printing');$s=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");
                echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>'.$e($s['invoice_ref']).'</title><style>body{font:16px/1.6 system-ui;margin:40px auto;max-width:850px;padding:20px}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ccc;text-align:left;padding:12px}</style><h1>Vietnam Travel Advisor</h1><h2>'.$e(str_replace('_',' ',$s['invoice_type'])).'</h2><p>'.$e($s['invoice_ref']).' · '.$e($s['booking_ref']).'</p><p>Customer: '.$e($s['customer_name']).'</p><p>Issued: '.$e($s['issue_date']).' · Due: '.$e($s['due_date']).'</p><table><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Total</th></tr>';
                foreach($s['items'] as $item)echo '<tr><td>'.$e($item['description']).'</td><td>'.$e($item['quantity']).'</td><td>'.$e($item['unit_price']).'</td><td>'.$e($item['total']).'</td></tr>';
                echo '</table><h2>Total: '.$e($s['currency']).' '.$e($s['total']).'</h2><p>'.$e($s['disclaimer']).'</p></html>';exit;
            }
            if(preg_match('#^bookings/(\d+)/(invoices|receipts|profit-v3|statement-v3)$#',$route,$m)){
                $id=(int)$m[1];$action=$m[2];
                if($method==='POST'&&$action==='invoices'){Auth::requirePermission($db,$u,'customer_payment.record');Http::json(['ok'=>true]+self::createInvoice($db,$u,$id,Http::body()),201);}
                if($method==='POST'&&$action==='receipts'){Auth::requirePermission($db,$u,'customer_payment.record');Http::json(['ok'=>true]+self::receipt($db,$u,$id,Http::body()),201);}
                if($method==='GET'&&$action==='profit-v3'){Auth::requirePermission($db,$u,'profit.view');Http::json(['ok'=>true]+self::profit($db,(int)$u['company_id'],$id));}
                if($method==='GET'&&$action==='statement-v3'){Auth::requirePermission($db,$u,'customer_ar.view');$party=self::party(self::booking($db,(int)$u['company_id'],$id));Http::json(['ok'=>true]+self::statement($db,(int)$u['company_id'],$party['kind'],$party['id'],$_GET['from']??'1900-01-01',$_GET['as_of']??date('Y-m-d')));}
            }
            if($method==='POST'&&preg_match('#^invoices/(\d+)/issue$#',$route,$m)){Auth::requirePermission($db,$u,'customer_payment.record');Http::json(['ok'=>true]+self::issueInvoice($db,$u,(int)$m[1]));}
            if($method==='POST'&&preg_match('#^receipts/(\d+)/allocate$#',$route,$m)){Auth::requirePermission($db,$u,'customer_payment.record');Http::json(['ok'=>true]+self::allocate($db,$u,(int)$m[1],Http::body()));}
            if($method==='POST'&&preg_match('#^payables/(\d+)/(reconcile|payments)$#',$route,$m)){Auth::requirePermission($db,$u,'supplier_payment.record');$result=$m[2]==='reconcile'?self::reconcile($db,$u,(int)$m[1],Http::body()):self::supplierPayment($db,$u,(int)$m[1],Http::body());Http::json(['ok'=>true]+$result,201);}
            if($method==='POST'&&preg_match('#^supplier-reconciliations/(\d+)/approve$#',$route,$m)){Auth::requirePermission($db,$u,'finance.close');Http::json(['ok'=>true]+self::approveReconciliation($db,$u,(int)$m[1]));}
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}
