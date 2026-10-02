<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI fixture only');
$cfg=require (getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/runtime/http-config.php');$c=$cfg['db'];
if(($cfg['app']['env']??'')!=='testing'||$c['host']!=='127.0.0.1'||(int)$c['port']!==33317||!str_starts_with($c['database'],'vta_http_'))throw new RuntimeException('Disposable local HTTP fixture required');
$db=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$suffix=bin2hex(random_bytes(4));$email='review-'.$suffix.'@example.invalid';
$q=$db->prepare('INSERT INTO roles(company_id,code,name) VALUES(1,?,?)');$q->execute(['DOC_'.$suffix,'Document review only']);$role=(int)$db->lastInsertId();
$q=$db->prepare("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code IN ('document.view','document.review')");$q->execute([$role]);
$q=$db->prepare('INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,?,?,?,?)');$q->execute([$role,'Document review fixture',$email,password_hash('Local-Http-Test-2026',PASSWORD_DEFAULT)]);
echo json_encode(['email'=>$email],JSON_THROW_ON_ERROR);
