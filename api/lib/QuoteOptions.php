<?php
declare(strict_types=1);

require_once __DIR__.'/QuoteCostItems.php';
require_once __DIR__.'/QuoteVs2.php';
final class QuoteOptions {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
    private static function atomic(PDO $db,callable $fn): array {if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$db->beginTransaction();try{$out=$fn();$db->commit();return $out;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    private static function json($v): string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function number($v,float $min=0,float $max=100000000): float {if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<$min||(float)$v>$max)throw new InvalidArgumentException('Numeric value outside allowed range');return (float)$v;}
    private static function text($v,int $max=190): string {if(!is_string($v)||trim($v)===''||strlen($v)>$max)throw new InvalidArgumentException('Invalid text');return trim($v);}
    public static function version(PDO $db,int $company,int $id,bool $lock=false): array {
        $v=self::q($db,'SELECT v.*,q.company_id,q.trip_id,q.inquiry_id,q.quote_ref,q.status quote_status,t.market,t.trip_ref,t.lead_contact_name,t.lead_whatsapp,t.lead_email,t.sales_owner_id,t.operations_owner_id FROM quote_versions v JOIN quotes q ON q.id=v.quote_id JOIN trips t ON t.id=q.trip_id AND t.company_id=q.company_id WHERE q.company_id=? AND v.id=?'.($lock?' FOR UPDATE':''),[$company,$id])->fetch();
        if(!$v)throw new OutOfBoundsException('Quote not found');return $v;
    }
    public static function pricing(float $cost,int $pay,string $mode,float $value,float $step=0): array {
        if($pay<1||!is_finite($cost)||$cost<0||$value<0||!is_finite($value)||$step<0||!is_finite($step))throw new InvalidArgumentException('Invalid pricing input');
        $per=$cost/$pay;
        $sell=match($mode){'MARKUP'=>$per*(1+$value/100),'TARGET_MARGIN'=>$value<100?$per/(1-$value/100):throw new InvalidArgumentException('Margin must be below 100%'),'MANUAL'=>$value,default=>throw new InvalidArgumentException('Invalid pricing mode')};
        if($step>0 && $mode!=='MANUAL')$sell=ceil(($sell-0.00000001)/$step)*$step;
        $sell=round($sell,2);$total=round($sell*$pay,2);$profit=round($total-$cost,2);
        return ['cost_total'=>round($cost,2),'selling_per_pax'=>$sell,'total_selling'=>$total,'profit'=>$profit,'margin_pct'=>$total>0?round($profit/$total*100,4):0,'markup_pct'=>$cost>0?round($profit/$cost*100,4):0,'pricing_mode'=>$mode,'pricing_value'=>$value,'rounding_step'=>$step];
    }
    private static function mutable(array $v): void {if(in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true))throw new DomainException('Issued quote is immutable; create a new revision');}
    private static function guestSegments(array $source): array {
        $counts=[];
        foreach(['adults','children','infants','foc','total_guests','paying_pax'] as $field){
            $value=$source[$field]??null;
            if((!is_int($value)&&!is_string($value))||!preg_match('/^[0-9]+$/',(string)$value)||filter_var($value,FILTER_VALIDATE_INT)===false)
                throw new DomainException('PAX_SEGMENT_REVIEW_REQUIRED: Create a new revision, enter reviewed adults, children and infants, recost every option, then approve, send and accept the revised quote. Existing sent snapshots remain unchanged.');
            $counts[$field]=(int)$value;
        }
        if($counts['total_guests']<1||$counts['paying_pax']<1||$counts['paying_pax']+$counts['foc']>$counts['total_guests']||$counts['adults']+$counts['children']+$counts['infants']+$counts['foc']!==$counts['total_guests'])
            throw new DomainException('PAX_SEGMENT_REVIEW_REQUIRED: Review adults, children, infants and FOC against total guests before costing. FOC is a separate guest count; no adult/child breakdown is inferred.');
        return $counts;
    }
    public static function save(PDO $db,array $user,int $version,array $body): array {
        return self::atomic($db,function()use($db,$user,$version,$body){
            $cid=(int)$user['company_id'];$v=self::version($db,$cid,$version,true);self::mutable($v);QuoteVs2Repository::legacy($v);
            if(self::q($db,'SELECT 1 FROM quote_sent_bundles WHERE quote_version_id=?',[$version])->fetchColumn())throw new DomainException('Sent bundle is immutable');
            $segments=self::guestSegments($v);$total=$segments['total_guests'];$pay=$segments['paying_pax'];$foc=$segments['foc'];
            if($total<1||$pay<1||$pay+$foc>$total)throw new InvalidArgumentException('Correct total guests, paying pax and FOC first');
            $level=$body['hotel_level']??'';if(!in_array($level,['3*','4*','5*'],true))throw new InvalidArgumentException('Hotel level required');
            $label=self::text($body['label']??$level);$lines=$body['lines']??[];
            if(!is_array($lines)||count($lines)<1||count($lines)>500)throw new InvalidArgumentException('One to 500 cost lines required');
            $fx=self::number($body['fx_rate']??$v['fx_rate'],0.000001,1000000);$cost=0;$saved=[];$manual=false;
            foreach($lines as $line){
                if(!is_array($line))throw new InvalidArgumentException('Invalid cost line');
                if(!empty($line['derived_transport_extra']))continue;
                $line['reason']=$line['reason']??$line['manual_reason']??'';
                $basis=$line['charge_basis']??'PAYING_PAX';if(!empty($line['rate_version_id'])){$basis=self::q($db,'SELECT rv.rate_basis FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id WHERE r.company_id=? AND rv.id=?',[$cid,(int)$line['rate_version_id']])->fetchColumn()?:$basis;}
                $pax=self::number($line['pax']??QuoteCostItems::defaultPax($v,$basis),0.01,10000);$qty=self::number($line['qty']??1,0.01,10000);
                $date=self::text($line['service_date']??$v['start_date']??'',10);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Valid service date required');
                $rateVersion=(int)($line['rate_version_id']??0);$rate=null;$transport=null;
                if($rateVersion){
                    $identity=self::q($db,'SELECT r.category,r.destination FROM rates r JOIN rate_versions v ON v.rate_id=r.id WHERE r.company_id=? AND v.id=?',[$cid,$rateVersion])->fetch();
                    if(!$identity)throw new InvalidArgumentException('Rate not in this company');
                    $candidates=RateEngine::match($db,$cid,['category'=>$identity['category'],'destination'=>$identity['destination']??'','travel_date'=>$date,'market'=>$v['market']??'','pax'=>$pay,'trip_ref'=>$v['trip_ref']]);
                    foreach($candidates as $candidate)if((int)$candidate['rate_version_id']===$rateVersion)$rate=$candidate;
                    if(!$rate||$rate['conflict'])throw new DomainException('Rate is unavailable, unapproved, blacked out or conflicted');
                    if(!in_array($rate['tax_basis'],['NET','TAX_INCLUDED'],true))throw new DomainException('Tax-exclusive/unknown rates require reviewed final cost as a manual line');
                    $unit=(float)$rate['effective_amount'];$currency=$rate['currency'];$supplier=(int)$rate['supplier_id'];$category=$rate['category'];$name=$rate['product_name'];
                    if($category==='TRANSPORT'){
                        $rules=self::q($db,'SELECT * FROM transport_rate_rules WHERE rate_version_id=?',[$rateVersion])->fetch();if(!$rules)throw new DomainException('Approved transport capacity and costing rules required');
                        $usage=$line['transport_usage']??['vehicles'=>$pax,'days'=>$qty,'hours'=>0,'km'=>0];if(!is_array($usage))throw new InvalidArgumentException('Invalid transport usage');$usage['pax']=$total;
                        $transport=RateEngine::transportCost($rate,$rules,$usage);$pax=$transport['pax'];$qty=$transport['qty'];$unit=$transport['unit_price'];$rate['transport_rules']=$rules;$rate['transport_usage']=$usage;
                    }
                    if($rate['rate_basis']==='PER_PAX'&&$pax!== (float)$total){self::text($line['reason']??'',1000);$manual=true;}
                    $source='APPROVED_RATE';
                }else{
                    $manual=true;$unit=self::number($line['unit_price']??-1);$currency=$line['currency']??'';$supplier=(int)($line['supplier_id']??0);
                    if(!self::q($db,"SELECT id FROM suppliers WHERE company_id=? AND id=? AND status='ACTIVE'",[$cid,$supplier])->fetchColumn())throw new InvalidArgumentException('Active supplier required');
                    $category=$line['category']??'OTHER';$name=self::text($line['service_name']??'');$source='MANUAL';self::text($line['reason']??'',1000);
                }
                if(!in_array($currency,['USD','VND'],true))throw new DomainException('A reviewed currency conversion is required for currencies other than USD/VND');
                if(!in_array($category,['TRANSPORT','GUIDE','HOTEL','MEAL','ATTRACTION','TOUR','CRUISE','VISA','OTHER'],true))throw new InvalidArgumentException('Invalid category');
                $amount=round($pax*$qty*$unit,2);$usd=$currency==='USD'?$amount:round($amount/$fx,2);$cost+=$usd;
                $saved[]=['charge_basis'=>$basis,'service_name'=>$name,'category'=>$category,'service_date'=>$date,'pax'=>$pax,'qty'=>$qty,'unit_price'=>$unit,'total'=>$amount,'currency'=>$currency,'cost_usd'=>$usd,'supplier_id'=>$supplier,'rate_version_id'=>$rateVersion?:null,'rate_snapshot'=>$rate,'transport_usage'=>$transport?$rate['transport_usage']:null,'source_type'=>$source,'manual_reason'=>$line['reason']?:null];
                if($transport&&$transport['extras']>0){$extra=$transport['extras'];$extraUsd=$currency==='USD'?$extra:round($extra/$fx,2);$cost+=$extraUsd;$saved[]=['derived_transport_extra'=>true,'service_name'=>$name.' — transport supplements','category'=>$category,'service_date'=>$date,'pax'=>1,'qty'=>1,'unit_price'=>$extra,'total'=>$extra,'currency'=>$currency,'cost_usd'=>$extraUsd,'supplier_id'=>$supplier,'rate_version_id'=>$rateVersion,'rate_snapshot'=>$rate,'source_type'=>'APPROVED_RATE','manual_reason'=>null];}
            }
            $mode=$body['pricing_mode']??'MARKUP';$value=self::number($body['pricing_value']??15);$step=self::number($body['rounding_step']??0,0,10000);
            if(!$saved)throw new InvalidArgumentException('At least one base service line required');
            $pricing=self::pricing(round($cost,2),$pay,$mode,$value,$step);
            if($pricing['total_selling']<=0)throw new InvalidArgumentException('Positive selling value required');
            $prior=self::q($db,'SELECT snapshot_json FROM quote_options WHERE quote_version_id=? AND hotel_level=?',[$version,$level])->fetchColumn();$prior=$prior?json_decode($prior,true):[];
            $review=!empty($prior['review_required'])||count(array_filter($lines,fn($l)=>!empty($l['review_required'])))>0;
            if($review&&!empty($body['review_copied'])){self::text($body['review_reason']??'',1000);$review=false;}
            if(!array_filter($saved,fn($l)=>empty($l['rate_version_id'])))$review=false;
            $snapshot=$segments+['review_required'=>$review,'review_reason'=>$body['review_reason']??null,'fx_rate'=>$fx,'selling_currency'=>'USD','lines'=>$saved,'pricing'=>$pricing,'manual_review_required'=>$manual];
            $existing=self::q($db,'SELECT id FROM quote_options WHERE quote_version_id=? AND hotel_level=?',[$version,$level])->fetchColumn();
            if($existing){self::q($db,'UPDATE quote_options SET label=?,snapshot_json=?,created_by=? WHERE id=?',[$label,self::json($snapshot),$user['id'],$existing]);$id=(int)$existing;}
            else{self::q($db,'INSERT INTO quote_options(quote_version_id,label,hotel_level,snapshot_json,created_by) VALUES(?,?,?,?,?)',[$version,$label,$level,self::json($snapshot),$user['id']]);$id=(int)$db->lastInsertId();}
            self::q($db,'DELETE FROM quote_bundle_approvals WHERE quote_version_id=?',[$version]);
            self::q($db,"UPDATE quote_versions SET version_status='DRAFT',approved_by=NULL,approved_at=NULL WHERE id=?",[$version]);
            $display=self::q($db,"SELECT snapshot_json FROM quote_options WHERE quote_version_id=? ORDER BY CASE hotel_level WHEN '4*' THEN 0 ELSE 1 END,hotel_level LIMIT 1",[$version])->fetchColumn();$display=json_decode($display,true);$p=$display['pricing'];
            self::q($db,'UPDATE quote_versions SET total_cost=?,cost_per_paying_pax=?,selling_per_pax=?,total_selling=?,profit_amount=?,margin_pct=?,markup_pct=? WHERE id=?',[$p['cost_total']*$display['fx_rate'],$p['cost_total']/$pay,$p['selling_per_pax'],$p['total_selling'],$p['profit'],$p['margin_pct'],$p['markup_pct'],$version]);
            self::q($db,"UPDATE quotes SET status='DRAFT' WHERE id=?",[$v['quote_id']]);
            Audit::log($db,$cid,(int)$user['id'],'QUOTE_OPTION_SAVED','quote_option',$id,null,['hotel_level'=>$level,'pricing'=>$pricing]);return ['id'=>$id,'pricing'=>$pricing];
        });
    }
    public static function bundle(PDO $db,array $v): array {
        if(QuoteVs2Repository::engine($v)==='VS2_1')return Vs2Snapshots::bundle($db,$v);
        $rows=self::q($db,'SELECT id,label,hotel_level,snapshot_json FROM quote_options WHERE quote_version_id=? ORDER BY hotel_level',[$v['id']])->fetchAll();
        if(!$rows)throw new DomainException('At least one priced option required');
        $segments=self::guestSegments($v);
        foreach($rows as &$row){$row['snapshot']=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);unset($row['snapshot_json']);
            $optionSegments=self::guestSegments($row['snapshot']);
            foreach($segments as $field=>$count)if($optionSegments[$field]!==$count)throw new DomainException('Guest counts changed; recost every option');
        }unset($row);
        $schedule=json_decode($v['schedule_json']??'[]',true,512,JSON_THROW_ON_ERROR);if(!$schedule||!is_array($schedule))throw new DomainException('Itinerary required');
        $publicDays=[];foreach($schedule as $i=>$day){if(!is_array($day))throw new InvalidArgumentException('Invalid itinerary');$clean=['day'=>$i+1];foreach(['date','title','description','meals','overnight'] as $key){if(isset($day[$key])&&!is_string($day[$key]))throw new InvalidArgumentException('Itinerary text required');$clean[$key]=(string)($day[$key]??'');}$publicDays[]=$clean;}
        return $segments+['document_language'=>$v['document_language']??'en','quote_ref'=>$v['quote_ref'],'version_no'=>(int)$v['version_no'],'tour_name'=>$v['tour_name'],'start_date'=>$v['start_date'],'end_date'=>$v['end_date'],'schedule'=>$publicDays,'included'=>$v['included_text'],'excluded'=>$v['excluded_text'],'terms'=>$v['terms_text'],'options'=>$rows];
    }
    public static function publicBundle(array $bundle): array {
        if(($bundle['schema']??'')==='VS2_1')return Vs2Snapshots::publicBundle($bundle);
        $bundle['options']=array_map(fn($o)=>['id'=>(int)$o['id'],'label'=>$o['label'],'hotel_level'=>$o['hotel_level'],'selling_per_pax'=>$o['snapshot']['pricing']['selling_per_pax'],'total_selling'=>$o['snapshot']['pricing']['total_selling'],'currency'=>$o['snapshot']['selling_currency']],$bundle['options']);return $bundle;
    }
    public static function publicSchedule($schedule): array {
        if(!is_array($schedule)||!$schedule)throw new InvalidArgumentException('Itinerary required');$safe=[];
        foreach($schedule as $i=>$day){if(!is_array($day))throw new InvalidArgumentException('Invalid itinerary day');$clean=['day'=>$i+1];foreach(['date','title','description','meals','overnight'] as $key)$clean[$key]=is_string($day[$key]??'')?(string)($day[$key]??''):'';$safe[]=$clean;}
        return $safe;
    }
    public static function approve(PDO $db,array $user,int $version,string $reason,?int $expected=null): array {
        return self::atomic($db,function()use($db,$user,$version,$reason,$expected){$v=self::actionVersion($db,$user,$version,$expected);self::mutable($v);$bundle=self::bundle($db,$v);foreach($bundle['options'] as $option)if(!empty($option['snapshot']['review_required']))throw new DomainException('Review copied option lines before approval');$reason=self::text($reason,1000);
            $hash=hash('sha256',self::json($bundle));self::q($db,'INSERT INTO quote_bundle_approvals(quote_version_id,content_hash,reason,approved_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash),reason=VALUES(reason),approved_by=VALUES(approved_by),approved_at=NOW()',[$version,$hash,$reason,$user['id']]);
            self::q($db,"UPDATE quote_versions SET version_status='APPROVED',approved_by=?,approved_at=NOW() WHERE id=?",[$user['id'],$version]);self::q($db,"UPDATE quotes SET status='APPROVED' WHERE id=?",[$v['quote_id']]);
            Audit::log($db,(int)$user['company_id'],(int)$user['id'],'QUOTE_BUNDLE_APPROVED','quote_version',$version,null,['hash'=>$hash,'reason'=>$reason]);return ['content_hash'=>$hash];
        });
    }
    public static function send(PDO $db,array $user,int $version,?int $expected=null): array {
        return self::atomic($db,function()use($db,$user,$version,$expected){$v=self::actionVersion($db,$user,$version,$expected);
            $old=self::q($db,'SELECT public_snapshot_json FROM quote_sent_bundles WHERE quote_version_id=?',[$version])->fetchColumn();if($old)return ['customer_safe_snapshot'=>json_decode($old,true)];
            if(QuoteVs2Repository::engine($v)==='VS2_1')QuoteVs2Validator::assertValid($db,$v);
            if($v['version_status']!=='APPROVED')throw new DomainException('Approve this quote bundle before sending');
            $bundle=self::bundle($db,$v);$hash=hash('sha256',self::json($bundle));$approved=self::q($db,'SELECT content_hash FROM quote_bundle_approvals WHERE quote_version_id=?',[$version])->fetchColumn();
            if(!$approved||!hash_equals($approved,$hash))throw new DomainException('Quote changed since approval');
            $public=self::publicBundle($bundle);
            self::q($db,'INSERT INTO quote_sent_bundles(quote_version_id,public_snapshot_json,internal_snapshot_json,content_hash,sent_by) VALUES(?,?,?,?,?)',[$version,self::json($public),self::json($bundle),$hash,$user['id']]);
            self::q($db,"UPDATE quote_versions SET version_status='SENT',sent_by=?,sent_at=NOW(),sent_snapshot_json=? WHERE id=?",[$user['id'],self::json($public),$version]);self::q($db,"UPDATE quotes SET status='SENT' WHERE id=?",[$v['quote_id']]);
            if($v['inquiry_id'])self::q($db,"UPDATE inquiries SET status='SENT',updated_by=? WHERE id=?",[$user['id'],$v['inquiry_id']]);
            if(QuoteVs2Repository::engine($v)==='VS2_1'){
                self::q($db,"UPDATE inquiries SET next_action='Follow up quotation',next_action_due=DATE_ADD(NOW(),INTERVAL 2 DAY) WHERE id=?",[$v['inquiry_id']]);
                self::q($db,"INSERT INTO tasks(company_id,title,entity_type,entity_id,owner_user_id,due_at,priority,status,source,rule_code) SELECT ?,'Follow up quotation','quote',?,?,DATE_ADD(NOW(),INTERVAL 2 DAY),'NORMAL','OPEN','AUTOMATION','QUOTE_FOLLOWUP' WHERE NOT EXISTS(SELECT 1 FROM tasks WHERE company_id=? AND entity_type='quote' AND entity_id=? AND rule_code='QUOTE_FOLLOWUP' AND status IN ('OPEN','SNOOZED'))",[$user['company_id'],$v['quote_id'],$user['id'],$user['company_id'],$v['quote_id']]);
            }
            Audit::log($db,(int)$user['company_id'],(int)$user['id'],'QUOTE_BUNDLE_SENT','quote_version',$version,null,['hash'=>$hash]);return ['customer_safe_snapshot'=>$public];
        });
    }
    public static function confirm(PDO $db,array $user,int $version,int $option,int $variant=0,string $sentHash='',?int $expected=null): array {
        return self::atomic($db,function()use($db,$user,$version,$option,$variant,$sentHash,$expected){$v=self::actionVersion($db,$user,$version,$expected);
            if(QuoteVs2Repository::engine($v)==='VS2_1')return Vs2Snapshots::confirm($db,$user,$v,$option,$variant,$sentHash);
            $sent=self::q($db,'SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$version])->fetch();if(!$sent)throw new DomainException('Only sent options can be accepted');
            $old=self::q($db,'SELECT option_id FROM quote_acceptances WHERE quote_version_id=?',[$version])->fetchColumn();if($old){if((int)$old!==$option)throw new DomainException('Accepted option cannot be changed');return ['option_id'=>$option];}
            if($v['version_status']!=='SENT')throw new DomainException('This version is no longer available for acceptance');
            $bundle=json_decode($sent['internal_snapshot_json'],true,512,JSON_THROW_ON_ERROR);$found=false;foreach($bundle['options'] as $o)if((int)$o['id']===$option)$found=true;if(!$found)throw new InvalidArgumentException('Choose an option from this sent quote');
            self::q($db,'INSERT INTO quote_acceptances(quote_version_id,option_id,sent_content_hash,accepted_by) VALUES(?,?,?,?)',[$version,$option,$sent['content_hash'],$user['id']]);
            self::q($db,"UPDATE quote_versions SET version_status='CONFIRMED' WHERE id=?",[$version]);self::q($db,"UPDATE quotes SET status='CONFIRMED',confirmed_version_no=?,updated_by=? WHERE id=?",[$v['version_no'],$user['id'],$v['quote_id']]);self::q($db,"UPDATE trips SET lifecycle_stage='CONFIRMED' WHERE id=?",[$v['trip_id']]);
            if($v['inquiry_id'])self::q($db,"UPDATE inquiries SET status='CONFIRMED',updated_by=? WHERE id=?",[$user['id'],$v['inquiry_id']]);
            Audit::log($db,(int)$user['company_id'],(int)$user['id'],'QUOTE_OPTION_ACCEPTED','quote_version',$version,null,['option_id'=>$option,'hash'=>$sent['content_hash']]);return ['option_id'=>$option];
        });
    }
    public static function booking(PDO $db,array $user,int $version,?int $expected=null): array {
        return self::atomic($db,function()use($db,$user,$version,$expected){$cid=(int)$user['company_id'];$uid=(int)$user['id'];$v=self::actionVersion($db,$user,$version,$expected);
            $old=self::q($db,'SELECT id,booking_ref FROM bookings WHERE company_id=? AND quote_id=?',[$cid,$v['quote_id']])->fetch();if($old)return $old;
            $selection=QuoteVs2Repository::engine($v)==='VS2_1'?'a.variant_id':'NULL variant_id';
            $accepted=self::q($db,'SELECT a.option_id,'.$selection.',s.internal_snapshot_json,s.public_snapshot_json,s.content_hash FROM quote_acceptances a JOIN quote_sent_bundles s ON s.quote_version_id=a.quote_version_id WHERE a.quote_version_id=?',[$version])->fetch();
            if(!$accepted||$v['version_status']!=='CONFIRMED')throw new DomainException('Confirm a sent option first');
            $bundle=json_decode($accepted['internal_snapshot_json'],true,512,JSON_THROW_ON_ERROR);$option=null;foreach($bundle['options'] as $o)if((int)$o['id']===(int)$accepted['option_id']&&(!($accepted['variant_id']??null)||(int)($o['variant_id']??0)===(int)$accepted['variant_id']))$option=$o;if(!$option)throw new RuntimeException('Accepted snapshot missing');$snap=$option['snapshot'];
            $segments=self::guestSegments($bundle);$optionSegments=self::guestSegments($snap);
            if($segments!==$optionSegments)throw new DomainException('PAX_SEGMENT_REVIEW_REQUIRED: Accepted bundle and option guest counts differ; create a reviewed revision before booking.');
            $ref='BKG-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(5)));
            self::q($db,"INSERT INTO bookings(company_id,booking_ref,trip_id,quote_id,confirmed_quote_version_no,lead_guest_name,lead_whatsapp,lead_email,start_date,end_date,adults,children,infants,foc,total_guests,paying_pax,selling_currency,confirmed_selling,operations_status,sales_owner_id,operations_owner_id,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'NEW_BOOKING',?,?,?,?)",[$cid,$ref,$v['trip_id'],$v['quote_id'],$bundle['version_no'],$v['lead_contact_name'],$v['lead_whatsapp'],$v['lead_email'],$bundle['start_date'],$bundle['end_date'],$segments['adults'],$segments['children'],$segments['infants'],$segments['foc'],$segments['total_guests'],$segments['paying_pax'],$snap['selling_currency'],$snap['pricing']['total_selling'],$v['sales_owner_id'],$v['operations_owner_id']?:$uid,$uid,$uid]);$id=(int)$db->lastInsertId();
            $smart=QuoteVs2Repository::engine($v)==='VS2_1';
            foreach($snap['lines'] as $i=>$line){$args=[$id,sprintf('SVC-%03d',$i+1),$line['category'],$line['service_name'],$line['service_date'],$line['pax'],$line['qty'],$line['supplier_id'],$line['total'],$line['currency'],$v['quote_ref'].' V'.$v['version_no'].' '.$option['hotel_level']];if($smart)$args[]=$line['notes']??null;self::q($db,"INSERT INTO booking_services(booking_id,service_ref,category,service_name,service_date,pax,qty,supplier_id,booking_status,planned_cost,cost_currency,source_type,source_ref".($smart?',notes':'').") VALUES(?,?,?,?,?,?,?,?,'PLANNED',?,?,'QUOTE_COST',?".($smart?',?':'').")",$args);}
            $public=json_decode($accepted['public_snapshot_json'],true,512,JSON_THROW_ON_ERROR);$public['options']=array_values(array_filter($public['options'],fn($o)=>(int)$o['id']===(int)$accepted['option_id']&&(!($accepted['variant_id']??null)||(int)($o['variant_id']??0)===(int)$accepted['variant_id'])));
            self::q($db,'INSERT INTO booking_quote_snapshots(booking_id,quote_version_id,option_id,public_snapshot_json,internal_snapshot_json,source_hash) VALUES(?,?,?,?,?,?)',[$id,$version,$accepted['option_id'],self::json($public),self::json(['quote'=>$bundle,'accepted_option'=>$option]),$accepted['content_hash']]);
            if($accepted['variant_id']??null)self::q($db,'UPDATE booking_quote_snapshots SET variant_id=? WHERE booking_id=?',[$accepted['variant_id'],$id]);
            self::q($db,"UPDATE trips SET lifecycle_stage='BOOKING' WHERE id=?",[$v['trip_id']]);Audit::log($db,$cid,$uid,'BOOKING_FROM_ACCEPTED_OPTION','booking',$id,null,['option_id'=>$accepted['option_id'],'source_hash'=>$accepted['content_hash']]);return ['id'=>$id,'booking_ref'=>$ref];
        });
    }
    private static function actionVersion(PDO $db,array $u,int $version,?int $expected): array {
        $v=self::version($db,(int)$u['company_id'],$version);
        return QuoteVs2Repository::engine($v)==='VS2_1'?QuoteVs2Repository::lock($db,(int)$u['company_id'],$version,$expected):self::version($db,(int)$u['company_id'],$version,true);
    }
    public static function handle(string $route,string $method,PDO $db,array $user): void {
        try{
            if($route==='v3/rate-match' && $method==='GET'){
                Auth::requirePermission($db,$user,'rate.view');
                Http::json(['ok'=>true,'items'=>RateEngine::match($db,(int)$user['company_id'],$_GET)]);
            }
            if(preg_match('#^quote-versions/(\d+)/options$#',$route,$m)){
                Auth::requirePermission($db,$user,$method==='GET'?'quote.view_cost':'quote.edit');$v=self::version($db,(int)$user['company_id'],(int)$m[1]);
                if($method==='POST')Http::json(['ok'=>true]+self::save($db,$user,(int)$m[1],Http::body()),201);
                if($method==='GET'){
                    $rows=self::q($db,'SELECT * FROM quote_options WHERE quote_version_id=? ORDER BY hotel_level',[$v['id']])->fetchAll();
                    foreach($rows as &$r){$r['snapshot']=json_decode($r['snapshot_json'],true);unset($r['snapshot_json']);if(!Auth::can($db,(int)$user['id'],'quote.view_profit'))unset($r['snapshot']['pricing']['profit'],$r['snapshot']['pricing']['margin_pct'],$r['snapshot']['pricing']['markup_pct']);}unset($r);
                    Http::json(['ok'=>true,'items'=>$rows]);
                }
            }
            if($method==='POST'&&preg_match('#^quotes/(\d+)/(approve|send|confirm|create-booking)$#',$route,$m)){
                $version=self::q($db,'SELECT v.id FROM quotes q JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=CASE WHEN ?=\'create-booking\' THEN q.confirmed_version_no ELSE q.current_version_no END WHERE q.company_id=? AND q.id=?',[$m[2],$user['company_id'],(int)$m[1]])->fetchColumn();
                if(!$version||!self::q($db,'SELECT 1 FROM quote_options WHERE quote_version_id=? LIMIT 1',[$version])->fetchColumn())return;
                $action=$m[2];Auth::requirePermission($db,$user,['approve'=>'quote.approve','send'=>'quote.send','confirm'=>'quote.confirm','create-booking'=>'booking.manage'][$action]);$b=Http::body();
                $result=match($action){'approve'=>self::approve($db,$user,(int)$version,(string)($b['reason']??'')),'send'=>self::send($db,$user,(int)$version),'confirm'=>self::confirm($db,$user,(int)$version,(int)($b['option_id']??0)),'create-booking'=>self::booking($db,$user,(int)$version)};Http::json(['ok'=>true]+$result,$action==='create-booking'?201:200);
            }
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>str_starts_with($e->getMessage(),'PAX_SEGMENT_REVIEW_REQUIRED:')?'PAX_SEGMENT_REVIEW_REQUIRED':'CONFLICT','message'=>$e->getMessage()],409);}
    }
}
