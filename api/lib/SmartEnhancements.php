<?php
declare(strict_types=1);

/**
 * Read-only, tenant/permission scoped Smart Workflow P1 APIs.
 * Never return supplier NET, margin, contact data beyond authorized display fields.
 * No migration, no new rights, no booking/quote state changes.
 */
final class SmartEnhancements {
    private static function allowed(PDO $db, array $user, string $permission): bool {
        return Auth::can($db, (int)$user['id'], $permission);
    }

    private static function rows(PDO $db, string $sql, array $bind): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function term(): string {
        $q = trim((string)($_GET['q'] ?? ''));
        if (function_exists('mb_substr')) return mb_substr($q, 0, 120, 'UTF-8');
        return substr($q, 0, 120);
    }

    private static function search(PDO $db, array $user): array {
        $groups = ['quotes'=>[], 'bookings'=>[], 'customers'=>[], 'suppliers'=>[]];
        // Partner portal users never enter internal customer/quote search, even if a role is misconfigured.
        if (self::allowed($db,$user,'b2b.portal') && !self::allowed($db,$user,'b2b.admin')) return $groups;
        $q = self::term();
        if ((function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q)) < 2) return $groups;
        $like = '%'.$q.'%';
        $company = (int)$user['company_id'];

        // Quotes and tours: only approved sales users; NEVER select cost, profit or NET fields.
        if (self::allowed($db,$user,'sales.view')) {
            $groups['quotes'] = self::rows($db, "SELECT q.id, q.quote_ref ref, t.title title, t.lead_contact_name detail, qv.version_status status
                FROM quotes q JOIN trips t ON t.id=q.trip_id AND t.company_id=q.company_id
                JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=q.current_version_no
                WHERE q.company_id=? AND (q.quote_ref LIKE ? OR t.trip_ref LIKE ? OR t.title LIKE ? OR t.lead_contact_name LIKE ?)
                ORDER BY q.updated_at DESC LIMIT 8", [$company,$like,$like,$like,$like]);
            $groups['customers'] = self::rows($db, "SELECT id, customer_ref ref, full_name title, company_name detail, status
                FROM customers WHERE company_id=? AND status<>'ARCHIVED'
                  AND (customer_ref LIKE ? OR full_name LIKE ? OR company_name LIKE ? OR email LIKE ? OR whatsapp LIKE ?)
                ORDER BY updated_at DESC LIMIT 6", [$company,$like,$like,$like,$like,$like]);
        }
        if (self::allowed($db,$user,'booking.view')) {
            $groups['bookings'] = self::rows($db, "SELECT b.id, b.booking_ref ref, t.title title, b.lead_guest_name detail, b.operations_status status
                FROM bookings b JOIN trips t ON t.id=b.trip_id AND t.company_id=b.company_id
                WHERE b.company_id=? AND (b.booking_ref LIKE ? OR t.title LIKE ? OR b.lead_guest_name LIKE ?)
                ORDER BY b.start_date DESC LIMIT 8", [$company,$like,$like,$like]);
        }
        if (self::allowed($db,$user,'supplier.view')) {
            $groups['suppliers'] = self::rows($db, "SELECT id, supplier_ref ref, name title, city detail, status
                FROM suppliers WHERE company_id=? AND status<>'ARCHIVED'
                  AND (supplier_ref LIKE ? OR name LIKE ? OR city LIKE ?)
                ORDER BY preferred DESC, name LIMIT 6", [$company,$like,$like,$like]);
        }
        return $groups;
    }

    private static function alerts(PDO $db, array $user): array {
        $company=(int)$user['company_id'];$uid=(int)$user['id'];$alerts=[];
        if (self::allowed($db,$user,'b2b.portal') && !self::allowed($db,$user,'b2b.admin')) return $alerts;
        if (self::allowed($db,$user,'task.view')) {
            $tasks = self::rows($db, "SELECT id,title,due_at,priority,
                CASE WHEN due_at < NOW() THEN 'OVERDUE'
                     WHEN DATE(due_at)=CURDATE() THEN 'DUE_TODAY'
                     ELSE 'UPCOMING' END due_state
                FROM tasks WHERE company_id=? AND owner_user_id=?
                AND status IN ('OPEN','SNOOZED')
                AND due_at IS NOT NULL AND due_at <= DATE_ADD(NOW(),INTERVAL 7 DAY)
                ORDER BY due_at ASC LIMIT 12", [$company,$uid]);
            foreach ($tasks as $r) $alerts[]=[
                'kind'=>'task','id'=>(int)$r['id'],'title'=>(string)$r['title'],
                'detail'=>(string)$r['due_at'],'severity'=>$r['due_state']==='OVERDUE'?'critical':'warning',
                'route'=>'operations'
            ];
        }
        if (self::allowed($db,$user,'operations.view')) {
            $bookings = self::rows($db, "SELECT id,booking_ref,lead_guest_name,start_date,risk_level
                FROM bookings WHERE company_id=?
                  AND risk_level IN ('HIGH','CRITICAL')
                  AND operations_status NOT IN ('COMPLETED','CANCELLED')
                  AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
                ORDER BY start_date LIMIT 10", [$company]);
            foreach ($bookings as $r) $alerts[]=[
                'kind'=>'booking','id'=>(int)$r['id'],
                'title'=>(string)$r['booking_ref'].' · '.(string)$r['lead_guest_name'],
                'detail'=>(string)$r['start_date'],'severity'=>$r['risk_level']==='CRITICAL'?'critical':'warning',
                'route'=>'booking'
            ];
        }
        if (self::allowed($db,$user,'rate.view')) {
            $rates = self::rows($db, "SELECT r.id,r.rate_ref,r.product_name,rv.valid_to
                FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id
                  AND rv.version_no=(SELECT MAX(x.version_no) FROM rate_versions x WHERE x.rate_id=r.id)
                WHERE r.company_id=? AND r.status='ACTIVE' AND rv.approval_status='APPROVED'
                  AND rv.valid_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
                ORDER BY rv.valid_to LIMIT 10", [$company]);
            foreach ($rates as $r) $alerts[]=[
                'kind'=>'rate','id'=>(int)$r['id'],
                'title'=>(string)$r['rate_ref'].' · '.(string)$r['product_name'],
                'detail'=>(string)$r['valid_to'],'severity'=>'warning',
                'route'=>'suppliers'
            ];
        }
        return $alerts;
    }

    public static function handle(string $route, string $method, PDO $db, array $user): void {
        if ($route !== 'smart/search' && $route !== 'smart/alerts') return;
        if ($method !== 'GET') Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        if ($route === 'smart/search') Http::json(['ok'=>true,'groups'=>self::search($db,$user)]);
        Http::json(['ok'=>true,'items'=>self::alerts($db,$user)]);
    }
}
