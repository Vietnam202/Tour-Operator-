<?php
declare(strict_types=1);

/** Permission filter for new quote-derived bookings on shared Operations endpoints. */
final class QuoteVs2Projection {
    public static function filter(PDO $db,array $u,array $payload,string $route): array {
        $cache=[];$isSmart=function(int $id)use($db,&$cache){if(!isset($cache[$id]))$cache[$id]=(bool)QuoteVs2Repository::q($db,'SELECT 1 FROM booking_quote_snapshots s JOIN bookings b ON b.id=s.booking_id WHERE s.booking_id=? AND s.variant_id IS NOT NULL',[$id])->fetchColumn();return $cache[$id];};
        $root=false;if(isset($payload['booking']['id']))$root=$isSmart((int)$payload['booking']['id']);
        if(!$root&&preg_match('#^bookings/(\d+)#',$route,$m))$root=$isSmart((int)$m[1]);
        if(!$root&&preg_match('#^supplier-orders/(\d+)#',$route,$m)){$id=QuoteVs2Repository::q($db,'SELECT booking_id FROM supplier_orders WHERE company_id=? AND id=?',[$u['company_id'],(int)$m[1]])->fetchColumn();if($id)$root=$isSmart((int)$id);}
        $cost=Auth::can($db,(int)$u['id'],'quote.view_cost');$profit=Auth::can($db,(int)$u['id'],'profit.view')&&Auth::can($db,(int)$u['id'],'quote.view_profit');$finance=Auth::can($db,(int)$u['id'],'finance.view');
        $visit=function(array $row,bool $smart=false)use(&$visit,$isSmart,$cost,$profit,$finance,$route){
            if(isset($row['booking_id']))$smart=$smart||$isSmart((int)$row['booking_id']);
            if(isset($row['booking_ref'],$row['id'])&&str_starts_with($route,'bookings'))$smart=$smart||$isSmart((int)$row['id']);
            foreach($row as $key=>$value){
                if($smart&&(!$cost&&preg_match('/cost|unit_price|rate_source|snapshot|payload|confirmed_amount|invoice_amount|variance|revisions|confirmations/i',(string)$key)||!$profit&&preg_match('/profit|margin|markup/i',(string)$key)||!$finance&&in_array($key,['finance','paid_amount','balance','payment_status','confirmed_selling'],true))){unset($row[$key]);continue;}
                if(is_array($value))$row[$key]=$visit($value,$smart);
            }return $row;
        };return $visit($payload,$root);
    }
}
