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
    public static function config(string $path,array $config): void {
        self::privatePath($path);$host=strtolower((string)parse_url($config['app']['base_url']??'',PHP_URL_HOST));
        if(!in_array($config['app']['env']??'',['staging','testing'],true)||!in_array($host,['v2quote.vietnamtraveladvisor.com.vn','127.0.0.1','localhost'],true))throw new RuntimeException('This build is restricted to staging and local testing');
    }
}
