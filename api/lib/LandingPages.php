<?php
declare(strict_types=1);

/** Structured landing drafts only; no arbitrary HTML, public hosting or provider calls. */
final class LandingPages {
 private const TYPES=['hero','text','highlights','itinerary','image','faq','cta','hero_form','countdown','overview','destinations_gallery','our_guests','inclusions','pricing','reviews','social_media','leadForm'];
 private static function text(array $a,string $key,int $max,bool $required=false):string {
  $s=$a[$key]??null;if(!is_string($s)||strlen($s)>$max*4||preg_match_all('/./us',$s)>$max||($required&&trim($s)===''))throw new InvalidArgumentException('Invalid '.$key);
  return trim($s);
 }
 private static function url(array $a,string $key):string {
  $s=self::text($a,$key,1000);if($s==='')return '';
  $p=parse_url($s);if(strlen($s)>1000||preg_match('/[\s\\\\\x00-\x1f]/',$s)||!filter_var($s,FILTER_VALIDATE_URL)||!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))throw new InvalidArgumentException('Use an HTTPS link without credentials');return $s;
 }
 public static function document(mixed $v):array {
  if(!is_array($v)||($v['schema']??null)!==1||!isset($v['blocks'])||!is_array($v['blocks'])||!array_is_list($v['blocks'])||count($v['blocks'])>40)throw new InvalidArgumentException('Invalid landing page: maximum 40 blocks');
  $r=['schema'=>1,'title'=>self::text($v,'title',120,true),'description'=>self::text($v,'description',300),'brand'=>self::text($v,'brand',100,true),'accent'=>self::text($v,'accent',7),'language'=>self::text($v,'language',2),'blocks'=>[]];
  if(!preg_match('/^#[a-fA-F0-9]{6}$/D',$r['accent'])||!in_array($r['language'],['en','vi'],true))throw new InvalidArgumentException('Invalid color or language');$ids=[];
  foreach($v['blocks'] as $b){if(!is_array($b)||!in_array($b['type']??null,self::TYPES,true)||!is_string($b['id']??null)||!preg_match('/^lp-[a-zA-Z0-9-]{8,70}$/D',$b['id'])||isset($ids[$b['id']]))throw new InvalidArgumentException('Invalid or duplicate block');$ids[$b['id']]=true;
   $n=['id'=>$b['id'],'type'=>$b['type'],'title'=>self::text($b,'title',200),'body'=>self::text($b,'body',5000),'image'=>self::url($b,'image'),'alt'=>self::text($b,'alt',200),'rights'=>self::text($b,'rights',500),'button'=>self::text($b,'button',80),'url'=>self::url($b,'url')];
   if($n['image']!==''&&($n['alt']===''||$n['rights']===''))throw new InvalidArgumentException('Image requires alt text and rights note');$n['secondary']=self::text($b+['secondary'=>''],'secondary',5000);
   $n['deadline']=self::text($b+['deadline'=>''],'deadline',25);if($n['deadline']!==''){$dt=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z',$n['deadline'],new DateTimeZone('UTC'));if(!$dt||$dt->format('Y-m-d\\TH:i:s\\Z')!==$n['deadline'])throw new InvalidArgumentException('Invalid offer deadline');}
   $n['form_url']=self::url($b+['form_url'=>''],'form_url');if($n['form_url']!==''){parse_str((string)parse_url($n['form_url'],PHP_URL_QUERY),$query);if(!str_ends_with((string)parse_url($n['form_url'],PHP_URL_PATH),'/request.html')||!is_string($query['form']??null)||!preg_match('/^[a-f0-9]{64}$/D',$query['form'])||parse_url($n['form_url'],PHP_URL_FRAGMENT)!==null)throw new InvalidArgumentException('Invalid Lead Hub form URL');}
   $items=$b['items']??[];if(!is_array($items)||!array_is_list($items)||count($items)>20)throw new InvalidArgumentException('Maximum 20 items per block');$n['items']=[];
   foreach($items as $item){if(!is_array($item))throw new InvalidArgumentException('Invalid block item');$item+=['title'=>'','text'=>'','image'=>'','alt'=>'','rights'=>'','url'=>'','price'=>''];$it=['title'=>self::text($item,'title',200),'text'=>self::text($item,'text',2000),'image'=>self::url($item,'image'),'alt'=>self::text($item,'alt',200),'rights'=>self::text($item,'rights',500),'url'=>self::url($item,'url'),'price'=>self::text($item,'price',100)];if($it['image']!==''&&($it['alt']===''||$it['rights']===''))throw new InvalidArgumentException('Each image requires alt text and permission note');$n['items'][]=$it;}
   $r['blocks'][]=$n;
  }if(strlen(json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))>450000)throw new InvalidArgumentException('Landing document exceeds 450 KB');return $r;
 }
 private static function q(PDO $db,string $sql,array $args=[]):PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
 private static function id(mixed $v):int {if(filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new InvalidArgumentException('Invalid record ID');return (int)$v;}
 private static function row(array $r):array {$r['document']=json_decode($r['document_json'],true,512,JSON_THROW_ON_ERROR);unset($r['document_json']);$r['id']=(int)$r['id'];$r['version_no']=(int)$r['version_no'];return $r;}
 public static function get(PDO $db,int $company,int $id):array {$r=self::q($db,'SELECT id,title,campaign_id,version_no,document_json,updated_at FROM marketing_landing_pages WHERE company_id=? AND id=?',[$company,$id])->fetch();if(!$r)throw new OutOfBoundsException('Landing page not found');return self::row($r);}
 /** Caller holds transaction and campaign.manage permission. CAS update prevents lost writes. */
 public static function save(PDO $db,array $u,array $b):array {
  $cid=(int)$u['company_id'];$doc=self::document($b['document']??null);$json=json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$campaign=empty($b['campaign_id'])?null:self::id($b['campaign_id']);
  if($campaign&&!self::q($db,"SELECT id FROM campaigns WHERE company_id=? AND id=? AND status='ACTIVE'",[$cid,$campaign])->fetchColumn())throw new OutOfBoundsException('Active campaign not found');
  foreach($doc['blocks'] as $block){if($block['form_url']==='')continue;parse_str((string)parse_url($block['form_url'],PHP_URL_QUERY),$query);
   if(!$campaign||!self::q($db,"SELECT id FROM lead_forms WHERE company_id=? AND campaign_id=? AND public_token=? AND status='ACTIVE'",[$cid,$campaign,$query['form']])->fetchColumn())throw new OutOfBoundsException('Choose an active Lead Hub form belonging to this campaign');
   if(isset($_SERVER['HTTP_HOST'])){$host=parse_url($block['form_url'],PHP_URL_HOST);$port=parse_url($block['form_url'],PHP_URL_PORT);if(strtolower((string)$host.($port&&$port!==443?':'.$port:''))!==strtolower($_SERVER['HTTP_HOST']))throw new InvalidArgumentException('Lead form must use this application host');}
   if(isset($_SERVER['SCRIPT_NAME'])&&str_ends_with($_SERVER['SCRIPT_NAME'],'/api/index.php')){$path=rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME']))),'/').'/request.html';if(parse_url($block['form_url'],PHP_URL_PATH)!==$path)throw new InvalidArgumentException('Lead form must use this application path');}
  }
  if(!empty($b['id'])){
   $id=self::id($b['id']);self::get($db,$cid,$id);$version=self::id($b['version_no']??null);
   $s=self::q($db,'UPDATE marketing_landing_pages SET title=?,campaign_id=?,document_json=?,version_no=version_no+1,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=? AND version_no=?',[$doc['title'],$campaign,$json,$u['id'],$cid,$id,$version]);
   if($s->rowCount()!==1)throw new DomainException('Landing page changed. Download your edit file, then reopen the server draft before saving.');
  }else{
   $key=$b['client_key']??'';if(!is_string($key)||!preg_match('/^lp-[a-zA-Z0-9-]{8,70}$/D',$key))throw new InvalidArgumentException('Invalid save key');
   self::q($db,'INSERT IGNORE INTO marketing_landing_pages(company_id,campaign_id,client_key,title,document_json,created_by,updated_by) VALUES(?,?,?,?,?,?,?)',[$cid,$campaign,$key,$doc['title'],$json,$u['id'],$u['id']]);
   $r=self::q($db,'SELECT id,document_json,campaign_id FROM marketing_landing_pages WHERE company_id=? AND client_key=? FOR UPDATE',[$cid,$key])->fetch();
   if(!$r||json_decode($r['document_json'],true,512,JSON_THROW_ON_ERROR)!=$doc||(int)$r['campaign_id']!==(int)$campaign)throw new DomainException('This save key already exists. Download your edit file and reopen the saved draft.');$id=(int)$r['id'];
  }return self::get($db,$cid,$id);
 }
 public static function handle(string $route,string $method,PDO $db,array $u):void {
  if($route!=='marketing/landing-pages')return;
  Auth::requirePermission($db,$u,$method==='GET'?'lead.view':'campaign.manage');
  try{
   if($method==='GET'){
    if(isset($_GET['id']))Http::json(['ok'=>true,'page'=>self::get($db,(int)$u['company_id'],self::id($_GET['id']))]);
    Http::json(['ok'=>true,'pages'=>self::q($db,'SELECT id,title,campaign_id,version_no,updated_at FROM marketing_landing_pages WHERE company_id=? ORDER BY updated_at DESC,id DESC LIMIT 200',[$u['company_id']])->fetchAll(),'limit'=>200]);
   }
   if($method!=='POST')Http::json(['ok'=>false,'message'=>'Method not allowed'],405);
   $b=Http::body();$db->beginTransaction();$r=self::save($db,$u,$b);
   Audit::log($db,(int)$u['company_id'],(int)$u['id'],'LANDING_DRAFT_SAVED','marketing_landing_pages',$r['id'],null,['version_no'=>$r['version_no'],'public'=>false]);
   $db->commit();Http::json(['ok'=>true,'page'=>$r,'publishing_enabled'=>false]);
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$status=$e instanceof OutOfBoundsException?404:($e instanceof DomainException?409:($e instanceof InvalidArgumentException?422:503));Http::json(['ok'=>false,'message'=>$status===503?'Landing drafts unavailable. Ask your administrator to check migration 020.':$e->getMessage()],$status);}
 }
}
