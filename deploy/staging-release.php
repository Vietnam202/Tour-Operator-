<?php
declare(strict_types=1);

// Deployment controller only. No web route, application changes or migration execution.
final class StagingRelease {
    public const HOME = '/home/v2quote.vietnamtraveladvisor.com.vn';
    public const URL = 'https://v2quote.vietnamtraveladvisor.com.vn';
    public const BASELINE = '9de9f6c951df1e1a1ad6231bb02d402e2420109c';
    public const REPO = 'https://github.com/Vietnam202/Tour-Operator-.git';
    public const BRANCH = 'codex/Vietnam/rc6.2-testing';

    public static function need(bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
    }
    public static function path(string $path): void {
        self::need((bool)preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D', $path), 'UNSAFE_PATH');
        foreach (explode('/', $path) as $part) self::need($part !== '.' && $part !== '..', 'UNSAFE_PATH');
    }
    public static function protectedPath(string $path): bool {
        return (bool)preg_match('~(?:^|/)(?:\.git|\.env(?:\.[^/]*)?|vta_private|private|uploads|storage|runtime|logs|cache|backups?|generated|node_modules)(?:/|$)|\.(?:pem|key|sqlite3?|db|log)$~i', $path)
            || in_array($path, ['api/config.php', 'api/config.example.php'], true);
    }
    public static function permittedTracked(string $path, string $metadata, array $historicalLogs): bool {
        return !self::protectedPath($path) || $path === 'api/config.example.php'
            || (str_starts_with($path,'verification/') && str_ends_with($path,'.log') && ($historicalLogs[$path]??null) === $metadata);
    }
    public static function managed(string $path): bool {
        self::path($path);
        if (self::protectedPath($path)) return false;
        if (str_starts_with($path, 'api/')) return (bool)preg_match('~\.(?:php|sql)$~', $path);
        if (str_starts_with($path, 'assets/')) return (bool)preg_match('~\.(?:css|js|svg|png|jpe?g|webp|ico|woff2?|ttf)$~', $path);
        return !str_contains($path, '/') && ((bool)preg_match('~\.(?:js|css|html|json|php)$~', $path) || in_array($path, ['.htaccess', '.user.ini'], true));
    }
    public static function digest(string $file): ?string {
        return is_file($file) ? hash_file('sha256', $file) : null;
    }
    public static function safeTarget(string $root, string $path): string {
        self::path($path);
        self::need(str_replace('\\','/',(string)realpath($root)) === str_replace('\\','/',$root) && !is_link($root), 'UNSAFE_ROOT');
        $current = $root;
        foreach (explode('/', $path) as $part) {
            $current .= '/'.$part;
            self::need(!is_link($current), 'SYMLINK_REFUSED: '.$path);
            if (file_exists($current)) self::need(str_replace('\\','/',(string)realpath($current)) === str_replace('\\','/',$current), 'PATH_ESCAPE: '.$path);
        }
        return $current;
    }
    public static function atomic(string $path, string $bytes, int $mode = 0644): void {
        $dir = dirname($path);
        self::need(is_dir($dir) || mkdir($dir, 0755, true), 'MKDIR_FAILED');
        $temp = tempnam($dir, '.vta-release-');
        self::need($temp !== false, 'TEMP_FAILED');
        try {
            self::need(file_put_contents($temp, $bytes) === strlen($bytes), 'WRITE_FAILED');
            self::need(chmod($temp, $mode) && rename($temp, $path), 'ATOMIC_INSTALL_FAILED');
        } finally { if (is_file($temp)) unlink($temp); }
    }
    public static function json(string $path): array {
        self::need(is_file($path) && !is_link($path), 'MISSING_STATE: '.basename($path));
        return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    public static function save(string $path, array $data): void {
        self::atomic($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n", 0600);
    }
    public static function command(array $args): string {
        $process = proc_open($args, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a']], $pipes);
        self::need(is_resource($process), 'COMMAND_START_FAILED');
        fclose($pipes[0]); $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
        self::need(proc_close($process) === 0, 'COMMAND_FAILED: '.basename($args[0]));
        return (string)$text;
    }
    public static function source(string $directory, string $ref = ''): array {
        self::need(trim(self::command(['git','-C',$directory,'remote','get-url','origin'])) === self::REPO, 'WRONG_REPOSITORY');
        self::need(trim(self::command(['git','-C',$directory,'branch','--show-current'])) === self::BRANCH, 'WRONG_BRANCH');
        self::need(trim(self::command(['git','-C',$directory,'status','--porcelain','--untracked-files=all'])) === '', 'DIRTY_CACHE');
        $sha = trim(self::command(['git','-C',$directory,'rev-parse','HEAD']));
        self::need($sha === trim(self::command(['git','-C',$directory,'rev-parse','refs/remotes/origin/'.self::BRANCH])), 'HEAD_NOT_TESTING');
        self::need((bool)preg_match('/^[0-9a-f]{40}$/D', $sha), 'INVALID_COMMIT');
        // Only the verified historical baseline may be inspected outside the current testing HEAD.
        self::need($ref === '' || $ref === self::BASELINE, 'BASELINE_REF_REQUIRED');
        if ($ref !== '') self::command(['git','-C',$directory,'merge-base','--is-ancestor',$ref,$sha]);
        $tree = $ref !== '' ? $ref : $sha;
        // Preserve existing committed test evidence, but never release it or permit new logs.
        $historicalLogs = [];
        foreach (explode("\0", self::command(['git','-C',$directory,'ls-tree','-rz',self::BASELINE,'--','verification'])) as $entry) {
            if ($entry === '') continue;
            [$metadata,$path] = explode("\t",$entry,2);
            if (str_ends_with($path,'.log')) $historicalLogs[$path] = $metadata;
        }
        $files = [];
        foreach (explode("\0", self::command(['git','-C',$directory,'ls-tree','-rz',$tree])) as $entry) {
            if ($entry === '') continue;
            [$metadata, $path] = explode("\t", $entry, 2);
            self::path($path);
            // Example config is documentation; actual private config/data must never be tracked.
            self::need(self::permittedTracked($path,$metadata,$historicalLogs), 'TRACKED_SERVER_DATA: '.$path);
            if (!self::managed($path)) continue;
            self::need((bool)preg_match('/^100(?:644|755) blob [0-9a-f]{40}$/D', $metadata), 'NON_REGULAR_SOURCE: '.$path);
            $hash = $ref === '' ? self::digest(self::safeTarget($directory, $path))
                : hash('sha256', self::command(['git','-C',$directory,'show',$tree.':'.$path]));
            $files[$path] = ['source'=>$hash, 'deployed'=>$hash];
        }
        ksort($files);
        foreach (['.htaccess','index.html','api/index.php','api/bootstrap.php','api/bin/healthcheck.php','app.js','smart-costing.js','quote-confirmation.js','booking-operations.js'] as $key) self::need(isset($files[$key]), 'INCOMPLETE_RELEASE');
        return ['commit'=>$tree, 'files'=>$files];
    }
    public static function requiredExtensions(): void {
        self::need(extension_loaded('pdo_mysql') && class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true), 'PDO_MYSQL_EXTENSION_REQUIRED');
        self::need(extension_loaded('curl'), 'CURL_EXTENSION_REQUIRED');
    }
    public static function adoptionState(string $root, array $baseline, callable $readBlob): array {
        self::need(($baseline['commit']??'') === self::BASELINE, 'INITIAL_BASELINE_ONLY');
        foreach ($baseline['files'] as $path=>&$file) {
            self::need(self::managed($path), 'UNMANAGED_STATE_PATH');
            $blob = $readBlob($path);
            self::need(is_string($blob) && hash('sha256',$blob) === $file['source'], 'BASELINE_SOURCE_CHANGED: '.$path);
            $actual = (string)file_get_contents(self::safeTarget($root,$path));
            self::need($actual === $blob || (!str_starts_with($path,'api/migrations/') && str_replace("\r\n","\n",$actual) === str_replace("\r\n","\n",$blob)), 'BASELINE_DRIFT: '.$path);
            $file['deployed'] = hash('sha256',$actual);
        } unset($file);
        return $baseline;
    }
    public static function verify(string $root, array $state): void {
        foreach ($state['files'] as $path=>$file) {
            self::need(self::managed($path), 'UNMANAGED_STATE_PATH');
            self::need(self::digest(self::safeTarget($root, $path)) === $file['deployed'], 'SERVER_DRIFT: '.$path);
        }
    }
    public static function plan(string $root, array $before, array $after): array {
        self::verify($root, $before);
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before['files']), array_keys($after['files']))) as $path) {
            self::need(self::managed($path), 'UNMANAGED_CHANGE');
            $old = $before['files'][$path] ?? null; $new = $after['files'][$path] ?? null;
            $target = self::safeTarget($root, $path);
            if (str_starts_with($path, 'api/migrations/')) self::need($old !== null && $new !== null && $new['source'] === $old['source'], 'MIGRATION_REVIEW_REQUIRED: '.$path);
            if ($old === null) self::need(!file_exists($target), 'UNTRACKED_FILE_CONFLICT: '.$path);
            if ($old !== null && $new !== null && $old['source'] === $new['source']) {
                $after['files'][$path]['deployed'] = $old['deployed']; continue;
            }
            $changes[$path] = ['before'=>self::digest($target), 'after'=>$new['source'] ?? null, 'mode'=>is_file($target) ? fileperms($target)&0777 : 0644];
        }
        return [$changes, $after];
    }
    public static function ledger(PDO $db, string $root, array $target): array {
        $rows = $db->query('SELECT version,sha256,status FROM migration_checksums ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $versions = $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        self::validateLedger($root,$target,$rows,$versions);
        return $rows;
    }
    public static function validateLedger(string $root, array $target, array $rows, array $versions): void {
        $expected = [];
        foreach ($target['files'] as $path=>$file) if (str_starts_with($path, 'api/migrations/') && str_ends_with($path, '.sql')) $expected[basename($path,'.sql')] = $file['source'];
        ksort($expected);
        self::need(array_keys($expected) === $versions && array_column($rows, 'version') === $versions, 'MIGRATION_REVIEW_REQUIRED');
        foreach ($rows as $row) {
            self::need($row['status'] === 'APPLIED' && $row['sha256'] === $expected[$row['version']], 'MIGRATION_CHECKSUM_MISMATCH');
            self::need(self::digest(self::safeTarget($root, 'api/migrations/'.$row['version'].'.sql')) === $row['sha256'], 'SERVER_MIGRATION_DRIFT');
        }
    }
    public static function backup(string $directory, string $root, string $config, array $before, array $after, array $changes): array {
        self::need(!file_exists($directory) && mkdir($directory, 0700, true), 'BACKUP_CONFLICT');
        self::atomic($directory.'/config.php', (string)file_get_contents($config), 0600);
        foreach (array_unique(array_merge(array_keys($changes), ['.htaccess'])) as $path) {
            $file = self::safeTarget($root, $path);
            if (is_file($file)) {
                $bytes = (string)file_get_contents($file);
                self::atomic($directory.'/source/'.$path, $bytes, 0600);
                self::need(self::digest($directory.'/source/'.$path) === self::digest($file), 'BACKUP_VERIFICATION_FAILED');
            }
        }
        $journal = ['backup'=>basename($directory), 'before'=>$before, 'after'=>$after, 'changes'=>$changes, 'config_hash'=>self::digest($config), 'htaccess_hash'=>self::digest($root.'/.htaccess'), 'htaccess_mode'=>fileperms($root.'/.htaccess')&0777];
        self::save($directory.'/journal.json', $journal);
        self::need(self::digest($directory.'/config.php') === $journal['config_hash'], 'CONFIG_BACKUP_FAILED');
        return $journal;
    }
    public static function maintenance(string $root): void {
        self::atomic($root.'/.htaccess', "Options -Indexes\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n");
    }
    public static function restoreAccess(string $root, string $directory, array $journal): void {
        self::need(self::digest($directory.'/source/.htaccess') === $journal['htaccess_hash'], 'HTACCESS_BACKUP_DAMAGED');
        self::atomic($root.'/.htaccess', (string)file_get_contents($directory.'/source/.htaccess'), $journal['htaccess_mode']);
    }
    public static function rollback(string $root, string $directory, array $journal): void {
        self::need($journal['backup'] === basename($directory), 'WRONG_BACKUP');
        foreach ($journal['changes'] as $path=>$change) {
            self::need(self::managed($path), 'UNMANAGED_ROLLBACK_PATH');
            $target = self::safeTarget($root, $path);
            $actual = self::digest($target);
            if ($path === '.htaccess') continue;
            self::need($actual === $change['before'] || $actual === $change['after'], 'ROLLBACK_SERVER_DRIFT: '.$path);
            if ($change['before'] !== null) self::need(self::digest($directory.'/source/'.$path) === $change['before'], 'ROLLBACK_BACKUP_DAMAGED');
        }
        self::maintenance($root);
        foreach ($journal['changes'] as $path=>$change) {
            if ($path === '.htaccess') continue;
            $target = self::safeTarget($root, $path);
            if ($change['before'] === null) { if (is_file($target)) self::need(unlink($target), 'ROLLBACK_REMOVE_FAILED'); }
            else self::atomic($target, (string)file_get_contents($directory.'/source/'.$path), $change['mode']);
        }
        self::restoreAccess($root, $directory, $journal);
        self::verify($root, $journal['before']);
    }
    public static function installChanges(string $root, string $source, array $changes): void {
        foreach ($changes as $path=>$change) {
            self::need(self::managed($path), 'UNMANAGED_INSTALL_PATH');
            if ($path === '.htaccess') continue;
            $file = self::safeTarget($root,$path);
            self::need(self::digest($file) === $change['before'], 'CUTOVER_PREIMAGE_CHANGED: '.$path);
            if ($change['after'] === null) self::need(unlink($file), 'MANAGED_REMOVE_FAILED');
            else {
                $origin = self::safeTarget($source,$path);
                self::need(self::digest($origin) === $change['after'], 'SOURCE_CHANGED_AFTER_PREFLIGHT');
                self::atomic($file,(string)file_get_contents($origin),$change['mode']);
            }
        }
    }
    public static function http(string $path, int $status): string {
        $curl = curl_init(self::URL.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>25, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_ENCODING=>'']);
        $body = curl_exec($curl); $actual = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        self::need(is_string($body) && $actual === $status, 'HTTP_HEALTH_FAILED: '.$path);
        return $body;
    }
    public static function health(string $root, array $state): void {
        self::verify($root, $state);
        self::command([PHP_BINARY, '-d','opcache.enable_cli=0',$root.'/api/bin/healthcheck.php']);
        $query = '?release='.$state['commit'];
        $html = self::http('/'.$query, 200);
        self::need(stripos($html,'<!doctype html') !== false && str_contains($html,'app.js'), 'APP_HTML_FAILED');
        $health = json_decode(self::http('/api/index.php?route=health&release='.$state['commit'],200),true,512,JSON_THROW_ON_ERROR);
        self::need(($health['ok']??false) === true && ($health['service']??'') === 'VTA API', 'API_BOOTSTRAP_FAILED');
        $auth = json_decode(self::http('/api/index.php?route=auth/me',401),true,512,JSON_THROW_ON_ERROR);
        self::need(($auth['ok']??true) === false && ($auth['error']??'') === 'AUTH_REQUIRED', 'AUTH_HEALTH_FAILED');
        foreach (['app.js','smart-costing.js','quote-confirmation.js','booking-operations.js'] as $path) self::need(hash('sha256',self::http('/'.$path.$query,200)) === $state['files'][$path]['deployed'], 'STATIC_ASSET_FAILED: '.$path);
    }
    public static function run(array $args): void {
        $root = self::HOME.'/public_html'; $private = self::HOME.'/vta_private'; $control = $private.'/fast-deploy'; $source = $control.'/source'; $configPath = $private.'/config.php';
        self::need(PHP_SAPI === 'cli' && PHP_BINARY === '/usr/local/lsws/lsphp83/bin/php', 'STAGING_PHP_REQUIRED');
        self::need(function_exists('posix_geteuid') && (posix_getpwuid(posix_geteuid())['name']??'') === 'vquot8508', 'STAGING_OWNER_REQUIRED');
        foreach ([$root,$private,$control] as $path) self::need(realpath($path) === $path && !is_link($path) && fileowner($path) === posix_geteuid(), 'STAGING_PATH_FAILED');
        self::need(realpath(__FILE__) === $control.'/staging-release.php' && realpath((string)getenv('VTA_CONFIG_FILE')) === $configPath && !is_link($configPath), 'PRIVATE_CONTROLLER_REQUIRED');
        $config = require $configPath;
        self::need(($config['app']['env']??'') === 'staging' && rtrim($config['app']['base_url']??'', '/') === self::URL && rtrim($config['security']['allowed_origin']??'', '/') === self::URL, 'STAGING_CONFIG_FAILED');
        self::need(($config['db']['host']??'') === '127.0.0.1' && ($config['db']['database']??'') === 'v2qu_v2qu_vtaos', 'STAGING_DATABASE_FAILED');
        self::requiredExtensions();
        $lock = fopen($control.'/release.lock','c'); self::need($lock !== false && flock($lock,LOCK_EX|LOCK_NB), 'DEPLOY_BUSY');
        $cfg = $config['db'];
        $db = new PDO('mysql:host=127.0.0.1;port='.(int)($cfg['port']??3306).';dbname=v2qu_v2qu_vtaos;charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $name = 'vta-migrate-'.substr(hash('sha256','v2qu_v2qu_vtaos'),0,40);
        $statement = $db->prepare('SELECT GET_LOCK(?,0)'); $statement->execute([$name]); self::need((int)$statement->fetchColumn() === 1, 'MIGRATION_BUSY');
        $journalFile = $control.'/active.json'; $stateFile = $control.'/deployed.json'; $halt = $control.'/HALTED'; $mode = $args[1]??'';
        try {
            if (is_file($journalFile)) {
                $journal = self::json($journalFile); self::path($journal['backup']); $backup = $control.'/backups/'.$journal['backup'];
                self::atomic($halt,"Interrupted cutover; deployment paused.\n",0600);
                self::rollback($root,$backup,$journal); self::save($stateFile,$journal['before']);
                self::need(unlink($journalFile), 'JOURNAL_REMOVE_FAILED');
                throw new RuntimeException('INTERRUPTED_DEPLOY_ROLLED_BACK');
            }
            if ($mode === '--preflight') { echo "PASS staging identity, PDO MySQL/curl and no active cutover\n"; return; }
            if ($mode === '--rollback') {
                $id = $args[2]??''; self::need((bool)preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}-[0-9a-f]{6}$/D',$id), 'BACKUP_ID_REQUIRED');
                $directory = $control.'/backups/'.$id; $journal = self::json($directory.'/journal.json');
                self::need(self::json($stateFile)['commit'] === $journal['after']['commit'], 'ROLLBACK_NOT_CURRENT');
                self::ledger($db,$root,$journal['before']);
                self::save($journalFile,$journal); self::atomic($halt,"Manual rollback; deployment paused.\n",0600);
                self::rollback($root,$directory,$journal); self::save($stateFile,$journal['before']); self::need(unlink($journalFile), 'JOURNAL_REMOVE_FAILED');
                self::health($root,$journal['before']); echo "PASS source rollback; database/config/data untouched\n"; return;
            }
            if ($mode === '--check' || $mode === '--resume') {
                $current = self::json($stateFile); self::ledger($db,$root,$current); self::health($root,$current);
                if ($mode === '--resume' && is_file($halt)) self::need(unlink($halt), 'HALT_REMOVE_FAILED');
                echo "PASS staging health ".$current['commit']."\n"; return;
            }
            self::need(!file_exists($halt), 'DEPLOY_HALTED');
            $target = self::source($source);
            if ($mode === '--adopt') {
                self::need(($args[2]??'') === self::BASELINE && !file_exists($stateFile), 'INITIAL_BASELINE_ONLY');
                self::command(['git','-C',$source,'merge-base','--is-ancestor',self::BASELINE,$target['commit']]);
                $complete = self::json($private.'/vs24-final-complete.json');
                self::need(($complete['result']??'') === 'PASS' && ($complete['commit']??'') === self::BASELINE && ($complete['migration_035']??'') === 'PASS', 'VS24_CHECKPOINT_REQUIRED');
                // Compare live bytes to the immutable baseline tree, never to the new target.
                // The normal plan below still rejects migration changes, drift and collisions.
                $baseline = self::adoptionState($root, self::source($source,self::BASELINE),
                    fn(string $path): string => self::command(['git','-C',$source,'show',self::BASELINE.':'.$path]));
                self::ledger($db,$root,$baseline); self::health($root,$baseline); self::save($stateFile,$baseline);
                echo "PASS adopted verified VS24 baseline; no source or database writes\n";
            } else self::need($mode === '', 'UNSUPPORTED_COMMAND');
            $before = self::json($stateFile);
            self::command(['git','-C',$source,'merge-base','--is-ancestor',$before['commit'],$target['commit']]);
            [$changes,$target] = self::plan($root,$before,$target);
            $ledger = self::ledger($db,$root,$target); $configHash = self::digest($configPath);
            if ($before['commit'] === $target['commit']) { echo "UNCHANGED ".$target['commit']."\n"; return; }
            foreach ($changes as $path=>$change) if ($change['after'] !== null && str_ends_with($path,'.php')) self::command([PHP_BINARY,'-d','opcache.enable_cli=0','-l',$source.'/'.$path]);
            $directory = $control.'/backups/'.gmdate('Ymd\THis\Z').'-'.substr($target['commit'],0,12).'-'.bin2hex(random_bytes(3));
            $journal = self::backup($directory,$root,$configPath,$before,$target,$changes);
            self::save($journalFile,$journal);
            try {
                if ($changes) {
                    self::maintenance($root);
                    self::installChanges($root,$source,$changes);
                    $newAccess = isset($changes['.htaccess']) ? (string)file_get_contents($source.'/.htaccess') : (string)file_get_contents($directory.'/source/.htaccess');
                    self::command([PHP_BINARY,'-d','opcache.enable_cli=0',$root.'/api/bin/healthcheck.php']);
                    self::atomic($root.'/.htaccess',$newAccess,$journal['htaccess_mode']);
                }
                self::health($root,$target);
                self::need(self::digest($configPath) === $configHash && self::ledger($db,$root,$target) === $ledger, 'CONFIG_OR_LEDGER_CHANGED');
                self::save($stateFile,$target);
                self::save($control.'/last-result.json',['result'=>'PASS','commit'=>$target['commit'],'previous'=>$before['commit'],'files_changed'=>count($changes),'backup'=>basename($directory),'database'=>'UNCHANGED','time'=>gmdate(DATE_ATOM)]);
                self::need(unlink($journalFile), 'JOURNAL_REMOVE_FAILED');
                echo "PASS staging deploy ".$target['commit'].' ('.count($changes)." files); database unchanged\n";
            } catch (Throwable $error) {
                self::atomic($halt,"Deployment failed; source rollback required.\n",0600);
                self::rollback($root,$directory,$journal); self::save($stateFile,$before);
                self::save($control.'/last-result.json',['result'=>'FAIL','commit'=>$target['commit'],'rollback'=>$before['commit'],'error'=>'DEPLOY_OR_HEALTH_FAILED','database'=>'UNCHANGED','time'=>gmdate(DATE_ATOM)]);
                self::need(unlink($journalFile), 'JOURNAL_REMOVE_FAILED');
                throw $error;
            }
        } finally { $statement = $db->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$name]); flock($lock,LOCK_UN); fclose($lock); }
    }
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']??'') === __FILE__) {
    try { StagingRelease::run($argv); }
    catch (Throwable $error) {
        // PDO/process details can contain environment information; never emit credentials/config.
        fwrite(STDERR, "FAIL ".($error instanceof PDOException ? 'STAGING_DATABASE_ERROR' : $error->getMessage())."\n"); exit(1);
    }
}
