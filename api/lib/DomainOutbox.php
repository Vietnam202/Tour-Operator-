<?php
declare(strict_types=1);

/** Internal domain events and deterministic effects share the existing task engine. No external delivery. */
final class DomainOutbox {
    private static function query(PDO $db,string $sql,array $args=[]): PDOStatement {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=preg_replace('/\s+FOR UPDATE\b/i','',$sql);
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    public static function hash(array $value): string {
        $canonical=function(mixed $v) use (&$canonical): mixed { if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as $k=>$x)$v[$k]=$canonical($x);return $v; };
        return hash('sha256',json_encode($canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    public static function commandReplay(PDO $db,array $user,string $key,string $hash): ?array {
        $row=self::query($db,'SELECT * FROM domain_execution_keys WHERE company_id=? AND execution_key=? FOR UPDATE',[$user['company_id'],'command:'.$key])->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        if((int)$row['actor_user_id']!==(int)$user['id']||!hash_equals($row['request_hash'],$hash))throw new DomainException('ACTION_KEY_REUSED: this retry key belongs to different content or actor');
        return json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR);
    }
    public static function commandResult(PDO $db,array $user,string $key,string $hash,string $type,int $id,array $result): void {
        self::query($db,"INSERT INTO domain_execution_keys(company_id,execution_key,execution_kind,entity_type,entity_id,actor_user_id,request_hash,result_json) VALUES(?,?,'COMMAND',?,?,?,?,?)",[$user['company_id'],'command:'.$key,$type,$id,$user['id'],$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
    }
    public static function event(PDO $db,array $user,string $name,string $type,int $id,int $version,array $payload,?array $task=null): int {
        if(!$db->inTransaction())throw new LogicException('Domain events must commit with the business transaction');
        $cid=(int)$user['company_id'];$uid=(int)$user['id'];$key=$type.':'.$id.':'.$version.':'.$name;
        $old=self::query($db,'SELECT id FROM domain_outbox WHERE company_id=? AND event_key=?',[$cid,$key])->fetchColumn();
        if($old)return (int)$old;
        self::query($db,'INSERT INTO domain_outbox(company_id,event_key,event_name,entity_type,entity_id,entity_version,actor_user_id,payload_json) VALUES(?,?,?,?,?,?,?,?)',[$cid,$key,$name,$type,$id,$version,$uid,json_encode($payload,JSON_THROW_ON_ERROR)]);
        $event=(int)$db->lastInsertId();
        if($task!==null){
            $effectKey='task:'.$event.':'.$task['rule_code'];
            self::query($db,"INSERT INTO tasks(company_id,title,entity_type,entity_id,owner_user_id,due_at,priority,status,source,rule_code) VALUES(?,?,?,?,?,?,'NORMAL','OPEN','AUTOMATION',?)",[$cid,$task['title'],$task['entity_type'],$task['entity_id'],$task['owner_user_id'],$task['due_at'],$task['rule_code']]);
            $taskId=(int)$db->lastInsertId();
            self::query($db,"INSERT INTO domain_execution_keys(company_id,execution_key,execution_kind,entity_type,entity_id,actor_user_id,request_hash,result_json,event_id,task_id) VALUES(?,?,'TASK',?,?,?,?,?,?,?)",[$cid,$effectKey,$task['entity_type'],$task['entity_id'],$uid,self::hash($task),json_encode(['task_id'=>$taskId],JSON_THROW_ON_ERROR),$event,$taskId]);
            Audit::log($db,$cid,$uid,'DOMAIN_TASK_CREATED',$task['entity_type'],(int)$task['entity_id'],null,['event_id'=>$event,'event_name'=>$name,'task_id'=>$taskId,'rule_code'=>$task['rule_code']]);
        }
        self::query($db,"UPDATE domain_outbox SET status='APPLIED',applied_at=CURRENT_TIMESTAMP WHERE id=? AND company_id=?",[$event,$cid]);
        return $event;
    }
}
