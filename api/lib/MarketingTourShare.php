<?php
declare(strict_types=1);

/**
 * P4: VTA-native, time-limited customer-safe tour outline links.
 * Links contain ONLY reviewed title/destination/day titles. Never sends source files,
 * commercial proposals, rates, supplier costs, or personal customer information.
 */
final class MarketingTourShare {
    private static function q(PDO $db,string $sql,array $values=[]):PDOStatement {
        $s=$db->prepare($sql);$s->execute($values);return $s;
    }
    private static function positive($value,string $name):int {
        $v=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($v===false||$v===null)throw new InvalidArgumentException('Invalid '.$name);
        return (int)$v;
    }
    private static function requestKey($value):string {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$value)!==1)
            throw new InvalidArgumentException('Invalid request_key');
        return $value;
    }
    private static function esc(string $s):string {
        return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false);
    }
    private static function program(PDO $db,int $company,int $id,bool $lock=false):array {
        $p=self::q($db,'SELECT id,title,destination,language,status,tags_json,days_json,marketing_share_approved_hash FROM tour_library_programs WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$company,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$p)throw new OutOfBoundsException('Program not found');
        return $p;
    }
    public static function safeSnapshot(array $p):array {
        $hash=MarketingTourAdvisor::snapshotHash($p);
        $approved=(string)($p['marketing_share_approved_hash']??'');
        if(($p['status']??'')!=='ACTIVE'||strlen($approved)!==64||!hash_equals($approved,$hash))
            throw new DomainException('Tour is not currently approved for marketing sharing');
        $days=json_decode((string)$p['days_json'],true,256,JSON_THROW_ON_ERROR);
        if(!is_array($days)||!array_is_list($days)||count($days)<1||count($days)>90)
            throw new DomainException('Invalid program days');
        // Use short metadata only. Source text and detailed day blocks are NOT approved by P3.
        $titles=[];
        foreach($days as $day) {
            if(!is_array($day)||!is_string($day['title']??null)||strlen($day['title'])>350)
                throw new DomainException('Invalid itinerary day title');
            $titles[]=$day['title'];
        }
        foreach(['title','destination','language'] as $key)
            if(!is_string($p[$key]??null))throw new DomainException('Invalid itinerary');
        return [
            'schema'=>'VTA_PUBLIC_TOUR_OUTLINE_V1',
            'title'=>$p['title'],'destination'=>$p['destination'],
            'language'=>in_array($p['language'],['vi','en'],true)?$p['language']:'en',
            'day_titles'=>$titles
        ];
    }
    public static function create(PDO $db,array $user,array $body):array {
        $company=(int)$user['company_id'];
        $conversation=self::positive($body['conversation_id']??null,'conversation_id');
        $program=self::positive($body['program_id']??null,'program_id');
        $key=self::requestKey($body['request_key']??null);
        $days=filter_var($body['expires_in_days']??7,FILTER_VALIDATE_INT);
        if($days===false||$days<1||$days>14)throw new InvalidArgumentException('Expiration must be between 1 and 14 days');
        $db->beginTransaction();
        try{
            $conv=self::q($db,"SELECT id,status FROM social_conversations WHERE company_id=? AND id=? FOR UPDATE",[$company,$conversation])->fetch(PDO::FETCH_ASSOC);
            if(!$conv)throw new OutOfBoundsException('Conversation not found');
            if($conv['status']==='CLOSED')throw new DomainException('Cannot create link for closed conversation');
            $previous=self::q($db,'SELECT id,program_id FROM marketing_tour_shares WHERE company_id=? AND conversation_id=? AND request_key=? FOR UPDATE',[
                $company,$conversation,$key])->fetch(PDO::FETCH_ASSOC);
            if($previous) {
                if((int)$previous['program_id']!==$program)throw new DomainException('Request key used with another tour');
                $db->commit();
                // Raw bearer tokens are not stored or returned on retries.
                return ['id'=>(int)$previous['id'],'replayed'=>true,'token'=>null,'requires_new_link'=>true];
            }
            $p=self::program($db,$company,$program,true);
            $snapshot=self::safeSnapshot($p);
            $hash=MarketingTourAdvisor::snapshotHash($p);
            $token=bin2hex(random_bytes(32));
            self::q($db,"INSERT INTO marketing_tour_shares(
                company_id,conversation_id,program_id,request_key,token_hash,
                program_snapshot_hash,snapshot_json,expires_at,created_by
                ) VALUES(?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL $days DAY),?)",[
                $company,$conversation,$program,$key,hash('sha256',$token),
                $hash,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),(int)$user['id']
            ]);
            $id=(int)$db->lastInsertId();
            Audit::log($db,$company,(int)$user['id'],'MARKETING_TOUR_SHARE_CREATED','marketing_tour_share',$id,null,[
                'program_id'=>$program,'conversation_id'=>$conversation,'days'=>$days]);
            $db->commit();
            return ['id'=>$id,'replayed'=>false,'token'=>$token,'expires_in_days'=>$days];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function view(PDO $db,string $token):array {
        if(preg_match('/^[a-f0-9]{64}$/D',$token)!==1)throw new OutOfBoundsException('Not found');
        $row=self::q($db,"SELECT s.id,s.company_id,s.program_id,s.snapshot_json,s.program_snapshot_hash,s.revoked_at,s.expires_at,
              p.id program_found,p.title,p.destination,p.language,p.status,p.tags_json,p.days_json,p.marketing_share_approved_hash
              FROM marketing_tour_shares s JOIN tour_library_programs p
              ON p.company_id=s.company_id AND p.id=s.program_id
              WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>NOW()
              LIMIT 1",[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Link not found or expired');
        // Invalidates public link immediately when the marketing approval becomes stale,
        // even if a staff member had already created the share earlier.
        try{$now=MarketingTourAdvisor::snapshotHash($row);}
        catch(Throwable $e){throw new OutOfBoundsException('Program unavailable');}
        if($row['status']!=='ACTIVE'||!hash_equals((string)($row['marketing_share_approved_hash']??''),$now)
            ||!hash_equals((string)$row['program_snapshot_hash'],$now))
            throw new OutOfBoundsException('Link no longer approved');
        $saved=json_decode((string)$row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($saved)||($saved['schema']??'')!=='VTA_PUBLIC_TOUR_OUTLINE_V1'
           ||!is_array($saved['day_titles']??null)||!array_is_list($saved['day_titles']))
            throw new OutOfBoundsException('Invalid share snapshot');
        self::q($db,'UPDATE marketing_tour_shares SET view_count=LEAST(view_count+1,4294967295),last_opened_at=NOW() WHERE id=? AND company_id=?',[
            $row['id'],$row['company_id']]);
        return $saved;
    }
    public static function html(array $snapshot):string {
        $lang=$snapshot['language']==='vi'?'vi':'en';
        $vi=$lang==='vi';
        $title=self::esc((string)$snapshot['title']);
        $destination=self::esc((string)$snapshot['destination']);
        $days='';
        foreach($snapshot['day_titles'] as $i=>$text){
            if(!is_string($text))continue;
            $num=$vi?'Ngày ':'Day ';
            $days.='<li><b>'.$num.($i+1).'</b> — '.self::esc($text).'</li>';
        }
        $disclaimer=$vi
            ?'Chương trình tham khảo. Giá bán, dịch vụ, hạng khách sạn và du thuyền, tình trạng còn chỗ cần nhân viên xác nhận.'
            :'Sample itinerary only. Prices, availability, hotel and cruise categories require confirmation by our travel team.';
        $label=$vi?'Điểm đến':'Destinations';
        $detail=$vi?'Lịch trình tóm tắt':'Itinerary outline';
        $css='body{font:16px/1.6 system-ui,sans-serif;color:#183046;max-width:760px;margin:32px auto;padding:0 20px}header{border-bottom:2px solid #173b63;padding-bottom:18px}small{letter-spacing:.12em;text-transform:uppercase;color:#5a7083}h1{font-size:29px;line-height:1.2}li{padding:8px 0;border-bottom:1px solid #e4eaf0}ul{padding-left:24px}footer{margin-top:28px;color:#4b6276;background:#f3f7fb;padding:16px;border-radius:9px}@media print{body{margin:0}footer{background:transparent}}';
        return '<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.' | Vietnam Travel Advisor</title><style>'.$css.'</style></head><body><header><small>Vietnam Travel Advisor · Sample Tour</small><h1>'.$title.'</h1><p><b>'.self::esc($label).':</b> '.$destination.'</p></header><main><h2>'.self::esc($detail).'</h2><ul>'.$days.'</ul></main><footer>'.self::esc($disclaimer).'</footer></body></html>';
    }
    public static function list(PDO $db,array $user,int $conversation):array {
        $company=(int)$user['company_id'];
        if(!self::q($db,'SELECT id FROM social_conversations WHERE company_id=? AND id=?',[$company,$conversation])->fetchColumn())
            throw new OutOfBoundsException('Conversation not found');
        return self::q($db,'SELECT id,program_id,created_at,expires_at,revoked_at,view_count,last_opened_at FROM marketing_tour_shares WHERE company_id=? AND conversation_id=? ORDER BY id DESC LIMIT 50',[
            $company,$conversation])->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function revoke(PDO $db,array $user,int $shareId):array {
        $company=(int)$user['company_id'];
        $db->beginTransaction();
        try{
            $row=self::q($db,'SELECT id,revoked_at FROM marketing_tour_shares WHERE company_id=? AND id=? FOR UPDATE',[$company,$shareId])->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new OutOfBoundsException('Share not found');
            if($row['revoked_at']!==null){$db->commit();return ['id'=>$shareId,'replayed'=>true];}
            self::q($db,'UPDATE marketing_tour_shares SET revoked_at=NOW() WHERE company_id=? AND id=?',[$company,$shareId]);
            Audit::log($db,$company,(int)$user['id'],'MARKETING_TOUR_SHARE_REVOKED','marketing_tour_share',$shareId);
            $db->commit();return ['id'=>$shareId,'revoked'=>true];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function publicHandle(string $route,string $method,PDO $db):void {
        if($route!=='marketing/tour-share/view')return;
        if($method!=='GET')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        try{
            $snapshot=self::view($db,is_string($_GET['token']??null)?$_GET['token']:'');
            header('Content-Type: text/html; charset=utf-8');
            echo self::html($snapshot);
            exit;
        }catch(Throwable $e){Http::json(['ok'=>false,'error'=>'LINK_NOT_AVAILABLE'],404);}
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $user):void {
        if(!str_starts_with($route,'marketing/tour-share/'))return;
        try{
            if($route==='marketing/tour-share/list'&&$method==='GET'){
                Auth::requirePermission($db,$user,'lead.view');
                $id=self::positive($_GET['conversation_id']??null,'conversation_id');
                Http::json(['ok'=>true,'items'=>self::list($db,$user,$id)]);
            }
            if($route==='marketing/tour-share/create'&&$method==='POST'){
                Auth::requirePermission($db,$user,'lead.manage');
                header('Cache-Control: no-store');
                Http::json(['ok'=>true]+self::create($db,$user,Http::body()),201);
            }
            if($route==='marketing/tour-share/revoke'&&$method==='POST'){
                Auth::requirePermission($db,$user,'lead.manage');
                $body=Http::body();Http::json(['ok'=>true]+self::revoke($db,$user,self::positive($body['id']??null,'id')));
            }
            Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION'],422);}
         catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
         catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}
