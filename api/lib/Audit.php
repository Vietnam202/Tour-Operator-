<?php
declare(strict_types=1);

final class Audit {
    public static function activity(PDO $db, int $companyId, string $entityType, int $entityId, string $event, string $summary, ?int $userId, array $meta=[]): void {
        $st=$db->prepare('INSERT INTO activities(company_id,entity_type,entity_id,event_code,summary,user_id,metadata_json) VALUES(?,?,?,?,?,?,?)');
        $st->execute([$companyId,$entityType,$entityId,$event,$summary,$userId,$meta?json_encode($meta,JSON_UNESCAPED_UNICODE):null]);
    }
    public static function log(PDO $db, int $companyId, ?int $userId, string $action, ?string $entityType, ?int $entityId, $before=null, $after=null): void {
        $st=$db->prepare('INSERT INTO audit_logs(company_id,user_id,action_code,entity_type,entity_id,before_json,after_json,request_id,ip_address) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute([$companyId,$userId,$action,$entityType,$entityId,$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE),Http::requestId(),$_SERVER['REMOTE_ADDR']??null]);
    }
}
