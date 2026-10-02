<?php
declare(strict_types=1);
require_once __DIR__.'/MarketingRules.php';

/** Native Marketing module. Same tenant, session, CSRF and campaign identities as LeadHub. */
final class CampaignPilot {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    private static function text(array $b,string $key,int $max,bool $required=true): string {
        $v=$b[$key]??'';
        if(!is_string($v)||strlen($v)>$max||($required&&trim($v)==='')) throw new InvalidArgumentException('Invalid '.$key);
        return trim($v);
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        if(!str_starts_with($route,'marketing/'))return;
        $cid=(int)$u['company_id'];$uid=(int)$u['id'];
        Auth::requirePermission($db,$u,$method==='GET'?'lead.view':($route==='marketing/decision'?'marketing.approve':'campaign.manage'));
        try {
            if($method==='GET'&&$route==='marketing/workspace') {
                $campaigns=self::q($db,'SELECT c.*,b.market,b.offer,b.audience,b.budget,b.currency FROM campaigns c LEFT JOIN marketing_briefs b ON b.company_id=c.company_id AND b.campaign_id=c.id WHERE c.company_id=? ORDER BY c.id DESC LIMIT 300',[$cid])->fetchAll();
                $items=self::q($db,'SELECT m.*,c.name campaign_name FROM marketing_content m JOIN campaigns c ON c.company_id=m.company_id AND c.id=m.campaign_id WHERE m.company_id=? ORDER BY m.id DESC LIMIT 300',[$cid])->fetchAll();
                Http::json(['ok'=>true,'campaigns'=>$campaigns,'items'=>$items,'publishing_enabled'=>false]);
            }
            $b=Http::body();
            $db->beginTransaction();
            if($method==='POST'&&$route==='marketing/campaigns') {
                $name=self::text($b,'name',190);$offer=self::text($b,'offer',8000);$audience=self::text($b,'audience',2000);$market=self::text($b,'market',120);
                $budget=$b['budget']??0;$currency=self::text($b,'currency',3);
                if(!is_numeric($budget)||(float)$budget<0||(float)$budget>999999999999||!in_array($currency,['INR','USD','VND'],true))throw new InvalidArgumentException('Invalid budget or currency');
                self::q($db,'INSERT INTO campaigns(company_id,name,source,created_by) VALUES(?,?,?,?)',[$cid,$name,'CAMPAIGNPILOT',$uid]);$id=(int)$db->lastInsertId();
                self::q($db,'INSERT INTO marketing_briefs(company_id,campaign_id,market,offer,audience,budget,currency) VALUES(?,?,?,?,?,?,?)',[$cid,$id,$market,$offer,$audience,$budget,$currency]);
                $event='MARKETING_CAMPAIGN_CREATED';$entity='campaign';
            } elseif($method==='POST'&&$route==='marketing/content') {
                $campaign=(int)($b['campaign_id']??0);
                if(!self::q($db,"SELECT id FROM campaigns WHERE company_id=? AND id=? AND status='ACTIVE' FOR UPDATE",[$cid,$campaign])->fetchColumn())throw new OutOfBoundsException('Campaign not found');
                $body=self::text($b,'body',12000);$channel=self::text($b,'channel',40);
                if(!in_array($channel,['Facebook','Instagram','Google Ads','TikTok','YouTube Shorts','WhatsApp','Gmail'],true))throw new InvalidArgumentException('Invalid channel');
                self::q($db,'INSERT INTO marketing_content(company_id,campaign_id,channel,body,created_by) VALUES(?,?,?,?,?)',[$cid,$campaign,$channel,$body,$uid]);$id=(int)$db->lastInsertId();$event='MARKETING_DRAFT_CREATED';$entity='marketing_content';
            } elseif($method==='POST'&&in_array($route,['marketing/submit','marketing/decision'],true)) {
                $id=(int)($b['id']??0);$row=self::q($db,'SELECT * FROM marketing_content WHERE company_id=? AND id=? FOR UPDATE',[$cid,$id])->fetch();
                if(!$row)throw new OutOfBoundsException('Content not found');
                $status=MarketingRules::transition($row['status'],$route==='marketing/submit'?'submit':'decision',$route==='marketing/decision'?self::text($b,'decision',10):'');
                self::q($db,'UPDATE marketing_content SET status=?,decided_by=?,decided_at=? WHERE company_id=? AND id=?',[$status,$status==='PENDING'?null:$uid,$status==='PENDING'?null:date('Y-m-d H:i:s'),$cid,$id]);
                $event='MARKETING_'.$status;$entity='marketing_content';
            }else{throw new OutOfBoundsException('Unknown marketing action');}
            Audit::log($db,$cid,$uid,$event,$entity,$id,null,['publishing_enabled'=>false]);
            $db->commit();Http::json(['ok'=>true,'id'=>$id,'publishing_enabled'=>false]);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof InvalidArgumentException||$e instanceof DomainException||$e instanceof OutOfBoundsException){Http::json(['ok'=>false,'message'=>$e->getMessage()],$e instanceof OutOfBoundsException?404:422);}
            throw $e;
        }
    }
}
