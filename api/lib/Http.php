<?php
declare(strict_types=1);

final class Http {
    public static function json(array $payload, int $status = 200): never {
        if(class_exists('QuoteVs2Projection')&&isset($GLOBALS['db'],$GLOBALS['user']))$payload=QuoteVs2Projection::filter($GLOBALS['db'],$GLOBALS['user'],$payload,(string)($_GET['route']??''));
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function body(): array {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) self::json(['ok'=>false,'error'=>'INVALID_JSON','message'=>'Invalid JSON body.'], 400);
        return $data;
    }

    public static function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

    public static function requireMethod(string ...$allowed): void {
        if (!in_array(self::method(), $allowed, true)) {
            self::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
        }
    }

    public static function requestId(): string {
        static $id;
        if (!$id) $id = bin2hex(random_bytes(12));
        return $id;
    }
}
