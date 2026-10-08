<?php
declare(strict_types=1);

/**
 * Read-only acceptance gate for stacked Marketing P0-P9.
 * It NEVER migrates databases, copies source, edits files, enables workers,
 * starts OAuth, or sends network requests to a customer/Meta.
 */
final class MarketingStagingGate {
    public const STAGES=[
        '023_marketing_webhook_core.sql',
        '024_website_unified_inbox.sql',
        '025_website_chat_outbound.sql',
        '037_marketing_tour_advisor_p3.sql',
        '038_marketing_tour_share_p4.sql',
        '039_social_publishing_core.sql',
        '040_instagram_publishing.sql',
        '041_meta_unified_inbox.sql',
        '042_meta_messenger_replies.sql',
        '043_meta_connection_health.sql'
    ];
    public const REQUIRED=[
        'api/lib/WebsiteInbox.php',
        'api/lib/WebsiteChatDelivery.php',
        'api/lib/MarketingTourAdvisor.php',
        'api/lib/MarketingTourShare.php',
        'api/lib/SocialPublishing.php',
        'api/lib/InstagramImagePoster.php',
        'api/lib/MetaInbox.php',
        'api/lib/MetaReplies.php',
        'api/lib/MetaConnectionHealth.php',
        'api/index.php','api/bootstrap.php',
        'social-inbox.js','social-publishing.js',
        'marketing-studio.js','meta-connections.js',
        'deploy/staging-release.php',
        'deploy/deploy-staging.sh',
        'api/lib/Migrations.php'
    ];
    private static function item(string $id,string $level,string $description):array{
        return ['id'=>$id,'level'=>$level,'description'=>$description];
    }
    private static function read(string $root,string $path):string{
        $file=$root.'/'.$path;
        if(!is_file($file)||is_link($file))throw new RuntimeException('Missing or unsafe source path: '.$path);
        $text=file_get_contents($file);
        if(!is_string($text))throw new RuntimeException('Unreadable source path: '.$path);
        return $text;
    }
    public static function inspectSource(string $root):array {
        $items=[];
        foreach(array_merge(self::STAGES,self::REQUIRED) as $file){
            $name=str_ends_with($file,'.sql')?'api/migrations/'.$file:$file;
            try{self::read($root,$name);}
            catch(Throwable $e){$items[]=self::item('MISSING_SOURCE','BLOCK',$name); }
        }
        if($items)return $items;
        $files=glob($root.'/api/migrations/*.sql')?:[];
        sort($files,SORT_STRING);
        $versions=array_map(static fn(string $v)=>basename($v),$files);
        $indexes=[];
        foreach(self::STAGES as $version){
            $at=array_search($version,$versions,true);
            if($at===false){$items[]=self::item('MISSING_MIGRATION','BLOCK',$version);continue;}
            $indexes[]=$at;
        }
        if($indexes!==array_values(array_unique($indexes))||$indexes!==array_values(array_filter($indexes,static fn($v)=>is_int($v))))
            $items[]=self::item('MIGRATION_ORDER','BLOCK','Marketing migrations are not unique');
        if($indexes!==array_values(array_map('intval',$indexes))||$indexes!==array_values(array_unique($indexes)))
            $items[]=self::item('MIGRATION_SEQUENCE','BLOCK','Unexpected migration sequence');
        $ordered=$indexes;$sorted=$indexes;sort($sorted,SORT_NUMERIC);
        if($ordered!==$sorted)
            $items[]=self::item('MIGRATION_SEQUENCE','BLOCK','Marketing dependency sequence does not follow migration filename order');
        $migrator=self::read($root,'api/lib/Migrations.php');
        $preflight=self::read($root,'deploy/staging-release.php');
        $script=self::read($root,'deploy/deploy-staging.sh');
        // Release controller cannot yet install an added migration. Keep BLOCK until
        // a separately reviewed DB-forward migration and release adapter is approved.
        if(str_contains($preflight,'MIGRATION_REVIEW_REQUIRED')&&str_contains($preflight,"str_starts_with(\$path, 'api/migrations/')"))
            $items[]=self::item('STAGING_DEPLOY_MIGRATIONS','BLOCK','Fast deploy controller refuses any new SQL migration until a reviewed forward-application process exists');
        if(str_contains($preflight,"public const BRANCH = 'codex/Vietnam/rc6.2-testing'") &&
           str_contains($script,'BRANCH=codex/Vietnam/rc6.2-testing'))
            $items[]=self::item('STAGING_BRANCH_PIN','BLOCK','Deployment is pinned to rc6.2-testing; stacked Marketing PR code has not been promoted and verified there');
        // Historical VS2.1 guard is not compatible with applying the candidate 023/024
        // before 025. Never bypass or amend historical SQL checksums silently.
        if(str_contains($migrator,"if(\$version==='025_vs21_shared_requirements')") &&
           str_contains($migrator,"version REGEXP '^02[34]_'"))
            $items[]=self::item('VS21_MIGRATION_GUARD','BLOCK','Migrator blocks 025_vs21_shared_requirements when 023/024 candidate history exists; requires independently reviewed schema reconciliation');
        $seen=[];
        foreach(self::STAGES as $file){
            $sql=self::read($root,'api/migrations/'.$file);
            $seen[$file]=hash('sha256',$sql);
            if(!preg_match('/(?:CREATE TABLE|ALTER TABLE)/i',$sql))
                $items[]=self::item('EMPTY_MIGRATION','BLOCK',$file.' contains no DDL');
        }
        $items[]=self::item('MIGRATION_HASH_MANIFEST','INFO','Source SQL hashes captured in independent manifest; existing applied migration checksums must remain unchanged');
        $items[]=self::item('SCOPE','INFO','Preflight is read-only; no database access unless --db is explicitly supplied, and no real Meta/website messages are sent');
        return $items;
    }
    public static function inspectLedger(PDO $db,string $root):array {
        $items=[];
        try{
            $rows=$db->query("SELECT version,sha256,status FROM migration_checksums")->fetchAll(PDO::FETCH_ASSOC);
            $applied=$db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        }catch(Throwable $e){
            return [self::item('LEDGER_UNAVAILABLE','BLOCK','Database migration ledger is missing or inaccessible')];
        }
        $applied=array_flip(array_map('strval',$applied));
        $known=[];
        foreach($rows as $row)$known[(string)$row['version']]=$row;
        foreach(self::STAGES as $filename) {
            $version=basename($filename,'.sql');
            $hash=hash_file('sha256',$root.'/api/migrations/'.$filename);
            $row=$known[$version]??null;
            if($row!==null&&($row['status']!=='APPLIED'||!hash_equals((string)$row['sha256'],(string)$hash)))
                $items[]=self::item('LEDGER_DRIFT','BLOCK',$version.' checksum/status mismatch');
            if(!isset($applied[$version]))
                $items[]=self::item('PENDING_MIGRATION','BLOCK',$version.' not applied to staging');
            elseif($row===null)
                $items[]=self::item('UNVERIFIED_BASELINE','BLOCK',$version.' applied without a tracked checksum');
        }
        return $items;
    }
    public static function inspectRuntime(array $config,string $host):array {
        $checks=[];
        $url=(string)($config['app']['base_url']??'');
        $configuredHost=parse_url($url,PHP_URL_HOST);
        if(($config['app']['env']??'')!=='staging'||$configuredHost!==$host
          ||!in_array($host,['v2quote.vietnamtraveladvisor.com.vn','localhost','127.0.0.1'],true))
            $checks[]=self::item('HOST_GUARD','BLOCK','Requested target is not the allowlisted staging host');
        if(($config['integrations']['social_publishing']['enabled']??false)===true)
            $checks[]=self::item('SOCIAL_SEND_ENABLED','BLOCK','Social publishing must be disabled during read-only acceptance');
        if(($config['integrations']['meta_inbox']['enabled']??false)===true)
            $checks[]=self::item('META_INBOUND_ENABLED','WARN','Inbound Meta account integration is enabled; verify webhook isolation, consent and test-only accounts');
        if(($config['integrations']['meta_connection_check']['enforce_messenger_verified']??false)===true)
            $checks[]=self::item('MESSENGER_CHECK','INFO','Messenger requires a recent verified Meta health status in addition to Page/task/window checks');
        return $checks;
    }
    public static function summary(array $items):array {
        $block=array_values(array_filter($items,static fn($v)=>($v['level']??'')==='BLOCK'));
        $warn=array_values(array_filter($items,static fn($v)=>($v['level']??'')==='WARN'));
        return ['safe_to_release'=>count($block)===0,
            'blockers'=>count($block),'warnings'=>count($warn),
            'checks'=>$items];
    }
}
