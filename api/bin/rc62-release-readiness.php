<?php
declare(strict_types=1);

/**
 * RC6.2 read-only candidate readiness gate.
 * CLI only; no DDL, DML, migration execution, document writes or external requests.
 *
 * php api/bin/rc62-release-readiness.php --manifest=/private/manifest.json --expected-sha=<operator-approved-40-hex> --expected-db=<operator-approved-clone>
 */
final class RC62ReleaseReadiness
{
    private const SOURCE_SCHEMA = 'vta.rc62.runtime.manifest.v1';
    private const RELEASE_TYPE = 'STAGING_CANDIDATE_NOT_PRODUCTION';

    // Emit only codes explicitly authored here; database and configuration
    // exceptions can contain private connection details and must stay opaque.
    private const SAFE_ERRORS = [
        'CLI_MANIFEST_SHA_DB_PIN_REQUIRED', 'RELEASE_SHA_MISMATCH',
        'CLONE_DATABASE_MISMATCH',
        'INVALID_RELEASE_MANIFEST', 'INCOMPLETE_RELEASE_MANIFEST',
        'MISSING_RUNTIME_ROOT', 'UNSAFE_MANIFEST_ENTRY',
        'RUNTIME_HASH_MISMATCH', 'INCOMPLETE_RUNTIME',
        'MIGRATION_MANIFEST_MISMATCH', 'INCOMPLETE_SQL_STACK',
        'DUPLICATE_SCHEMA_VERSION', 'DUPLICATE_MIGRATION_LEDGER',
        'MIGRATION_CHECKSUM_OR_STATUS_MISMATCH', 'UNEXPECTED_SCHEMA_VERSION',
        'UNEXPECTED_MIGRATION_LEDGER', 'PRIVATE_INPUT_MISSING',
        'INVALID_MANIFEST_JSON', 'INVALID_PRIVATE_CONFIG',
        'UNSAFE_BASE_URL', 'ORIGIN_MISMATCH',
        'ISOLATED_DB_NAME_REQUIRED', 'PHP_EXTENSION_MISSING', 'PHP_83_REQUIRED',
        'MIGRATION_FILE_COUNT_DRIFT', 'MIGRATIONS_PENDING_ON_CLONE'
    ];

    public static function safeFailure(Throwable $error): string
    {
        // PDOException is a RuntimeException subclass; never forward its
        // message, even when it looks like an expected status code.
        if (get_class($error) !== RuntimeException::class) return 'PREFLIGHT_ERROR';
        $message = $error->getMessage();
        return in_array($message, self::SAFE_ERRORS, true) ? $message : 'PREFLIGHT_ERROR';
    }

