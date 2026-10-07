<?php
declare(strict_types=1);

/** Commercial projections reuse SmartCosting's fixed-point selling formulas. */
final class CommercialPolicy {
    public static function validate(array $b): array {
        $channel=$b['channel']??'B2B_AGENT';
        $modes=$channel==='B2B_AGENT'?['B2B_MARKUP','B2B_MARGIN','B2B_NET_RATE']:($channel==='B2C_DIRECT'?['B2C_MARKUP','B2C_MARGIN','B2C_RETAIL_PRICE']:[]);
        if(!in_array($b['policy_mode']??'',$modes,true))throw new InvalidArgumentException('Channel and selling policy must match');
        $r=['policy_key'=>QuoteVs2Domain::text($b['policy_key']??'',80),'name'=>QuoteVs2Domain::text($b['name']??'',190),'channel'=>$channel,'policy_mode'=>$b['policy_mode']];
        foreach(['pricing_value','minimum_margin_pct','warning_margin_pct','commission_pct','deposit_pct'] as $k){$n=Vs2Decimal::parse($b[$k]??'0',4);if($n<0||($k!=='pricing_value'&&($k==='deposit_pct'?$n>1000000:$n>=1000000)))throw new InvalidArgumentException('Commission and margin must be below 100%; deposit may be 100%');$r[$k]=Vs2Decimal::format($n,4);}
        if(Vs2Decimal::parse($r['warning_margin_pct'],4)<Vs2Decimal::parse($r['minimum_margin_pct'],4))throw new InvalidArgumentException('Warning threshold must be at least blocking threshold');
        if($channel==='B2C_DIRECT'&&Vs2Decimal::parse($r['commission_pct'],4)!==0)throw new InvalidArgumentException('B2C has no agent commission');
        $r['selling_currency']=$b['selling_currency']??'USD';if(!in_array($r['selling_currency'],['USD','VND'],true))throw new InvalidArgumentException('Supported selling currencies: USD, VND');
        $step=Vs2Decimal::parse($b['rounding_step']??'0');if($step<0)throw new InvalidArgumentException('Invalid rounding step');$r['rounding_step']=Vs2Decimal::format($step);
        $days=QuoteVs2Domain::count($b['validity_days']??14);if($days<1||$days>365)throw new InvalidArgumentException('Validity must be 1–365 days');$r['validity_days']=$days;
        foreach(['payment_terms','cancellation_policy'] as $k)$r[$k]=QuoteVs2Domain::text($b[$k]??'',12000,true);
        if(str_ends_with($r['policy_mode'],'MARGIN')&&Vs2Decimal::parse($r['pricing_value'],4)>=1000000)throw new InvalidArgumentException('Target margin must be below 100');
        return $r;
    }
    public static function create(PDO $db,array $u,array $b): array {
        $p=self::validate($b);return QuoteVs2Repository::atomic($db,function()use($db,$u,$p){
            QuoteVs2Repository::q($db,'SELECT id FROM companies WHERE id=? FOR UPDATE',[$u['company_id']]);
            $n=1+(int)QuoteVs2Repository::q($db,'SELECT COALESCE(MAX(version_no),0) FROM commercial_policies WHERE company_id=? AND policy_key=?',[$u['company_id'],$p['policy_key']])->fetchColumn();
            $r=['company_id'=>$u['company_id'],'version_no'=>$n]+$p+['created_by'=>$u['id']];
            QuoteVs2Repository::q($db,'INSERT INTO commercial_policies('.implode(',',array_keys($r)).') VALUES('.implode(',',array_fill(0,count($r),'?')).')',array_values($r));$id=(int)$db->lastInsertId();
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'COMMERCIAL_POLICY_VERSION_CREATED','commercial_policy',$id,null,$p+['version_no'=>$n]);return self::get($db,(int)$u['company_id'],$id);
        });
    }
    public static function get(PDO $db,int $company,int $id): array {
        $r=QuoteVs2Repository::q($db,'SELECT * FROM commercial_policies WHERE company_id=? AND id=?',[$company,$id])->fetch();if(!$r)throw new OutOfBoundsException('Commercial policy not found');return $r;
    }
    public static function variant(array $p,?string $net=null): array {
        $mode=str_ends_with($p['policy_mode'],'MARKUP')?'MARKUP':(str_ends_with($p['policy_mode'],'MARGIN')?'TARGET_MARGIN':'MANUAL');
        return ['selling_currency'=>$p['selling_currency'],'pricing_mode'=>$net!==null?'MANUAL':$mode,'pricing_value'=>$net??$p['pricing_value'],'rounding_step'=>$p['rounding_step']];
    }
    public static function pricing(int $cost,int $pay,string $fx,array $p,?string $net=null): array {
        $r=SmartCosting::pricing($cost,$pay,$fx,self::variant($p,$net));$n=Vs2Decimal::parse($r['selling_per_pax']);$commission=Vs2Decimal::parse($p['commission_pct'],4);
        if($n<=0)throw new DomainException('SELLING_PRICE_NEEDED');
        $gross=$commission?Vs2Decimal::ratio($n,1000000,1000000-$commission,true):$n;$grossTotal=Vs2Decimal::mul($gross,$pay);$netTotal=Vs2Decimal::mul($n,$pay);
        $deposit=Vs2Decimal::ratio($netTotal,Vs2Decimal::parse($p['deposit_pct'],4),1000000);
        $margin=Vs2Decimal::parse($r['margin_pct'],4);$status=$margin<Vs2Decimal::parse($p['minimum_margin_pct'],4)?'MARGIN_BELOW_POLICY':($margin<Vs2Decimal::parse($p['warning_margin_pct'],4)?'MARGIN_WARNING':'VALID');
        return array_replace($r,['selling_per_pax'=>Vs2Decimal::format($gross),'total_selling'=>Vs2Decimal::format($grossTotal),'net_per_pax'=>Vs2Decimal::format($n),'net_payable'=>Vs2Decimal::format($netTotal),'commission_per_pax'=>Vs2Decimal::format($gross-$n),'commission_total'=>Vs2Decimal::format($grossTotal-$netTotal),'deposit'=>Vs2Decimal::format($deposit),'balance'=>Vs2Decimal::format($netTotal-$deposit),'pricing_status'=>$status]);
    }
}
