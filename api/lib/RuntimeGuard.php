<?php
declare(strict_types=1);

final class RuntimeGuard {
    public static function inside(string $path,string $root): bool {
        $path=str_replace('\\','/',rtrim($path,'/\\'));$root=str_replace('\\','/',rtrim($root,'/\\'));
        if(PHP_OS_FAMILY==='Windows'){$path=strtolower($path);$root=strtolower($root);}return $path===$root||str_starts_with($path,$root.'/');
    }
    public static function privatePath(string $path): void {
        $root=realpath(dirname(__DIR__,2));$resolved=realpath($path);
        if(!$resolved||!$root||self::inside($resolved,$root))throw new RuntimeException('Private path must resolve outside the public application directory');
    }
    /**
     * Default allowlist remains unchanged for the existing staging deployment
     * controller and localhost disposable CI. A different, isolated hostname
     * is opt-in and requires an independently pinned exact host + clone DB.
     * No public request parameter, forwarded header or wildcard can enable it.
     */
    public static function config(string $path,array $config): void {
        self::privatePath($path);
        $base=(string)($config['app']['base_url']??'');
        $host=strtolower((string)parse_url($base,PHP_URL_HOST));
        $env=$config['app']['env']??'';
        if(!in_array($env,['staging','testing'],true))
            throw new RuntimeException('STAGING_ENV_REQUIRED');
        if(in_array($host,['v2quote.vietnamtraveladvisor.com.vn','127.0.0.1','localhost'],true))
            return; // Preserve historical deployment and fixture behavior.
        self::isolatedStaging($config,$host,$base);
    }

    /** No automatic activation: the operator must provision these private pins. */
    public static function isolatedStaging(array $config,string $host,string $base): void {
        $fail=static function():never{
            throw new RuntimeException('ISOLATED_STAGING_HOST_NOT_APPROVED');
        };
        if(($config['app']['env']??'')!=='staging')$fail();
        // Explicit stage/staging DNS label only, not a substring in production.
        if(!preg_match('/^[a-z0-9][a-z0-9.-]{3,250}[a-z0-9]$/D',$host)
            || !preg_match('/(?:^|[.-])stag(?:e|ing)(?:[.-]|$)/D',$host)
            || preg_match('/(?:^|[.-])(?:prod|production|live)(?:[.-]|$)/D',$host)
            || filter_var($host,FILTER_VALIDATE_IP)
            || in_array($host,['quote.vietnamtraveladvisor.com.vn','v2quote.vietnamtraveladvisor.com.vn'],true))
            $fail();
        // Exact HTTPS canonical URL, no port, credentials, path, query or fragment.
        $origin='https://'.$host;
        if(rtrim($base,'/')!==$origin
            || ($config['security']['allowed_origin']??'')!==$origin
            || ($config['security']['approved_staging_host']??'')!==$host
            || getenv('VTA_RC62_STAGING_HOST_PIN')!==$host)
            $fail();
        // A real approved clone, never the historical v2quote database.
        $db=$config['db']??[];
        $dbName=$db['database']??'';
        if(($db['host']??'')!=='127.0.0.1'
            || !is_string($dbName)
            || !preg_match('/^[A-Za-z0-9_]{6,64}$/D',$dbName)
            || !preg_match('/(?:^|_)(?:clone|staging|stage|sandbox|test|rc62)(?:_|$)/iD',$dbName)
            || preg_match('/(?:^|_)(?:prod|production|live)(?:_|$)/iD',$dbName)
            || str_starts_with(strtolower($dbName),'v2qu_v2qu_vtaos')
            || getenv('VTA_RC62_STAGING_DB_PIN')!==$dbName)
            $fail();
        // An isolated clone must NEVER share production media, even if the
        // old storage lives outside the application source/public_html.
        // Stage runs local-private only; enabling Google Drive would risk
        // reusing a production OAuth account, folder and supplier images.
        if(($config['storage']['driver']??'')!=='local')$fail();
        $dir=(string)($config['storage']['local_path']??'');
        try{
            self::privatePath($dir);
            $resolved=str_replace('\\','/',(string)realpath($dir));
        }catch(Throwable){$fail();}
        if($resolved==='' || !str_starts_with($resolved,'/')
            || preg_match('~(?:^|/)(?:public_html|www|htdocs)(?:/|$)~i',$resolved)
            || preg_match('~(?:^|[/_.-])v2quote(?:[/_.-]|$)~i',$resolved)
            || !preg_match('~(?:^|[/_.-])stag(?:e|ing)(?:[/_.-]|$)~i',$resolved)
            || getenv('VTA_RC62_STAGING_STORAGE_PIN')!==$resolved)
            $fail();
        // Do not trust X-Forwarded-Host or client supplied proxies. PHP-FPM
        // should receive actual HTTPS and the independently approved Host.
        if(PHP_SAPI!=='cli' && (!isset($_SERVER['HTTP_HOST']) || !isset($_SERVER['HTTPS'])))
            $fail();
        if(isset($_SERVER['HTTP_HOST']) && strtolower((string)$_SERVER['HTTP_HOST'])!==$host)
            $fail();
        if(isset($_SERVER['HTTPS']) && !in_array(strtolower((string)$_SERVER['HTTPS']),['on','1'],true))
            $fail();
    }
}
