<?php
declare(strict_types=1);

/** Child repository; all ownership resolves through the existing quote/rate masters. */
final class QuoteVs2Repository {
    public static function q(PDO $db,string $sql,array $args=[]): PDOStatement {
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    public static function json($value): string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public static function decode($value,array $default=[]): array {return $value===null?$default:json_decode((string)$value,true,512,JSON_THROW_ON_ERROR);}
    public static function engine(array $v): string {
        $engine=$v['costing_engine']??'VS1';
        if(!in_array($engine,['VS1','VS2_1'],true))throw new DomainException('UNKNOWN_COSTING_ENGINE');
        return $engine;
    }
    public static function legacy(array $v): void {if(self::engine($v)!=='VS1')throw new DomainException('USE_SMART_COSTING: Legacy inputs are inactive for this version');}
    public static function mutable(PDO $db,array $v): void {
        if(in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true)||self::q($db,'SELECT 1 FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetchColumn())throw new DomainException('IMMUTABLE_VERSION: Create a revision');
    }
    public static function lock(PDO $db,int $company,int $id,?int $expected=null): array {
        $quote=self::q($db,'SELECT q.id FROM quotes q JOIN quote_versions v ON v.quote_id=q.id WHERE q.company_id=? AND v.id=?',[$company,$id])->fetchColumn();
        if(!$quote)throw new OutOfBoundsException('Quote not found');
        self::q($db,'SELECT id FROM quotes WHERE id=? AND company_id=? FOR UPDATE',[$quote,$company]);
        $v=QuoteOptions::version($db,$company,$id,true);self::engine($v);
        if($expected!==null&&(int)$v['costing_revision']!==$expected)throw new DomainException('STALE_REVISION');
        return $v;
    }
    public static function graph(PDO $db,array $v): array {
        $id=(int)$v['id'];
        $profile=self::q($db,'SELECT * FROM quote_guest_profiles WHERE quote_version_id=?',[$id])->fetch()?:[];
        $requirements=self::q($db,'SELECT * FROM quote_service_requirements WHERE quote_version_id=? ORDER BY sort_order,id',[$id])->fetchAll();
        $variants=self::q($db,'SELECT c.*,o.hotel_level,o.quote_version_id FROM quote_option_variants c JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? ORDER BY c.sort_order,c.id',[$id])->fetchAll();
        foreach($variants as &$variant)$variant['lines']=self::q($db,'SELECT * FROM quote_variant_cost_lines WHERE variant_id=? ORDER BY sort_order,id',[$variant['id']])->fetchAll();unset($variant);
        return ['version'=>$v,'profile'=>$profile,'requirements'=>$requirements,'variants'=>$variants];
    }
    public static function variant(PDO $db,int $version,int $id): array {
        $r=self::q($db,'SELECT c.*,o.hotel_level FROM quote_option_variants c JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? AND c.id=?',[$version,$id])->fetch();
        if(!$r)throw new OutOfBoundsException('Variant not found');return $r;
    }
    public static function finish(PDO $db,array $v,array $u,string $event,array $details=[]): void {
        self::q($db,"UPDATE quote_versions SET costing_revision=costing_revision+1,version_status='DRAFT',approved_by=NULL,approved_at=NULL WHERE id=?",[$v['id']]);
        self::q($db,'DELETE FROM quote_bundle_approvals WHERE quote_version_id=?',[$v['id']]);
        self::q($db,"UPDATE quotes SET status='DRAFT',updated_by=? WHERE id=?",[$u['id'],$v['quote_id']]);
        if(class_exists('Vs2Snapshots')){
            $current=QuoteOptions::version($db,(int)$u['company_id'],(int)$v['id']);$p=Vs2Snapshots::summary($db,$current);
            self::q($db,'UPDATE quote_versions SET total_cost=?,cost_per_paying_pax=?,selling_per_pax=?,total_selling=?,profit_amount=?,margin_pct=?,markup_pct=? WHERE id=?',[$p['total_cost'],$p['cost_per_paying_pax'],$p['selling_per_pax'],$p['total_selling'],$p['profit_amount'],$p['margin_pct'],$p['markup_pct'],$v['id']]);
        }
        Audit::log($db,(int)$u['company_id'],(int)$u['id'],$event,'quote_version',(int)$v['id'],null,$details);
    }
    public static function expected(array $body): int {
        if(!isset($body['expected_revision'])||!preg_match('/^\d+$/',(string)$body['expected_revision']))throw new InvalidArgumentException('expected_revision required');
        return (int)$body['expected_revision'];
    }
    public static function atomic(PDO $db,callable $fn): array {
        $own=!$db->inTransaction();if($own){if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$db->beginTransaction();}
        try{$out=$fn();if($own)$db->commit();return $out;}catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    }
}
