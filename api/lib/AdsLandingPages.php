<?php
declare(strict_types=1);

/**
 * RC6.2 Ads Landing Publisher
 * Source of truth: independently reviewed LIVE B2CStudio publication event.
 * No Master, private Quote, PartnerHub NET, cost, or source documents exported.
 * Public analytics are aggregated only, without IP/UA/fingerprint/PII.
 */
final class AdsLandingPages {
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement {
        $st=$db->prepare($sql);$st->execute($args);return $st;
    }
    public static function slug(mixed $v):string {
        if(!is_string($v)||strlen($v)>90||strlen($v)<4||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$v))
            throw new InvalidArgumentException('Use a lower-case URL slug (4–90 characters, letters, numbers, hyphens)');
        return $v;
    }
    private static function id($v):int {
        $id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false||$id===null)throw new InvalidArgumentException('Invalid ID');
        return (int)$id;
    }
    public static function utmValue($v,int $limit=80):string {
        if(!is_string($v)||strlen($v)>$limit)return '';
        $v=trim($v);
        return preg_match('/^[a-zA-Z0-9_.-]*$/D',$v)?$v:'';
    }
    public static function attribution(array $get,array $landing):array {
        $out=[];
        foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id'] as $key)
            $out[$key]=self::utmValue($get[$key]??'', $key==='utm_campaign'?100:80);
        if($out['utm_source']==='')$out['utm_source']='direct';
        if($out['utm_medium']==='')$out['utm_medium']='unknown';
        if($out['utm_campaign']==='')$out['utm_campaign']=self::utmValue($landing['default_utm_campaign']??'',100);
        $out['referrer']=''; // Never collect arbitrary referring URLs/PII.
        $out['landing_url']='api/index.php?route=ads/landing/'.self::slug($landing['slug']);
        return $out;
    }
    public static function publicUrl(array $cfg,string $slug):string {
        $rel='api/index.php?route=ads/landing/'.self::slug($slug);
        $base=rtrim((string)($cfg['app']['base_url']??''),'/');
        $p=parse_url($base);
        // Reject unsafe URL: never echo Host: header supplied by arbitrary clients.
        if($base===''||!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])
           ||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment']))
            throw new DomainException('An approved HTTPS app base_url is required to publish ads');
        return $base.'/'.$rel;
    }
    public static function metric(PDO $db,int $landingId,string $type,array $utm):void {
        if(!in_array($type,['VIEW','CTA','LEAD'],true))throw new InvalidArgumentException('Invalid analytics event');
        self::q($db,'INSERT INTO ads_landing_metrics(landing_id,day,event_type,utm_source,utm_medium,utm_campaign,count_total)
                    VALUES(?,CURRENT_DATE(),?,?,?,?,1) ON DUPLICATE KEY UPDATE count_total=count_total+1',[
            $landingId,$type,
            self::utmValue($utm['utm_source']??''),
            self::utmValue($utm['utm_medium']??''),
            self::utmValue($utm['utm_campaign']??'',100)
        ]);
    }
    /** Validation of campaign/form and live B2C approval is mandatory on EVERY GET / POST. */
    public static function live(PDO $db,string $slug,bool $lock=false):array {
        $slug=self::slug($slug);
        $sql="SELECT a.*, f.public_token AS private_form_token
              FROM ads_landing_pages a
              JOIN campaigns c ON c.company_id=a.company_id AND c.id=a.campaign_id AND c.status='ACTIVE'
              JOIN lead_forms f ON f.company_id=a.company_id AND f.id=a.lead_form_id
                    AND f.campaign_id=a.campaign_id AND f.status='ACTIVE'
              WHERE a.slug=? AND a.status='PUBLISHED'".($lock?' FOR UPDATE':'');
        $row=self::q($db,$sql,[$slug])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Landing not available');
        $pub=B2CStudio::publication($db,(int)$row['company_id'],(int)$row['publication_event_id']);
        $snapshot=json_decode((string)$pub['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        $row['public_snapshot']=AdsLandingPresentation::snapshot($snapshot);
        return $row;
    }
    private static function requireSession():void {
        if(session_status()!==PHP_SESSION_ACTIVE)throw new DomainException('Session unavailable');
    }
    private static function csrf(string $slug):string {
        self::requireSession();
        if(!isset($_SESSION['ads_csrf'])||!is_array($_SESSION['ads_csrf']))$_SESSION['ads_csrf']=[];
        if(!is_string($_SESSION['ads_csrf'][$slug]??null))
            $_SESSION['ads_csrf'][$slug]=bin2hex(random_bytes(24));
        return $_SESSION['ads_csrf'][$slug];
    }
    private static function validCsrf(string $slug,$candidate):void {
        $expected=self::csrf($slug);
        if(!is_string($candidate)||!hash_equals($expected,$candidate))
            throw new DomainException('CSRF failed');
    }
    public static function visitorUtm(string $slug,array $landing,array $params):array {
        self::requireSession();
        if(!isset($_SESSION['ads_attribution'])||!is_array($_SESSION['ads_attribution']))$_SESSION['ads_attribution']=[];
        if(count(array_intersect(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id'],array_keys($params)))>0
          ||!isset($_SESSION['ads_attribution'][$slug]))
            $_SESSION['ads_attribution'][$slug]=self::attribution($params,$landing);
        return $_SESSION['ads_attribution'][$slug];
    }
    private static function headers(?string $nonce=null):void {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, follow');
        $scripts=$nonce===null?"'none'":"'nonce-".$nonce."'";
        header("Content-Security-Policy: default-src 'none'; script-src ".$scripts.
            "; style-src 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");
    }
    private static function publicGet(PDO $db,array $cfg,string $slug):void {
        $l=self::live($db,$slug);
        $utm=self::visitorUtm($slug,$l,$_GET);
        self::metric($db,(int)$l['id'],'VIEW',$utm);
        $csrf=self::csrf($slug);$nonce=base64_encode(random_bytes(16));
        self::headers($nonce);
        header('Content-Type: text/html; charset=utf-8');
        echo AdsLandingPresentation::html($l['public_snapshot'],$l,AdsLandingPresentation::style($_GET['layout']??$l['layout']),$csrf,$nonce);
        exit;
    }
    private static function event(PDO $db,string $slug):void {
        $body=file_get_contents('php://input');
        if(!is_string($body)||strlen($body)>4096)throw new InvalidArgumentException('Invalid analytics event');
        $data=json_decode($body,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data)||($data['event']??null)!=='cta')
            throw new InvalidArgumentException('Unsupported event');
        self::validCsrf($slug,$data['csrf']??null);
        $l=self::live($db,$slug);
        if(!isset($_SESSION['ads_cta'])||!is_array($_SESSION['ads_cta']))$_SESSION['ads_cta']=[];
        if(empty($_SESSION['ads_cta'][$slug])){
            self::metric($db,(int)$l['id'],'CTA',self::visitorUtm($slug,$l,[]));
            $_SESSION['ads_cta'][$slug]=true;
        }
        Http::json(['ok'=>true,'recorded'=>true]);
    }
    private static function submit(PDO $db,string $slug):void {
        self::headers();
        self::validCsrf($slug,$_POST['csrf']??null);
        if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new InvalidArgumentException('Payload too large');
        $landing=self::live($db,$slug);
        $input=$_POST;$input['consent']=($input['consent']??null)==='yes';
        $hotel=$input['hotel_level']??'';
        if(!is_string($hotel)||!in_array($hotel,['','3-star','4-star','5-star'],true))
            throw new InvalidArgumentException('Invalid hotel level');
        if(!is_string($input['travel_date']??null)||$input['travel_date']==='')
            throw new InvalidArgumentException('Departure date required');
        $attr=self::visitorUtm($slug,$landing,[]);
        $input['attribution']=$attr;
        $lead=B2CStorefront::lead($input);
        $lead['destination']=$landing['public_snapshot']['destination'];
        $lead['hotel_level']=$hotel;
        $lead['message']='ADS ENQUIRY | landing #'.(int)$landing['id'].' | publication #'.(int)$landing['publication_event_id'].
            ' | Tour: '.(string)$landing['public_snapshot']['title']."\n".$lead['message'];
        $lead['submission_key']='ads_'.hash('sha256',(int)$landing['company_id'].':'.(int)$landing['id'].':'.(string)$input['request_id']);
        $expectedCompany=(int)$landing['company_id'];$landingId=(int)$landing['id'];
        $event=(int)$landing['publication_event_id'];
        LeadHub::submit($db,(string)$landing['private_form_token'],$lead,
            static function(int $company,int $leadId)use($db,$slug,$landingId,$event,$expectedCompany,$attr):void{
                if($company!==$expectedCompany)throw new OutOfBoundsException('Wrong company Lead Form');
                $now=self::live($db,$slug,true);
                if((int)$now['id']!==$landingId||(int)$now['publication_event_id']!==$event)
                    throw new OutOfBoundsException('Publication changed');
                $saved=self::q($db,'INSERT IGNORE INTO ads_landing_lead_links(landing_id,company_id,lead_request_id,publication_event_id)
                    VALUES(?,?,?,?)',[$landingId,$company,$leadId,$event]);
                if($saved->rowCount()===1)self::metric($db,$landingId,'LEAD',$attr);
            });
        http_response_code(201);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Enquiry received · Vietnam Travel Advisor</title><main style="max-width:620px;margin:11vh auto;padding:24px;font:18px/1.7 system-ui;color:#174052">'
            .'<h1>Thank you — enquiry received</h1><p>Our team will confirm availability, services and pricing. This is not a confirmed booking.</p>'
            .'<p>Vietnam Travel Advisor · info@vietnamtraveladvisor.com.vn</p></main></html>';
        exit;
    }
    public static function publicHandle(string $route,string $method,PDO $db,array $cfg):void {
        if(!preg_match('#^ads/landing/([a-z0-9-]+)(?:/(lead|event))?$#D',$route,$parts))return;
        $slug=$parts[1];$action=$parts[2]??'';
        try{
            if($action===''&&$method==='GET')self::publicGet($db,$cfg,$slug);
            if($action==='lead'&&$method==='POST')self::submit($db,$slug);
            if($action==='event'&&$method==='POST')self::event($db,$slug);
            Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'LANDING_UNAVAILABLE'],404);}
        catch(OverflowException $e){Http::json(['ok'=>false,'error'=>'RATE_LIMITED'],429);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'UNAVAILABLE_OR_CONFLICT'],409);}
        catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'INVALID_REQUEST'],422);}
        catch(Throwable $e){Http::json(['ok'=>false,'error'=>'LANDING_UNAVAILABLE'],503);}
    }
    private static function show(array $r,array $cfg):array {
        return ['id'=>(int)$r['id'],'slug'=>$r['slug'],'status'=>$r['status'],'layout'=>$r['layout'],
            'publication_event_id'=>(int)$r['publication_event_id'],'campaign_id'=>(int)$r['campaign_id'],
            'lead_form_id'=>(int)$r['lead_form_id'],
            'url'=>self::publicUrl($cfg,$r['slug']),'created_at'=>$r['created_at'],'withdrawn_at'=>$r['withdrawn_at']];
    }
    public static function create(PDO $db,array $cfg,array $u,array $body):array {
        $company=(int)$u['company_id'];
        $slug=self::slug($body['slug']??null);
        $event=self::id($body['publication_event_id']??null);
        $campaign=self::id($body['campaign_id']??null);
        $form=self::id($body['lead_form_id']??null);
        $layout=$body['layout']??'detailed';
        if(!is_string($layout)||!in_array($layout,AdsLandingPresentation::LAYOUTS,true))
            throw new InvalidArgumentException('Invalid template');
        if(($body['public_content_reviewed']??false)!==true)throw new DomainException('Human publication review required');
        $default=self::utmValue($body['default_utm_campaign']??'',100);
        if(($body['default_utm_campaign']??'')!=='' && $default==='')throw new InvalidArgumentException('Invalid default UTM campaign');
        self::publicUrl($cfg,$slug); // Refuse non-HTTPS or unconfigured destination before publishing.
        $db->beginTransaction();
        try{
            $pub=B2CStudio::publication($db,$company,$event);
            $snap=json_decode((string)$pub['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            AdsLandingPresentation::snapshot($snap);
            $row=self::q($db,"SELECT f.id FROM lead_forms f
                JOIN campaigns c ON c.id=f.campaign_id AND c.company_id=f.company_id
                WHERE f.id=? AND f.company_id=? AND f.campaign_id=? AND f.status='ACTIVE' AND c.status='ACTIVE' FOR UPDATE",
                [$form,$company,$campaign])->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new OutOfBoundsException('Active campaign/form required for this company');
            if(self::q($db,'SELECT id FROM ads_landing_pages WHERE slug=? FOR UPDATE',[$slug])->fetchColumn())
                throw new DomainException('Slug already used — select a new stable URL');
            self::q($db,"INSERT INTO ads_landing_pages(company_id,slug,publication_event_id,campaign_id,lead_form_id,layout,default_utm_campaign,created_by)
                VALUES(?,?,?,?,?,?,?,?)",
                [$company,$slug,$event,$campaign,$form,$layout,$default,(int)$u['id']]);
            $id=(int)$db->lastInsertId();
            Audit::log($db,$company,(int)$u['id'],'ADS_LANDING_PUBLISHED','ads_landing',$id,null,
                ['slug'=>$slug,'publication_event_id'=>$event,'layout'=>$layout]);
            $db->commit();
            return self::show(self::q($db,'SELECT * FROM ads_landing_pages WHERE id=? AND company_id=?',[$id,$company])->fetch(PDO::FETCH_ASSOC),$cfg);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function withdraw(PDO $db,array $u,int $id):array {
        $company=(int)$u['company_id'];$db->beginTransaction();
        try{
            $st=self::q($db,'SELECT * FROM ads_landing_pages WHERE id=? AND company_id=? FOR UPDATE',[$id,$company]);
            $row=$st->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new OutOfBoundsException('Landing unavailable');
            if($row['status']==='PUBLISHED'){
                self::q($db,"UPDATE ads_landing_pages SET status='WITHDRAWN',withdrawn_by=?,withdrawn_at=NOW() WHERE id=? AND company_id=?",
                    [(int)$u['id'],$id,$company]);
                Audit::log($db,$company,(int)$u['id'],'ADS_LANDING_WITHDRAWN','ads_landing',$id,
                    ['status'=>'PUBLISHED'],['status'=>'WITHDRAWN']);
            }
            $db->commit();return ['id'=>$id,'status'=>'WITHDRAWN'];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function manage(string $route,string $method,PDO $db,array $cfg,array $u):void {
        if(!str_starts_with($route,'ads/landing-admin'))return;
        try{
            if($route==='ads/landing-admin/options'&&$method==='GET'){
                Auth::requirePermission($db,$u,'b2c.approve');
                Auth::requirePermission($db,$u,'campaign.manage');
                $cid=(int)$u['company_id'];
                $events=self::q($db,"SELECT e.id AS publication_event_id,p.id AS program_id,p.title,p.destination,p.revision
                    FROM b2c_publication_events e
                    JOIN b2c_programs p ON p.id=e.program_id AND p.company_id=e.company_id
                    WHERE e.company_id=? AND e.status='PUBLISHED' AND p.status='PUBLISHED'
                      AND p.revision=e.revision AND p.approved_revision=p.revision
                      AND e.id=(SELECT MAX(e2.id) FROM b2c_publication_events e2
                                WHERE e2.company_id=e.company_id AND e2.program_id=e.program_id)
                    ORDER BY e.id DESC LIMIT 100",[$cid])->fetchAll(PDO::FETCH_ASSOC);
                $forms=self::q($db,"SELECT f.id AS lead_form_id,c.id AS campaign_id,c.source
                    FROM lead_forms f JOIN campaigns c ON c.id=f.campaign_id AND c.company_id=f.company_id
                    WHERE f.company_id=? AND f.status='ACTIVE' AND c.status='ACTIVE'
                    ORDER BY c.id DESC,f.id DESC LIMIT 100",[$cid])->fetchAll(PDO::FETCH_ASSOC);
                Http::json(['ok'=>true,'publications'=>$events,'forms'=>$forms,'layouts'=>AdsLandingPresentation::LAYOUTS]);
            }
            if($route==='ads/landing-admin'&&$method==='GET'){
                Auth::requirePermission($db,$u,'lead.view');
                $list=self::q($db,'SELECT * FROM ads_landing_pages WHERE company_id=? ORDER BY id DESC LIMIT 200',
                    [(int)$u['company_id']])->fetchAll(PDO::FETCH_ASSOC);
                Http::json(['ok'=>true,'items'=>array_map(static fn($r)=>self::show($r,$cfg),$list)]);
            }
            if($route==='ads/landing-admin'&&$method==='POST'){
                Auth::requirePermission($db,$u,'b2c.approve');
                Auth::requirePermission($db,$u,'campaign.manage');
                Http::json(['ok'=>true,'landing'=>self::create($db,$cfg,$u,Http::body())],201);
            }
            if(preg_match('#^ads/landing-admin/(\d+)/withdraw$#D',$route,$m)&&$method==='POST'){
                Auth::requirePermission($db,$u,'b2c.approve');
                Http::json(['ok'=>true]+self::withdraw($db,$u,(int)$m[1]));
            }
            if(preg_match('#^ads/landing-admin/(\d+)/metrics$#D',$route,$m)&&$method==='GET'){
                Auth::requirePermission($db,$u,'lead.view');
                $id=self::id($m[1]);$exists=self::q($db,'SELECT id FROM ads_landing_pages WHERE id=? AND company_id=?',
                    [$id,(int)$u['company_id']])->fetchColumn();
                if(!$exists)throw new OutOfBoundsException('Landing unavailable');
                $res=self::q($db,'SELECT day,event_type,utm_source,utm_medium,utm_campaign,SUM(count_total) AS total
                    FROM ads_landing_metrics WHERE landing_id=? GROUP BY day,event_type,utm_source,utm_medium,utm_campaign
                    ORDER BY day DESC,event_type LIMIT 500',[$id])->fetchAll(PDO::FETCH_ASSOC);
                Http::json(['ok'=>true,'landing_id'=>$id,'metrics'=>$res,'note'=>'Aggregated first-party counts only. No third-party pixels or personal identifiers.']);
            }
            Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
        catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}
        catch(Throwable $e){Http::json(['ok'=>false,'error'=>'ADS_UNAVAILABLE'],503);}
    }
}
