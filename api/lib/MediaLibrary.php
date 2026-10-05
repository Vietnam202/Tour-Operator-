<?php
declare(strict_types=1);

/** Image bytes are immutable, re-encoded and stored outside the web root. */
final class MediaLibrary {
 public const MAX_BYTES=12582912;
 public const MAX_PIXELS=16000000;
 public static function q(PDO $db,string $sql,array $a=[]):PDOStatement{$s=$db->prepare($sql);$s->execute($a);return $s;}
 public static function json($v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 public static function text($v,int $max=190):string{if(!is_string($v)||strlen($v)>$max||str_contains($v,"\0")||!preg_match('//u',$v))throw new InvalidArgumentException('Invalid media/proposal text');return trim($v);}
 public static function tags($v):array{if(!is_array($v)||!array_is_list($v)||count($v)>20)throw new InvalidArgumentException('Maximum 20 tags');return array_values(array_unique(array_map(fn($t)=>self::text($t,64),$v)));}
 public static function accessible(array $a,array $u,bool $review=false):bool{return (int)$a['company_id']===(int)$u['company_id']&&((int)$a['owner_id']===(int)$u['id']||($review&&$a['visibility']!=='PERSONAL')||($a['status']==='APPROVED'&&$a['visibility']!=='PERSONAL'));}
 public static function asset(PDO $db,array $u,int $id,bool $review=false):array{$a=self::q($db,'SELECT * FROM media_assets WHERE company_id=? AND id=?',[$u['company_id'],$id])->fetch(PDO::FETCH_ASSOC);if(!$a||!self::accessible($a,$u,$review))throw new OutOfBoundsException('Media not found');return $a;}
 public static function projection(array $a):array{$out=[];foreach(['id','title','destination','category','visibility','status','width','height','byte_size','source_type','source_modified_time','linked','supersedes_id','created_at','favourite'] as $k)if(isset($a[$k]))$out[$k]=$a[$k];$out['tags']=json_decode($a['tags_json'],true);$out['low_resolution']=(int)$a['width']<1200||(int)$a['height']<675;return $out;}
 public static function list(PDO $db,array $u,array $filter=[],bool $review=false):array{
  $sql='SELECT a.*,EXISTS(SELECT 1 FROM media_favourites f WHERE f.asset_id=a.id AND f.user_id=?) favourite FROM media_assets a WHERE company_id=? AND (owner_id=? OR (visibility<>\'PERSONAL\' AND status=\'APPROVED\')'.($review?' OR visibility<>\'PERSONAL\'':'').')';$args=[$u['id'],$u['company_id'],$u['id']];
  foreach(['destination','category','status'] as $k)if(!empty($filter[$k])){$sql.=" AND $k=?";$args[]=self::text($filter[$k]);}
  if(!empty($filter['q'])){$sql.=' AND (title LIKE ? OR tags_json LIKE ?)';$v='%'.self::text($filter['q']).'%';$args[]=$v;$args[]=$v;}
  if(!empty($filter['favourites'])){$sql.=' AND EXISTS(SELECT 1 FROM media_favourites f WHERE f.asset_id=a.id AND f.user_id=?)';$args[]=$u['id'];}
  $page=max(1,min(10000,(int)($filter['page']??1)));$rows=self::q($db,$sql.' ORDER BY a.id DESC LIMIT 41 OFFSET '.(($page-1)*40),$args)->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>40;return ['items'=>array_map([self::class,'projection'],array_slice($rows,0,40)),'page'=>$page,'has_more'=>$more];
 }
 public static function directory(array $config):string{$base=rtrim((string)($config['storage']['local_path']??''),'/');if($base==='')throw new RuntimeException('Private local media storage required');$dir=$base.'/media';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create media directory');RuntimeGuard::privatePath($dir);return $dir;}
 public static function image(string $bytes,string $directory):array{
  if(strlen($bytes)<1||strlen($bytes)>self::MAX_BYTES)throw new InvalidArgumentException('Image must be 1 byte–12 MB');if(!function_exists('imagecreatefromstring'))throw new RuntimeException('PHP GD extension is required for media');
  $info=@getimagesizefromstring($bytes);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))throw new InvalidArgumentException('Upload a real JPEG, PNG or WebP image (SVG is not supported)');
  if($info[0]<1||$info[1]<1||$info[0]*$info[1]>self::MAX_PIXELS)throw new InvalidArgumentException('Image exceeds 16 megapixels');$im=@imagecreatefromstring($bytes);if(!$im)throw new InvalidArgumentException('Image cannot be decoded');
  $paths=[];$dimensions=[];try{foreach([2400,400] as $edge){$scale=min(1,$edge/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$dest=imagecreatetruecolor($w,$h);$white=imagecolorallocate($dest,255,255,255);imagefill($dest,0,0,$white);imagecopyresampled($dest,$im,0,0,0,0,$w,$h,$info[0],$info[1]);$p=$directory.'/'.bin2hex(random_bytes(24)).'.jpg';$paths[]=$p;$dimensions[]=[$w,$h];try{if(!imagejpeg($dest,$p,$edge===400?78:88))throw new RuntimeException('Cannot write optimized media');chmod($p,0660);}finally{imagedestroy($dest);}}}
  catch(Throwable $e){foreach($paths as $p)if(is_file($p))unlink($p);throw $e;}finally{imagedestroy($im);}
  return ['storage_path'=>$paths[0],'thumbnail_path'=>$paths[1],'width'=>$dimensions[0][0],'height'=>$dimensions[0][1],'byte_size'=>filesize($paths[0]),'content_sha256'=>hash_file('sha256',$paths[0]),'original_sha256'=>hash('sha256',$bytes)];
 }
 public static function ingest(PDO $db,array $cfg,array $u,string $bytes,array $b,array $source=[]):array{
  $visibility=$b['visibility']??'PERSONAL';if(!in_array($visibility,['PERSONAL','COMPANY','SYNCED'],true)||($visibility==='SYNCED'&&empty($source['drive_file_id'])))throw new InvalidArgumentException('Invalid media visibility');
  $title=self::text($b['title']??'Uploaded image');if($title==='')throw new InvalidArgumentException('Image title required');$destination=self::text($b['destination']??'');$category=self::text($b['category']??'',64);$tags=self::tags($b['tags']??[]);
  $existing=self::q($db,"SELECT * FROM media_assets WHERE company_id=? AND original_sha256=? AND owner_id=? AND visibility=? AND status<>'ARCHIVED' ORDER BY id DESC LIMIT 1",[$u['company_id'],hash('sha256',$bytes),$u['id'],$visibility])->fetch(PDO::FETCH_ASSOC);
  if($existing&&empty($source['supersedes_id'])&&($source['drive_file_id']??null)===($existing['drive_file_id']??null)&&($source['source_modified_time']??null)===($existing['source_modified_time']??null))return ['asset'=>self::projection($existing),'duplicate'=>true];
  $image=self::image($bytes,self::directory($cfg));try{$fields=['company_id'=>$u['company_id'],'owner_id'=>$u['id'],'visibility'=>$visibility,'status'=>'DRAFT','title'=>$title,'destination'=>$destination,'category'=>$category,'tags_json'=>self::json($tags)]+$image+array_intersect_key($source,array_flip(['source_type','drive_file_id','drive_folder_id','source_modified_time','linked','supersedes_id']));
   self::q($db,'INSERT INTO media_assets('.implode(',',array_keys($fields)).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')',array_values($fields));$id=(int)$db->lastInsertId();Audit::log($db,(int)$u['company_id'],(int)$u['id'],'MEDIA_UPLOADED','media_asset',$id,null,['title'=>$title,'content_sha256'=>$image['content_sha256'],'source_type'=>$source['source_type']??'PC']);return ['asset'=>self::projection(self::asset($db,$u,$id)),'duplicate'=>false];
  }catch(Throwable $e){foreach(['storage_path','thumbnail_path'] as $k)if(is_file($image[$k]))unlink($image[$k]);throw $e;}
 }
 public static function review(PDO $db,array $u,int $id,array $b):array{
  $a=self::asset($db,$u,$id,true);if($a['visibility']==='PERSONAL'&&(int)$a['owner_id']!==(int)$u['id'])throw new OutOfBoundsException('Personal image not found');$status=$b['status']??$a['status'];$visibility=$b['visibility']??$a['visibility'];
  if(!in_array($status,['DRAFT','APPROVED','ARCHIVED'],true)||!in_array($visibility,['PERSONAL','COMPANY','SYNCED'],true)||($visibility==='SYNCED'&&$a['source_type']!=='GOOGLE_DRIVE'))throw new InvalidArgumentException('Invalid media state');
  if($a['status']==='ARCHIVED'&&$status!=='ARCHIVED')throw new DomainException('Archived media cannot be restored; import a new asset');if($a['status']==='APPROVED'&&$status==='DRAFT')throw new DomainException('Archive approved media instead of downgrading');
  $title=self::text($b['title']??$a['title']);if($title==='')throw new InvalidArgumentException('Title required');self::q($db,'UPDATE media_assets SET title=?,destination=?,category=?,tags_json=?,visibility=?,status=?,reviewed_by=? WHERE id=?',[$title,self::text($b['destination']??$a['destination']),self::text($b['category']??$a['category'],64),self::json(self::tags($b['tags']??json_decode($a['tags_json'],true))),$visibility,$status,$u['id'],$id]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'MEDIA_REVIEWED','media_asset',$id,['status'=>$a['status']],['status'=>$status,'visibility'=>$visibility]);return self::projection(self::asset($db,$u,$id,true));
 }
 public static function path(array $cfg,array $a,bool $thumb=false):string{$base=realpath(self::directory($cfg));$p=realpath($a[$thumb?'thumbnail_path':'storage_path']);if(!$p||!RuntimeGuard::inside($p,$base)||!is_file($p))throw new OutOfBoundsException('Image missing');if(!$thumb&&!hash_equals($a['content_sha256'],hash_file('sha256',$p)))throw new RuntimeException('Media integrity check failed');return $p;}
 public static function stream(array $cfg,array $a,bool $thumb=false):never{$p=self::path($cfg,$a,$thumb);header('Content-Type: image/jpeg');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($p));readfile($p);exit;}
 public static function handle(string $route,string $method,PDO $db,array $cfg,array $u):void{
  if(!preg_match('#^media(?:/|$)#',$route))return;Auth::requirePermission($db,$u,'media.view');$review=Auth::can($db,(int)$u['id'],'media.review');
  try{
   if($route==='media'&&$method==='GET')Http::json(['ok'=>true]+self::list($db,$u,$_GET,$review));
   if($route==='media/upload'&&$method==='POST'){Auth::requirePermission($db,$u,'media.upload');$f=$_FILES['file']??[];if(($f['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']??'')||filesize($f['tmp_name'])>self::MAX_BYTES)throw new InvalidArgumentException('Image upload failed; maximum 12 MB');$b=$_POST;$b['tags']=isset($b['tags'])?json_decode($b['tags'],true):[];Http::json(['ok'=>true]+self::ingest($db,$cfg,$u,(string)file_get_contents($f['tmp_name']),$b),201);}
   if(preg_match('#^media/(\d+)/(image|thumbnail)$#',$route,$m)&&$method==='GET')self::stream($cfg,self::asset($db,$u,(int)$m[1],$review),$m[2]==='thumbnail');
   if(preg_match('#^media/(\d+)$#',$route,$m)&&$method==='PUT'){Auth::requirePermission($db,$u,'media.review');Http::json(['ok'=>true,'asset'=>self::review($db,$u,(int)$m[1],Http::body())]);}
   if(preg_match('#^media/(\d+)/favourite$#',$route,$m)&&in_array($method,['POST','DELETE'],true)){self::asset($db,$u,(int)$m[1],$review);if($method==='POST')self::q($db,'INSERT IGNORE INTO media_favourites(user_id,asset_id) VALUES(?,?)',[$u['id'],$m[1]]);else self::q($db,'DELETE FROM media_favourites WHERE user_id=? AND asset_id=?',[$u['id'],$m[1]]);Http::json(['ok'=>true]);}
  }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(RuntimeException $e){error_log('Media operation failed: '.$e->getMessage());Http::json(['ok'=>false,'error'=>'MEDIA_UNAVAILABLE','message'=>'Check private storage and PHP GD configuration.'],503);}
 }
}
