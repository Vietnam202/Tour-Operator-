<?php
declare(strict_types=1);
final class QuoteExport {
 public static function html(array $s):string {
  $lang=($s['document_language']??'en')==='vi'?'vi':'en';
  $labels=['Quotation'=>'Báo giá','Version'=>'Phiên bản','guests'=>'khách','paying'=>'trả tiền','Package options'=>'Các phương án','Option'=>'Phương án','Hotel'=>'Khách sạn','Per paying guest'=>'Mỗi khách trả tiền','Total'=>'Tổng cộng','Your itinerary'=>'Lịch trình','Day'=>'Ngày','Meals:'=>'Bữa ăn:','Overnight:'=>'Nghỉ đêm:','Included'=>'Bao gồm','Excluded'=>'Không bao gồm','Booking terms'=>'Điều kiện đặt dịch vụ'];
  $t=fn($key)=>$lang==='vi'?($labels[$key]??$key):$key;
  $e=fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $html='<!doctype html><html lang="'.$lang.'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($s['quote_ref']??'Quotation').'</title><style>body{font:16px/1.6 system-ui;max-width:900px;margin:40px auto;padding:24px;color:#17362f}section{break-inside:avoid}table{width:100%;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #ccc}@media print{body{margin:0}}</style><h1>Vietnam Travel Advisor</h1><p>'.$e($t('Quotation')).' '.$e($s['quote_ref']??'').' · '.$e($t('Version')).' '.$e($s['version_no']??'').'</p><h2>'.$e($s['tour_name']).'</h2><p>'.$e($s['start_date']).' – '.$e($s['end_date']).' · '.$e($s['total_guests']).' '.$e($t('guests')).' · '.$e($s['paying_pax']).' '.$e($t('paying')).' · '.$e($s['foc']).' FOC</p>';
  $options=$s['options']??[['label'=>'Package','hotel_level'=>$s['hotel_level']??'','currency'=>$s['currency']??'USD','selling_per_pax'=>$s['selling_per_pax']??0,'total_selling'=>$s['total_selling']??0]];
  $html.='<h2>'.$e($t('Package options')).'</h2><table><thead><tr><th>'.$e($t('Option')).'</th><th>'.$e($t('Hotel')).'</th><th>'.$e($t('Per paying guest')).'</th><th>'.$e($t('Total')).'</th></tr></thead><tbody>';
  foreach($options as $o)$html.='<tr><td>'.$e($o['label']).'</td><td>'.$e($o['hotel_level']).'</td><td>'.$e($o['currency']).' '.$e($o['selling_per_pax']).'</td><td>'.$e($o['currency']).' '.$e($o['total_selling']).'</td></tr>';
  $html.='</tbody></table><h2>'.$e($t('Your itinerary')).'</h2>';foreach(QuoteOptions::publicSchedule($s['schedule']??[]) as $day)$html.='<section><h3>'.$e($t('Day')).' '.$e($day['day']).' · '.$e($day['title']).'</h3><p>'.nl2br($e($day['description'])).'</p><p>'.$e($t('Meals:')).' '.$e($day['meals']).' · '.$e($t('Overnight:')).' '.$e($day['overnight']).'</p></section>';
  foreach(['included'=>'Included','excluded'=>'Excluded','terms'=>'Booking terms'] as $key=>$label)if(!empty($s[$key]))$html.='<h2>'.$e($t($label)).'</h2><p>'.nl2br($e($s[$key])).'</p>';
  return $html.'</html>';
 }
 public static function handle(string $route,string $method,PDO $db,array $u):void {
  if($method!=='GET'||!preg_match('#^quote-versions/(\d+)/customer-html$#',$route,$m))return;
  Auth::requirePermission($db,$u,'sales.view');
  try{$v=QuoteOptions::version($db,(int)$u['company_id'],(int)$m[1]);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
  $q=$db->prepare('SELECT public_snapshot_json FROM quote_sent_bundles WHERE quote_version_id=?');$q->execute([$v['id']]);$raw=$q->fetchColumn()?:$v['sent_snapshot_json'];if(!$raw)Http::json(['ok'=>false,'error'=>'ISSUE_QUOTE_FIRST'],409);
  $s=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$s['quote_ref']=$v['quote_ref'];$s['version_no']=(int)$v['version_no'];if(!empty($s['presentation'])){header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");echo ProposalOutput::html($s,fn($asset)=>'index.php?route=quote-versions/'.$v['id'].'/proposal/images/'.$asset);exit;}header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");echo self::html($s);exit;
 }
}