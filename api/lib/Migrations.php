<?php
declare(strict_types=1);

final class Migrations {
    public static function run(PDO $db,string $directory): array {
        $lock='vta-migrate-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$db->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);
        if((int)$s->fetchColumn()!==1) throw new RuntimeException('Another migration is running');
        $applied=[];
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $db->exec("CREATE TABLE IF NOT EXISTS migration_checksums (version VARCHAR(64) PRIMARY KEY,sha256 CHAR(64) NOT NULL,status ENUM('RUNNING','APPLIED','FAILED') NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $files=glob($directory.'/*.sql')?:[];sort($files,SORT_STRING);
            foreach($files as $file) {
                $version=basename($file,'.sql');$hash=hash_file('sha256',$file);
                if($version==='025_vs21_shared_requirements') {
                    $bad=$db->query("SELECT version FROM schema_migrations WHERE version REGEXP '^02[34]_' LIMIT 1")->fetchColumn();
                    if($bad)throw new RuntimeException('Candidate 023/024 history requires an approved adapter; VS2.1 upgrade stopped');
                    $candidate=$db->query("SHOW COLUMNS FROM quote_options LIKE 'costing_mode'")->fetch();
                    if($candidate)throw new RuntimeException('Candidate quote option schema detected; upgrade stopped');
                }
                $s=$db->prepare('SELECT * FROM migration_checksums WHERE version=?');$s->execute([$version]);$tracked=$s->fetch(PDO::FETCH_ASSOC);
                if($tracked && !hash_equals($tracked['sha256'],$hash)) throw new RuntimeException('Migration checksum changed: '.$version);
                if($tracked && $tracked['status']!=='APPLIED') throw new RuntimeException('Partial migration requires review before retry: '.$version);
                $s=$db->prepare('SELECT version FROM schema_migrations WHERE version=?');$s->execute([$version]);
                if($s->fetchColumn()) {
                    // Existing v2.4 migration hashes are baselined on the first upgrade.
                    $db->prepare("INSERT IGNORE INTO migration_checksums(version,sha256,status) VALUES(?,?,'APPLIED')")->execute([$version,$hash]);continue;
                }
                $db->prepare("INSERT INTO migration_checksums(version,sha256,status) VALUES(?,?,'RUNNING')")->execute([$version,$hash]);
                try {
                    $sql=file_get_contents($file);if($sql===false) throw new RuntimeException('Cannot read migration');
                    // Project migrations use one statement per semicolon/newline; routines are not supported.
                    foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $statement) $db->exec($statement);
                    $db->prepare('INSERT IGNORE INTO schema_migrations(version) VALUES(?)')->execute([$version]);
                    $db->prepare("UPDATE migration_checksums SET status='APPLIED' WHERE version=?")->execute([$version]);$applied[]=$version;
                } catch(Throwable $e) {
                    $db->prepare("UPDATE migration_checksums SET status='FAILED' WHERE version=?")->execute([$version]);
                    throw new RuntimeException('Migration failed; DDL may have partially committed: '.$version,0,$e);
                }
            }
        } finally { $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]); }
        return $applied;
    }
}
