<?php
declare(strict_types=1);

final class MarketingRules {
 public const CHANNELS=['Facebook','Instagram','TikTok','YouTube Shorts','Google Ads','WhatsApp','Gmail','Threads','Google Business','WordPress','X','Pinterest'];
 public const TOPICS=['Vietnam Destination','Vietnam Travel Tips','India → Vietnam','Vietnam Packages','Customer Reviews','Promotion / CTA','General'];
 public const FORMATS=['POST','REEL_SCRIPT','VIDEO','AD_COPY','EMAIL','STORY'];
 public static function text(array $b,string $key,int $max,bool $required=false):string {
  $v=$b[$key]??'';if(!is_string($v)||strlen($v)>$max||($required&&trim($v)===''))throw new InvalidArgumentException('Invalid '.$key);return trim($v);
 }
 public static function id($v):int {if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<1)throw new InvalidArgumentException('Invalid record ID');return (int)$v;}
 public static function choice(array $b,string $key,array $values,string $default=''):string {
  $v=$b[$key]??$default;if(!is_string($v)||!in_array($v,$values,true))throw new InvalidArgumentException('Invalid '.$key);return $v;
 }
 public static function metadata(array $b):array {
  $url=self::text($b,'asset_url',1000);$rights=self::text($b,'rights_note',500);
  if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null))throw new InvalidArgumentException('Asset link must be HTTPS without credentials');
  if($url!==''&&$rights==='')throw new InvalidArgumentException('Describe ownership or usage permission for the asset');
  return ['topic'=>self::choice($b,'topic',self::TOPICS,'General'),'tags'=>self::text($b,'tags',500),'asset_url'=>$url,'rights_note'=>$rights];
 }
 public static function planned($raw):?string {
  if($raw===null||$raw==='')return null;if(!is_string($raw))throw new InvalidArgumentException('Invalid planned time');
  $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$raw,new DateTimeZone('UTC'));
  if(!$d||$d->format('Y-m-d\TH:i:s\Z')!==$raw||(int)$d->format('Y')<1000)throw new InvalidArgumentException('Planned time must be UTC ISO format');
  return $d->format('Y-m-d H:i:s');
 }
 public static function content(array $b):array {
  return ['campaign_id'=>self::id($b['campaign_id']??null),'channel'=>self::choice($b,'channel',self::CHANNELS),'body'=>self::text($b,'body',12000,true),'content_format'=>self::choice($b,'content_format',self::FORMATS,'POST'),'planned_at'=>self::planned($b['planned_at']??null),'parent_content_id'=>empty($b['parent_content_id'])?null:self::id($b['parent_content_id'])]+self::metadata($b);
 }
 public static function batch(array $b):array {
  $key=self::text($b,'request_key',80,true);if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Invalid request key');
  $entries=$b['entries']??null;if(!is_array($entries)||!array_is_list($entries)||count($entries)<1||count($entries)>30)throw new InvalidArgumentException('A batch must contain 1–30 drafts');
  $rows=[];foreach($entries as $e){if(!is_array($e))throw new InvalidArgumentException('Invalid entry');$rows[]=self::content($e);}
  return ['key'=>$key,'entries'=>$rows,'hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
 }
 public static function transition(string $current,string $action,string $decision=''):string {
  if($action==='submit'&&$current==='DRAFT')return 'PENDING';
  if($action==='decision'&&$current==='PENDING'&&in_array($decision,['APPROVED','REJECTED'],true))return $decision;
  throw new DomainException('Content state changed or action is not available. Reload the workspace.');
 }
}
