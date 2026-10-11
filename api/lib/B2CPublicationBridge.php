<?php
declare(strict_types=1);
require_once __DIR__.'/B2CStudio.php';
require_once __DIR__.'/WebhookCenter.php';
/** Read-only signed publication feed, separate from visitor sessions and private APIs. */
final class B2CPublicationBridge {
    public static function pull(PDO $db,array $cfg,string $source,string $raw,string $signature): array {
        $spec=$cfg['integrations']['b2c_publications'][$source]??null;
        if(!preg_match('/^[a-z0-9_-]{3,64}$/D',$source)||!is_array($spec)||empty($spec['enabled'])||!WebhookCenter::validSignature($raw,(string)($spec['secret']??''),$signature))throw new OutOfBoundsException('UNAUTHORIZED');
        if(strlen($raw)>4096)throw new InvalidArgumentException('Payload too large');$b=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($b)||!is_int($b['timestamp']??null)||abs(time()-$b['timestamp'])>120||!is_string($b['nonce']??null)||!preg_match('/^[a-f0-9]{32}$/D',$b['nonce'])||!is_int($b['after_id']??null)||$b['after_id']<0)throw new InvalidArgumentException('Invalid feed request');
        $company=filter_var($spec['company_id']??null,FILTER_VALIDATE_INT);if(!$company)throw new DomainException('Source not configured');
        // Every event is replayable; ordering comes from the monotonically increasing event ID.
        $q=$db->prepare('SELECT * FROM b2c_publication_events WHERE company_id=? AND id>? ORDER BY id LIMIT 100');$q->execute([$company,$b['after_id']]);$events=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $payload=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if(!hash_equals($r['snapshot_hash'],hash('sha256',$r['snapshot_json'])))throw new DomainException('Snapshot changed');
            $events[]=['event_id'=>(int)$r['id'],'program_id'=>(int)$r['program_id'],'revision'=>(int)$r['revision'],'status'=>$r['status'],'snapshot'=>$payload,'snapshot_hash'=>$r['snapshot_hash'],'image_base'=>rtrim((string)($cfg['app']['base_url']??''),'/').'/api/index.php?route=b2c-public-image/'.(int)$r['id'].'/'];
        }
        $body=MediaLibrary::json(['ok'=>true,'source'=>$source,'nonce'=>$b['nonce'],'after_id'=>$b['after_id'],'generated_at'=>time(),'events'=>$events,'has_more'=>count($events)===100]);
        return ['body'=>$body,'signature'=>'sha256='.hash_hmac('sha256',$body,$spec['secret'])];
    }
    public static function publicHandle(string $route,string $method,PDO $db,array $cfg): void {
        if(preg_match('#^b2c-public-image/(\d+)/(\d+)$#D',$route,$m)){
            if($method!=='GET')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
            try{
                $q=$db->prepare('SELECT company_id FROM b2c_publication_events WHERE id=?');$q->execute([(int)$m[1]]);$company=(int)$q->fetchColumn();
                $publication=B2CStudio::publication($db,$company,(int)$m[1]);$snapshot=json_decode($publication['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$asset=(int)$m[2];
                if(!in_array($asset,FreeformDocument::imageIds($snapshot['document']),true))throw new OutOfBoundsException('Image not published');
                $q=$db->prepare("SELECT * FROM media_assets WHERE company_id=? AND id=? AND status='APPROVED' AND visibility IN ('COMPANY','SYNCED') AND quote_id IS NULL");$q->execute([$company,$asset]);$a=$q->fetch(PDO::FETCH_ASSOC);if(!$a)throw new OutOfBoundsException('Image not approved');
                $path=MediaLibrary::path($cfg,$a);if(!hash_equals($a['content_sha256'],hash_file('sha256',$path)))throw new OutOfBoundsException('Image changed');
                header('Content-Type: image/jpeg');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');readfile($path);exit;
            }catch(Throwable $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
        }
        if($route!=='webhooks/b2c-publications')return;
        if($method!=='POST')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        try{$r=self::pull($db,$cfg,(string)($_SERVER['HTTP_X_VTA_WEBHOOK_SOURCE']??''),(string)file_get_contents('php://input'),(string)($_SERVER['HTTP_X_VTA_SIGNATURE_256']??''));header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-VTA-SIGNATURE-256: '.$r['signature']);echo $r['body'];exit;}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);}catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);}catch(Throwable $e){Http::json(['ok'=>false,'error'=>'PUBLICATION_UNAVAILABLE'],503);}
    }
}
