<?php
declare(strict_types=1);

final class Migrations {
    /**
     * The marketing SQL filenames were authored before the already-deployed
     * 025–036 quotation upgrades. Running them alphabetically before
     * 025_vs21_shared_requirements would collide with its deliberate legacy
     * candidate-history check. This is an execution plan, NOT an edit to any
     * applied SQL file or to historical schema_migrations rows.
     *
     * Only these exact reviewed names are deferred; an unrelated 023/024
     * candidate must still be rejected by the existing VS2.1 guard.
     */
    private const MARKETING_DEFERRED=[
        '023_marketing_webhook_core',
        '024_website_unified_inbox',
        '025_website_chat_outbound',
        '037_marketing_tour_advisor_p3',
        '038_marketing_tour_share_p4',
        '039_social_publishing_core',
        '040_instagram_publishing',
        '041_meta_unified_inbox',
        '042_meta_messenger_replies',
        '043_meta_connection_health'
    ];

    /** @return list<string> Full paths, core SQL first, marketing after 036. */
    public static function orderedFiles(string $directory):array {
        $files=glob(rtrim($directory,'/').'/*.sql')?:[];
        sort($files,SORT_STRING);
        $deferred=[];$core=[];
        foreach($files as $file) {
            $name=basename($file,'.sql');
            if(in_array($name,self::MARKETING_DEFERRED,true))$deferred[$name]=$file;
            else $core[]=$file;
        }
        // Never apply a partial stack, or accidentally use a different SQL
        // version with the same marketing prefix. Fresh core-only installs are
        // allowed; a present marketing stack must be complete and reviewed.
        if($deferred && count($deferred)!==count(self::MARKETING_DEFERRED))
            throw new RuntimeException('Partial marketing migration stack; execution refused');
        if($deferred) {
            if(!in_array($directory.'/025_vs21_shared_requirements.sql',$core,true) &&
               !in_array(rtrim($directory,'/').'/025_vs21_shared_requirements.sql',$core,true))
                throw new RuntimeException('VS2.1 prerequisite missing from marketing upgrade');
            foreach(self::MARKETING_DEFERRED as $name)$core[]=$deferred[$name];
        }
        return $core;
    }

    public static function run(PDO $db,string $directory): array {
        $lock='vta-migrate-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$db->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);
        if((int)$s->fetchColumn()!==1) throw new RuntimeException('Another migration is running');
        $applied=[];
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $db->exec("CREATE TABLE IF NOT EXISTS migration_checksums (version VARCHAR(64) PRIMARY KEY,sha256 CHAR(64) NOT NULL,status ENUM('RUNNING','APPLIED','FAILED') NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $files=self::orderedFiles($directory);
            foreach($files as $file) {
                $version=basename($file,'.sql');$hash=hash_file('sha256',$file);
                if($version==='025_vs21_shared_requirements') {
                    // Apply the legacy candidate-history guard only BEFORE the
                    // first 025 upgrade. A legitimate 025-applied DB will later
                    // include Marketing 023/024 entries and must stay re-runnable.
                    $previous025=$db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');
                    $previous025->execute([$version]);
                    if(!$previous025->fetchColumn()) {
                    $bad=$db->query("SELECT version FROM schema_migrations WHERE version REGEXP '^02[34]_' LIMIT 1")->fetchColumn();
                    if($bad)throw new RuntimeException('Candidate 023/024 history requires an approved adapter; VS2.1 upgrade stopped');
                    $candidate=$db->query("SHOW COLUMNS FROM quote_options LIKE 'costing_mode'")->fetch();
                    if($candidate)throw new RuntimeException('Candidate quote option schema detected; upgrade stopped');
                    $index=$db->query("SHOW INDEX FROM quote_options WHERE Key_name='uq_option_level'")->fetchAll(PDO::FETCH_ASSOC);
                    if(array_column($index,'Column_name')!==['quote_version_id','hotel_level'])throw new RuntimeException('Historical hotel option unique index mismatch; upgrade stopped');
                    }
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
