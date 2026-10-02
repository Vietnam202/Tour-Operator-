<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/RuntimeGuard.php';
function assertGuard(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
assertGuard(RuntimeGuard::inside('/srv/app/file','/srv/app'),'nested file recognized');
assertGuard(!RuntimeGuard::inside('/srv/application/file','/srv/app'),'sibling prefix is not public root');
try{RuntimeGuard::privatePath(__DIR__.'/../api/config.example.php');throw new LogicException('Accepted public config');}catch(RuntimeException $e){assertGuard(true,'public-root configuration rejected');}
$tmp=tempnam(sys_get_temp_dir(),'vta-config-test-');
try{
 RuntimeGuard::config($tmp,['app'=>['env'=>'testing','base_url'=>'http://127.0.0.1:8873']]);assertGuard(true,'private local test configuration accepted');
 try{RuntimeGuard::config($tmp,['app'=>['env'=>'production','base_url'=>'https://quote.vietnamtraveladvisor.com.vn']]);throw new LogicException('Accepted production');}catch(RuntimeException $e){assertGuard(true,'production configuration rejected without connecting');}
}finally{unlink($tmp);}
