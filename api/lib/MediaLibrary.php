<?php
declare(strict_types=1);

/** Immutable image bytes in the existing private local storage; metadata is tenant scoped. */
final class MediaLibrary {
 public const MAX_BYTES=12582912;
 public const MAX_PIXELS=16000000;
 public static function q(PDO $db,string $sql,array $args=[]): PDOStatement { $s=$db->prepare($sql);$s->execute($args);return $s; }
 public static function json($value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
 public static function text($value,int $max=190): string {
  if(!is_string($value)||strlen($value)>$max||str_contains($value,"\0")||!preg_match('//u',$value))throw new InvalidArgumentException('Invalid media/proposal text');return trim($value);
 }
 public static function tags($value): array {
  if(!is_array($value)||!array_is_list($value)||count($value)>20)throw new InvalidArgumentException('Use up to 20 tags/markets');return array_values(array_unique(array_map(fn($t)=>self::text($t,64),$value)));
 }
 public static function accessible(array $a,array $u,bool $review=false): bool {
  return (int)$a['company_id']===(int)$u['company_id']&&((int)$a['owner_id']===(int)$u['id']||($review&&$a['visibility']!=='PERSONAL')||($a['status']==='APPROVED'&&$a['visibility']!=='PERSONAL'));
 }
 public static function asset(PDO $db,array $u,int $id,bool $review=false): array {
  $a=self::q($db,'SELECT a.*,d.drive_file_id,d.mapping_id drive_folder_id,d.source_modified_time,d.linked,d.sync_status FROM media_assets a LEFT JOIN media_drive_links d ON d.asset_id=a.id WHERE a.company_id=? AND a.id=?',[$u['company_id'],$id])->fetch(PDO::FETCH_ASSOC);
  if(!$a||!self::accessible($a,$u,$review))throw new OutOfBoundsException('Media not found');return $a;
 }
 public static function projection(array $a): array {
  $safe=array_intersect_key($a,array_flip(['id','title','original_filename','mime_type','source_type','visibility','status','width','height','original_width','original_height','original_size','byte_size','orientation','destination','category','service_type','property_reference','owner_id','quote_id','created_at','updated_at','favourite','linked','sync_status','source_modified_time','supersedes_id']));
  $safe['tags']=json_decode($a['tags_json'],true);$safe['markets']=json_decode($a['markets_json'],true);$safe['low_resolution']=(int)$a['width']<1200||(int)$a['height']<675;return $safe;
 }
 public static function list(PDO $db,array $u,array $filter=[],bool $review=false): array {
  $sql="SELECT a.*,EXISTS(SELECT 1 FROM media_favourites f WHERE f.asset_id=a.id AND f.user_id=?) favourite FROM media_assets a WHERE a.company_id=? AND (a.owner_id=? OR (a.visibility<>'PERSONAL' AND a.status='APPROVED')".($review?" OR a.visibility<>'PERSONAL'":"").')';$args=[$u['id'],$u['company_id'],$u['id']];
  foreach(['destination','category','status','visibility','service_type'] as $key)if(!empty($filter[$key])){$sql.=" AND a.$key=?";$args[]=self::text($filter[$key]);}
  if(!empty($filter['quote_id'])){$sql.=" AND (a.visibility<>'PERSONAL' OR a.quote_id IS NULL OR a.quote_id=?)";$args[]=(int)$filter['quote_id'];}
  if(!empty($filter['q'])){$sql.=' AND (a.title LIKE ? OR a.tags_json LIKE ?)';$q='%'.self::text($filter['q']).'%';$args[]=$q;$args[]=$q;}
  if(!empty($filter['favourites'])){$sql.=' AND EXISTS(SELECT 1 FROM media_favourites f WHERE f.asset_id=a.id AND f.user_id=?)';$args[]=$u['id'];}
  $page=max(1,min(10000,(int)($filter['page']??1)));$rows=self::q($db,$sql.' ORDER BY a.id DESC LIMIT 41 OFFSET '.(($page-1)*40),$args)->fetchAll(PDO::FETCH_ASSOC);
  return ['items'=>array_map([self::class,'projection'],array_slice($rows,0,40)),'page'=>$page,'has_more'=>count($rows)>40];
 }
 public static function directory(array $cfg): string {
  $base=rtrim((string)($cfg['storage']['local_path']??''),'/\\');if($base==='')throw new RuntimeException('Private local storage is required');$dir=$base.'/media';
  if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create private media directory');RuntimeGuard::privatePath($dir);return $dir;
 }
 public static function image(string $bytes,string $dir): array {
  if(strlen($bytes)<1||strlen($bytes)>self::MAX_BYTES)throw new InvalidArgumentException('Image must be under 12 MB');
  if(!function_exists('imagecreatefromstring'))throw new RuntimeException('PHP GD is required');
  $info=@getimagesizefromstring($bytes);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))throw new InvalidArgumentException('Use a real JPEG, PNG or WebP image. SVG/HEIC/GIF are unsupported.');
  if($info[0]<1||$info[1]<1||$info[0]*$info[1]>self::MAX_PIXELS)throw new InvalidArgumentException('Image exceeds 16 megapixels; export a smaller copy');
  $im=@imagecreatefromstring($bytes);if(!$im)throw new InvalidArgumentException('Image cannot be decoded');$paths=[];$sizes=[];
  try {
   $original=$dir.'/'.bin2hex(random_bytes(24)).'.original';$paths[]=$original;if(file_put_contents($original,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Cannot preserve original image');chmod($original,0600);
   foreach([2400,400] as $edge){$scale=min(1,$edge/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$dest=imagecreatetruecolor($w,$h);if(!$dest)throw new RuntimeException('Image allocation failed');
    try{$white=imagecolorallocate($dest,255,255,255);imagefill($dest,0,0,$white);imagecopyresampled($dest,$im,0,0,0,0,$w,$h,$info[0],$info[1]);$p=$dir.'/'.bin2hex(random_bytes(24)).'.jpg';$paths[]=$p;$sizes[]=[$w,$h];if(!imagejpeg($dest,$p,$edge===400?78:88))throw new RuntimeException('Cannot optimize image');chmod($p,0640);}finally{imagedestroy($dest);}
   }
  }catch(Throwable $e){foreach($paths as $p)if(is_file($p))unlink($p);throw $e;}finally{imagedestroy($im);}
  return ['original_path'=>$paths[0],'storage_path'=>$paths[1],'thumbnail_path'=>$paths[2],'original_sha256'=>hash('sha256',$bytes),'content_sha256'=>hash_file('sha256',$paths[1]),'width'=>$sizes[0][0],'height'=>$sizes[0][1],'original_width'=>$info[0],'original_height'=>$info[1],'mime_type'=>$info['mime'],'original_size'=>strlen($bytes),'byte_size'=>filesize($paths[1]),'orientation'=>$info[0]===$info[1]?'SQUARE':($info[0]>$info[1]?'LANDSCAPE':'PORTRAIT')];
 }
 public static function ingest(PDO $db,array $cfg,array $u,string $bytes,array $b,array $source=[]): array {
  $visibility=$b['visibility']??'PERSONAL';if(!in_array($visibility,['PERSONAL','COMPANY','SYNCED'],true)||($visibility==='SYNCED'&&empty($source['drive_file_id'])))throw new InvalidArgumentException('Invalid visibility');
  $title=self::text($b['title']??'Uploaded image',255);if($title==='')throw new InvalidArgumentException('Image title required');$filename=self::text(basename($b['filename']??$title),255);$quote=$visibility==='PERSONAL'?(int)($b['quote_id']??0):0;
  if($quote&&!self::q($db,'SELECT id FROM quotes WHERE company_id=? AND id=?',[$u['company_id'],$quote])->fetchColumn())throw new OutOfBoundsException('Quote not found');
  if(!empty($source['drive_folder_id']))MediaDrive::mapping($db,$u,(int)$source['drive_folder_id']);
  $old=self::q($db,"SELECT id FROM media_assets WHERE company_id=? AND owner_id=? AND original_sha256=? AND visibility=? AND COALESCE(quote_id,0)=? AND status<>'ARCHIVED' ORDER BY id DESC LIMIT 1",[$u['company_id'],$u['id'],hash('sha256',$bytes),$visibility,$quote])->fetchColumn();
  if($old&&!$source)return ['asset'=>self::projection(self::asset($db,$u,(int)$old)),'duplicate'=>true];
  $meta=['destination'=>self::text($b['destination']??''),'category'=>self::text($b['category']??'',64),'service_type'=>self::text($b['service_type']??'',32),'property_reference'=>self::text($b['property_reference']??''),'tags_json'=>self::json(self::tags($b['tags']??[])),'markets_json'=>self::json(self::tags($b['markets']??[]))];
  $image=self::image($bytes,self::directory($cfg));
  try {return QuoteVs2Repository::atomic($db,function()use($db,$u,$source,$image,$meta,$visibility,$title,$filename,$quote){
   $data=['company_id'=>$u['company_id'],'owner_id'=>$u['id'],'quote_id'=>$quote?:null,'visibility'=>$visibility,'status'=>'DRAFT','source_type'=>$source['source_type']??'PC','title'=>$title,'original_filename'=>$filename,'supersedes_id'=>$source['supersedes_id']??null]+$image+$meta;
   self::q($db,'INSERT INTO media_assets('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));$id=(int)$db->lastInsertId();
   if(!empty($source['drive_file_id']))self::q($db,'INSERT INTO media_drive_links(asset_id,mapping_id,drive_file_id,source_modified_time,source_filename,linked) VALUES(?,?,?,?,?,?)',[$id,$source['drive_folder_id'],$source['drive_file_id'],$source['source_modified_time']??'',$filename,$source['linked']??0]);
   Audit::log($db,(int)$u['company_id'],(int)$u['id'],'MEDIA_UPLOADED','media_asset',$id,null,['title'=>$title,'sha256'=>$image['content_sha256'],'source'=>$data['source_type']]);return ['asset'=>self::projection(self::asset($db,$u,$id)),'duplicate'=>false];
  });}catch(Throwable $e){foreach(['original_path','storage_path','thumbnail_path'] as $key)if(is_file($image[$key]))unlink($image[$key]);throw $e;}
 }
 public static function review(PDO $db,array $u,int $id,array $b): array {
  $approver=Auth::can($db,(int)$u['id'],'media.approve');$a=self::asset($db,$u,$id,$approver);$status=$b['status']??$a['status'];$visibility=$b['visibility']??$a['visibility'];
  if(!$approver&&((int)$a['owner_id']!==(int)$u['id']||($status==='APPROVED'&&$visibility!=='PERSONAL')||$a['status']==='APPROVED'&&$a['visibility']!=='PERSONAL'))throw new DomainException('MEDIA_APPROVAL_REQUIRED');
  if(!in_array($status,['DRAFT','APPROVED','ARCHIVED'],true)||!in_array($visibility,['PERSONAL','COMPANY','SYNCED'],true)||($visibility==='SYNCED'&&$a['source_type']!=='GOOGLE_DRIVE'))throw new InvalidArgumentException('Invalid media state');
  if($a['status']==='ARCHIVED'&&$status!=='ARCHIVED')throw new DomainException('Import a new asset to replace archived media');
  $data=['title'=>self::text($b['title']??$a['title'],255),'destination'=>self::text($b['destination']??$a['destination']),'category'=>self::text($b['category']??$a['category'],64),'service_type'=>self::text($b['service_type']??$a['service_type'],32),'property_reference'=>self::text($b['property_reference']??$a['property_reference']),'tags_json'=>self::json(self::tags($b['tags']??json_decode($a['tags_json'],true))),'markets_json'=>self::json(self::tags($b['markets']??json_decode($a['markets_json'],true))),'status'=>$status,'visibility'=>$visibility,'reviewed_by'=>$u['id']];
  if($data['title']==='')throw new InvalidArgumentException('Title required');self::q($db,'UPDATE media_assets SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($data))).' WHERE id=?',[...array_values($data),$id]);
  Audit::log($db,(int)$u['company_id'],(int)$u['id'],'MEDIA_REVIEWED','media_asset',$id,['status'=>$a['status']],['status'=>$status,'visibility'=>$visibility]);return self::projection(self::asset($db,$u,$id,$approver));
 }
 public static function path(array $cfg,array $a,bool $thumb=false,bool $original=false): string {
  $base=realpath(self::directory($cfg));$p=realpath((string)$a[$original?'original_path':($thumb?'thumbnail_path':'storage_path')]);if(!$p||!$base||!RuntimeGuard::inside($p,$base)||!is_file($p))throw new OutOfBoundsException('Media unavailable');return $p;
 }
 public static function stream(array $cfg,array $a,bool $thumb=false,bool $original=false): never {
  $path=self::path($cfg,$a,$thumb,$original);header('Content-Type: '.($original?'application/octet-stream':'image/jpeg'));header('Content-Disposition: '.($original?'attachment':'inline').'; filename="image'.(int)$a['id'].($original?'.original':'.jpg').'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($path));readfile($path);exit;
 }
 public static function handle(string $route,string $method,PDO $db,array $cfg,array $u): void {
  if(!preg_match('#^media(?:/|$)#',$route))return;Auth::requirePermission($db,$u,'media.view');
  try {
   if($route==='media'&&$method==='GET')Http::json(['ok'=>true]+self::list($db,$u,$_GET,Auth::can($db,(int)$u['id'],'media.approve')));
   if($route==='media/upload'&&$method==='POST'){
    Auth::requirePermission($db,$u,'media.manage');$f=$_FILES['file']??[];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']??'')||($f['size']??0)>self::MAX_BYTES)throw new InvalidArgumentException('Upload JPEG/PNG/WebP under 12 MB');
    $b=$_POST;$b['filename']=basename($f['name']);foreach(['tags','markets'] as $key)$b[$key]=isset($b[$key])?json_decode($b[$key],true,512,JSON_THROW_ON_ERROR):[];
    Http::json(['ok'=>true]+self::ingest($db,$cfg,$u,(string)file_get_contents($f['tmp_name']),$b),201);
   }
   if(preg_match('#^media/(\d+)(?:/(thumbnail|image|original|favourite))?$#',$route,$m)){
    $id=(int)$m[1];$action=$m[2]??'';$a=self::asset($db,$u,$id,Auth::can($db,(int)$u['id'],'media.approve'));
    if($method==='GET'&&in_array($action,['thumbnail','image','original'],true)){if($action==='original')Auth::requirePermission($db,$u,'media.manage');self::stream($cfg,$a,$action==='thumbnail',$action==='original');}
    if($method==='GET'&&$action==='')Http::json(['ok'=>true,'asset'=>self::projection($a)]);
    if($method==='PATCH'&&$action===''){Auth::requirePermission($db,$u,'media.manage');Http::json(['ok'=>true,'asset'=>self::review($db,$u,$id,Http::body())]);}
    if($method==='POST'&&$action==='favourite'){$on=!empty(Http::body()['enabled']);if($on)self::q($db,'INSERT IGNORE INTO media_favourites(asset_id,user_id) VALUES(?,?)',[$id,$u['id']]);else self::q($db,'DELETE FROM media_favourites WHERE asset_id=? AND user_id=?',[$id,$u['id']]);Http::json(['ok'=>true]);}
   }
  }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
 }
}
