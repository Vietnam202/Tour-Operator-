<?php
declare(strict_types=1);

/**
 * B2B Partner Hub. Master tour content is snapshotted from TourLibrary V5;
 * all prices are read ONLY from a locked, company-scoped QuoteProposal snapshot.
 * Partner outputs are constructed from allow-listed fields, never source_text,
 * supplier costing, rate documents, quote internals, or private notes.
 */
final class PartnerHub {
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement {
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    private static function json($v):string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function parse(string $json):array {return json_decode($json,true,512,JSON_THROW_ON_ERROR);}
    private static function field($v,int $max=190):string {
        if(!is_string($v)||strlen($v)>$max||!preg_match('//u',$v)||str_contains($v,"\0"))throw new InvalidArgumentException('Invalid text');
        return trim($v);
    }
    private static function id($v):int {
        $n=filter_var($v,FILTER_VALIDATE_INT);
        if($n===false||$n<1)throw new InvalidArgumentException('A valid ID is required');
        return $n;
    }
    private static function requireAdmin(PDO $db,array $u):void {Auth::requirePermission($db,$u,'b2b.admin');}
    private static function requirePortal(PDO $db,array $u):void {
        if(Auth::can($db,(int)$u['id'],'b2b.admin'))return;
        Auth::requirePermission($db,$u,'b2b.portal');
    }
    private static function agency(PDO $db,array $u,int $id):array {
        self::requirePortal($db,$u);
        $r=self::q($db,'SELECT * FROM b2b_agencies WHERE id=? AND company_id=? AND status=?',[$id,$u['company_id'],'ACTIVE'])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new OutOfBoundsException('Agency unavailable');
        if(!Auth::can($db,(int)$u['id'],'b2b.admin')){
            $ok=self::q($db,'SELECT 1 FROM b2b_agency_members WHERE company_id=? AND agency_id=? AND user_id=?',[$u['company_id'],$id,$u['id']])->fetchColumn();
            if(!$ok)throw new OutOfBoundsException('Agency unavailable');
        }
        return $r;
    }
    private static function safeAgency(array $a):array {
        return ['id'=>(int)$a['id'],'name'=>$a['agency_name'],'brand_name'=>$a['brand_name'],
            'brand_color'=>$a['brand_color'],'email'=>$a['email'],'whatsapp'=>$a['whatsapp'],
            'has_logo'=>!empty($a['logo_path'])];
    }
    private static function publication(PDO $db,array $u,int $id):array {
        $r=self::q($db,"SELECT * FROM b2b_publications WHERE id=? AND company_id=? AND status='PUBLISHED'",[$id,$u['company_id']])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new OutOfBoundsException('Published tour unavailable');return $r;
    }
    private static function working(PDO $db,array $u,int $id):array {
        $r=self::q($db,'SELECT * FROM b2b_partner_tours WHERE id=? AND company_id=?',[$id,$u['company_id']])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new OutOfBoundsException('Tour draft unavailable');
        self::agency($db,$u,(int)$r['agency_id']);return $r;
    }
    /** Explicit public content allow-list. Imported proposal price tables are NOT approved net prices. */
    private static function publicContent(array $p):array {
        $proposal=$p['proposal']??[];
        return [
            'title'=>$p['title']??'','destination'=>$p['destination']??'',
            'language'=>$p['language']??'en',
            'days'=>array_map(static function(array $day):array{$day['notes']='';return $day;},ScheduleImport::normalize($p['days']??[])),
            'included_text'=>$p['included_text']??'',
            'excluded_text'=>$p['excluded_text']??'',
            'terms_text'=>$p['terms_text']??'',
            'tour_code'=>self::field((string)($proposal['tour_code']??''),50),
            'tour_type'=>in_array($proposal['tour_type']??'',['PRIVATE','SIC','BOTH'],true)?$proposal['tour_type']:'PRIVATE',
            'overview'=>self::field((string)($proposal['overview']??''),30000),
            'highlights'=>array_values(array_slice(array_map(fn($s)=>self::field((string)$s,500),is_array($proposal['highlights']??null)?$proposal['highlights']:[]),0,20)),
            'hotels'=>array_values(array_slice(is_array($proposal['hotels']??null)?$proposal['hotels']:[],0,60)),
            'policies'=>array_intersect_key(is_array($proposal['policies']??null)?$proposal['policies']:[],array_flip(['children','payment','cancellation','notes']))
        ];
    }
    /** Partner edits only presentation content; cannot inject cost/supplier values. */
    public static function normalizeCopy(array $b):array {
        $out=[
            'title'=>self::field($b['title']??'',190),
            'destination'=>self::field($b['destination']??'',190),
            'language'=>self::field($b['language']??'en',16),
            'days'=>ScheduleImport::normalize($b['days']??[])
        ];
        if($out['title']===''||!$out['days'])throw new InvalidArgumentException('Tour title and days are required');
        foreach(['included_text','excluded_text','terms_text','overview'] as $k)$out[$k]=self::field($b[$k]??'',100000);
        $out['tour_code']=self::field($b['tour_code']??'',50);
        $out['tour_type']=in_array($b['tour_type']??'',['PRIVATE','SIC','BOTH'],true)?$b['tour_type']:'PRIVATE';
        $highlights=$b['highlights']??[];if(!is_array($highlights)||!array_is_list($highlights)||count($highlights)>20)throw new InvalidArgumentException('Invalid highlights');
        $out['highlights']=array_map(fn($v)=>self::field($v,500),$highlights);
        $hotels=$b['hotels']??[];if(!is_array($hotels)||!array_is_list($hotels)||count($hotels)>60)throw new InvalidArgumentException('Invalid hotels');
        $out['hotels']=[];foreach($hotels as $h){if(!is_array($h))throw new InvalidArgumentException('Invalid hotel row');$row=[];foreach(['destination','three','four','five'] as $k)$row[$k]=self::field($h[$k]??'',400);$out['hotels'][]=$row;}
        $policies=$b['policies']??[];if(!is_array($policies))throw new InvalidArgumentException('Invalid policies');
        $out['policies']=[];foreach(['children','payment','cancellation','notes'] as $k)$out['policies'][$k]=self::field($policies[$k]??'',30000);
        if(strlen(self::json($out))>1000000)throw new InvalidArgumentException('Tour too large');
        return $out;
    }
    private static function approvedRates(PDO $db,array $u,int $version):array {
        $v=self::q($db,'SELECT v.id,v.version_status FROM quote_versions v JOIN quotes q ON q.id=v.quote_id WHERE v.id=? AND q.company_id=?',[$version,$u['company_id']])->fetch(PDO::FETCH_ASSOC);
        if(!$v||!in_array($v['version_status'],['SENT','CONFIRMED'],true))throw new DomainException('Select a SENT/CONFIRMED quotation in this company');
        $source=QuoteOptions::version($db,(int)$u['company_id'],$version);
        $bundle=QuoteProposal::publicQuote($db,$source);
        $commercial=$bundle['commercial']??[];
        $valid=self::field((string)($commercial['valid_until']??''),10);
        if(!preg_match('/^20\d\d-\d\d-\d\d$/D',$valid)||$valid<date('Y-m-d'))throw new DomainException('Published NET prices require valid future expiry');
        $rows=[];
        foreach($commercial['cells']??[] as $cell){
            $net=$cell['net_per_pax']??null;
            if(!is_numeric($net)||(float)$net<=0)continue;
            $currency=strtoupper((string)($cell['currency']??''));
            if(!in_array($currency,['USD','VND'],true))continue;
            $min=(int)($cell['pax_min']??0);$max=(int)($cell['pax_max']??0);
            if($min<1||$max<$min||$max>1000)continue;
            $hotel=preg_replace('/[^345]/','',(string)($cell['hotel_level']??''));
            $cruise=preg_replace('/[^345]/','',(string)($cell['cruise_level']??''));
            $mode=strtoupper((string)($cell['mode']??''));
            if(!in_array($mode,['PRIVATE','SIC','GROUP'],true)||!in_array($hotel,['3','4','5'],true))continue;
            $rows[]=['mode'=>$mode==='GROUP'?'SIC':$mode,'pax_min'=>$min,'pax_max'=>$max,
                'hotel'=>$hotel,'cruise'=>in_array($cruise,['3','4','5'],true)?$cruise:'',
                'currency'=>$currency,'net_per_pax'=>(float)$net];
        }
        if(!$rows)throw new DomainException('Quotation has no approved NET cells. Publish itinerary without rates instead');
        return ['valid_until'=>$valid,'source_quote_version_id'=>$version,'cells'=>$rows];
    }
    private static function quote(array $work,array $pub,array $params):array {
        $rates=self::parse($pub['rate_json']);$cells=$rates['cells']??[];
        $pax=self::id($params['pax']??0);$mode=strtoupper(self::field($params['mode']??'',10));
        $hotel=self::field((string)($params['hotel']??''),1);$cruise=self::field((string)($params['cruise']??''),1);
        if($pax>1000||!in_array($mode,['PRIVATE','SIC'],true)||!in_array($hotel,['3','4','5'],true)||($cruise!==''&&!in_array($cruise,['3','4','5'],true)))throw new InvalidArgumentException('Select valid PAX, mode, hotel and cruise');
        $expires=$rates['valid_until']??'';
        if($pub['status']!=='PUBLISHED'||!$expires||$expires<date('Y-m-d'))return ['status'=>'REQUEST_NET','message'=>'No currently approved NET price'];
        $found=[];foreach($cells as $r)if($r['mode']===$mode&&$r['hotel']===$hotel&&($r['cruise']??'')===$cruise&&$pax>=$r['pax_min']&&$pax<=$r['pax_max'])$found[]=$r;
        if(count($found)!==1)return ['status'=>'REQUEST_NET','message'=>'No unique approved rate for this configuration'];
        $net=(float)$found[0]['net_per_pax'];$markup=(float)$work['markup_value'];
        $sale=$work['markup_type']==='FIXED'?$net+$markup:$net*(1+$markup/100);
        return ['status'=>'QUOTABLE','pax'=>$pax,'mode'=>$mode,'hotel'=>$hotel,'cruise'=>$cruise,
            'currency'=>$found[0]['currency'],'net_per_pax'=>round($net,2),'selling_per_pax'=>round($sale,2),
            'selling_total'=>round($sale*$pax,2),'markup_type'=>$work['markup_type'],'markup_value'=>$markup,
            'valid_until'=>$expires,'publication_version'=>(int)$pub['version_no']];
    }
    private static function output(array $work,array $agency,array $pub,array $params,string $audience):array {
        $data=self::parse($work['content_json']);$q=$audience==='itinerary'?['status'=>'ITINERARY_ONLY']:self::quote($work,$pub,$params);
        if($audience!=='itinerary'&&$q['status']!=='QUOTABLE')throw new DomainException('No approved NET price for this configuration; export itinerary only');
        $blocks=[['type'=>'brand','text'=>$agency['brand_name']],['type'=>'title','text'=>$data['title']],
            ['type'=>'text','text'=>trim($data['destination'].' · '.$data['tour_code'])],
            ['type'=>'heading','text'=>'Tour Overview'],['type'=>'text','text'=>$data['overview']]];
        if(!empty($agency['logo_path'])&&is_file($agency['logo_path'])) {
            $dimensions=@getimagesize($agency['logo_path']);
            if($dimensions&&hash_equals((string)$agency['logo_sha256'],hash_file('sha256',$agency['logo_path'])))
                array_splice($blocks,1,0,[['type'=>'image','asset_id'=>1,'caption'=>'','width'=>$dimensions[0],'height'=>$dimensions[1],'width_pct'=>25]]);
        }
        if($data['highlights']){$blocks[]=['type'=>'heading','text'=>'Highlights'];foreach($data['highlights'] as $h)$blocks[]=['type'=>'text','text'=>'• '.$h];}
        $blocks[]=['type'=>'heading','text'=>'Detailed Itinerary'];
        foreach($data['days'] as $i=>$day){
            $blocks[]=['type'=>'heading','text'=>'Day '.($i+1).' – '.$day['title']];
            $blocks[]=['type'=>'text','text'=>$day['description']];
            $blocks[]=['type'=>'text','text'=>'Meals: '.$day['meals'].($day['overnight']?' · Overnight: '.$day['overnight']:'')];
        }
        if($data['hotels']){$rows=[];foreach($data['hotels'] as $h)$rows[]=[$h['destination'],$h['three'],$h['four'],$h['five']];$blocks[]=['type'=>'heading','text'=>'Hotels & Cruise Options'];$blocks[]=['type'=>'table','headers'=>['Destination','3 Star','4 Star','5 Star'],'rows'=>$rows];}
        if($audience!=='itinerary'){
            $blocks[]=['type'=>'heading','text'=>$audience==='agency'?'Agency NET Working Copy':'Client Price Offer'];
            $rows=[['Configuration',$q['mode'].' · '.$q['pax'].' pax · Hotel '.$q['hotel'].'★ · Cruise '.($q['cruise']?:'N/A')],
                ['Validity',$q['valid_until']],
                ['Selling / pax',$q['currency'].' '.number_format($q['selling_per_pax'],2)],
                ['Total selling',$q['currency'].' '.number_format($q['selling_total'],2)]];
            if($audience==='agency')$rows[]=['VTA NET / pax',$q['currency'].' '.number_format($q['net_per_pax'],2)];
            $blocks[]=['type'=>'table','headers'=>['Item','Value'],'rows'=>$rows];
        }
        foreach(['included_text'=>'Included','excluded_text'=>'Excluded','terms_text'=>'Terms & Conditions'] as $key=>$heading)if($data[$key]!==''){$blocks[]=['type'=>'heading','text'=>$heading];$blocks[]=['type'=>'text','text'=>$data[$key]];}
        foreach(['children'=>'Children Policy','payment'=>'Payment Terms','cancellation'=>'Cancellation Policy','notes'=>'Notes'] as $key=>$heading)if(!empty($data['policies'][$key])){$blocks[]=['type'=>'heading','text'=>$heading];$blocks[]=['type'=>'text','text'=>$data['policies'][$key]];}
        $blocks[]=['type'=>'text','text'=>'Contact: '.$agency['email'].' · '.$agency['whatsapp']];
        return ['tour_name'=>$data['title'],'quote_ref'=>'B2B-'.$work['id'],
            'presentation'=>['settings'=>['template'=>'VTA_B2B_WHITE_LABEL','brand_name'=>$agency['brand_name'],'brand_color'=>$agency['brand_color']],'blocks'=>$blocks]];
    }
    public static function handle(string $route,string $method,PDO $db,array $cfg,array $u):void {
        if($route!=='b2b'&&!str_starts_with($route,'b2b/'))return;
        try {
            self::requirePortal($db,$u);$company=(int)$u['company_id'];$user=(int)$u['id'];
            $admin=Auth::can($db,$user,'b2b.admin');
            if($route==='b2b/me'&&$method==='GET'){
                $agencies=$admin?self::q($db,"SELECT * FROM b2b_agencies WHERE company_id=? AND status='ACTIVE' ORDER BY agency_name",[$company])->fetchAll(PDO::FETCH_ASSOC):
                self::q($db,"SELECT a.* FROM b2b_agencies a JOIN b2b_agency_members m ON m.agency_id=a.id AND m.company_id=a.company_id WHERE a.company_id=? AND m.user_id=? AND a.status='ACTIVE' ORDER BY a.agency_name",[$company,$user])->fetchAll(PDO::FETCH_ASSOC);
                Http::json(['ok'=>true,'admin'=>$admin,'agencies'=>array_map([self::class,'safeAgency'],$agencies)]);
            }
            if(preg_match('#^b2b/agencies/(\\d+)/logo$#D',$route,$lm)){
                $agency=self::agency($db,$u,(int)$lm[1]);
                if($method==='GET'){
                    $file=(string)($agency['logo_path']??'');
                    if(!$file||!is_file($file)||!hash_equals((string)$agency['logo_sha256'],hash_file('sha256',$file)))throw new OutOfBoundsException('Logo unavailable');
                    header('Content-Type: image/jpeg');header('Content-Disposition: inline; filename="agency-logo.jpg"');
                    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($file);exit;
                }
                if($method==='POST'){
                    $file=$_FILES['file']??[];
                    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>2097152)throw new InvalidArgumentException('Upload PNG or JPEG up to 2 MB');
                    $info=@getimagesize($file['tmp_name']);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)||$info[0]>4000||$info[1]>4000||$info[0]<1||$info[1]<1)throw new InvalidArgumentException('Unsupported logo');
                    if(!function_exists('imagecreatetruecolor'))throw new DomainException('Logo processing requires GD');
                    $src=$info[2]===IMAGETYPE_PNG?@imagecreatefrompng($file['tmp_name']):@imagecreatefromjpeg($file['tmp_name']);
                    if(!$src)throw new InvalidArgumentException('Invalid logo pixels');
                    $scale=min(1,800/max($info[0],$info[1]));$width=max(1,(int)round($info[0]*$scale));$height=max(1,(int)round($info[1]*$scale));
                    $dst=imagecreatetruecolor($width,$height);$white=imagecolorallocate($dst,255,255,255);imagefilledrectangle($dst,0,0,$width,$height,$white);imagealphablending($dst,true);
                    imagecopyresampled($dst,$src,0,0,0,0,$width,$height,$info[0],$info[1]);imagedestroy($src);
                    $base=rtrim((string)($cfg['storage']['local_path']??''),'/\\\\');if(!$base)throw new DomainException('Private storage unavailable');
                    $dir=$base.'/b2b-agency-logos';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new DomainException('Private logo directory unavailable');RuntimeGuard::privatePath($dir);
                    $temp=tempnam($dir,'logo-');if(!$temp){imagedestroy($dst);throw new DomainException('Cannot store agency logo');}
                    $ok=imagejpeg($dst,$temp,92);imagedestroy($dst);
                    if(!$ok){@unlink($temp);throw new DomainException('Cannot process agency logo');}
                    $hash=hash_file('sha256',$temp);$path=$dir.'/agency-'.$agency['id'].'-'.$hash.'.jpg';
                    if(!rename($temp,$path)){@unlink($temp);throw new DomainException('Cannot write logo');}
                    @chmod($path,0640);
                    self::q($db,'UPDATE b2b_agencies SET logo_path=?,logo_mime=?,logo_sha256=? WHERE company_id=? AND id=?',[$path,'image/jpeg',$hash,$company,$agency['id']]);
                    Audit::log($db,$company,$user,'B2B_LOGO_UPDATED','b2b_agency',(int)$agency['id']);Http::json(['ok'=>true,'sha256'=>$hash]);
                }
            }
            if($route==='b2b/admin/agencies'){
                self::requireAdmin($db,$u);
                if($method==='GET')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT id,agency_name,brand_name,brand_color,email,whatsapp,status FROM b2b_agencies WHERE company_id=? ORDER BY id DESC',[$company])->fetchAll(PDO::FETCH_ASSOC)]);
                if($method==='POST'){
                    $b=Http::body();$name=self::field($b['agency_name']??'',190);if(!$name)throw new InvalidArgumentException('Agency name required');
                    self::q($db,'INSERT INTO b2b_agencies(company_id,agency_name,brand_name) VALUES(?,?,?)',[$company,$name,$name]);$id=(int)$db->lastInsertId();
                    Audit::log($db,$company,$user,'B2B_AGENCY_CREATED','b2b_agency',$id,null,['name'=>$name]);Http::json(['ok'=>true,'id'=>$id],201);
                }
            }
            if(preg_match('#^b2b/admin/agencies/(\d+)/members$#D',$route,$m)&&$method==='POST'){
                self::requireAdmin($db,$u);$agency=self::agency($db,$u,(int)$m[1]);$id=self::id(Http::body()['user_id']??0);
                $target=self::q($db,'SELECT u.id,r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.company_id=? AND u.status=?',[$id,$company,'ACTIVE'])->fetch(PDO::FETCH_ASSOC);
                if(!$target||$target['code']!=='PARTNER'||!Auth::can($db,$id,'b2b.portal'))throw new DomainException('Assign an existing ACTIVE user with the PARTNER role and b2b.portal permission');
                self::q($db,'INSERT IGNORE INTO b2b_agency_members(company_id,agency_id,user_id) VALUES(?,?,?)',[$company,$agency['id'],$id]);
                Audit::log($db,$company,$user,'B2B_MEMBER_ASSIGNED','b2b_agency',(int)$agency['id'],null,['user_id'=>$id]);Http::json(['ok'=>true]);
            }
            if(preg_match('#^b2b/agencies/(\d+)/branding$#D',$route,$m)&&$method==='PUT'){
                $a=self::agency($db,$u,(int)$m[1]);$b=Http::body();
                $brand=self::field($b['brand_name']??$a['brand_name'],190);$email=self::field($b['email']??$a['email'],190);$whatsapp=self::field($b['whatsapp']??$a['whatsapp'],64);
                $color=self::field($b['brand_color']??$a['brand_color'],7);
                if(!$brand||!preg_match('/^#[0-9a-fA-F]{6}$/D',$color)||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('Invalid agency branding');
                self::q($db,'UPDATE b2b_agencies SET brand_name=?,brand_color=?,email=?,whatsapp=? WHERE id=? AND company_id=?',[$brand,$color,$email,$whatsapp,$a['id'],$company]);
                Http::json(['ok'=>true]);
            }
            if($route==='b2b/admin/publications'&&$method==='GET'){
                self::requireAdmin($db,$u);Http::json(['ok'=>true,'items'=>self::q($db,"SELECT p.id,p.program_id,p.version_no,p.status,p.created_at,t.title FROM b2b_publications p JOIN tour_library_programs t ON t.id=p.program_id AND t.company_id=p.company_id WHERE p.company_id=? ORDER BY p.id DESC LIMIT 200",[$company])->fetchAll(PDO::FETCH_ASSOC)]);
            }
            if($route==='b2b/admin/publish'&&$method==='POST'){
                self::requireAdmin($db,$u);$b=Http::body();$programId=self::id($b['program_id']??0);
                $master=self::q($db,'SELECT * FROM tour_library_programs WHERE company_id=? AND id=?',[$company,$programId])->fetch(PDO::FETCH_ASSOC);
                if(!$master||$master['status']!=='ACTIVE')throw new DomainException('Approve and activate the Master Tour in V5 first');
                $program=TourLibrary::publicProgram($master,true);$snapshot=self::normalizeCopy(self::publicContent($program));
                $sourceId=($b['source_quote_version_id']??null);$rates=['cells'=>[]];$source=null;
                if($sourceId!==null&&$sourceId!==''){$source=self::id($sourceId);$rates=self::approvedRates($db,$u,$source);}
                $db->beginTransaction();
                try {
                    $next=(int)self::q($db,'SELECT COALESCE(MAX(version_no),0)+1 FROM b2b_publications WHERE company_id=? AND program_id=? FOR UPDATE',[$company,$programId])->fetchColumn();
                    self::q($db,'INSERT INTO b2b_publications(company_id,program_id,version_no,content_json,rate_json,source_quote_version_id,created_by) VALUES(?,?,?,?,?,?,?)',[$company,$programId,$next,self::json($snapshot),self::json($rates),$source,$user]);
                    $id=(int)$db->lastInsertId();
                    Audit::log($db,$company,$user,'B2B_TOUR_PUBLISHED','b2b_publication',$id,null,['program_id'=>$programId,'version'=>$next,'approved_rate_source'=>$source]);
                    $db->commit();Http::json(['ok'=>true,'id'=>$id,'version'=>$next],201);
                }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            }
            if(preg_match('#^b2b/admin/publications/(\d+)/revoke$#D',$route,$m)&&$method==='POST'){
                self::requireAdmin($db,$u);self::q($db,"UPDATE b2b_publications SET status='REVOKED' WHERE company_id=? AND id=?",[$company,$m[1]]);
                Audit::log($db,$company,$user,'B2B_PUBLICATION_REVOKED','b2b_publication',(int)$m[1]);Http::json(['ok'=>true]);
            }
            if($route==='b2b/tours'&&$method==='GET'){
                $rows=self::q($db,"SELECT p.id,p.program_id,p.version_no,p.content_json,p.rate_json,p.created_at FROM b2b_publications p JOIN (SELECT program_id,MAX(version_no) version_no FROM b2b_publications WHERE company_id=? GROUP BY program_id) latest ON latest.program_id=p.program_id AND latest.version_no=p.version_no WHERE p.company_id=? AND p.status='PUBLISHED' ORDER BY p.created_at DESC LIMIT 200",[$company,$company])->fetchAll(PDO::FETCH_ASSOC);
                $items=[];foreach($rows as $r){$d=self::parse($r['content_json']);$rates=self::parse($r['rate_json']);$items[]=['id'=>(int)$r['id'],'program_id'=>(int)$r['program_id'],'version'=>(int)$r['version_no'],'title'=>$d['title'],'destination'=>$d['destination'],'days'=>count($d['days']),'tour_type'=>$d['tour_type'],'has_approved_net'=>!empty($rates['cells'])&&($rates['valid_until']??'')>=date('Y-m-d')];}
                Http::json(['ok'=>true,'items'=>$items]);
            }
            if(preg_match('#^b2b/tours/(\d+)$#D',$route,$m)&&$method==='GET'){
                $p=self::publication($db,$u,(int)$m[1]);$data=self::parse($p['content_json']);Http::json(['ok'=>true,'publication'=>['id'=>(int)$p['id'],'version'=>(int)$p['version_no'],'content'=>$data,'has_rates'=>!empty(self::parse($p['rate_json'])['cells'])]]);
            }
            if(preg_match('#^b2b/tours/(\d+)/copy$#D',$route,$m)&&$method==='POST'){
                $pub=self::publication($db,$u,(int)$m[1]);$agency=self::agency($db,$u,self::id(Http::body()['agency_id']??0));
                self::q($db,'INSERT INTO b2b_partner_tours(company_id,agency_id,publication_id,content_json,created_by) VALUES(?,?,?,?,?)',[$company,$agency['id'],$pub['id'],$pub['content_json'],$user]);
                $id=(int)$db->lastInsertId();Audit::log($db,$company,$user,'B2B_TOUR_COPIED','b2b_partner_tour',$id,null,['publication_id'=>(int)$pub['id'],'agency_id'=>(int)$agency['id']]);
                Http::json(['ok'=>true,'id'=>$id],201);
            }
            if($route==='b2b/my-tours'&&$method==='GET'){
                $allowed=$admin?self::q($db,'SELECT w.*,p.version_no,p.status publication_status,a.brand_name FROM b2b_partner_tours w JOIN b2b_publications p ON p.id=w.publication_id AND p.company_id=w.company_id JOIN b2b_agencies a ON a.id=w.agency_id AND a.company_id=w.company_id WHERE w.company_id=? ORDER BY w.updated_at DESC LIMIT 200',[$company]):
                self::q($db,'SELECT w.*,p.version_no,p.status publication_status,a.brand_name FROM b2b_partner_tours w JOIN b2b_publications p ON p.id=w.publication_id AND p.company_id=w.company_id JOIN b2b_agencies a ON a.id=w.agency_id AND a.company_id=w.company_id JOIN b2b_agency_members m ON m.agency_id=w.agency_id AND m.company_id=w.company_id AND m.user_id=? WHERE w.company_id=? ORDER BY w.updated_at DESC LIMIT 200',[$user,$company]);
                $rows=[];foreach($allowed->fetchAll(PDO::FETCH_ASSOC) as $r){$d=self::parse($r['content_json']);$rows[]=['id'=>(int)$r['id'],'title'=>$d['title'],'agency'=>$r['brand_name'],'agency_id'=>(int)$r['agency_id'],'publication_version'=>(int)$r['version_no'],'publication_status'=>$r['publication_status'],'revision'=>(int)$r['revision']];}
                Http::json(['ok'=>true,'items'=>$rows]);
            }
            if(preg_match('#^b2b/my-tours/(\d+)(?:/(.*))?$#D',$route,$m)){
                $w=self::working($db,$u,(int)$m[1]);$action=$m[2]??'';
                $agency=self::agency($db,$u,(int)$w['agency_id']);
                $pub=self::q($db,'SELECT * FROM b2b_publications WHERE id=? AND company_id=?',[$w['publication_id'],$company])->fetch(PDO::FETCH_ASSOC);
                if(!$pub)throw new OutOfBoundsException('Publication unavailable');
                if($action===''&&$method==='GET'){
                    Http::json(['ok'=>true,'tour'=>['id'=>(int)$w['id'],'content'=>self::parse($w['content_json']),'agency'=>self::safeAgency($agency),'revision'=>(int)$w['revision'],'markup_type'=>$w['markup_type'],'markup_value'=>(float)$w['markup_value'],'publication_status'=>$pub['status']]]);
                }
                if($action===''&&$method==='PUT'){
                    if($pub['status']!=='PUBLISHED')throw new DomainException('Publication revoked; create a new copy from an active tour');
                    $b=Http::body();$rev=self::id($b['revision']??0);
                    $copy=self::normalizeCopy($b['content']??[]);
                    $type=self::field($b['markup_type']??'',10);$markup=$b['markup_value']??null;
                    if(!in_array($type,['PERCENT','FIXED'],true)||!is_numeric($markup)||$markup<0||$markup>100000||($type==='PERCENT'&&$markup>200))throw new InvalidArgumentException('Invalid markup');
                    $s=self::q($db,'UPDATE b2b_partner_tours SET content_json=?,markup_type=?,markup_value=?,revision=revision+1 WHERE id=? AND company_id=? AND agency_id=? AND revision=?', [self::json($copy),$type,$markup,$w['id'],$company,$agency['id'],$rev]);
                    if(!$s->rowCount())throw new DomainException('This tour changed in another tab; reload before saving');
                    Http::json(['ok'=>true,'revision'=>$rev+1]);
                }
                if($action==='quote'&&$method==='GET')Http::json(['ok'=>true,'quote'=>self::quote($w,$pub,$_GET)]);
                if(preg_match('#^export/(docx|pdf|html)$#D',$action,$fm)&&$method==='GET'){
                    $audience=self::field($_GET['audience']??'itinerary',12);
                    if(!in_array($audience,['itinerary','agency','client'],true))throw new InvalidArgumentException('Invalid output mode');
                    $s=self::output($w,$agency,$pub,$_GET,$audience);
                    // Existing VTA serializer produces real editable OOXML and paginated Unicode PDF.
                    ProposalOutput::respond($fm[1],$s,$cfg,
                        fn($id)=>'index.php?route=b2b/agencies/'.$agency['id'].'/logo',
                        fn($id)=>(int)$id===1&&(is_file((string)$agency['logo_path']))&&hash_equals((string)$agency['logo_sha256'],hash_file('sha256',$agency['logo_path']))?$agency['logo_path']:throw new OutOfBoundsException('Logo unavailable'),false);
                }
                if($action==='booking-request'&&$method==='POST'){
                    if($pub['status']!=='PUBLISHED')throw new DomainException('This tour is no longer published');
                    $b=Http::body();$date=self::field($b['departure_date']??'',10);
                    if(!preg_match('/^20\d\d-\d\d-\d\d$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))||$date<date('Y-m-d'))throw new InvalidArgumentException('Valid future departure date required');
                    $q=self::quote($w,$pub,$b);if($q['status']!=='QUOTABLE')throw new DomainException('VTA must approve the NET price before a booking request');if($date>$q['valid_until'])throw new DomainException('Departure is outside the approved NET validity');
                    $name=self::field($b['guest_name']??'',190);$email=self::field($b['guest_email']??'',190);$notes=self::field($b['notes']??'',10000);
                    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid guest email');
                    // Fixed immutable commercial snapshot for Operations review; not a confirmed booking.
                    $snapshot=['tour'=>self::parse($w['content_json']),'brand'=>self::safeAgency($agency),'quote'=>$q,'departure_date'=>$date];
                    self::q($db,'INSERT INTO b2b_booking_requests(company_id,agency_id,partner_tour_id,departure_date,paying_pax,guest_name,guest_email,notes,client_snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[$company,$agency['id'],$w['id'],$date,$q['pax'],$name,$email,$notes,self::json($snapshot),$user]);
                    $id=(int)$db->lastInsertId();Audit::log($db,$company,$user,'B2B_BOOKING_REQUESTED','b2b_booking_request',$id,null,['tour_id'=>(int)$w['id']]);
                    Http::json(['ok'=>true,'id'=>$id,'status'=>'REQUESTED','message'=>'Awaiting VTA Operations confirmation'],201);
                }
            }
            if($route==='b2b/admin/requests'&&$method==='GET'){
                self::requireAdmin($db,$u);Http::json(['ok'=>true,'items'=>self::q($db,'SELECT r.id,r.agency_id,a.agency_name,r.partner_tour_id,r.departure_date,r.paying_pax,r.guest_name,r.status,r.created_at FROM b2b_booking_requests r JOIN b2b_agencies a ON a.company_id=r.company_id AND a.id=r.agency_id WHERE r.company_id=? ORDER BY r.id DESC LIMIT 200',[$company])->fetchAll(PDO::FETCH_ASSOC)]);
            }
            Http::json(['ok'=>false,'error'=>'METHOD_OR_ROUTE_NOT_SUPPORTED'],405);
        }catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND','message'=>$e->getMessage()],404);}
    }
}
