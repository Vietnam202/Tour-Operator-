<?php
declare(strict_types=1);

final class Auth {
    public static function startSession(array $config): void {
        $app = $config['app'] ?? [];
        session_name($app['session_name'] ?? 'vta_session');
        session_set_cookie_params([
            'lifetime'=>(int)($app['session_lifetime'] ?? 28800),
            'path'=>'/',
            'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly'=>true,
            'samesite'=>'Strict',
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }

    public static function login(PDO $db, string $email, string $password): array {
        $stmt = $db->prepare("SELECT u.*, r.code role_code, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.status='ACTIVE' LIMIT 1");
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            usleep(250000);
            Http::json(['ok'=>false,'error'=>'INVALID_CREDENTIALS','message'=>'Email or password is incorrect.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['company_id'] = (int)$user['company_id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
        return self::publicUser($db, (int)$user['id']);
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireUser(PDO $db): array {
        $id = (int)($_SESSION['user_id'] ?? 0);
        if (!$id) Http::json(['ok'=>false,'error'=>'AUTH_REQUIRED','message'=>'Please sign in.'], 401);
        $user = self::publicUser($db, $id);
        if (($user['status'] ?? '') !== 'ACTIVE') {
            self::logout();
            Http::json(['ok'=>false,'error'=>'ACCOUNT_INACTIVE'], 401);
        }
        return $user;
    }

    public static function checkCsrf(): void {
        if (in_array(Http::method(), ['GET','HEAD','OPTIONS'], true)) return;
        $token = $_SERVER['HTTP_X_VTA_CSRF'] ?? '';
        if (!$token || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
            Http::json(['ok'=>false,'error'=>'CSRF_FAILED','message'=>'Security token is invalid. Refresh and try again.'], 419);
        }
    }

    public static function can(PDO $db, int $userId, string $permission): bool {
        $sql = "SELECT
            COALESCE((SELECT effect FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=? AND p.code=? LIMIT 1),
                     CASE WHEN EXISTS(SELECT 1 FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions p2 ON p2.id=rp.permission_id WHERE u.id=? AND p2.code=?) THEN 'ALLOW' ELSE 'DENY' END) effect";
        $st=$db->prepare($sql); $st->execute([$userId,$permission,$userId,$permission]);
        return $st->fetchColumn()==='ALLOW';
    }

    public static function requirePermission(PDO $db, array $user, string $permission): void {
        if (!self::can($db, (int)$user['id'], $permission)) {
            Http::json(['ok'=>false,'error'=>'FORBIDDEN','message'=>'You do not have permission for this action.'], 403);
        }
    }

    public static function publicUser(PDO $db, int $id): array {
        $st=$db->prepare("SELECT u.id,u.company_id,u.full_name,u.email,u.mobile,u.status,u.last_login_at,r.code role_code,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1");
        $st->execute([$id]);
        $u=$st->fetch();
        if(!$u) Http::json(['ok'=>false,'error'=>'USER_NOT_FOUND'],401);
        $p=$db->prepare("SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN users u ON u.role_id=rp.role_id WHERE u.id=? UNION SELECT p.code FROM permissions p JOIN user_permissions up ON up.permission_id=p.id WHERE up.user_id=? AND up.effect='ALLOW'");
        $p->execute([$id,$id]);
        $perms=array_values(array_unique(array_column($p->fetchAll(),'code')));
        $den=$db->prepare("SELECT p.code FROM permissions p JOIN user_permissions up ON up.permission_id=p.id WHERE up.user_id=? AND up.effect='DENY'");
        $den->execute([$id]);
        $denied=array_column($den->fetchAll(),'code');
        $u['permissions']=array_values(array_diff($perms,$denied));
        $u['csrf']=$_SESSION['csrf'] ?? null;
        return $u;
    }
}
