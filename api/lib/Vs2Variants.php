<?php
declare(strict_types=1);

final class Vs2Variants {
    public static function parent(PDO $db,array $u,int $version,string $level): int {
        if(!in_array($level,['3*','4*','5*'],true))throw new InvalidArgumentException('Invalid hotel level');
        $old=QuoteVs2Repository::q($db,'SELECT id FROM quote_options WHERE quote_version_id=? AND hotel_level=?',[$version,$level])->fetchColumn();if($old)return (int)$old;
        QuoteVs2Repository::q($db,"INSERT INTO quote_options(quote_version_id,label,hotel_level,snapshot_json,created_by) VALUES(?,?,?,'{}',?)",[$version,$level,$level,$u['id']]);return (int)$db->lastInsertId();
    }
    public static function save(PDO $db,array $u,array $v,array $body): int {
        $id=(int)($body['variant_id']??0);$old=$id?QuoteVs2Repository::variant($db,(int)$v['id'],$id):[];
        $r=array_replace(['variant_key'=>'variant-'.bin2hex(random_bytes(8)),'hotel_level'=>'4*','cruise_level'=>4,'label'=>'Custom Mix','costing_mode'=>'PRIVATE','preset_code'=>'CUSTOM_MIX','sort_order'=>0,'is_offered'=>0,'pricing_mode'=>'MARKUP','pricing_value'=>'15','rounding_step'=>'0','selling_currency'=>'USD'],$old,$body);
        if(!in_array($r['costing_mode'],['PRIVATE','SIC','HYBRID'],true)||!in_array($r['pricing_mode'],['MARKUP','TARGET_MARGIN','MANUAL'],true)||!in_array($r['selling_currency'],['USD','VND'],true))throw new InvalidArgumentException('Invalid variant settings');
        if($r['cruise_level']!==null&&!in_array((int)$r['cruise_level'],[3,4,5],true))throw new InvalidArgumentException('Invalid cruise level');
        Vs2Decimal::parse($r['pricing_value'],4);Vs2Decimal::parse($r['rounding_step']);
        $parent=self::parent($db,$u,(int)$v['id'],$r['hotel_level']);
        $data=['quote_option_id'=>$parent,'variant_key'=>QuoteVs2Domain::text($r['variant_key'],80),'label'=>QuoteVs2Domain::text($r['label'],190),'costing_mode'=>$r['costing_mode'],'cruise_level'=>$r['cruise_level'],'preset_code'=>QuoteVs2Domain::text($r['preset_code'],24),'sort_order'=>QuoteVs2Domain::count($r['sort_order']),'is_offered'=>!empty($r['is_offered'])?1:0,'pricing_mode'=>$r['pricing_mode'],'pricing_value'=>$r['pricing_value'],'rounding_step'=>$r['rounding_step'],'selling_currency'=>$r['selling_currency'],'updated_by'=>$u['id']];
        if($id)QuoteVs2Repository::q($db,'UPDATE quote_option_variants SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($data))).' WHERE id=?',[...array_values($data),$id]);
        else{QuoteVs2Repository::q($db,'INSERT INTO quote_option_variants('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));$id=(int)$db->lastInsertId();}
        self::lines($db,$u,$v,$id);
        if($old){$categories=[];if($old['hotel_level']!==$r['hotel_level'])$categories[]='HOTEL';if($old['cruise_level']!==$r['cruise_level'])$categories[]='CRUISE';if($old['costing_mode']!==$r['costing_mode'])$categories=array_keys(QuoteVs2Domain::FORMULAS);
            foreach($categories as $category)QuoteVs2Repository::q($db,'UPDATE quote_variant_cost_lines l JOIN quote_service_requirements r ON r.id=l.requirement_id SET l.review_required=1 WHERE l.variant_id=? AND r.category=?',[$id,$category]);
        }
        $g=QuoteVs2Repository::graph($db,$v);SmartCosting::aggregate($db,$v,QuoteVs2Repository::variant($db,(int)$v['id'],$id),array_column($g['requirements'],null,'id'),QuoteVs2Domain::guests($v,$g['profile']));return $id;
    }
    public static function lines(PDO $db,array $u,array $v,int $id): void {
        $reqs=QuoteVs2Repository::q($db,'SELECT * FROM quote_service_requirements WHERE quote_version_id=? ORDER BY sort_order,id',[$v['id']])->fetchAll();
        foreach($reqs as $req){if(QuoteVs2Repository::q($db,"SELECT id FROM quote_variant_cost_lines WHERE variant_id=? AND requirement_id=? AND line_kind='SERVICE'",[$id,$req['id']])->fetchColumn())continue;
            QuoteVs2Repository::q($db,"INSERT INTO quote_variant_cost_lines(variant_id,requirement_id,line_key,sort_order,line_kind,service_mode,formula_code,quantity_source,updated_by) VALUES(?,?,?,?,'SERVICE',?,?,?,?)",[$id,$req['id'],'line-'.$req['requirement_key'],$req['sort_order'],$req['service_mode']==='BOTH'?'PRIVATE':$req['service_mode'],QuoteVs2Domain::FORMULAS[$req['category']],$req['default_quantity_source'],$u['id']]);
        }
    }
    public static function generate(PDO $db,array $u,array $v,array $body): array {
        $modes=$body['modes']??['PRIVATE','SIC'];Vs2Templates::requirements($db,$u,$v,$modes);$ids=[];
        foreach($modes as $m=>$mode)foreach([3=>'Economy',4=>'Recommended',5=>'Premium'] as $level=>$label){$key=strtolower($mode).'-'.$level.'-'.$level;$parent=self::parent($db,$u,(int)$v['id'],$level.'*');$old=QuoteVs2Repository::q($db,'SELECT id FROM quote_option_variants WHERE quote_option_id=? AND variant_key=?',[$parent,$key])->fetchColumn();
            if($old){self::lines($db,$u,$v,(int)$old);$ids[]=(int)$old;continue;}
            $ids[]=self::save($db,$u,$v,['variant_key'=>$key,'hotel_level'=>$level.'*','cruise_level'=>$level,'costing_mode'=>$mode,'preset_code'=>strtoupper($label),'label'=>$mode.' '.$label,'sort_order'=>$m*10+$level]);
        }return $ids;
    }
}
