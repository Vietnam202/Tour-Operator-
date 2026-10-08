<?php
declare(strict_types=1);
require_once __DIR__.'/MediaLibrary.php';

/** Presentation only: configuration on the existing version, media links as children. */
final class QuoteProposal {
 public const TEMPLATES=['VTA_STANDARD_B2B'=>'VTA Standard B2B','VTA_B2B_WHITE_LABEL'=>'VTA B2B White Label','VTA_EXPLORER_B2C'=>'VTA Explorer B2C','VTA_PREMIUM_B2C'=>'VTA Premium B2C'];
 public const SECTIONS=['briefing','highlights','schedule','price','hotel','cruise','itinerary','included','excluded','children','payment','cancellation','notes','contact'];
 public const ROLES=['COVER','DAY_HERO','DAY_GALLERY','HOTEL','HOTEL_GALLERY','CRUISE','CRUISE_GALLERY','SERVICE','LOGO'];
 public const DOCUMENT_SECTIONS=['briefing','highlights','services','included','excluded','children','payment','cancellation','terms','notes'];
 /** Typed public blocks, never executable HTML or commercial inputs. */
 public static function runs($runs):array {
  if(!is_array($runs)||!array_is_list($runs)||count($runs)>500)throw new InvalidArgumentException('Invalid document text runs');$out=[];
  foreach($runs as $r){if(!is_array($r))throw new InvalidArgumentException('Invalid document run');$text=$r['text']??'';MediaLibrary::text($text,20000);if($text==='')continue;$run=['text'=>$text,'bold'=>!empty($r['bold']),'italic'=>!empty($r['italic'])];if(!empty($r['underline']))$run['underline']=true;if(array_key_exists('font_size',$r)&&$r['font_size']!==null&&$r['font_size']!==''){$size=filter_var($r['font_size'],FILTER_VALIDATE_INT);if($size===false||!in_array($size,[10,11,12,14,16,18,20,24,28,32],true))throw new InvalidArgumentException('Invalid document font size');$run['font_size']=$size;}$out[]=$run;}return $out;
 }
 public static function richBlocks($blocks):array {
  if(!is_array($blocks)||!array_is_list($blocks)||count($blocks)>1000)throw new InvalidArgumentException('Invalid document blocks');$out=[];
  foreach($blocks as $b){if(!is_array($b))throw new InvalidArgumentException('Invalid document block');$type=$b['type']??'';
   if(in_array($type,['paragraph','heading'],true)){$r=['type'=>$type,'runs'=>self::runs($b['runs']??[])];if($type==='heading'){$level=(int)($b['level']??2);if($level<1||$level>6)throw new InvalidArgumentException('Heading level 1-6 required');$r['level']=$level;}$alignment=$b['alignment']??'';if($alignment!==''&&!in_array($alignment,['left','center','right','justify'],true))throw new InvalidArgumentException('Invalid paragraph alignment');if($alignment!=='')$r['alignment']=$alignment;}
   elseif($type==='list'){$items=$b['items']??[];if(!is_array($items)||!array_is_list($items)||count($items)>200)throw new InvalidArgumentException('Invalid list');$r=['type'=>'list','ordered'=>!empty($b['ordered']),'items'=>array_map([self::class,'runs'],$items)];}
   elseif($type==='table'){$rows=$b['rows']??[];if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>150)throw new InvalidArgumentException('Invalid table');$clean=[];$width=null;foreach($rows as $row){if(!is_array($row)||!array_is_list($row)||!$row||count($row)>12||($width!==null&&count($row)!==$width))throw new InvalidArgumentException('Malformed document table; review cells');$width=count($row);$clean[]=array_map([self::class,'runs'],$row);}$r=['type'=>'table','rows'=>$clean];}
   else throw new InvalidArgumentException('Unsupported document block');$out[]=$r;
  }if(strlen(MediaLibrary::json($out))>350000)throw new InvalidArgumentException('Document section too long');return $out;
 }
 public static function blockText(array $blocks):string {
  $run=fn($r)=>implode('',array_column($r,'text'));$lines=[];foreach($blocks as $b){if(isset($b['runs']))$lines[]=$run($b['runs']);elseif($b['type']==='list')foreach($b['items'] as $i=>$r)$lines[]=($b['ordered']?($i+1).'. ':'• ').$run($r);elseif($b['type']==='table')foreach($b['rows'] as $row)$lines[]=implode("\t",array_map($run,$row));}return implode("\n",$lines);
 }
 public static function document($d):array {
  if(!is_array($d)||($d['schema']??'')!=='VTA_DOC_1')throw new InvalidArgumentException('Invalid document representation');
  $out=['schema'=>'VTA_DOC_1','title'=>MediaLibrary::text($d['title']??'',1000),'sections'=>[],'days'=>[],'image_sizes'=>[]];
  if(!is_array($d['sections']??[])||!is_array($d['days']??[])||count($d['days']??[])>90)throw new InvalidArgumentException('Invalid document sections');
  foreach($d['sections']??[] as $key=>$blocks){if(!in_array($key,self::DOCUMENT_SECTIONS,true))throw new InvalidArgumentException('Unsupported document section');$out['sections'][$key]=self::richBlocks($blocks);}
  foreach($d['days']??[] as $key=>$blocks){$key=MediaLibrary::text((string)$key,80);$out['days'][$key]=self::richBlocks($blocks);}
  $sizes=$d['image_sizes']??[];if(!is_array($sizes)||count($sizes)>80)throw new InvalidArgumentException('Invalid document image sizes');foreach($sizes as $key=>$width){$key=MediaLibrary::text((string)$key,340);$width=filter_var($width,FILTER_VALIDATE_INT);if($key===''||$width===false||!in_array($width,[25,50,75,100],true))throw new InvalidArgumentException('Image width must be 25, 50, 75 or 100 percent');$out['image_sizes'][$key]=$width;}
  $review=$d['review']??[];$numbers=$review['source_numbers']??[];if(!is_array($numbers)||!array_is_list($numbers)||count($numbers)>90)throw new InvalidArgumentException('Invalid source day numbers');foreach($numbers as $n)if(!is_int($n)||$n<1||$n>999)throw new InvalidArgumentException('Invalid source day number');
  $out['review']=['source_numbers'=>$numbers,'duration_days'=>(int)($review['duration_days']??0),'acknowledged'=>!empty($review['acknowledged'])];if($out['review']['duration_days']<0||$out['review']['duration_days']>999)throw new InvalidArgumentException('Invalid source duration');
  if(strlen(MediaLibrary::json($out))>900000)throw new InvalidArgumentException('Proposal is too long');return $out;
 }
 public static function documentCheck(array $s,array $days):array {
  $d=$s['document']??[];$warnings=[];$numbers=$d['review']['source_numbers']??[];
  if($numbers&&count(array_unique($numbers))!==count($numbers))$warnings[]='Duplicate Day in imported source.';
  if($numbers&&$numbers!==range(1,count($numbers)))$warnings[]='Missing Day or numbering jump in imported source; review before using the draft sequence.';
  if(!empty($d['review']['duration_days'])&&$d['review']['duration_days']!==count($days))$warnings[]='Duration differs from detailed itinerary day count.';
  foreach($days as $i=>$day)if(trim($day['title']??'')==='')$warnings[]='Day '.($i+1).' is missing a title.';
  if($d&&trim($d['title']??'')==='')$warnings[]='Tour title is empty.';
  foreach(['included','excluded','payment'] as $key)if(array_key_exists($key,$d['sections']??[])&&trim(self::blockText($d['sections'][$key]))==='')$warnings[]=ucfirst($key).' section is empty.';
  return array_values(array_unique($warnings));
 }
 private static function q(PDO $db,string $s,array $a=[]): PDOStatement {return MediaLibrary::q($db,$s,$a);}
 public static function settings(array $b): array {
  $template=$b['template']??'VTA_STANDARD_B2B';if(!isset(self::TEMPLATES[$template]))throw new InvalidArgumentException('Choose a proposal template');$channel=str_contains($template,'B2B')?'B2B':'B2C';
  if(isset($b['channel'])&&$b['channel']!==$channel)throw new InvalidArgumentException('Template must match channel');
  $s=['schema'=>'VS2_2','template'=>$template,'channel'=>$channel,'brand_mode'=>$template==='VTA_B2B_WHITE_LABEL'?'WHITE_LABEL':'VTA'];
  foreach(['briefing','highlights','children_policy','payment_terms','cancellation_policy','important_notes'] as $key)$s[$key]=MediaLibrary::text($b[$key]??'',12000);
  foreach(['agent_company','contact_person','market','quote_valid_until','brand_name','contact_email','contact_phone','contact_address','cta_label','cta_url'] as $key)$s[$key]=MediaLibrary::text($b[$key]??'',500);
  if($s['quote_valid_until']!=='')ScheduleImport::date($s['quote_valid_until']);if($s['contact_email']!==''&&!filter_var($s['contact_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Valid contact email required');
  if($s['cta_url']!==''&&!preg_match('#^https://[^\s<>"\x00-\x1f]+$#D',$s['cta_url']))throw new InvalidArgumentException('Contact link must use HTTPS');
  if($s['brand_mode']==='WHITE_LABEL'&&$s['brand_name']==='')throw new InvalidArgumentException('Partner company name is required');
  $s['brand_color']=$b['brand_color']??'#163b60';if(!preg_match('/^#[a-fA-F0-9]{6}$/D',$s['brand_color']))throw new InvalidArgumentException('Use a six-digit brand color');
  $s['hide_vta_contact']=!empty($b['hide_vta_contact']);$s['pricing_display']=$b['pricing_display']??'NET';if(!in_array($s['pricing_display'],['NET','PAX_BAND','PRIVATE_SIC','SELECTED_OPTION'],true))throw new InvalidArgumentException('Invalid price display');
  $s['selected_variant_id']=(int)($b['selected_variant_id']??0);
  $sections=$b['sections']??self::SECTIONS;if(!is_array($sections)||!array_is_list($sections)||count(array_unique($sections))!==count($sections)||array_diff($sections,self::SECTIONS))throw new InvalidArgumentException('Invalid section order');$s['sections']=$sections;
  $rows=$b['accommodation']??[];if(!is_array($rows)||!array_is_list($rows)||count($rows)>50)throw new InvalidArgumentException('Use up to 50 accommodation rows');$s['accommodation']=[];
  foreach($rows as $r){if(!is_array($r)||!in_array($r['type']??'',['HOTEL','CRUISE'],true))throw new InvalidArgumentException('Accommodation type required');$n=$r['nights']??0;if(!is_numeric($n)||(int)$n!=(float)$n||$n<0||$n>90)throw new InvalidArgumentException('Invalid nights');$row=['type'=>$r['type'],'nights'=>(int)$n];foreach(['destination','three_star','four_star','five_star'] as $key)$row[$key]=MediaLibrary::text($r[$key]??'',500);$s['accommodation'][]=$row;}
  if(array_key_exists('document',$b))$s['document']=self::document($b['document']);
  return $s;
 }
 public static function config(array $v): ?array {$p=json_decode($v['proposal_json']??'null',true);return is_array($p)&&($p['schema']??'')==='VS2_2'?self::settings($p):null;}
 public static function days(array $v,bool $internal=true): array {
  $days=json_decode($v['schedule_json']?:'[]',true,512,JSON_THROW_ON_ERROR);$days=QuoteVs2Domain::schedule($days);
  if(!$internal)foreach($days as &$d){unset($d['notes'],$d['internal_notes'],$d['special_requests']);}unset($d);return $days;
 }
 public static function context(PDO $db,array $u,array $v): array {
  $sent=self::q($db,'SELECT public_snapshot_json FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetchColumn();$locked=in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true)||(bool)$sent;
  $s=$sent?json_decode($sent,true):null;$p=$s['presentation']??null;
  $settings=$locked&&$p?($p['settings']??self::settings([])):(self::config($v)??self::settings([]));
  $links=$locked&&$p?($p['media']??[]):self::q($db,'SELECT asset_id,role,day_key,reference_key,caption,sort_order FROM media_assignments WHERE quote_version_id=? ORDER BY sort_order,id',[$v['id']])->fetchAll(PDO::FETCH_ASSOC);
  $days=$locked&&$p?array_map(fn($d)=>$d['schedule']+['day_key'=>$d['key']],$p['days']):self::days($v,Auth::can($db,(int)$u['id'],'quote.view_cost'));
  $keys=array_column($days,'day_key');$orphan=array_values(array_filter($links,fn($l)=>$l['day_key']!==''&&!in_array($l['day_key'],$keys,true)));
  $choices=$locked&&$s?($s['options']??[]):(QuoteVs2Repository::engine($v)==='VS2_1'?self::q($db,'SELECT c.id variant_id,c.label,c.costing_mode,o.hotel_level,c.cruise_level FROM quote_option_variants c JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? AND c.is_offered=1 ORDER BY c.sort_order,c.id',[$v['id']])->fetchAll():self::q($db,'SELECT id variant_id,label,hotel_level FROM quote_options WHERE quote_version_id=? ORDER BY hotel_level',[$v['id']])->fetchAll());
  $selling=$locked&&$s?($s['options']??[]):[];
  if(!$locked&&QuoteVs2Repository::engine($v)==='VS2_1')foreach(self::q($db,'SELECT c.id variant_id,c.label,c.costing_mode,o.hotel_level,c.cruise_level,c.selling_currency,c.pricing_result_json FROM quote_option_variants c JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? AND c.is_offered=1 ORDER BY c.sort_order,c.id',[$v['id']])->fetchAll() as $o){$p=QuoteVs2Repository::decode($o['pricing_result_json']);unset($o['pricing_result_json'],$o['selling_currency']);if(isset($p['selling_per_pax']))$selling[]=$o+['selling_per_pax'=>$p['selling_per_pax'],'total_selling'=>$p['total_selling'],'currency'=>$p['selling_currency']];}
  return ['settings'=>$settings,'days'=>$days,'links'=>$links,'orphan_links'=>$orphan,'variant_choices'=>$choices,'selling_options'=>$selling,'immutable'=>$locked,'enabled'=>(bool)self::config($v),'templates'=>self::TEMPLATES,'costing_revision'=>(int)$v['costing_revision'],'document_check'=>self::documentCheck($settings,$days),'requirements'=>QuoteVs2Repository::engine($v)==='VS2_1'?QuoteVs2::contextDto($db,$u,$v)['requirements']:[]];
 }
 public static function save(PDO $db,array $u,int $id,array $b): array {
  return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$b){
   $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$id,QuoteVs2Repository::expected($b));QuoteVs2Repository::mutable($db,$v);$settings=self::settings($b['settings']??self::config($v)??[]);
   if($settings['brand_mode']==='WHITE_LABEL'&&!Auth::can($db,(int)$u['id'],'proposal.white_label'))throw new DomainException('WHITE_LABEL_PERMISSION_REQUIRED');
   $days=$b['days']??self::days($v);if(!is_array($days)||!array_is_list($days)||!$days||count($days)>90)throw new InvalidArgumentException('One to 90 days required');
   $before=self::days($v);$prior=array_column($before,null,'day_key');
   foreach($days as &$d){if(!is_array($d))throw new InvalidArgumentException('Invalid day');if(!Auth::can($db,(int)$u['id'],'quote.view_cost'))foreach(['notes','internal_notes','special_requests'] as $key){unset($d[$key]);if(isset($prior[$d['day_key']??''][$key]))$d[$key]=$prior[$d['day_key']][$key];}}unset($d);
   $days=QuoteVs2Domain::schedule($days);$keys=array_column($days,'day_key');$links=$b['links']??[];
   foreach($settings['document']['days']??[] as $key=>$blocks){if(!in_array($key,$keys,true))throw new InvalidArgumentException('Document day is not in this itinerary');$day=$days[array_search($key,$keys,true)];if(trim(self::blockText($blocks))!==$day['description'])throw new InvalidArgumentException('Document and itinerary text must match');}
   if(!is_array($links)||!array_is_list($links)||count($links)>80)throw new InvalidArgumentException('Use up to 80 media assignments');$valid=[];$single=[];$unique=[];$bytes=0;
   foreach($links as $i=>$l){$role=$l['role']??'';$key=$l['day_key']??'';$ref=MediaLibrary::text($l['reference_key']??'');
    if(!in_array($role,self::ROLES,true)||!is_string($key)||($key!==''&&!in_array($key,$keys,true))||(in_array($role,['COVER','LOGO'],true)&&$key!=='')||(in_array($role,['DAY_HERO','DAY_GALLERY'],true)&&$key===''))throw new InvalidArgumentException('Invalid image role/day');
    $asset=MediaLibrary::asset($db,$u,(int)($l['asset_id']??0));if($asset['status']==='ARCHIVED'||($asset['quote_id']&&(int)$asset['quote_id']!==(int)$v['quote_id']))throw new DomainException('Image is archived or belongs to another quote');
    $slot=$role.'|'.$key.'|'.$ref;$uniqueKey=$slot.'|'.$asset['id'];if(isset($unique[$uniqueKey]))throw new InvalidArgumentException('Duplicate assignment');$unique[$uniqueKey]=true;$single[$slot]=($single[$slot]??0)+1;
    if((in_array($role,['COVER','DAY_HERO','HOTEL','CRUISE','LOGO'],true)&&$single[$slot]>1)||($role==='DAY_GALLERY'&&$single[$slot]>6))throw new InvalidArgumentException('Use one main image per slot and up to six day gallery images');
    $bytes+=(int)$asset['byte_size'];if($bytes>25165824)throw new InvalidArgumentException('Optimized proposal images exceed 24 MB');
    $valid[]=['asset_id'=>(int)$asset['id'],'role'=>$role,'day_key'=>$key,'reference_key'=>$ref,'caption'=>MediaLibrary::text($l['caption']??'',500),'sort_order'=>$i];
   }
   self::q($db,'DELETE FROM media_assignments WHERE quote_version_id=?',[$id]);foreach($valid as $l)self::q($db,'INSERT INTO media_assignments(quote_version_id,asset_id,role,day_key,reference_key,caption,sort_order) VALUES(?,?,?,?,?,?,?)',[$id,...array_values($l)]);
   self::q($db,'UPDATE quote_versions SET proposal_json=? WHERE id=?',[MediaLibrary::json($settings),$id]);
   if(QuoteVs2Repository::engine($v)==='VS2_1'&&MediaLibrary::json($days)!==MediaLibrary::json($before))QuoteSmartCosting::context($db,$u,$id,['expected_revision'=>(int)$v['costing_revision'],'schedule'=>$days]);
   else {
    self::q($db,"UPDATE quote_versions SET schedule_json=?,costing_revision=costing_revision+1,version_status='DRAFT',approved_by=NULL,approved_at=NULL WHERE id=?",[MediaLibrary::json($days),$id]);
    self::q($db,'DELETE FROM quote_bundle_approvals WHERE quote_version_id=?',[$id]);self::q($db,"UPDATE quotes SET status='DRAFT',updated_by=? WHERE id=?",[$u['id'],$v['quote_id']]);
   }
   Audit::log($db,(int)$u['company_id'],(int)$u['id'],'PROPOSAL_SAVED','quote_version',$id,null,['template'=>$settings['template'],'days'=>count($days),'media'=>count($valid)]);return self::context($db,$u,QuoteOptions::version($db,(int)$u['company_id'],$id));
  });
 }
 /** Only configured drafts add presentation; legacy approval fingerprints stay identical. */
 public static function extend(PDO $db,array $v,array $bundle,bool $draftPreview=false): array {
  $settings=self::config($v);if(!$settings)return $bundle;
  $days=self::days($v,false);$keys=array_column($days,'day_key');$media=[];
  foreach(self::q($db,'SELECT m.*,a.company_id,a.status,a.visibility,a.quote_id,a.content_sha256,a.width,a.height FROM media_assignments m JOIN media_assets a ON a.id=m.asset_id WHERE m.quote_version_id=? ORDER BY m.sort_order,m.id',[$v['id']])->fetchAll() as $l){
   if((int)$l['company_id']!==(int)$v['company_id']||$l['status']==='ARCHIVED'||($l['quote_id']&&(int)$l['quote_id']!==(int)$v['quote_id'])||(!$draftPreview&&$l['visibility']!=='PERSONAL'&&$l['status']!=='APPROVED')||($l['day_key']!==''&&!in_array($l['day_key'],$keys,true)))throw new DomainException('MEDIA_REVIEW_REQUIRED: approve company media and review day assignments');
   $media[]=['asset_id'=>(int)$l['asset_id'],'role'=>$l['role'],'day_key'=>$l['day_key'],'reference_key'=>$l['reference_key'],'caption'=>$l['caption'],'sha256'=>$l['content_sha256'],'width'=>(int)$l['width'],'height'=>(int)$l['height']];
  }
  $public=$draftPreview?$bundle:QuoteOptions::publicBundle($bundle);$options=$public['options'];
  if(!$draftPreview&&$settings['pricing_display']==='SELECTED_OPTION'&&!array_filter($options,fn($o)=>(int)($o['variant_id']??$o['id'])===$settings['selected_variant_id']))throw new DomainException('Select an offered option for the proposal');
  // Output never reads internal lines, rate evidence, source metadata or profit fields.
  $p=['schema'=>'VS2_2','render_version'=>1,'settings'=>$settings,'days'=>array_map(fn($d,$i)=>['key'=>$d['day_key'],'schedule'=>QuoteOptions::publicSchedule($days,true)[$i],'fields'=>array_intersect_key($d,array_flip(['route','destination','activities','transport_mode','hotel','cruise','public_notes','guide_required']))],$days,array_keys($days)),'media'=>$media];
  if($settings['channel']==='B2C')foreach(['agent_company','contact_person','market'] as $key)unset($p['settings'][$key]);
  $public['presentation']=$p;$p['blocks']=ProposalOutput::blocks($public);$public['presentation']=$p;
  $p['public_html']=ProposalOutput::html($public,fn($asset)=>'@@MEDIA_'.$asset.'@@',false);$bundle['presentation']=$p;return $bundle;
 }
 public static function copy(PDO $db,int $source,int $target): void {
  $json=self::q($db,'SELECT proposal_json FROM quote_versions WHERE id=?',[$source])->fetchColumn();if(!self::config(['proposal_json'=>$json]))return;
  self::q($db,'INSERT INTO media_assignments(quote_version_id,asset_id,role,day_key,reference_key,caption,sort_order) SELECT ?,asset_id,role,day_key,reference_key,caption,sort_order FROM media_assignments WHERE quote_version_id=?',[$target,$source]);
 }
 public static function publicQuote(PDO $db,array $v): array {
  $sent=self::q($db,'SELECT public_snapshot_json FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetchColumn()?:$v['sent_snapshot_json'];if($sent)return json_decode($sent,true,512,JSON_THROW_ON_ERROR);
  // Valid candidates use the exact send builder. Incomplete drafts can still
  // preview their layout, but publish no invented or unreviewed selling prices.
  try{return QuoteOptions::publicBundle(QuoteOptions::bundle($db,$v));}
  catch(DomainException $e){
   if(in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true))throw $e;
   $public=array_intersect_key($v,array_flip([...QuoteVs2Domain::BASE,'quote_ref','version_no','tour_name','start_date','end_date','document_language']));
   $public+=['schedule'=>QuoteOptions::publicSchedule(self::days($v,false)),'included'=>$v['included_text'],'excluded'=>$v['excluded_text'],'terms'=>$v['terms_text'],'options'=>[]];
   if(QuoteVs2Repository::engine($v)==='VS2_1'&&QuoteVs2Validator::validate($db,$v)['valid'])$public=QuoteOptions::publicBundle(Vs2Snapshots::bundle($db,$v));
   return self::extend($db,$v,$public,true);
  }
 }
 public static function snapshotAsset(PDO $db,array $v,array $s,int $id): array {
  $ref=null;foreach($s['presentation']['media']??[] as $m)if((int)$m['asset_id']===$id)$ref=$m;if(!$ref)throw new OutOfBoundsException('Media not in this proposal');
  $a=self::q($db,'SELECT * FROM media_assets WHERE company_id=? AND id=?',[$v['company_id'],$id])->fetch();if(!$a||!hash_equals($ref['sha256'],$a['content_sha256']))throw new OutOfBoundsException('Media unavailable');return $a;
 }
 public static function createLink(PDO $db,array $cfg,array $u,int $id,int $days=14): array {
  return QuoteVs2Repository::atomic($db,function()use($db,$cfg,$u,$id,$days){
   $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$id);if(!in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true)||empty(self::publicQuote($db,$v)['presentation']))throw new DomainException('Send a visual proposal before creating its web link');
   $policy=$cfg['proposal']['public_links']??true;if($policy!==true)throw new DomainException('Public proposal links are disabled by policy');if($days<1||$days>90)throw new InvalidArgumentException('Lifetime must be 1–90 days');
   $token=bin2hex(random_bytes(32));$expiry=date('Y-m-d H:i:s',time()+$days*86400);self::q($db,'INSERT INTO proposal_public_links(company_id,quote_version_id,token_hash,expires_at,created_by) VALUES(?,?,?,?,?)',[$u['company_id'],$id,hash('sha256',$token),$expiry,$u['id']]);$link=(int)$db->lastInsertId();
   Audit::log($db,(int)$u['company_id'],(int)$u['id'],'PROPOSAL_LINK_CREATED','quote_version',$id,null,['link_id'=>$link,'expires_at'=>$expiry]);return ['id'=>$link,'token'=>$token,'expires_at'=>$expiry,'url'=>rtrim($cfg['app']['base_url'],'/').'/api/index.php?route=public-proposals/'.$token];
  });
 }
 public static function token(PDO $db,string $token): array {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new OutOfBoundsException('Proposal unavailable');
  $r=self::q($db,'SELECT l.company_id,l.quote_version_id FROM proposal_public_links l JOIN quotes q ON q.company_id=l.company_id JOIN quote_versions v ON v.quote_id=q.id AND v.id=l.quote_version_id JOIN quote_sent_bundles s ON s.quote_version_id=v.id WHERE l.token_hash=? AND l.revoked_at IS NULL AND l.expires_at>NOW()',[hash('sha256',$token)])->fetch();
  if(!$r)throw new OutOfBoundsException('Proposal unavailable');$v=QuoteOptions::version($db,(int)$r['company_id'],(int)$r['quote_version_id']);return ['version'=>$v,'snapshot'=>self::publicQuote($db,$v)];
 }
 public static function handle(string $route,string $method,PDO $db,array $cfg,array $u): void {
  if(!preg_match('#^quote-versions/(\d+)/proposal(?:/(.*))?$#',$route,$m))return;$id=(int)$m[1];$action=$m[2]??'';
  try {
   $v=QuoteOptions::version($db,(int)$u['company_id'],$id);Auth::requireQuoteRead($db,$u,$v);
   if($method==='POST'&&$action==='import-preview'){
    Auth::requirePermission($db,$u,'quote.edit');Auth::requirePermission($db,$u,'proposal.edit');QuoteVs2Repository::mutable($db,$v);
    $b=isset($_FILES['file'])?$_POST:Http::body();if(isset($_FILES['file']))$out=ScheduleImport::documentUpload($_FILES['file']);elseif(array_key_exists('html',$b))$out=ScheduleImport::documentHtmlPreview((string)$b['html']);else $out=ScheduleImport::documentPreview((string)($b['text']??''));Http::json(['ok'=>true]+$out);
   }
   if($method==='GET'&&$action==='')Http::json(['ok'=>true]+self::context($db,$u,$v));
   if($method==='PUT'&&$action===''){Auth::requirePermission($db,$u,'quote.edit');Auth::requirePermission($db,$u,'proposal.edit');Auth::requirePermission($db,$u,'media.view');Http::json(['ok'=>true]+self::save($db,$u,$id,Http::body()));}
   if($method==='GET'&&preg_match('#^images/(\d+)$#',$action,$im)){$s=self::publicQuote($db,$v);$a=self::snapshotAsset($db,$v,$s,(int)$im[1]);if(!hash_equals($a['content_sha256'],hash_file('sha256',MediaLibrary::path($cfg,$a))))throw new OutOfBoundsException('Media bytes changed');MediaLibrary::stream($cfg,$a);}
   if($method==='GET'&&in_array($action,['html','pdf','docx'],true)){$s=self::publicQuote($db,$v);ProposalOutput::respond($action,$s,$cfg,fn($asset)=>'index.php?route=quote-versions/'.$id.'/proposal/images/'.$asset,fn($asset)=>self::verifiedPath($db,$cfg,$v,$s,$asset),!in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true));}
   if($action==='links'){Auth::requirePermission($db,$u,'quote.send');Auth::requirePermission($db,$u,'proposal.send');if($method==='POST')Http::json(['ok'=>true]+self::createLink($db,$cfg,$u,$id,(int)(Http::body()['days']??14)),201);if($method==='GET')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT id,expires_at,revoked_at FROM proposal_public_links WHERE company_id=? AND quote_version_id=? ORDER BY id DESC',[$u['company_id'],$id])->fetchAll()]);}
   if($method==='DELETE'&&preg_match('#^links/(\d+)$#',$action,$lm)){Auth::requirePermission($db,$u,'quote.send');Auth::requirePermission($db,$u,'proposal.send');self::q($db,'UPDATE proposal_public_links SET revoked_at=NOW() WHERE company_id=? AND quote_version_id=? AND id=?',[$u['company_id'],$id,$lm[1]]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'PROPOSAL_LINK_REVOKED','quote_version',$id,null,['link_id'=>(int)$lm[1]]);Http::json(['ok'=>true]);}
  }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
 }
 public static function verifiedPath(PDO $db,array $cfg,array $v,array $s,int $id): string {
  $a=self::snapshotAsset($db,$v,$s,$id);$path=MediaLibrary::path($cfg,$a);if(!hash_equals($a['content_sha256'],hash_file('sha256',$path)))throw new OutOfBoundsException('Media bytes changed');return $path;
 }
 public static function publicHandle(string $route,string $method,PDO $db,array $cfg): void {
  if($method!=='GET'||!preg_match('#^public-proposals/([a-f0-9]{64})(?:/(images/\d+|pdf|docx))?$#D',$route,$m))return;
  try {if(($cfg['proposal']['public_links']??true)!==true)throw new OutOfBoundsException('Proposal unavailable');$r=self::token($db,$m[1]);$s=$r['snapshot'];$v=$r['version'];$action=$m[2]??'html';if(str_starts_with($action,'images/')){$id=(int)substr($action,7);self::verifiedPath($db,$cfg,$v,$s,$id);MediaLibrary::stream($cfg,self::snapshotAsset($db,$v,$s,$id));}
   ProposalOutput::respond($action,$s,$cfg,fn($asset)=>'index.php?route=public-proposals/'.$m[1].'/images/'.$asset,fn($asset)=>self::verifiedPath($db,$cfg,$v,$s,$asset),false);
  }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'PROPOSAL_UNAVAILABLE'],404);}
 }
}
