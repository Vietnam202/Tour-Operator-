<?php
declare(strict_types=1);

/** Booking is the first lock for every booking-linked writer and financial close/reopen. */
final class BookingIntegrity {
    public static function begin(PDO $db): void {
        if($db->inTransaction())throw new LogicException('A booking transaction is already active');
        // Parent discovery is unlocked. Avoid a stale repeatable-read snapshot after waiting for its lock.
        // This applies only to the next transaction, leaving the connection/server defaults unchanged.
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();
    }
    public static function query(PDO $db,string $sql,array $args=[]): PDOStatement {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=preg_replace('/\s+FOR UPDATE\b/i','',$sql);
        $statement=$db->prepare($sql);$statement->execute($args);return $statement;
    }
    public static function lock(PDO $db,int $company,int $booking): array {
        if(!$db->inTransaction())throw new LogicException('Booking writes require an active transaction');
        $row=self::query($db,'SELECT * FROM bookings WHERE company_id=? AND id=? FOR UPDATE',[$company,$booking])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Booking not found');return $row;
    }
    public static function assertFinancialOpen(array $booking): void {
        if(isset($booking['finance_closed_at'])&&$booking['finance_closed_at']!=='')throw new DomainException('Booking finance is closed. An authorized finance reopen with a reason is required before changes.');
    }
    public static function financial(PDO $db,int $company,int $booking): array {
        $row=self::lock($db,$company,$booking);self::assertFinancialOpen($row);return $row;
    }
    /** Resolve the tenant-scoped parent without locking a child before its booking. */
    public static function parentId(PDO $db,int $company,string $kind,int $id): int {
        $from=match($kind){
            'invoice'=>'customer_invoices e JOIN bookings b ON b.id=e.booking_id AND b.company_id=e.company_id',
            'payable'=>'supplier_payables e JOIN bookings b ON b.id=e.booking_id AND b.company_id=e.company_id',
            'order'=>'supplier_orders e JOIN bookings b ON b.id=e.booking_id AND b.company_id=e.company_id',
            'service'=>'booking_services e JOIN bookings b ON b.id=e.booking_id',
            'issue'=>'operational_issues e JOIN bookings b ON b.id=e.booking_id AND b.company_id=e.company_id',
            'assignment'=>'resource_assignments e JOIN booking_services s ON s.id=e.service_id JOIN bookings b ON b.id=s.booking_id',
            'reconciliation'=>'supplier_invoice_reconciliations e JOIN supplier_payables p ON p.id=e.payable_id JOIN bookings b ON b.id=p.booking_id AND b.company_id=p.company_id',
            default=>throw new InvalidArgumentException('Unsupported booking-linked record')
        };
        $parent=self::query($db,'SELECT b.id FROM '.$from.' WHERE b.company_id=? AND e.id=?',[$company,$id])->fetchColumn();
        if($parent===false)throw new OutOfBoundsException(ucfirst($kind).' not found');return (int)$parent;
    }
    public static function financialFor(PDO $db,int $company,string $kind,int $id): array {
        return self::financial($db,$company,self::parentId($db,$company,$kind,$id));
    }
}
