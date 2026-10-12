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
     * A new operator-approved staging host must be pinned twice:
     * in the private out-of-webroot config and independently through process
     * environment supplied by the staging operator. This guard does NOT
     * authorize deployment or prove the deployed Git SHA itself.
     *
     * @param array<string,mixed> $server
     * @param array<string,string> $pins
     */
    public static function isolatedStaging(array $config,array $server,array $pins): void {
        $deny=static function():never {throw new RuntimeException('UNSAFE_ISOLATED_STAGING_BINDING');};
        $app=$config['app']??[];$db=$config['db']??[];$security=$config['security']??[];
        $binding=$security['staging_guard']??null;
        if(!is_array($binding)||($app['env']??null)!=='staging')$deny();
        $base=$app['base_url']??null;
        if(!is_string($base)||$base===''||rtrim($base,'/')==='')$deny();
        $parts=parse_url($base);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'
            ||isset($parts['user'],$parts['pass'])||isset($parts['user'])
            ||isset($parts['port'])||isset($parts['query'])||isset($parts['fragment'])
            ||!in_array($parts['path']??'',['','/'],true))$deny();
        $host=$parts['host']??null;
        if(!is_string($host)
            ||!preg_match('/^[a-z0-9][a-z0-9.-]{5,190}\.(?:vietnamtraveladvisor\.com\.vn|vietnamtraveladvisor\.vn)$/D',$host)
            ||!preg_match('/(?:^|[.-])stag(?:e|ing)(?:[.-]|$)/D',$host)
            ||preg_match('/(?:^|[.-])(?:prod|production|live)(?:[.-]|$)/D',$host)
            ||in_array($host,['quote.vietnamtraveladvisor.com.vn','v2quote.vietnamtraveladvisor.com.vn'],true))$deny();
        $clone=$db['database']??null;$sha=$binding['source_sha']??null;
        if(!is_string($clone)||!preg_match('/^[A-Za-z0-9_]{6,64}$/D',$clone)
            ||!preg_match('/(?:^|_)(?:stage|staging|clone|sandbox|rc62)(?:_|$)/i',$clone)
            ||preg_match('/(?:^|_)(?:prod|production|live)(?:_|$)/i',$clone)
            ||str_starts_with(strtolower($clone),'v2qu_v2qu_vtaos')
            ||!is_string($sha)||!preg_match('/^[a-f0-9]{40}$/D',$sha))$deny();
        $pinHost=$pins['host']??null;$pinDb=$pins['db']??null;$pinSha=$pins['sha']??null;
        if(!is_string($pinHost)||!is_string($pinDb)||!is_string($pinSha)
            ||!hash_equals($host,$pinHost)||!hash_equals($clone,$pinDb)
            ||!hash_equals($sha,$pinSha)
            ||!hash_equals($host,(string)($binding['host']??''))
            ||!hash_equals($clone,(string)($binding['database']??'')))$deny();
        if(($security['allowed_origin']??null)!=='https://'.$host
            ||!in_array($db['host']??null,['127.0.0.1','localhost'],true))$deny();
        $root=realpath(dirname(__DIR__,2));
        $configuredRoot=$binding['docroot']??null;
        if(!$root||!is_string($configuredRoot)
            ||!realpath($configuredRoot)||!hash_equals($root,(string)realpath($configuredRoot))
            ||preg_match('~(?:^|/)(?:v2quote|quote)\.vietnamtraveladvisor\.com\.vn(?:/|$)~i',$root))$deny();
        // PHP-FPM/OpenLiteSpeed may populate HTTP_HOST/DOCUMENT_ROOT only for
        // real HTTP requests. Never trust forwarded HTTPS or forwarded hosts.
        if(isset($server['HTTP_HOST'])||isset($server['DOCUMENT_ROOT'])){
            if(($server['HTTP_HOST']??null)!==$host
                ||!in_array(strtolower((string)($server['HTTPS']??'')),['on','1'],true)
                ||!isset($server['DOCUMENT_ROOT'])
                ||realpath((string)$server['DOCUMENT_ROOT'])!==$root)$deny();
        }
    }

    public static function config(string $path,array $config): void {
        self::privatePath($path);
        $base=$config['app']['base_url']??null;
        $host=is_string($base)?strtolower((string)parse_url($base,PHP_URL_HOST)):'';
        if(!in_array($config['app']['env']??null,['staging','testing'],true))
            throw new RuntimeException('UNSAFE_STAGING_ENV');
        // Preserve the existing, historically reviewed legacy test/staging
        // vhost and disposable localhost contracts; do not alter deployment pin.
        if(in_array($host,['v2quote.vietnamtraveladvisor.com.vn','127.0.0.1','localhost'],true))
            return;
        self::isolatedStaging($config,$_SERVER,[
            'host'=>(string)(getenv('VTA_STAGING_PIN_HOST')?:''),
            'db'=>(string)(getenv('VTA_STAGING_PIN_DB')?:''),
            'sha'=>(string)(getenv('VTA_STAGING_PIN_SHA')?:'')
        ]);
    }
}
