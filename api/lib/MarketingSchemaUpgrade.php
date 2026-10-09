<?php
declare(strict_types=1);
require_once __DIR__.'/Migrations.php';

/**
 * P12: fail-closed, forward-only Marketing schema rehearsal.
 *
 * A source manifest may be inspected anywhere. DB inspection is READ ONLY.
 * The ONLY write path here is an explicitly authorized disposable local clone
 * with vta_p12_clone_* DB name. Staging / production are never writable.
 */
final class MarketingSchemaUpgrade {
    private const MARKETING=[
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
    public static function manifest(string $directory):array {
        $files=Migrations::orderedFiles($directory);
        $core=[];$marketing=[];$all=[];
        foreach($files as $file) {
            if(!is_file($file)||is_link($file)||!is_readable($file))
                throw new RuntimeException('Untrusted migration path');
            $version=basename($file,'.sql');
            $hash=hash_file('sha256',$file);
            if(!is_string($hash))throw new RuntimeException('Cannot hash migration');
            if(isset($all[$version]))throw new RuntimeException('Duplicate migration version');
            $all[$version]=$hash;
            if(in_array($version,self::MARKETING,true))$marketing[$version]=$hash;
            else $core[$version]=$hash;
        }
        if(array_keys($marketing)!==self::MARKETING)
            throw new RuntimeException('Reviewed Marketing migration stack is incomplete or misordered');
        if(!isset($core['025_vs21_shared_requirements'])||
           !isset($core['036_tour_library_proposal_studio']))
            throw new RuntimeException('VS2.1/VS2.4 schema prerequisite unavailable');
        // Encode ordered (version, SHA-256) pairs so the digest changes if either
        // source content OR approved execution order changes.
        $pairs=[];
        foreach($all as $version=>$hash)$pairs[]=[$version,$hash];
        $fingerprint=hash('sha256',json_encode($pairs,JSON_THROW_ON_ERROR));
        return ['fingerprint'=>$fingerprint,'core'=>$core,'marketing'=>$marketing,'ordered_versions'=>array_keys($all)];
    }
    public static function inspect(PDO $db,array $manifest):array {
        $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        if($database==='')throw new RuntimeException('Database not selected');
        try {
            $history=$db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $tracked=$db->query('SELECT version,sha256,status FROM migration_checksums')->fetchAll(PDO::FETCH_ASSOC);
        }catch(Throwable $e){
            throw new RuntimeException('Migration ledger missing; P12 must never create or baseline ledger on staging',0,$e);
        }
        $applied=array_fill_keys(array_map('strval',$history),true);
        $ledger=[];
        foreach($tracked as $entry) {
            $version=(string)$entry['version'];
            if(isset($ledger[$version]))throw new RuntimeException('Repeated migration checksum row');
            $ledger[$version]=$entry;
        }
        $expected=array_merge($manifest['core'],$manifest['marketing']);
        foreach($applied as $version=>$_) {
            if(!isset($expected[$version]))throw new RuntimeException('Unknown applied schema version; manual reconciliation required');
        }
        foreach($ledger as $version=>$row) {
            if(!isset($expected[$version]))throw new RuntimeException('Unknown checksum entry; manual reconciliation required');
            if(($row['status']??null)!=='APPLIED')throw new RuntimeException('Unresolved RUNNING/FAILED migration; manual recovery required');
            if(!hash_equals($expected[$version],(string)$row['sha256']))
                throw new RuntimeException('Historical migration source checksum drift');
            if(!isset($applied[$version]))throw new RuntimeException('Tracked migration missing from applied ledger');
        }
        // P12 never silently baselines historical SQL checksums. Any untracked
        // installed migration is a hard blocker until a separate DBA review.
        foreach($applied as $version=>$_)
            if(!isset($ledger[$version]))throw new RuntimeException('Applied migration lacks reviewed checksum');

        $coreVersions=array_keys($manifest['core']);
        foreach($coreVersions as $version)
            if(!isset($applied[$version]))
                throw new RuntimeException('Core prerequisite '.$version.' not applied and verified');

        $marketingVersions=array_keys($manifest['marketing']);
        $pending=[];
        $gap=false;
        foreach($marketingVersions as $version) {
            if(isset($applied[$version])) {
                if($gap)throw new RuntimeException('Non-contiguous partial Marketing migration history');
            } else {
                $gap=true;$pending[]=$version;
            }
        }
        return ['database'=>$database,'manifest'=>$manifest['fingerprint'],
            'core_verified'=>count($coreVersions),'marketing_applied'=>count($marketingVersions)-count($pending),
            'pending'=>$pending,'ready_for_clone_rehearsal'=>true];
    }
    public static function cloneOnly(PDO $db,array $config):void {
        $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        $configured=(string)($config['db']['database']??'');
        $host=strtolower((string)($config['db']['host']??''));
        $appHost=strtolower((string)parse_url((string)($config['app']['base_url']??''),PHP_URL_HOST));
        if($name!==$configured
          ||preg_match('/^vta_p12_clone_[a-z0-9_]{2,40}$/D',$name)!==1
          ||!in_array($host,['127.0.0.1','localhost'],true)
          ||!in_array($appHost,['127.0.0.1','localhost'],true)
          ||($config['app']['env']??'')!=='testing'
          ||($config['integrations']['social_publishing']['enabled']??false)===true
          ||($config['integrations']['meta_inbox']['enabled']??false)===true)
            throw new RuntimeException('REFUSED: only a local isolated disposable testing clone can receive P12 DDL');
    }
    public static function applyClone(PDO $db,string $directory,array $config,string $approvedFingerprint):array {
        self::cloneOnly($db,$config);
        if(!preg_match('/^[a-f0-9]{64}$/D',$approvedFingerprint))
            throw new InvalidArgumentException('Approved fingerprint must be SHA-256 hex');
        $manifest=self::manifest($directory);
        if(!hash_equals($manifest['fingerprint'],$approvedFingerprint))
            throw new RuntimeException('SQL manifest differs from explicitly approved fingerprint');
        $before=self::inspect($db,$manifest);
        // mysql advisory lock coordinates multiple rehearsal invocations.
        $lock='vta-p12-'.substr(hash('sha256',$before['database']),0,32);
        $s=$db->prepare('SELECT GET_LOCK(?,1)');$s->execute([$lock]);
        if((int)$s->fetchColumn()!==1)throw new RuntimeException('P12 clone rehearsal busy');
        try{
            $again=self::inspect($db,self::manifest($directory));
            if($again['pending']!==$before['pending'])
                throw new RuntimeException('Clone ledger changed between review and lock');
            if($again['manifest']!==$approvedFingerprint)
                throw new RuntimeException('Clone source hash changed before apply');
            $done=Migrations::run($db,$directory);
            if($done!==$before['pending'])
                throw new RuntimeException('Unexpected migration execution set');
            $after=self::inspect($db,self::manifest($directory));
            if($after['pending']!==[])throw new RuntimeException('Incomplete rehearsal; do not promote');
            return ['applied'=>$done,'before'=>$before,'after'=>$after,'clone_only'=>true];
        } finally {
            $release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);
        }
    }
}
