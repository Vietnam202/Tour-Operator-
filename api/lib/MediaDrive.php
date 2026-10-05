<?php
declare(strict_types=1);
require_once __DIR__.'/MediaLibrary.php';

/** Read-only adapter. Credentials and allowed folders belong to exactly one tenant. */
final class MediaDrive {
 private array $cfg;
 private $transport;
 private ?string $token=null;
 public function __construct(array $cfg,?callable $transport=null){$this->cfg=$cfg;$this->transport=$transport;}
 public static function forCompany(array $config,int $company):self{return new self($config['media_drive']['accounts'][$company]??[]);}
 public function available():bool{return !empty($this->cfg['allowed_folder_ids'])&&(!empty($this->cfg['oauth_refresh_token'])||!empty($this->cfg['service_account_json']));}
 public static function id($v):string{$s=MediaLibrary::text($v,200);if(!preg_match('/^[A-Za-z0-9_-]{10,200}$/D',$s))throw new InvalidArgumentException('Invalid Drive file/folder ID');return $s;}
 private function http(string $url,string $method,array $headers,?string $body,int $limit):array{
  if($this->transport)return ($this->transport)($url,$method,$headers,$body,$limit);
  if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL required');$raw='';$tooLarge=false;$ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$raw,&$tooLarge,$limit){if(strlen($raw)+strlen($part)>$limit){$tooLarge=true;return 0;}$raw.=$part;return strlen($part);}]);
  $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if(!$ok||$tooLarge)throw new RuntimeException('Drive response unavailable or too large');return [$code,$raw];
 }
 private function token():string{
  if($this->token)return $this->token;if(!$this->available())throw new RuntimeException('Drive account/folder allowlist not configured');
  if(!empty($this->cfg['oauth_refresh_token']))$data=['client_id'=>$this->cfg['oauth_client_id']??'','client_secret'=>$this->cfg['oauth_client_secret']??'','refresh_token'=>$this->cfg['oauth_refresh_token'],'grant_type'=>'refresh_token'];
  else{$path=(string)$this->cfg['service_account_json'];RuntimeGuard::privatePath($path);$a=json_decode((string)file_get_contents($path),true);if(empty($a['client_email'])||empty($a['private_key']))throw new RuntimeException('Invalid service account');$b64=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$now=time();$s=$b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.$b64(json_encode(['iss'=>$a['client_email'],'scope'=>'https://www.googleapis.com/auth/drive.readonly','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]));$sig='';if(!openssl_sign($s,$sig,$a['private_key'],OPENSSL_ALGO_SHA256))throw new RuntimeException('Cannot sign Drive assertion');$data=['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$s.'.'.$b64($sig)];}
  [$status,$raw]=$this->http('https://oauth2.googleapis.com/token','POST',['Content-Type: application/x-www-form-urlencoded'],http_build_query($data),65536);$out=json_decode($raw,true);if($status!==200||empty($out['access_token']))throw new RuntimeException('Drive authentication failed');return $this->token=$out['access_token'];
 }
 private function get(string $path,array $params=[],bool $binary=false):array|string{
  [$status,$raw]=$this->http('https://www.googleapis.com/drive/v3/'.$path.'?'.http_build_query($params),'GET',['Authorization: Bearer '.$this->token()],null,$binary?MediaLibrary::MAX_BYTES:262144);
  if($status!==200)throw new RuntimeException('Drive file unavailable');if($binary)return $raw;$out=json_decode($raw,true);if(!is_array($out))throw new RuntimeException('Invalid Drive response');return $out;
 }
 public function folder(string $folder):array{
  $folder=self::id($folder);if(!in_array($folder,$this->cfg['allowed_folder_ids']??[],true))throw new DomainException('Folder is outside the company allowlist');$f=$this->get('files/'.$folder,['fields'=>'id,name,mimeType,trashed','supportsAllDrives'=>'true']);if(($f['mimeType']??'')!=='application/vnd.google-apps.folder'||!empty($f['trashed']))throw new InvalidArgumentException('Choose an active Drive folder');return $f;
 }
 public function browse(string $folder,string $page=''):array{
  $this->folder($folder);if(strlen($page)>2048||preg_match('/[\x00-\x1f]/',$page))throw new InvalidArgumentException('Invalid Drive page token');
  $params=['q'=>"'$folder' in parents and trashed = false and (mimeType = 'image/jpeg' or mimeType = 'image/png' or mimeType = 'image/webp')",'fields'=>'nextPageToken,incompleteSearch,files(id,name,mimeType,size,modifiedTime)','pageSize'=>40,'orderBy'=>'name','supportsAllDrives'=>'true','includeItemsFromAllDrives'=>'true'];if($page!=='')$params['pageToken']=$page;return $this->get('files',$params);
 }
 public function metadata(string $folder,string $file):array{
  $this->folder($folder);$file=self::id($file);$f=$this->get('files/'.$file,['fields'=>'id,name,mimeType,size,modifiedTime,parents,trashed','supportsAllDrives'=>'true']);
  if(!in_array($folder,$f['parents']??[],true)||!empty($f['trashed']))throw new DomainException('Image is outside the mapped folder');if(!in_array($f['mimeType']??'',['image/jpeg','image/png','image/webp'],true)||(int)($f['size']??0)>MediaLibrary::MAX_BYTES)throw new InvalidArgumentException('Choose a JPEG/PNG/WebP image under 12 MB');return $f;
 }
 public function download(string $folder,string $file):array{$before=$this->metadata($folder,$file);$bytes=$this->get('files/'.self::id($file),['alt'=>'media','supportsAllDrives'=>'true'],true);$after=$this->metadata($folder,$file);if(($before['modifiedTime']??null)!==($after['modifiedTime']??null))throw new DomainException('Drive image changed during import; try again');return ['metadata'=>$after,'bytes'=>$bytes];}
 public static function mapping(PDO $db,array $u,int $id):array{$f=MediaLibrary::q($db,'SELECT * FROM media_drive_folders WHERE company_id=? AND id=?',[$u['company_id'],$id])->fetch(PDO::FETCH_ASSOC);if(!$f)throw new OutOfBoundsException('Drive mapping not found');return $f;}
 public static function import(PDO $db,array $cfg,array $u,array $mapping,string $file,bool $linked,?array $old=null,?self $adapter=null):array{
  if((int)($mapping['company_id']??0)!==(int)$u['company_id'])throw new OutOfBoundsException('Drive mapping not found');
  $adapter??=self::forCompany($cfg,(int)$u['company_id']);$r=$adapter->download($mapping['folder_id'],$file);$m=$r['metadata'];
  if($old&&$old['source_modified_time']===$m['modifiedTime'])return ['asset'=>MediaLibrary::projection($old),'unchanged'=>true];
  return MediaLibrary::ingest($db,$cfg,$u,$r['bytes'],['visibility'=>'SYNCED','title'=>MediaLibrary::text($m['name']),'destination'=>$mapping['destination'],'category'=>$mapping['category'],'tags'=>json_decode($mapping['tags_json'],true)],['source_type'=>'GOOGLE_DRIVE','drive_file_id'=>$file,'drive_folder_id'=>$mapping['id'],'source_modified_time'=>$m['modifiedTime']??'','linked'=>$linked?1:0,'supersedes_id'=>$old['id']??null]);
 }
 public static function handle(string $route,string $method,PDO $db,array $cfg,array $u):void{
  if(!preg_match('#^media-drive(?:/|$)#',$route))return;Auth::requirePermission($db,$u,'media.view');$drive=self::forCompany($cfg,(int)$u['company_id']);
  try{
   if($route==='media-drive'&&$method==='GET'){$maps=MediaLibrary::q($db,'SELECT id,folder_id,destination,category,tags_json FROM media_drive_folders WHERE company_id=? ORDER BY id',[$u['company_id']])->fetchAll(PDO::FETCH_ASSOC);Http::json(['ok'=>true,'available'=>$drive->available(),'mappings'=>$maps]);}
   if($route==='media-drive/mappings'&&$method==='POST'){Auth::requirePermission($db,$u,'media.review');$b=Http::body();$folder=self::id($b['folder_id']??'');$drive->folder($folder);MediaLibrary::q($db,'INSERT INTO media_drive_folders(company_id,folder_id,destination,category,tags_json,created_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE destination=VALUES(destination),category=VALUES(category),tags_json=VALUES(tags_json)',[$u['company_id'],$folder,MediaLibrary::text($b['destination']??''),MediaLibrary::text($b['category']??'',64),MediaLibrary::json(MediaLibrary::tags($b['tags']??[])),$u['id']]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'MEDIA_DRIVE_MAPPED','company',(int)$u['company_id'],null,['folder_id'=>$folder]);Http::json(['ok'=>true]);}
   if(preg_match('#^media-drive/(\d+)/files$#',$route,$m)&&$method==='GET'){$mapping=self::mapping($db,$u,(int)$m[1]);Http::json(['ok'=>true]+$drive->browse($mapping['folder_id'],(string)($_GET['page_token']??'')));}
   if(preg_match('#^media-drive/(\d+)/import$#',$route,$m)&&$method==='POST'){Auth::requirePermission($db,$u,'media.upload');$mapping=self::mapping($db,$u,(int)$m[1]);$b=Http::body();$ids=$b['file_ids']??[];if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>10)throw new InvalidArgumentException('Choose 1–10 images');$items=[];$errors=[];foreach($ids as $id){$id=self::id($id);try{$items[]=self::import($db,$cfg,$u,$mapping,$id,!empty($b['linked']));}catch(Throwable $e){$errors[]=['file_id'=>$id,'message'=>'Image unavailable, moved or invalid; retry this file.'];}}Http::json(['ok'=>true,'items'=>$items,'errors'=>$errors],201);}
   if(preg_match('#^media-drive/assets/(\d+)/(check|sync)$#',$route,$m)&&$method==='POST'){Auth::requirePermission($db,$u,'media.upload');$a=MediaLibrary::asset($db,$u,(int)$m[1],Auth::can($db,(int)$u['id'],'media.review'));if(empty($a['linked'])||$a['source_type']!=='GOOGLE_DRIVE')throw new DomainException('Image is not linked to Drive');$mapping=self::mapping($db,$u,(int)$a['drive_folder_id']);if($m[2]==='check'){$f=$drive->metadata($mapping['folder_id'],$a['drive_file_id']);Http::json(['ok'=>true,'changed'=>$a['source_modified_time']!==$f['modifiedTime'],'source_modified_time'=>$f['modifiedTime']]);}Http::json(['ok'=>true]+self::import($db,$cfg,$u,$mapping,$a['drive_file_id'],true,$a));}
  }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(RuntimeException $e){error_log('Drive media request failed');Http::json(['ok'=>false,'error'=>'DRIVE_UNAVAILABLE','message'=>'Configure the company Drive account and folder allowlist in private server configuration.'],503);}
 }
}
