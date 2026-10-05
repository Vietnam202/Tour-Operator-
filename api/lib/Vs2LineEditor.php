<?php
declare(strict_types=1);

final class Vs2LineEditor {
    public static function write(PDO $db,array $u,array $v,int $variantId,array $body,string $action='save',int $id=0): array {
        $variant=QuoteVs2Repository::variant($db,(int)$v['id'],$variantId);$old=[];
        if($id){$old=QuoteVs2Repository::q($db,'SELECT * FROM quote_variant_cost_lines WHERE variant_id=? AND id=?',[$variantId,$id])->fetch();if(!$old)throw new OutOfBoundsException('Line not found');}
        if($action==='delete'){
            if(QuoteVs2Repository::q($db,'SELECT 1 FROM quote_variant_cost_lines WHERE included_by_line_id=? OR adjusts_line_id=?',[$id,$id])->fetchColumn())throw new DomainException('LINE_HAS_DEPENDENTS');
            QuoteVs2Repository::q($db,'DELETE FROM quote_variant_cost_lines WHERE id=?',[$id]);SmartCosting::refresh($db,$v);Vs2Inclusions::apply($db,$v);return ['line_id'=>$id];
        }
        if($action==='duplicate'){
            $req=QuoteVs2Repository::q($db,'SELECT * FROM quote_service_requirements WHERE id=? AND quote_version_id=?',[$old['requirement_id'],$v['id']])->fetch();unset($req['id']);$req['requirement_key']='req-'.bin2hex(random_bytes(8));$req['service_name'].=' copy';$reqId=QuoteSmartCosting::requirement($db,$u,(int)$v['id'],$req);$body=['requirement_id'=>$reqId]+array_intersect_key($old,array_flip(['formula_code','quantity_source','quantity_override','custom_quantity','units_override','override_reason','supplier_id','rate_version_id','original_currency','unit_amount_original','manual_reason','manual_contract_json','service_mode']));$id=0;$old=[];
        }
        if($action==='duplicate'&&!empty($body['rate_version_id']))unset($body['unit_amount_original']);
        if($action==='reorder'){QuoteVs2Repository::q($db,'UPDATE quote_variant_cost_lines SET sort_order=?,updated_by=? WHERE id=?',[QuoteVs2Domain::count($body['sort_order']??null),$u['id'],$id]);return ['line_id'=>$id];}
        $allowed=['expected_revision','requirement','requirement_id','line_key','sort_order','line_kind','service_mode','formula_code','quantity_source','quantity_override','custom_quantity','units_override','override_reason','supplier_id','rate_version_id','original_currency','unit_amount_original','adjusts_line_id','adjustment_amount_vnd','manual_reason','manual_contract','manual_contract_json','no_cost','review_reason'];
        foreach(array_keys($body) as $key)if(!in_array($key,$allowed,true))throw new InvalidArgumentException('Unsupported cost input: '.$key);
        if(isset($body['requirement']))$body['requirement_id']=QuoteSmartCosting::requirement($db,$u,(int)$v['id'],$body['requirement']);
        $r=array_replace(['line_key'=>'line-'.bin2hex(random_bytes(8)),'sort_order'=>0,'line_kind'=>'SERVICE','service_mode'=>'PRIVATE','quantity_override'=>null,'custom_quantity'=>null,'units_override'=>null,'override_reason'=>null,'supplier_id'=>null,'rate_version_id'=>null,'original_currency'=>'VND','unit_amount_original'=>null,'adjusts_line_id'=>null,'adjustment_amount_vnd'=>null,'manual_reason'=>null,'manual_contract_json'=>null],$old,$body);
        $req=QuoteVs2Repository::q($db,'SELECT * FROM quote_service_requirements WHERE quote_version_id=? AND id=?',[$v['id'],$r['requirement_id']??0])->fetch();if(!$req)throw new OutOfBoundsException('Requirement not found');
        $r['formula_code']=$r['formula_code']??QuoteVs2Domain::FORMULAS[$req['category']];$r['quantity_source']=$r['quantity_source']??$req['default_quantity_source'];
        if(!in_array($r['formula_code'],[...array_values(QuoteVs2Domain::FORMULAS),'LUMP_SUM'],true)||!in_array($r['quantity_source'],QuoteVs2Domain::SOURCES,true)||!in_array($r['service_mode'],['PRIVATE','SIC'],true)||!in_array($r['line_kind'],['SERVICE','ADJUSTMENT'],true))throw new InvalidArgumentException('Invalid line basis');
        if(in_array($r['formula_code'],['TRANSFER_PACKAGE','GUIDE_DAY'],true)&&$r['quantity_source']!=='CUSTOM_QTY')throw new InvalidArgumentException('Transfer and guide require explicit CUSTOM_QTY resource counts');
        if(!in_array($r['original_currency'],['VND','USD'],true))throw new InvalidArgumentException('Invalid currency');
        $contract=$body['manual_contract']??QuoteVs2Repository::decode($r['manual_contract_json']??null);if(!is_array($contract))throw new InvalidArgumentException('Invalid manual contract');
        $data=[];foreach(['requirement_id','line_key','sort_order','line_kind','service_mode','formula_code','quantity_source','override_reason','supplier_id','rate_version_id','original_currency','unit_amount_original','adjusts_line_id','adjustment_amount_vnd','manual_reason'] as $key)$data[$key]=$r[$key];
        $data['line_key']=QuoteVs2Domain::text($r['line_key'],80);$data['sort_order']=QuoteVs2Domain::count($r['sort_order']);
        foreach(['quantity_override','custom_quantity','units_override'] as $key)$data[$key]=QuoteVs2Domain::count($r[$key],true);
        if($data['quantity_override']!==null||$data['units_override']!==null)QuoteVs2Domain::text($r['override_reason']??'');
        if($r['supplier_id']&&!QuoteVs2Repository::q($db,"SELECT 1 FROM suppliers WHERE company_id=? AND id=? AND status='ACTIVE'",[$v['company_id'],$r['supplier_id']])->fetchColumn())throw new InvalidArgumentException('Supplier not in this company');
        if($r['rate_version_id']){
            $identity=QuoteVs2Repository::q($db,'SELECT r.supplier_id FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id WHERE r.company_id=? AND rv.id=?',[$v['company_id'],$r['rate_version_id']])->fetch();if(!$identity)throw new InvalidArgumentException('Rate not in this company');
            if(array_key_exists('unit_amount_original',$body))throw new InvalidArgumentException('Stored-rate amount is server controlled');$data['supplier_id']=$identity['supplier_id'];$data['unit_amount_original']=null;
        }elseif($r['unit_amount_original']!==null){if(Vs2Decimal::parse($r['unit_amount_original'])<0)throw new InvalidArgumentException('Negative unit rate');QuoteVs2Domain::text($r['manual_reason']??'');}
        $data['manual_contract_json']=$contract?QuoteVs2Repository::json($contract):null;$data['origin']=$r['rate_version_id']?'EDITED':($r['unit_amount_original']!==null?'MANUAL':'EDITED');$data['review_required']=1;$data['reviewed_by']=null;$data['reviewed_context_hash']=null;$data['updated_by']=$u['id'];$data['coverage_state']='UNRESOLVED';$data['included_by_line_id']=null;$data['inclusion_rule_id']=null;$data['total_vnd']=null;
        if($r['line_kind']==='ADJUSTMENT'){
            $parent=QuoteVs2Repository::q($db,"SELECT * FROM quote_variant_cost_lines WHERE variant_id=? AND id=? AND line_kind='SERVICE'",[$variantId,$r['adjusts_line_id']])->fetch();if(!$parent||(int)$parent['requirement_id']!==(int)$req['id']||(int)$parent['id']===$id)throw new InvalidArgumentException('Adjustment requires a parent service in this variant');
            $data['supplier_id']=$parent['supplier_id'];$data['total_vnd']=Vs2Decimal::format(Vs2Decimal::parse($r['adjustment_amount_vnd']??''));$data['coverage_state']='PRICED';$data['rate_status']='MANUAL_COST';$data['calculation_trace_json']=QuoteVs2Repository::json(['sentence'=>'Reviewed parent adjustment '.$data['total_vnd'].' VND']);QuoteVs2Domain::text($r['manual_reason']??'');
        }elseif(!empty($body['no_cost'])){QuoteVs2Domain::text($r['manual_reason']??'');if(empty($contract['evidence']))throw new InvalidArgumentException('No-cost evidence required');$data['coverage_state']='NO_COST';$data['total_vnd']='0.00';$data['rate_status']='NEEDS_REVIEW';$data['calculation_trace_json']=QuoteVs2Repository::json(['sentence'=>'No cost: '.$r['manual_reason']]);}
        if($id)QuoteVs2Repository::q($db,'UPDATE quote_variant_cost_lines SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($data))).' WHERE id=?',[...array_values($data),$id]);
        else{QuoteVs2Repository::q($db,'INSERT INTO quote_variant_cost_lines(variant_id,'.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data)+1,'?')).')',[$variantId,...array_values($data)]);$id=(int)$db->lastInsertId();}
        SmartCosting::refresh($db,$v,[],false,[],[$id]);Vs2Inclusions::apply($db,$v);return ['line_id'=>$id];
    }
    public static function review(PDO $db,array $u,array $v,int $variantId,int $id,array $body): array {
        QuoteVs2Domain::text($body['review_reason']??'');$variant=QuoteVs2Repository::variant($db,(int)$v['id'],$variantId);$g=QuoteVs2Repository::graph($db,$v);$reqs=array_column($g['requirements'],null,'id');
        $line=QuoteVs2Repository::q($db,'SELECT * FROM quote_variant_cost_lines WHERE variant_id=? AND id=?',[$variantId,$id])->fetch();if(!$line)throw new OutOfBoundsException('Line not found');$req=$reqs[$line['requirement_id']];$rate=null;
        if(!in_array($line['coverage_state'],['PRICED','INCLUDED','NO_COST'],true))throw new DomainException('RATE_NEEDED');
        if($line['line_kind']==='SERVICE'&&$line['coverage_state']==='PRICED')$rate=Vs2RateResolver::resolve($db,$v,QuoteVs2Domain::guests($v,$g['profile']),$req,$variant,$line);
        $hash=SmartCosting::reviewHash($v,$g['profile'],$req,$variant,$line,$rate);QuoteVs2Repository::q($db,'UPDATE quote_variant_cost_lines SET review_required=0,reviewed_by=?,reviewed_context_hash=?,updated_by=? WHERE id=?',[$u['id'],$hash,$u['id'],$id]);return ['line_id'=>$id];
    }
}
