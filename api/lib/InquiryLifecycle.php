<?php
declare(strict_types=1);

/** Inquiry is the first lock for removal, edits and linked commercial transitions. */
final class InquiryLifecycle {
    private static function query(PDO $db,string $sql,array $args): PDOStatement {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=preg_replace('/\s+FOR UPDATE\b/i','',$sql);
        $statement=$db->prepare($sql);$statement->execute($args);return $statement;
    }
    public static function lock(PDO $db,int $company,int $id): array {
        if(!$db->inTransaction())throw new LogicException('Inquiry writes require an active transaction');
        $row=self::query($db,'SELECT * FROM inquiries WHERE company_id=? AND id=? FOR UPDATE',[$company,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Inquiry not found');
        return $row;
    }
    public static function active(array $inquiry): void {
        if($inquiry['deleted_at']!==null)throw new DomainException('INQUIRY_ARCHIVED: Restore this Lead before continuing.');
    }
    public static function expected(array $body): int {
        $value=$body['expected_version_no']??null;
        if((!is_int($value)&&!is_string($value))||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)
            throw new InvalidArgumentException('expected_version_no is required and must be a positive integer.');
        return (int)$value;
    }
    public static function quote(PDO $db,int $company,int $id): array {
        if(!$db->inTransaction())throw new LogicException('Quote transitions require an active transaction');
        // Discover without taking a quote/version/trip lock before its inquiry.
        $quote=self::query($db,'SELECT id,inquiry_id,trip_id FROM quotes WHERE company_id=? AND id=?',[$company,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$quote)throw new OutOfBoundsException('Quote not found');
        if($quote['inquiry_id']!==null){
            $inquiry=self::lock($db,$company,(int)$quote['inquiry_id']);
            if((int)$inquiry['trip_id']!==(int)$quote['trip_id'])throw new OutOfBoundsException('Inquiry not found');
            self::active($inquiry);
        }
        $locked=self::query($db,'SELECT * FROM quotes WHERE company_id=? AND id=? FOR UPDATE',[$company,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$locked||(int)$locked['inquiry_id']!==(int)$quote['inquiry_id']||(int)$locked['trip_id']!==(int)$quote['trip_id'])
            throw new DomainException('QUOTE_LINK_CHANGED: Reload this quotation before continuing.');
        return $locked;
    }
    public static function version(PDO $db,int $company,int $version): array {
        if(!$db->inTransaction())throw new LogicException('Quote version writes require an active transaction');
        $quoteId=self::query($db,'SELECT q.id FROM quote_versions v JOIN quotes q ON q.id=v.quote_id WHERE q.company_id=? AND v.id=?',[$company,$version])->fetchColumn();
        if(!$quoteId)throw new OutOfBoundsException('Quote version not found');
        return self::quote($db,$company,(int)$quoteId);
    }
}
