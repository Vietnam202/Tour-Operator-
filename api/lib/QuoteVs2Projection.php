<?php
declare(strict_types=1);

/** Field-level projection for quote and booking responses; stored rows are never changed. */
final class QuoteVs2Projection {
    public static function safeSchedule(array $days): array {
        $allowed=array_flip(['day','day_key','date','title','route','activities','description','transport_mode','overnight','overnight_type','guide_required','cruise','meals']);
        return array_map(static fn($day)=>is_array($day)?array_intersect_key($day,$allowed):[], $days);
    }

    public static function filter(PDO $db,array $u,array $payload,string $route): array {
        $quote=preg_match('#^(?:quotes(?:/|$)|quote-versions/)#',$route)===1;
        $booking=preg_match('#^(?:bookings(?:/|$)|services/|booking-services/|supplier-orders/|supplier-payables(?:/|$)|supplier-payments(?:/|$)|operations/(?:timeline|departures)(?:/|$)|finance/bookings/)#',$route)===1;
        if(!$quote&&!$booking)return $payload;
        $cost=Auth::can($db,(int)$u['id'],'quote.view_cost');
        $profit=Auth::can($db,(int)$u['id'],'quote.view_profit')&&Auth::can($db,(int)$u['id'],'profit.view');
        $finance=Auth::can($db,(int)$u['id'],'finance.view');
        if($cost&&$profit&&$finance)return $payload;

        // The shared itinerary remains untouched; this only changes its response.
        if(!$cost&&isset($payload['version']['schedule_json'])){
            $days=json_decode((string)$payload['version']['schedule_json'],true);
            $payload['version']['schedule_json']=json_encode(self::safeSchedule(is_array($days)?$days:[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        if(!$cost&&isset($payload['schedule'])&&is_array($payload['schedule'])&&str_contains($route,'smart-costing/context')){
            $payload['schedule']=self::safeSchedule($payload['schedule']);
        }
        // Operations needs supplier identity for dispatch and communication.
        $operational=str_starts_with($route,'operations/')||str_starts_with($route,'supplier-orders/');
        $supplierFinance=str_starts_with($route,'supplier-payables/')||$route==='supplier-payables'||str_starts_with($route,'supplier-payments/');
        $visit=function(array $row)use(&$visit,$cost,$profit,$finance,$booking,$operational,$supplierFinance): array {
            foreach($row as $key=>$value){
                $name=(string)$key;
                if(!$cost){
                    if($name==='cost_json'){$row[$key]='[]';continue;}
                    if($name==='cost_items'){$row[$key]=[];continue;}
                    if($name==='proposal_json'){$row[$key]='{}';continue;}
                    if($name==='metadata'){$row[$key]=[];continue;}
                    if($name==='activity'&&$booking){$row[$key]=[];continue;}
                    if(in_array($name,['revisions','confirmations','payables','supplier_payments','schedules'],true)){unset($row[$key]);continue;}
                    if(in_array($name,['costing_engine','costing_revision'],true))continue;
                    if($supplierFinance&&in_array($name,['amount','paid_amount','balance','bank_fee'],true)){unset($row[$key]);continue;}
                    if(preg_match('/cost|unit_price|unit_rate|rate_(?:id|ref|version|source|snapshot|terms|evidence)|source_document|source_ref|snapshot|payload|confirmed_amount|confirmed_total|invoice_amount|total_amount|variance|evidence|trace|manual_reason|contract|payment_terms|bank_details|^notes$|_notes$|^internal_|^supplier_(?:total|paid|balance)|unit_amount_original|^fx_rate$|^include_agreed_rate$/i',$name)){
                        if(in_array($name,['customer_safe_snapshot','sent_snapshot_json'],true)){if(is_array($value))$row[$key]=$visit($value);continue;}
                        unset($row[$key]);continue;
                    }
                    if(!$operational&&in_array($name,['supplier_id','supplier_name','supplier_ref'],true)){unset($row[$key]);continue;}
                }
                if((!$profit||!$cost)&&preg_match('/profit|margin|markup|^pricing_value$|^pricing_mode$|^rounding_step$|minimum_margin/i',$name)){unset($row[$key]);continue;}
                if(!$finance&&$booking&&(in_array($name,['finance','paid_amount','balance','confirmed_selling'],true)||str_ends_with($name,'payment_status'))){unset($row[$key]);continue;}
                if(is_array($value))$row[$key]=$visit($value);
            }
            return $row;
        };
        return $visit($payload);
    }
}
