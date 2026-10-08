<?php
declare(strict_types=1);
require __DIR__.'/../api/lib/Migrations.php';
function need(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 echo 'PASS '.$label.PHP_EOL;
}
$db=new PDO(getenv('VTA_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=vta_ci;charset=utf8mb4',
    getenv('VTA_TEST_USER')?:'vta',getenv('VTA_TEST_PASSWORD')?:'vta_ci_pw',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("CREATE TABLE quote_options (quote_version_id BIGINT NOT NULL,hotel_level VARCHAR(16) NOT NULL,
    UNIQUE KEY uq_option_level(quote_version_id,hotel_level))");
$dir=sys_get_temp_dir().'/vta-marketing-migration-'.bin2hex(random_bytes(6));
if(!mkdir($dir,0700))throw new RuntimeException('Cannot create disposable SQL fixture');
$names=[
    '023_marketing_webhook_core','024_website_unified_inbox','025_website_chat_outbound',
    '037_marketing_tour_advisor_p3','038_marketing_tour_share_p4',
    '039_social_publishing_core','040_instagram_publishing','041_meta_unified_inbox',
    '042_meta_messenger_replies','043_meta_connection_health'
];
try{
    file_put_contents($dir.'/025_vs21_shared_requirements.sql',
        "CREATE TABLE core_schema_025(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;\n");
    foreach($names as $i=>$name)
        file_put_contents($dir.'/'.$name.'.sql',
            "CREATE TABLE test_marketing_".($i+1)."(id INT PRIMARY KEY) ENGINE=InnoDB;\n");
    $files=Migrations::orderedFiles($dir);
    $versions=array_map(static fn($p)=>basename($p,'.sql'),$files);
    need($versions[0]==='025_vs21_shared_requirements'&&$versions[1]==='023_marketing_webhook_core'
        &&end($versions)==='043_meta_connection_health',
        'Marketing 023/024 follows legacy 025 and preserves reviewed internal sequence');
    $applied=Migrations::run($db,$dir);
    need(count($applied)===11&&$applied[0]==='025_vs21_shared_requirements',
        'MariaDB installs all eleven fixture stages in safe dependency order');
    need((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===11,
        'each applied version is recorded exactly once');
    need((int)$db->query("SELECT COUNT(*) FROM migration_checksums WHERE status='APPLIED'")->fetchColumn()===11,
        'all first-apply exact SHA256 values are recorded');
    need(Migrations::run($db,$dir)===[],
        're-running migrator after marketing 023/024 does not falsely trip VS2.1 guard');
    need((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===11,
        'idempotent repeat does not duplicate ledger rows');
    // Test a tampered previously-applied SQL version: no DDL may run.
    $file=$dir.'/038_marketing_tour_share_p4.sql';
    file_put_contents($file,str_replace('ENGINE=InnoDB','ENGINE=MyISAM',(string)file_get_contents($file)));
    $drift=false;try{Migrations::run($db,$dir);}
    catch(RuntimeException $e){$drift=str_contains($e->getMessage(),'Migration checksum changed');}
    need($drift,'applied migration checksum mismatch blocks upgrades');
    // Validate the historical 023/024 candidate guard remains active BEFORE
    // applying 025 on a separate logical sequence (ledger reset in fixture DB).
    file_put_contents($file,"CREATE TABLE test_marketing_5(id INT PRIMARY KEY) ENGINE=InnoDB;\n");
    $db->exec('DELETE FROM migration_checksums');
    $db->exec('DELETE FROM schema_migrations');
    $db->exec("INSERT INTO schema_migrations(version) VALUES('023_unapproved_candidate')");
    $rejected=false;try{Migrations::run($db,$dir);}
    catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'Candidate 023/024 history');}
    need($rejected,'historical unrelated 023 candidate cannot bypass VS2.1 guard');
    // Verify partial Marketing feature stack cannot silently run.
    unlink($dir.'/040_instagram_publishing.sql');
    $partial=false;try{Migrations::orderedFiles($dir);}
    catch(RuntimeException $e){$partial=str_contains($e->getMessage(),'Partial marketing');}
    need($partial,'partial Marketing SQL bundle is refused before DDL');
    echo "P11 migration-planner MariaDB integration checks complete.\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $file)unlink($file);
    rmdir($dir);
}
