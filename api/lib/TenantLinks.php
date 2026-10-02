<?php
declare(strict_types=1);

/** Validate optional manual CRM links before any row is inserted. */
final class TenantLinks {
    public static function active(PDO $db,int $companyId,string $table,mixed $value,string $field,bool $required=false): ?int {
        if(!in_array($table,['users','customers','agents'],true))throw new InvalidArgumentException('Unsupported link type');
        if($value===null||$value===''||$value===0||$value==='0'){
            if($required)throw new InvalidArgumentException($field.' requires an active record in this company');
            return null;
        }
        if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]*$/',(string)$value))throw new InvalidArgumentException('Invalid '.$field);
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new InvalidArgumentException('Invalid '.$field);
        $s=$db->prepare("SELECT id FROM $table WHERE company_id=? AND id=? AND status='ACTIVE'");$s->execute([$companyId,$id]);
        if(!$s->fetchColumn())throw new InvalidArgumentException($field.' requires an active record in this company');
        return $id;
    }
}
