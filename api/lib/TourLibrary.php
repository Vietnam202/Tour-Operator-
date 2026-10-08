<?php
declare(strict_types=1);

/** Company-scoped reusable programs. Import text is data; it is never executed. */
final class TourLibrary {
    public const MAX_FILE_BYTES = 10485760;
    public const MAX_TEXT_BYTES = 1000000;
    private static function q(PDO $db, string $sql, array $args=[]): PDOStatement { $s=$db->prepare($sql);$s->execute($args);return $s; }
    private static function json($v): string { return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    private static function text($v, int $max, string $name): string {
        if(!is_string($v)||strlen($v)>$max||!preg_match('//u',$v)||str_contains($v,"\0"))throw new InvalidArgumentException('Invalid '.$name);
        return trim($v);
    }
    /**
     * Customer-facing proposal fields only. No supplier costs, margins or commission values.
     * Strict allow-list prevents accidentally persisting confidential costing input.
     */
    private static function normalizeProposal($raw): array {
        if(!is_array($raw))throw new InvalidArgumentException('Invalid proposal data');
        $v=fn($name,$max=10000)=>self::text($raw[$name]??'', $max, 'proposal '.$name);
        $highlights=$raw['highlights']??[];
        if(!is_array($highlights)||!array_is_list($highlights)||count($highlights)>20)throw new InvalidArgumentException('Invalid tour highlights');
        $highlights=array_map(fn($x)=>self::text($x,500,'highlight'),$highlights);
        $group=$raw['group_prices']??[];
        if(!is_array($group)||!array_is_list($group)||count($group)>3)throw new InvalidArgumentException('Invalid group pricing');
        $money=function($v): string {
            $x=self::text($v??'',30,'selling price');
            if($x!==''&&!preg_match('/^\\d{1,7}(?:\\.\\d{1,2})?$/D',$x))throw new InvalidArgumentException('Prices must be nonnegative decimal USD amounts or blank');
            return $x;
        };
        $groups=[];
        foreach($group as $g) {
            if(!is_array($g)||!in_array((string)($g['hotel']??''),['3','4','5'],true))throw new InvalidArgumentException('Invalid hotel category');
            $groups[]=['hotel'=>(string)$g['hotel'],'price'=>$money($g['price']??''),'single'=>$money($g['single']??'')];
        }
        $private=$raw['private_prices']??[];
        if(!is_array($private)||!array_is_list($private)||count($private)>30)throw new InvalidArgumentException('Maximum 30 private price rows');
        $bands=[];
        foreach($private as $row) {
            if(!is_array($row))throw new InvalidArgumentException('Invalid private price row');
            $min=filter_var($row['min']??null,FILTER_VALIDATE_INT);
            $max=filter_var($row['max']??null,FILTER_VALIDATE_INT);
            if($min===false||$max===false||$min<1||$max<$min||$max>1000)throw new InvalidArgumentException('Invalid passenger range');
            $bands[]=['min'=>$min,'max'=>$max,'three'=>$money($row['three']??''),'four'=>$money($row['four']??''),'five'=>$money($row['five']??'')];
        }
        usort($bands,fn($a,$b)=>$a['min']<=>$b['min']);
        for($i=1;$i<count($bands);$i++)if($bands[$i]['min']<=$bands[$i-1]['max'])throw new InvalidArgumentException('Overlapping private passenger ranges');
        $hotels=$raw['hotels']??[];
        if(!is_array($hotels)||!array_is_list($hotels)||count($hotels)>60)throw new InvalidArgumentException('Maximum 60 hotel rows');
        $hotelRows=[];
        foreach($hotels as $hotel) {
            if(!is_array($hotel))throw new InvalidArgumentException('Invalid hotel row');
            $r=[];foreach(['destination','three','four','five'] as $key)$r[$key]=self::text($hotel[$key]??'',400,'hotel '.$key);
            $hotelRows[]=$r;
        }
        $policies=$raw['policies']??[];
        if(!is_array($policies))throw new InvalidArgumentException('Invalid policies');
        $policy=[];
        foreach(['children','payment','cancellation','notes'] as $key)$policy[$key]=self::text($policies[$key]??'',30000,'policy '.$key);
        $type=$v('tour_type',20);
        if($type!==''&&!in_array($type,['PRIVATE','SIC','BOTH'],true))throw new InvalidArgumentException('Invalid tour type');
        return [
            'schema'=>'VTA_LIBRARY_PROPOSAL_V1',
            'tour_code'=>$v('tour_code',50),
            'tour_type'=>$type?:'PRIVATE',
            'overview'=>$v('overview',30000),
            'highlights'=>$highlights,
            'group_prices'=>$groups,
            'private_prices'=>$bands,
            'hotels'=>$hotelRows,
            'policies'=>$policy
        ];
    }

    public static function normalize(array $b): array {
        $out=['title'=>self::text($b['title']??'',190,'program title'),'destination'=>self::text($b['destination']??'',190,'destination'),'language'=>self::text($b['language']??'en',16,'language')];
        if($out['title']==='')throw new InvalidArgumentException('Program title is required');
        if(!preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})?$/D',$out['language']))throw new InvalidArgumentException('Use a language code such as en or vi');
        $out['language']=strtolower($out['language']);
        $tags=$b['tags']??[];
        if(!is_array($tags)||!array_is_list($tags)||count($tags)>20)throw new InvalidArgumentException('Maximum 20 tags');
        $out['tags']=[];foreach($tags as $tag){$t=self::text($tag,64,'tag');if($t!=='')$out['tags'][]=$t;}$out['tags']=array_values(array_unique($out['tags']));
        $out['days']=ScheduleImport::normalize($b['days']??[]);
        foreach(['included_text','excluded_text','terms_text'] as $k)$out[$k]=self::text($b[$k]??'',100000,$k);
        if(array_key_exists('proposal',$b))$out['proposal']=self::normalizeProposal($b['proposal']);
        $out['source_text']=self::text($b['source_text']??'',self::MAX_TEXT_BYTES,'source text');
        $out['source_name']=self::filename(self::text($b['source_name']??'',255,'source name'));
        $out['source_type']=self::text($b['source_type']??'MANUAL',20,'source type');
        if(!in_array($out['source_type'],['PC','GOOGLE_DRIVE','TEXT','MANUAL'],true))throw new InvalidArgumentException('Invalid source type');
        $out['source_url']=self::text($b['source_url']??'',2048,'source URL');
        if($out['source_url']!=='')$out['source_url']=self::driveLink($out['source_url'])['source_url'];
        if($out['source_type']==='GOOGLE_DRIVE'&&$out['source_url']==='')throw new InvalidArgumentException('Google Drive source URL is required');
        if($out['source_type']!=='GOOGLE_DRIVE'&&$out['source_url']!=='')throw new InvalidArgumentException('A Drive link requires Google Drive source type');
        $out['status']=self::text($b['status']??'DRAFT',16,'status');
        if(!in_array($out['status'],['DRAFT','ACTIVE'],true))throw new InvalidArgumentException('Use DRAFT or ACTIVE; archive with the archive action');
        if($out['status']==='ACTIVE'&&!$out['days'])throw new InvalidArgumentException('Add at least one itinerary day before activating the program');
        if(strlen(self::json($out))>3000000)throw new InvalidArgumentException('Program content is too large');
        return $out;
    }
    public static function filename(string $s): string {
        $s=basename(str_replace('\\','/',$s));$s=preg_replace('/[\x00-\x1f\x7f]+/','',$s)??'';
        return trim($s);
    }
    /** Accept a share file link, never a folder, arbitrary host, login URL or OAuth URL. */
    public static function driveLink(string $url): array {
        if(strlen($url)>2048||preg_match('/[\x00-\x20\x7f]/',$url))throw new InvalidArgumentException('Paste a valid Google Drive file or Google Docs link');
        $p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment']))throw new InvalidArgumentException('Use an HTTPS Google Drive file or Google Docs link');
        $host=strtolower($p['host']??'');$path=$p['path']??'';$query=[];parse_str($p['query']??'',$query);$id='';$kind='';
        if($host==='docs.google.com'&&preg_match('#^/document/(?:u/\d+/)?d/([A-Za-z0-9_-]{10,200})(?:/(?:edit|view|preview|export))?/?$#D',$path,$m)){$id=$m[1];$kind='DOCS';}
        elseif($host==='drive.google.com') {
            if(str_contains($path,'/folders/'))throw new InvalidArgumentException('Google Drive folders are not supported. Select individual files, or download the folder and upload files from your PC.');
            if(preg_match('#^/file/(?:u/\d+/)?d/([A-Za-z0-9_-]{10,200})(?:/(?:view|edit|preview))?/?$#D',$path,$m)){$id=$m[1];$kind='FILE';}
            elseif(in_array($path,['/open','/uc','/download'],true)&&is_string($query['id']??null)&&preg_match('/^[A-Za-z0-9_-]{10,200}$/D',$query['id'])){$id=$query['id'];$kind='FILE';}
        }
        if($id==='')throw new InvalidArgumentException('Use a Google Drive file share link or a Google Docs document link. Private files must be downloaded with your Google account and uploaded from your PC.');
        $rk=$query['resourcekey']??'';
        if(!is_string($rk)||($rk!==''&&!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$rk)))throw new InvalidArgumentException('Invalid Google Drive resource key');
        $suffix=$rk!==''?'&resourcekey='.rawurlencode($rk):'';
        return ['kind'=>$kind,'id'=>$id,'source_url'=>$kind==='DOCS'?'https://docs.google.com/document/d/'.$id.'/edit':'https://drive.google.com/file/d/'.$id.'/view'.($rk!==''?'?resourcekey='.rawurlencode($rk):''),
            'download_url'=>$kind==='DOCS'?'https://docs.google.com/document/d/'.$id.'/export?format=pdf':'https://drive.google.com/uc?export=download&id='.$id.$suffix];
    }
    public static function remoteHost(string $url): string {
        if(strlen($url)>8192||preg_match('/[\x00-\x20\x7f]/',$url))throw new InvalidArgumentException('Unsafe Google download redirect');
        $p=parse_url($url);$host=strtolower($p['host']??'');
        $allowed=in_array($host,['drive.google.com','docs.google.com','drive.usercontent.google.com'],true)||preg_match('/^doc-[a-z0-9-]+-docs\.googleusercontent\.com$/D',$host);
        if(!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment'])||!$allowed)throw new InvalidArgumentException('The file requires Google sign-in or an unsupported redirect. Download it from Google Drive and upload it from your PC.');
        return $host;
    }
    public static function publicIpv4(string $ip): bool {
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
        $n=ip2long($ip);if($n===false)return false;$n=(int)sprintf('%u',$n);
        // Shared address space, benchmarking, multicast and reserved blocks are not Internet destinations.
        foreach([['0.0.0.0',8],['100.64.0.0',10],['192.0.0.0',24],['198.18.0.0',15],['224.0.0.0',4],['240.0.0.0',4]] as [$base,$bits]){
            $mask=(0xffffffff<<(32-$bits))&0xffffffff;if(($n&$mask)===(((int)sprintf('%u',ip2long($base)))&$mask))return false;
        }return true;
    }
    public static function redirectUrl(string $from,string $location): string {
        if(str_starts_with($location,'https://'))$out=$location;
        elseif(str_starts_with($location,'//'))$out='https:'.$location;
        elseif(str_starts_with($location,'/')&&!str_starts_with($location,'/\\'))$out='https://'.self::remoteHost($from).$location;
        else throw new InvalidArgumentException('Unsupported Google download redirect; download the file and upload from your PC');
        self::remoteHost($out);return $out;
    }
    private static function download(string $url): array {
        if(!function_exists('curl_init'))throw new RuntimeException('Google Drive import requires the PHP cURL extension with a valid TLS CA bundle. Download the file and upload it from your PC.');
        $deadline=microtime(true)+40;
        for($redirect=0;$redirect<=4;$redirect++) {
            $host=self::remoteHost($url);$ips=gethostbynamel($host)?:[];
            if(!$ips)throw new RuntimeException('Google download host could not be resolved. Download the file and upload from your PC.');
            foreach($ips as $ip)if(!self::publicIpv4($ip))throw new RuntimeException('Unsafe Google download DNS address');
            $timeout=(int)ceil($deadline-microtime(true));if($timeout<1)throw new RuntimeException('Google download timed out');
            $body='';$headers=[];$headerBytes=0;$tooLarge=false;
            $ch=curl_init($url);$opts=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>min(10,$timeout),CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_HTTPHEADER=>['Accept: application/pdf, application/vnd.openxmlformats-officedocument.wordprocessingml.document, text/plain','Accept-Encoding: identity'],
                CURLOPT_USERAGENT=>'VTA-Tour-Library/RC5.3',
                CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headers,&$headerBytes,&$tooLarge):int{
                    $headerBytes+=strlen($line);if($headerBytes>32768)return 0;
                    if(str_starts_with($line,'HTTP/'))$headers=[];
                    if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}
                    if(isset($headers['content-length'])&&(float)$headers['content-length']>self::MAX_FILE_BYTES){$tooLarge=true;return 0;}return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>self::MAX_FILE_BYTES){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}];
            curl_setopt_array($ch,$opts);$ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if($tooLarge)throw new InvalidArgumentException('Google Drive file exceeds the 10 MB import limit');
            if($ok===false)throw new RuntimeException('Google download failed. Check the PHP TLS CA bundle/network or download the file and upload from your PC.');
            if(in_array($code,[301,302,303,307,308],true)) {if($redirect===4)throw new RuntimeException('Too many Google download redirects');$url=self::redirectUrl($url,$headers['location']??'');continue;}
            if($code!==200||$body===''||str_contains(strtolower($headers['content-type']??''),'text/html')||preg_match('/^\s*(?:<!doctype\s+html|<html)/i',substr($body,0,256)))throw new InvalidArgumentException('Google could not provide a downloadable public file (private link, download disabled, quota or confirmation page). Download it with your Google account and upload from your PC.');
            return ['bytes'=>$body,'headers'=>$headers];
        }throw new RuntimeException('Google download failed');
    }
    private static function mime(string $ext): string { return match($ext){'pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','txt'=>'text/plain',default=>throw new InvalidArgumentException('Use DOCX, PDF or TXT')}; }
    public static function validateFile(string $path,string $ext): void {
        self::mime($ext);clearstatcache(true,$path);$size=filesize($path);if($size===false||$size<1||$size>self::MAX_FILE_BYTES)throw new InvalidArgumentException('File must contain data and be at most 10 MB');
        $head=file_get_contents($path,false,null,0,1024);if($head===false)throw new InvalidArgumentException('Cannot read uploaded file');
        if($ext==='pdf'&&!str_starts_with($head,'%PDF-'))throw new InvalidArgumentException('The file is not a PDF');
        if($ext==='docx') {
            if(!class_exists('ZipArchive'))throw new InvalidArgumentException('DOCX import requires PHP ZipArchive. Upload TXT or paste text instead.');
            $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('Invalid DOCX archive');$stat=$zip->statName('word/document.xml');$zip->close();
            if(!$stat||$stat['size']>4194304)throw new InvalidArgumentException('Invalid or oversized DOCX document content');
        }
        if($ext==='txt'){$s=(string)file_get_contents($path);if(str_contains($s,"\0")||!preg_match('//u',$s))throw new InvalidArgumentException('TXT must contain UTF-8 text');}
    }
    public static function previewText(string $text,string $name='',string $type='TEXT',string $url=''): array {
        $text=self::text($text,self::MAX_TEXT_BYTES,'source text');
        if($text==='')$out=['days'=>[],'included_text'=>'','excluded_text'=>'','terms_text'=>'','source_text'=>'','warnings'=>['No readable text was extracted. Keep the original file and enter the itinerary manually.'],'sections_found'=>['included_text'=>false,'excluded_text'=>false,'terms_text'=>false]];
        else {
            try{$out=ScheduleImport::parse($text);}catch(InvalidArgumentException $e){
                if(!str_contains($e->getMessage(),'No Day / Ngày headings'))throw $e;
                $out=['days'=>[],'included_text'=>'','excluded_text'=>'','terms_text'=>'','source_text'=>$text,'warnings'=>[$e->getMessage().'; keep as a draft and enter days before activating.'],'sections_found'=>['included_text'=>false,'excluded_text'=>false,'terms_text'=>false]];
            }
        }
        $first=trim(strtok($text,"\n")?:'');if(preg_match('/^(?:Day|Ngày)\s*\d/i',$first))$first='';
        $title=$first!==''?$first:pathinfo($name,PATHINFO_FILENAME);if(strlen($title)>190)$title=function_exists('mb_strcut')?mb_strcut($title,0,190,'UTF-8'):substr($title,0,190);
        if(!preg_match('//u',$title))$title='Imported tour program';
        $language=preg_match('/^\s*Ngày\s*\d/miu',$text)?'vi':'en';
        return ['title'=>$title!==''?$title:'Imported tour program','language'=>$language,'source_name'=>self::filename($name),'source_type'=>$type,'source_url'=>$url,'status'=>'DRAFT']+$out;
    }
    private static function stage(PDO $db,array $config,array $u,string $path,string $name,string $ext,string $type,string $url): string {
        $base=rtrim((string)($config['storage']['local_path']??''),'/\\');if($base==='')throw new RuntimeException('Private local storage is required to preserve tour program source files');
        $directory=$base.'/tour-library';if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new RuntimeException('Cannot create private tour library storage');
        RuntimeGuard::privatePath($directory);
        self::cleanupImports($db,$directory,$u);
        $pending=self::q($db,'SELECT COUNT(*) files,COALESCE(SUM(source_size),0) bytes FROM tour_library_imports WHERE company_id=? AND user_id=? AND consumed_program_id IS NULL',[(int)$u['company_id'],(int)$u['id']])->fetch(PDO::FETCH_ASSOC);
        if((int)$pending['files']>=50||(int)$pending['bytes']+(int)filesize($path)>209715200)throw new DomainException('Too many pending source files. Save the current import batch or wait for unused imports to expire.');
        $sha=hash_file('sha256',$path);$size=filesize($path);$stored=LocalStorage::upload(['local_path'=>$directory],$path,$name);$token=bin2hex(random_bytes(32));
        try {self::q($db,'INSERT INTO tour_library_imports(company_id,user_id,token_hash,source_name,source_type,source_url,storage_path,mime_type,source_sha256,source_size,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[(int)$u['company_id'],(int)$u['id'],hash('sha256',$token),$name,$type,$url,$stored['path'],self::mime($ext),$sha,$size,date('Y-m-d H:i:s',time()+7200)]);}
        catch(Throwable $e){if(is_file($stored['path']))unlink($stored['path']);throw $e;}
        return $token;
    }
    private static function cleanupImports(PDO $db,string $directory,array $u): void {
        $base=realpath($directory);if(!$base)throw new RuntimeException('Private source storage is unavailable');
        $rows=self::q($db,'SELECT id,storage_path FROM tour_library_imports WHERE company_id=? AND user_id=? AND consumed_program_id IS NULL AND expires_at<? LIMIT 100',[(int)$u['company_id'],(int)$u['id'],date('Y-m-d H:i:s')])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){$real=realpath($row['storage_path']);if($real&&(!RuntimeGuard::inside($real,$base)||!is_file($real)))continue;if($real&&!unlink($real))continue;self::q($db,'DELETE FROM tour_library_imports WHERE company_id=? AND user_id=? AND id=? AND consumed_program_id IS NULL',[(int)$u['company_id'],(int)$u['id'],(int)$row['id']]);}
    }
    public static function preview(PDO $db,array $config,array $u,array $b,?array $file=null): array {
        if($file!==null) {
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Upload failed. Check PHP upload_max_filesize and post_max_size (at least 11 MB).');
            $path=$file['tmp_name'];$name=self::filename(self::text($file['name']??'',255,'filename'));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$type='PC';$url='';
        }elseif(array_key_exists('source_url',$b)) {
            $link=self::driveLink(self::text($b['source_url'],2048,'Google Drive URL'));$r=self::download($link['download_url']);$name='';$cd=$r['headers']['content-disposition']??'';
            if(preg_match("/filename\*=UTF-8''([^;]+)/i",$cd,$m))$name=self::filename(rawurldecode($m[1]));
            elseif(preg_match('/filename="([^"\r\n]+)"/i',$cd,$m)||preg_match('/filename=([^;\r\n]+)/i',$cd,$m))$name=self::filename(trim($m[1],'" '));
            if($link['kind']==='DOCS')$ext='pdf';
            else {$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['docx','pdf','txt'],true))$ext=match(strtolower(explode(';',$r['headers']['content-type']??'')[0])){'application/pdf'=>'pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','text/plain'=>'txt',default=>''};}
            self::mime($ext);if($name==='')$name='Google-'.substr($link['id'],0,24).'.'.$ext;
            if(strlen($name)>255)$name='Google-'.substr($link['id'],0,24).'.'.$ext;
            $path=tempnam(sys_get_temp_dir(),'vta-tour-');if($path===false||file_put_contents($path,$r['bytes'])===false)throw new RuntimeException('Cannot create import temporary file');$type='GOOGLE_DRIVE';$url=$link['source_url'];
        }else {
            $text=self::text($b['text']??'',self::MAX_TEXT_BYTES,'source text');if($text==='')throw new InvalidArgumentException('Paste itinerary text, upload a file, or enter a Google Drive link');
            return self::previewText($text,self::text($b['source_name']??'Pasted itinerary',255,'source name'));
        }
        try {
            self::validateFile($path,$ext);$r=DocumentParser::extract($path,$ext);if(strlen($r['text'])>=250000)throw new InvalidArgumentException('Extracted text is too long; split the document before importing');
            $out=self::previewText($r['text'],$name,$type,$url);$out['warnings'][]=$r['note'];$out['extraction_quality']=$r['quality'];
            $out['import_token']=self::stage($db,$config,$u,$path,$name,$ext,$type,$url);$out['has_source']=true;return $out;
        }finally {if($type==='GOOGLE_DRIVE'&&is_file($path))unlink($path);}
    }
    /**
     * Preview a ZIP of existing B2B Word programs; each DOCX is staged with its
     * original binary, reviewed individually and then saved via the normal V5 API.
     * No prices are approved or published by this importer.
     */
    public static function previewZip(PDO $db,array $cfg,array $u,array $file):array {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))
            throw new InvalidArgumentException('Upload a ZIP file from your PC');
        if(($file['size']??0)>209715200||($file['size']??0)<20)
            throw new InvalidArgumentException('ZIP must be under 200 MB');
        if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP import requires PHP ZipArchive');
        $zip=new ZipArchive();
        if($zip->open($file['tmp_name'])!==true)throw new InvalidArgumentException('Invalid ZIP archive');
        $items=[];$seen=[];$total=0;$count=0;
        try{
            if($zip->numFiles>250)throw new InvalidArgumentException('ZIP has too many archive entries');
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);if(!$stat)continue;
                $path=(string)($stat['name']??'');
                if(str_ends_with($path,'/')||str_starts_with(basename($path),'.')||str_contains($path,'__MACOSX'))continue;
                if(str_contains($path,"\0")||str_contains($path,'\\')||str_contains($path,'../')||str_starts_with($path,'/'))
                    throw new InvalidArgumentException('Unsafe ZIP entry name');
                $name=self::filename(basename($path));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                if(!in_array($ext,['docx','pdf','txt'],true))continue;
                if(++$count>50)throw new InvalidArgumentException('ZIP contains more than 50 document files');
                if(isset($seen[strtolower($name)]))throw new InvalidArgumentException('Duplicate filename in ZIP: '.$name);
                $seen[strtolower($name)]=true;
                $size=(int)($stat['size']??0);$packed=(int)($stat['comp_size']??0);$total+=$size;
                if($size<1||$size>self::MAX_FILE_BYTES||$total>209715200||$size/max(1,$packed)>150)
                    throw new InvalidArgumentException('ZIP file limit or unsafe compression ratio: '.$name);
                $temp=tempnam(sys_get_temp_dir(),'vta-b2b-');
                if($temp===false)throw new RuntimeException('Cannot create temporary import file');
                try {
                    $stream=$zip->getStream($path);if(!$stream)throw new InvalidArgumentException('Cannot read ZIP member');
                    $output=fopen($temp,'wb');
                    if(!$output){fclose($stream);throw new RuntimeException('Cannot open import temp file');}
                    $copied=stream_copy_to_stream($stream,$output,self::MAX_FILE_BYTES+1);fclose($stream);fclose($output);
                    if($copied!==$size)throw new InvalidArgumentException('ZIP member decompression did not match expected size');
                    self::validateFile($temp,$ext);
                    $r=DocumentParser::extract($temp,$ext);
                    if(strlen($r['text'])>=250000)throw new InvalidArgumentException('Document too long');
                    $item=self::previewText($r['text'],$name,'PC','');
                    $item['warnings'][]=$r['note'];
                    $item['warnings'][]='Review tables, pictures, policies and imported historical prices before approval. Original Word source is retained.';
                    $item['extraction_quality']=$r['quality'];
                    $item['import_token']=self::stage($db,$cfg,$u,$temp,$name,$ext,'PC','');
                    $item['has_source']=true;
                    $items[]=['name'=>$name,'status'=>'READY','preview'=>$item];
                }catch(Throwable $e){
                    $items[]=['name'=>$name,'status'=>'ERROR','message'=>$e->getMessage()];
                }finally{if(is_file($temp))unlink($temp);}
            }
        }finally{$zip->close();}
        if($count===0)throw new InvalidArgumentException('ZIP contains no DOCX/PDF/TXT');
        return ['items'=>$items,'total'=>$count,'ready'=>count(array_filter($items,fn($row)=>$row['status']==='READY')),'requires_review'=>true];
    }
    private static function program(PDO $db,int $company,int $id,bool $lock=false): array {
        $r=self::q($db,'SELECT * FROM tour_library_programs WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$company,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new OutOfBoundsException('Tour program not found');return $r;
    }
    public static function publicProgram(array $r,bool $detail=true): array {
        $days=json_decode($r['days_json']??'[]',true,512,JSON_THROW_ON_ERROR);$tags=json_decode($r['tags_json']??'[]',true,512,JSON_THROW_ON_ERROR);
        $out=[];foreach(['title','destination','language','source_name','source_type','source_url','status','updated_at'] as $k)$out[$k]=$r[$k]??'';
        $out['id']=(int)$r['id'];$out['tags']=$tags;$out['day_count']=count($days);$out['has_source']=!empty($r['source_storage_path']);
        if($detail){$out['days']=$days;foreach(['included_text','excluded_text','terms_text','source_text'] as $k)$out[$k]=$r[$k]??'';$out['proposal']=json_decode($r['proposal_json']??'null',true)?:[];}
        return $out;
    }
    public static function save(PDO $db,array $u,array $b,int $id=0): array {
        $data=self::normalize($b);$company=(int)$u['company_id'];$user=(int)$u['id'];$token=$b['import_token']??'';
        if(!is_string($token)||($token!==''&&!preg_match('/^[a-f0-9]{64}$/D',$token)))throw new InvalidArgumentException('Invalid import token');
        $key=$id?'':($b['creation_key']??'');if(!is_string($key)||($key!==''&&!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key)))throw new InvalidArgumentException('Invalid creation key');
        $digest=hash('sha256',self::json($data).$token);
        $db->beginTransaction();try {
            if($key!==''){$prior=self::q($db,'SELECT * FROM tour_library_programs WHERE company_id=? AND creation_key=? FOR UPDATE',[$company,$key])->fetch(PDO::FETCH_ASSOC);if($prior){if(!hash_equals((string)$prior['creation_hash'],$digest))throw new DomainException('This creation key was already used with different program content. Refresh and review the saved program.');$db->commit();return ['id'=>(int)$prior['id'],'program'=>self::publicProgram($prior),'replayed'=>true];}}
            $before=$id?self::program($db,$company,$id,true):null;
            if($before&&$before['status']==='ARCHIVED')throw new DomainException('Archived programs cannot be edited');
            if(!array_key_exists('proposal',$data))$data['proposal']=$before?json_decode($before['proposal_json']??'null',true):[];
            $original=null;
            if($token!=='') {
                $original=self::q($db,'SELECT * FROM tour_library_imports WHERE company_id=? AND user_id=? AND token_hash=? FOR UPDATE',[$company,$user,hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
                if(!$original||strtotime($original['expires_at'])<time()||!empty($original['consumed_program_id']))throw new DomainException('The source import expired or was already saved. Upload it again.');
                if(!is_file($original['storage_path'])||!hash_equals($original['source_sha256'],hash_file('sha256',$original['storage_path'])))throw new DomainException('Original import file is unavailable or changed. Upload it again.');
                $data['source_name']=$original['source_name'];$data['source_type']=$original['source_type'];$data['source_url']=$original['source_url'];
            }elseif($before&&!empty($before['source_storage_path'])) {
                // Editing content must not make the preserved binary claim a different origin.
                foreach(['source_name','source_type','source_url'] as $k)$data[$k]=$before[$k];
            }
            $columns=['title','destination','language','tags_json','days_json','included_text','excluded_text','terms_text','proposal_json','source_text','source_name','source_type','source_url','status'];
            $values=[];foreach($columns as $k)$values[]=$k==='tags_json'?self::json($data['tags']):($k==='days_json'?self::json($data['days']):($k==='proposal_json'?self::json($data['proposal']):$data[$k]));
            if(!$id&&$key!==''){$columns[]='creation_key';$values[]=$key;$columns[]='creation_hash';$values[]=$digest;}
            if($original){foreach(['source_storage_path'=>'storage_path','source_mime'=>'mime_type','source_sha256'=>'source_sha256','source_size'=>'source_size'] as $k=>$from){$columns[]=$k;$values[]=$original[$from];}}
            if($id)self::q($db,'UPDATE tour_library_programs SET '.implode(',',array_map(fn($k)=>$k.'=?',$columns)).',updated_by=? WHERE company_id=? AND id=?',[...$values,$user,$company,$id]);
            else {self::q($db,'INSERT INTO tour_library_programs('.implode(',',$columns).',company_id,created_by,updated_by) VALUES('.implode(',',array_fill(0,count($values)+3,'?')).')',[...$values,$company,$user,$user]);$id=(int)$db->lastInsertId();}
            if($original)self::q($db,'UPDATE tour_library_imports SET consumed_program_id=? WHERE company_id=? AND user_id=? AND id=?',[$id,$company,$user,$original['id']]);
            $saved=self::publicProgram(self::program($db,$company,$id));
            Audit::log($db,$company,$user,$before?'TOUR_LIBRARY_UPDATED':'TOUR_LIBRARY_CREATED','tour_library',$id,$before?['title'=>$before['title'],'status'=>$before['status']]:null,['title'=>$data['title'],'status'=>$data['status'],'days'=>count($data['days'])]);
            $db->commit();return ['id'=>$id,'program'=>$saved];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** A destination with any program/commercial/cost content is never overwritten. */
    public static function assertEmptyDraft(array $v,bool $hasCosts=false,bool $hasOptions=false,bool $hasSent=false): void {
        if(($v['costing_engine']??'VS1')!=='VS1')throw new DomainException('Use the Smart Itinerary editor for an activated version');
        if(($v['quote_status']??'')!=='DRAFT'||($v['version_status']??'')!=='DRAFT'||$hasSent)throw new DomainException('Select an editable DRAFT quote that has not been sent');
        foreach(['schedule_json','cost_json','proposal_json'] as $k){$x=json_decode($v[$k]??'null',true,512,JSON_THROW_ON_ERROR);if($x!==null&&$x!==[]&&$x!=='')throw new DomainException('The target quote contains content. Select an empty draft to avoid overwriting it.');}
        foreach(['included_text','excluded_text','terms_text'] as $k)if(trim((string)($v[$k]??''))!=='')throw new DomainException('The target quote contains commercial terms. Select an empty draft.');
        if($hasCosts||$hasOptions||(float)($v['total_cost']??0)!==0.0||(float)($v['total_selling']??0)!==0.0)throw new DomainException('The target quote contains pricing. Select an empty draft.');
    }
    public static function copyDays(array $days,?string $start,?string $end): array {
        $days=ScheduleImport::normalize($days);if(!$days)throw new DomainException('The program has no itinerary days');
        if(!$start)throw new InvalidArgumentException('Set the draft quote travel start date first');$date=ScheduleImport::date($start);
        $last=$date->modify('+'.(count($days)-1).' days')->format('Y-m-d');
        if($end&&$last>ScheduleImport::date($end)->format('Y-m-d'))throw new DomainException('The program is longer than the target quote travel dates');
        foreach($days as $i=>&$day)$day['date']=$date->modify("+$i days")->format('Y-m-d');unset($day);return $days;
    }
    public static function copyToQuote(PDO $db,array $u,int $id,int $quote): array {
        if($quote<1)throw new InvalidArgumentException('Select a target draft quote');$company=(int)$u['company_id'];$user=(int)$u['id'];
        $db->beginTransaction();try {
            $program=self::program($db,$company,$id,true);if($program['status']==='ARCHIVED')throw new DomainException('Archived programs cannot be copied');
            $v=self::q($db,'SELECT v.*,q.company_id,q.status quote_status,q.quote_ref FROM quotes q JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=q.current_version_no WHERE q.company_id=? AND q.id=? FOR UPDATE',[$company,$quote])->fetch(PDO::FETCH_ASSOC);
            if(!$v)throw new OutOfBoundsException('Target quote not found');$version=(int)$v['id'];
            self::assertEmptyDraft($v,(bool)self::q($db,'SELECT 1 FROM quote_cost_items WHERE quote_version_id=? LIMIT 1',[$version])->fetchColumn(),(bool)self::q($db,'SELECT 1 FROM quote_options WHERE quote_version_id=? LIMIT 1',[$version])->fetchColumn(),(bool)self::q($db,'SELECT 1 FROM quote_sent_bundles WHERE quote_version_id=? LIMIT 1',[$version])->fetchColumn());
            $days=self::copyDays(json_decode($program['days_json'],true,512,JSON_THROW_ON_ERROR),$v['start_date'],$v['end_date']);
            self::q($db,'UPDATE quote_versions SET tour_name=?,schedule_json=?,included_text=?,excluded_text=?,terms_text=?,document_language=? WHERE id=?',[$program['title'],self::json($days),$program['included_text'],$program['excluded_text'],$program['terms_text'],in_array($program['language'],['en','vi'],true)?$program['language']:'en',$version]);
            $snapshot=self::publicProgram($program);$snapshot['source_sha256']=$program['source_sha256'];$snapshot['source_storage_path']=$program['source_storage_path'];$snapshot['source_mime']=$program['source_mime'];
            self::q($db,'INSERT INTO tour_library_quote_sources(company_id,quote_version_id,program_id,snapshot_json,created_by) VALUES(?,?,?,?,?)',[$company,$version,$id,self::json($snapshot),$user]);
            self::q($db,'UPDATE quotes SET updated_by=? WHERE company_id=? AND id=?',[$user,$company,$quote]);
            Audit::log($db,$company,$user,'TOUR_LIBRARY_COPIED','quote_version',$version,null,['program_id'=>$id,'quote_id'=>$quote,'days'=>count($days)]);
            $db->commit();return ['quote_id'=>$quote,'version_id'=>$version,'quote_ref'=>$v['quote_ref'],'day_count'=>count($days),'message'=>'Program and commercial terms copied. Review itinerary and price this draft for its travel dates.'];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function permission(PDO $db,array $u,bool $manage=false): void {
        foreach($manage?['tour_library.manage','product.manage','document.upload']:['tour_library.view','product.view','supplier.view'] as $p)if(Auth::can($db,(int)$u['id'],$p))return;
        Http::json(['ok'=>false,'error'=>'FORBIDDEN','message'=>'You do not have permission for the tour program library.'],403);
    }
    public static function handle(string $route,string $method,PDO $db,array $config,array $u): void {
        if($route!=='tour-library'&&!str_starts_with($route,'tour-library/'))return;
        try {
            if($route==='tour-library/preview-zip'&&$method==='POST'){self::permission($db,$u,true);Http::json(['ok'=>true]+self::previewZip($db,$config,$u,$_FILES['file']??[]));}
            if($route==='tour-library/preview'&&$method==='POST'){self::permission($db,$u,true);$b=isset($_FILES['file'])?$_POST:Http::body();Http::json(['ok'=>true]+self::preview($db,$config,$u,$b,$_FILES['file']??null));}
            if($route==='tour-library'&&$method==='GET') {
                self::permission($db,$u);$limit=filter_var($_GET['limit']??100,FILTER_VALIDATE_INT);$offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT);
                if($limit===false||$limit<1||$limit>100||$offset===false||$offset<0||$offset>1000000)throw new InvalidArgumentException('Use limit 1-100 and a nonnegative offset');
                $where='company_id=?';$args=[(int)$u['company_id']];$status=$_GET['status']??'ALL';
                if(!is_string($status)||!in_array($status,['ALL','DRAFT','ACTIVE','ARCHIVED'],true))throw new InvalidArgumentException('Invalid status filter');
                if($status!=='ALL'){$where.=' AND status=?';$args[]=$status;}
                $search=self::text($_GET['q']??'',190,'search');if($search!==''){$where.=' AND (title LIKE ? OR destination LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
                $total=(int)self::q($db,'SELECT COUNT(*) FROM tour_library_programs WHERE '.$where,$args)->fetchColumn();
                $rows=self::q($db,'SELECT * FROM tour_library_programs WHERE '.$where.' ORDER BY updated_at DESC,id DESC LIMIT '.(int)$limit.' OFFSET '.(int)$offset,$args)->fetchAll(PDO::FETCH_ASSOC);
                Http::json(['ok'=>true,'items'=>array_map(fn($r)=>self::publicProgram($r,false),$rows),'total'=>$total,'limit'=>$limit,'offset'=>$offset]);
            }
            if($route==='tour-library'&&$method==='POST'){self::permission($db,$u,true);Http::json(['ok'=>true]+self::save($db,$u,Http::body()),201);}
            if(preg_match('#^tour-library/(\d+)(?:/(archive|source|copy-to-quote))?$#D',$route,$m)) {
                $id=(int)$m[1];$action=$m[2]??'';$company=(int)$u['company_id'];
                if($action===''&&$method==='GET'){self::permission($db,$u);Http::json(['ok'=>true,'program'=>self::publicProgram(self::program($db,$company,$id))]);}
                if($action===''&&$method==='PUT'){self::permission($db,$u,true);Http::json(['ok'=>true]+self::save($db,$u,Http::body(),$id));}
                if($action==='archive'&&$method==='POST'){self::permission($db,$u,true);$before=self::program($db,$company,$id);self::q($db,"UPDATE tour_library_programs SET status='ARCHIVED',updated_by=? WHERE company_id=? AND id=?",[(int)$u['id'],$company,$id]);Audit::log($db,$company,(int)$u['id'],'TOUR_LIBRARY_ARCHIVED','tour_library',$id,['status'=>$before['status']],['status'=>'ARCHIVED']);Http::json(['ok'=>true,'id'=>$id]);}
                if($action==='source'&&$method==='GET'){self::permission($db,$u);$p=self::program($db,$company,$id);if(empty($p['source_storage_path']))throw new OutOfBoundsException('Original file is not available');Storage::stream($config,['storage_driver'=>'LOCAL','storage_path'=>$p['source_storage_path'],'mime_type'=>$p['source_mime'],'original_filename'=>$p['source_name']]);}
                if($action==='copy-to-quote'&&$method==='POST'){self::permission($db,$u);Auth::requirePermission($db,$u,'quote.edit');$b=Http::body();$quote=filter_var($b['quote_id']??null,FILTER_VALIDATE_INT);if($quote===false||$quote===null||$quote<1)throw new InvalidArgumentException('Select a target draft quote');Http::json(['ok'=>true]+self::copyToQuote($db,$u,$id,$quote));}
            }
            Http::json(['ok'=>false,'error'=>'METHOD_OR_ROUTE_NOT_SUPPORTED'],405);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND','message'=>$e->getMessage()],404);}
        catch(RuntimeException $e){Http::json(['ok'=>false,'error'=>'IMPORT_UNAVAILABLE','message'=>str_starts_with($route,'tour-library/preview')?$e->getMessage():'Tour program storage is unavailable. Check the migration and private storage configuration.'],503);}
    }
}