    public static function assertPinnedCommit(string $manifestSha, string $expectedSha): void
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $manifestSha)
            || !preg_match('/^[a-f0-9]{40}$/D', $expectedSha)
            || !hash_equals($expectedSha, $manifestSha))
            self::reject('RELEASE_SHA_MISMATCH');
    }

    /** Enforce a second independent operator pin for the isolated DB clone. */
    public static function assertCloneDatabase(
        string $configured, string $operatorPinned, ?string $connected = null
    ): void {
        if (!preg_match('/^[A-Za-z0-9_]{6,64}$/D', $configured)
            || !preg_match('/(?:^|_)(?:staging|stage|clone|sandbox|test|rc62)(?:_|$)/i', $configured)
            || preg_match('/(?:^|_)(?:prod|production|live)(?:_|$)/i', $configured)
            || str_starts_with(strtolower($configured), 'v2qu_v2qu_vtaos')
            || !hash_equals($configured, $operatorPinned)
            || ($connected !== null && !hash_equals($configured, $connected)))
            self::reject('CLONE_DATABASE_MISMATCH');
    }

    private static function reject(string $reason): void
    {
        throw new RuntimeException($reason);
    }

    public static function safeRelative(string $name): bool
    {
        if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/')
            || preg_match('/[\x00-\x1f]/', $name)) return false;
        $parts = explode('/', $name);
        foreach ($parts as $part) {
            if ($part === '' || $part === '..' || $part === '.') return false;
        }
        return true;
    }

    /** @return array{commit:string,count:int,migrations:int} */
    public static function files(string $root, array $manifest): array
    {
        if (($manifest['schema'] ?? null) !== self::SOURCE_SCHEMA
            || ($manifest['release_type'] ?? null) !== self::RELEASE_TYPE
            || !preg_match('/^[a-f0-9]{40}$/D', (string)($manifest['source_commit'] ?? '')))
            self::reject('INVALID_RELEASE_MANIFEST');

        $items = $manifest['sha256'] ?? null;
        if (!is_array($items) || array_is_list($items) || count($items) < 100
            || count($items) !== ($manifest['file_count'] ?? null))
            self::reject('INCOMPLETE_RELEASE_MANIFEST');
        $root = realpath($root);
        if (!$root || !is_dir($root)) self::reject('MISSING_RUNTIME_ROOT');
        $migrations = 0;
        foreach ($items as $name => $hash) {
            if (!is_string($name) || !self::safeRelative($name)
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash))
                self::reject('UNSAFE_MANIFEST_ENTRY');
            $path = $root . '/' . $name;
            $resolved = realpath($path);
            // Also reject a symlinked parent directory which could read a file
            // outside the intended runtime root.
            if (!$resolved || !str_starts_with($resolved, $root.'/')
                || !is_file($path) || is_link($path)
                || !hash_equals($hash, hash_file('sha256', $path) ?: ''))
                self::reject('RUNTIME_HASH_MISMATCH');
            if (str_starts_with($name, 'api/migrations/') && str_ends_with($name, '.sql')) $migrations++;
        }
        foreach ([
            'index.html', 'app.js', 'api/bootstrap.php', 'api/index.php',
            'api/lib/PartnerHub.php', 'api/lib/TourLibrary.php',
            'api/lib/SmartEnhancements.php', 'api/migrations/038_b2b_hybrid_distribution.sql'
        ] as $path) {
            if (!isset($items[$path])) self::reject('INCOMPLETE_RUNTIME');
        }
        if ($migrations !== ($manifest['migration_count'] ?? null) || $migrations < 30)
            self::reject('MIGRATION_MANIFEST_MISMATCH');
        return ['commit'=>$manifest['source_commit'], 'count'=>count($items), 'migrations'=>$migrations];
    }

    /**
     * Compare exact migration versions + file digests with *read-only* DB ledger.
     * All applied migrations need matching checksum/status; no baselining or DDL here.
     *
     * @param array<string,string> $expected version=>sha256
     * @param list<string> $versions
     * @param list<array{version:string,sha256:string,status:string}> $ledger
     */
    public static function ledger(array $expected, array $versions, array $ledger): array
    {
        if (!$expected || count($expected) < 30) self::reject('INCOMPLETE_SQL_STACK');
        if (count($versions) !== count(array_unique($versions))) self::reject('DUPLICATE_SCHEMA_VERSION');
        $versions = array_values(array_unique($versions));
        $applied = array_fill_keys($versions, true);
        $checks = [];
        foreach ($ledger as $row) {
            if (isset($checks[$row['version']])) self::reject('DUPLICATE_MIGRATION_LEDGER');
            $checks[$row['version']] = $row;
        }
        $pending = [];
        foreach ($expected as $name => $hash) {
            if (!isset($applied[$name])) {
                $pending[] = $name;
                continue;
            }
            if (!isset($checks[$name]) || $checks[$name]['status'] !== 'APPLIED'
                || !hash_equals($hash, $checks[$name]['sha256']))
                self::reject('MIGRATION_CHECKSUM_OR_STATUS_MISMATCH');
        }
        foreach ($versions as $version) {
            if (!isset($expected[$version])) self::reject('UNEXPECTED_SCHEMA_VERSION');
        }
        foreach ($checks as $name => $row) {
            if (!isset($expected[$name]) || !isset($applied[$name]))
                self::reject('UNEXPECTED_MIGRATION_LEDGER');
        }
        return ['expected'=>count($expected), 'applied'=>count($versions), 'pending'=>$pending];
    }

    public static function run(array $argv): void
    {
        if (PHP_SAPI !== 'cli' || count($argv) !== 4
            || !str_starts_with($argv[1], '--manifest=')
            || !str_starts_with($argv[2], '--expected-sha=')
            || !str_starts_with($argv[3], '--expected-db='))
            self::reject('CLI_MANIFEST_SHA_DB_PIN_REQUIRED');
        $manifestPath = substr($argv[1], strlen('--manifest='));
        $expectedSha = substr($argv[2], strlen('--expected-sha='));
        $expectedDb = substr($argv[3], strlen('--expected-db='));
        $appRoot = dirname(__DIR__, 2);
        require_once dirname(__DIR__) . '/lib/RuntimeGuard.php';
        // The candidate manifest and config must be private, not web-accessible.
        RuntimeGuard::privatePath($manifestPath);
        $configPath = getenv('VTA_CONFIG_FILE') ?: dirname(__DIR__, 3) . '/vta_private/config.php';
        RuntimeGuard::privatePath($configPath);
        if (!is_file($manifestPath) || !is_readable($manifestPath) || !is_file($configPath))
            self::reject('PRIVATE_INPUT_MISSING');
        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) self::reject('INVALID_MANIFEST_JSON');
        $files = self::files($appRoot, $manifest);
        self::assertPinnedCommit($files['commit'], $expectedSha);
        $config = require $configPath;
        if (!is_array($config)) self::reject('INVALID_PRIVATE_CONFIG');
        RuntimeGuard::config($configPath, $config);
        $origin = rtrim((string)($config['security']['allowed_origin'] ?? ''), '/');
        $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
        if (!str_starts_with($base, 'https://') && !preg_match('~^http://(?:localhost|127\.0\.0\.1)(?::[0-9]+)?$~D', $base))
            self::reject('UNSAFE_BASE_URL');
        if ($origin !== $base) self::reject('ORIGIN_MISMATCH');
        $cfg = $config['db'] ?? [];
        $dbName = (string)($cfg['database'] ?? '');
        // Match the operator-approved, isolated clone name BEFORE connecting.
        // A mere 'test' substring inside a production DB name is not sufficient.
        self::assertCloneDatabase($dbName, $expectedDb);
        foreach (['pdo_mysql', 'mbstring', 'dom', 'zip', 'gd', 'curl'] as $extension) {
            if (!extension_loaded($extension)) self::reject('PHP_EXTENSION_MISSING');
        }
        if (PHP_VERSION_ID < 80300) self::reject('PHP_83_REQUIRED');
        require_once dirname(__DIR__) . '/lib/Migrations.php';
        $expected = [];
        foreach (Migrations::orderedFiles($appRoot.'/api/migrations') as $file) {
            $expected[basename($file, '.sql')] = hash_file('sha256', $file);
        }
        if (count($expected) !== $files['migrations']) self::reject('MIGRATION_FILE_COUNT_DRIFT');
        // Read-only account recommended. No connection to WordPress or third-party provider.
        $db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string)($cfg['host'] ?? '127.0.0.1'), (int)($cfg['port'] ?? 3306), $dbName),
            (string)($cfg['username'] ?? ''), (string)($cfg['password'] ?? ''), [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false
            ]);
        self::assertCloneDatabase(
            $dbName, $expectedDb, (string)$db->query('SELECT DATABASE()')->fetchColumn()
        );
        $versions = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $ledger = $db->query('SELECT version,sha256,status FROM migration_checksums')->fetchAll(PDO::FETCH_ASSOC);
        $result = self::ledger($expected, $versions, $ledger);
        if ($result['pending']) self::reject('MIGRATIONS_PENDING_ON_CLONE');
        echo json_encode([
            'ok'=>true, 'status'=>'READ_ONLY_PREFLIGHT_PASS',
            'source_commit'=>$files['commit'], 'runtime_files'=>$files['count'],
            'migrations_checked'=>$result['applied'],
            'release_authorized'=>false,
            'requires'=>['restore_rehearsal','multirole_staging_uat','operator_approval']
        ], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        RC62ReleaseReadiness::run($argv);
    } catch (Throwable $error) {
        // Never print DB, filesystem or private config exception text.
        fwrite(STDERR, json_encode([
            'ok'=>false,'status'=>'RELEASE_BLOCKED',
            'reason'=>RC62ReleaseReadiness::safeFailure($error)
        ], JSON_UNESCAPED_SLASHES)."\n");
        exit(2);
    }
}
