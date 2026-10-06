<?php
declare(strict_types=1);

final class Vs2Snapshots {
    public static function confirm(PDO $db,array $u,array $v,int $option,int $variant,string $hash): array {
        if(!$variant||!$option||!$hash)throw new DomainException('AMBIGUOUS_VARIANT: Exact sent option, variant and hash required');
        $sent=QuoteVs2Repository::q($db,'SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetch();if(!$sent||!hash_equals($sent['content_hash'],$hash))throw new DomainException('STALE_SENT_HASH');
        $old=QuoteVs2Repository::q($db,'SELECT option_id,variant_id FROM quote_acceptances WHERE quote_version_id=?',[$v['id']])->fetch();
        if($old){if((int)$old['option_id']!==$option||(int)$old['variant_id']!==$variant)throw new DomainException('ACCEPTANCE_IMMUTABLE');return ['option_id'=>$option,'variant_id'=>$variant];}
        if($v['version_status']!=='SENT')throw new DomainException('SENT_REQUIRED');$bundle=QuoteVs2Repository::decode($sent['internal_snapshot_json']);$selected=null;
        foreach($bundle['options'] as $row)if($row['option_id']===$option&&$row['variant_id']===$variant)$selected=$row;if(!$selected)throw new DomainException('VARIANT_NOT_OFFERED');
        QuoteVs2Repository::q($db,'INSERT INTO quote_acceptances(quote_version_id,option_id,variant_id,sent_content_hash,accepted_by) VALUES(?,?,?,?,?)',[$v['id'],$option,$variant,$hash,$u['id']]);
        QuoteVs2Repository::q($db,"UPDATE quote_versions SET version_status='CONFIRMED' WHERE id=?",[$v['id']]);
        QuoteVs2Repository::q($db,"UPDATE quotes SET status='CONFIRMED',confirmed_version_no=?,updated_by=? WHERE id=?",[$v['version_no'],$u['id'],$v['quote_id']]);
        QuoteVs2Repository::q($db,"UPDATE trips SET lifecycle_stage='CONFIRMED' WHERE id=?",[$v['trip_id']]);
        if($v['inquiry_id'])QuoteVs2Repository::q($db,"UPDATE inquiries SET status='CONFIRMED',updated_by=? WHERE id=?",[$u['id'],$v['inquiry_id']]);
        QuoteVs2Repository::q($db,"UPDATE tasks SET status='DONE',completed_at=NOW() WHERE company_id=? AND entity_type='quote' AND entity_id=? AND rule_code='QUOTE_FOLLOWUP' AND status IN ('OPEN','SNOOZED')",[$u['company_id'],$v['quote_id']]);
        Audit::log($db,(int)$u['company_id'],(int)$u['id'],'QUOTE_OPTION_ACCEPTED','quote_version',(int)$v['id'],null,['option_id'=>$option,'variant_id'=>$variant,'hash'=>$hash]);return ['option_id'=>$option,'variant_id'=>$variant];
    }
    public static function summary(PDO $db,array $v): array {
        $row=QuoteVs2Repository::q($db,'SELECT c.* FROM quote_option_variants c JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? AND c.is_offered=1 ORDER BY c.sort_order,c.id LIMIT 1',[$v['id']])->fetch();$p=$row?QuoteVs2Repository::decode($row['pricing_result_json']):[];$fx=(string)$v['fx_rate'];
        $cost=Vs2Decimal::parse($p['cost_total_vnd']??'0');$usd=Vs2Decimal::ratio($cost,1000000,Vs2Decimal::parse($fx,6));$profit=Vs2Decimal::ratio(Vs2Decimal::parse($p['profit_vnd']??'0'),1000000,Vs2Decimal::parse($fx,6));
        return ['total_cost'=>Vs2Decimal::format($cost),'cost_per_paying_pax'=>Vs2Decimal::format(Vs2Decimal::ratio($usd,1,max(1,(int)$v['paying_pax']))),'selling_per_pax'=>$p['selling_per_pax']??'0.00','total_selling'=>$p['total_selling']??'0.00','profit_amount'=>Vs2Decimal::format($profit),'margin_pct'=>$p['margin_pct']??'0','markup_pct'=>$p['markup_pct']??'0','pricing_mode'=>$row['pricing_mode']??'MARKUP','pricing_value'=>$row['pricing_value']??'15','rounding_step'=>$row['rounding_step']??'0','fx_rate'=>$fx,'items'=>[]];
    }
    public static function shiftRequirements(PDO $db,int $id,int $offset): void {
        $shift=fn($date)=>$date?(new DateTimeImmutable($date))->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d'):null;
        foreach(QuoteVs2Repository::q($db,'SELECT * FROM quote_service_requirements WHERE quote_version_id=?',[$id])->fetchAll() as $req){$scope=QuoteVs2Repository::decode($req['scope_json']);if(isset($scope['dates']))$scope['dates']=array_map($shift,$scope['dates']);if(isset($scope['occurrences']))foreach($scope['occurrences'] as &$o)if(is_array($o)&&isset($o['date']))$o['date']=$shift($o['date']);unset($o);QuoteVs2Repository::q($db,'UPDATE quote_service_requirements SET service_date=?,service_end_date=?,scope_json=? WHERE id=?',[$shift($req['service_date']),$shift($req['service_end_date']),QuoteVs2Repository::json($scope),$req['id']]);}
    }
    private static function stable(array $row): array {foreach(['updated_at','updated_by','calculated_at','approved_at'] as $key)unset($row[$key]);return $row;}
    public static function bundle(PDO $db,array $v): array {
        QuoteVs2Validator::assertValid($db,$v);$g=QuoteVs2Repository::graph($db,$v);$guests=QuoteVs2Domain::guests($v,$g['profile']);$reqs=array_column($g['requirements'],null,'id');$options=[];
        foreach($g['variants'] as $variant)if($variant['is_offered']){
            $active=array_values(array_filter($variant['lines'],fn($l)=>QuoteVs2Domain::applies($reqs[$l['requirement_id']],$variant,$l)));
            $pricing=QuoteVs2Repository::decode($variant['pricing_result_json']);
            $options[]=['id'=>(int)$variant['quote_option_id'],'option_id'=>(int)$variant['quote_option_id'],'variant_id'=>(int)$variant['id'],'label'=>$variant['label'],'hotel_level'=>$variant['hotel_level'],'cruise_level'=>$variant['cruise_level'],'costing_mode'=>$variant['costing_mode'],'preset_code'=>$variant['preset_code'],'snapshot'=>$guests+['fx_rate'=>$v['fx_rate'],'selling_currency'=>$variant['selling_currency'],'pricing'=>$pricing,'lines'=>QuoteVs2Validator::bookingLines($active,$reqs,(string)$v['fx_rate']),'cost_lines'=>array_map([self::class,'stable'],$active),'variant'=>self::stable(array_diff_key($variant,['lines'=>true]))]];
        }
        return $guests+['schema'=>'VS2_1','costing_engine'=>'VS2_1','costing_revision'=>(int)$v['costing_revision'],'quote_ref'=>$v['quote_ref'],'version_no'=>(int)$v['version_no'],'tour_name'=>$v['tour_name'],'start_date'=>$v['start_date'],'end_date'=>$v['end_date'],'document_language'=>$v['document_language']??'en','schedule'=>QuoteOptions::publicSchedule(QuoteVs2Repository::decode($v['schedule_json'])),'internal_schedule'=>QuoteVs2Repository::decode($v['schedule_json']),'included'=>$v['included_text'],'excluded'=>$v['excluded_text'],'terms'=>$v['terms_text'],'guest_profile'=>self::stable($g['profile']),'requirements'=>array_map([self::class,'stable'],$g['requirements']),'minimum_margin'=>QuoteVs2Validator::policy($db,(int)$v['company_id']),'options'=>$options];
    }
    public static function publicBundle(array $bundle): array {
        $safe=array_intersect_key($bundle,array_flip([...QuoteVs2Domain::BASE,'schema','quote_ref','version_no','tour_name','start_date','end_date','document_language','schedule','included','excluded','terms','presentation']));
        $safe['options']=array_map(fn($o)=>['id'=>$o['id'],'option_id'=>$o['option_id'],'variant_id'=>$o['variant_id'],'label'=>$o['label'],'hotel_level'=>$o['hotel_level'],'cruise_level'=>$o['cruise_level'],'costing_mode'=>$o['costing_mode'],'selling_per_pax'=>$o['snapshot']['pricing']['selling_per_pax'],'total_selling'=>$o['snapshot']['pricing']['total_selling'],'currency'=>$o['snapshot']['selling_currency']],$bundle['options']);return $safe;
    }
    public static function copy(PDO $db,array $u,int $oldId,int $newId): void {
        $old=QuoteOptions::version($db,(int)$u['company_id'],$oldId);if(QuoteVs2Repository::engine($old)!=='VS2_1')return;$g=QuoteVs2Repository::graph($db,$old);
        QuoteVs2Repository::q($db,"UPDATE quote_versions SET costing_engine='VS2_1',costing_revision=0 WHERE id=?",[$newId]);
        $profile=$g['profile'];unset($profile['quote_version_id'],$profile['updated_at']);$profile['updated_by']=$u['id'];$profile['review_metadata_json']=QuoteVs2Repository::json(['reviewed'=>[],'copied_from'=>$oldId]);
        QuoteVs2Repository::q($db,'INSERT INTO quote_guest_profiles(quote_version_id,'.implode(',',array_keys($profile)).') VALUES('.implode(',',array_fill(0,count($profile)+1,'?')).')',[$newId,...array_values($profile)]);
        $map=[];foreach($g['requirements'] as $req){$prior=(int)$req['id'];unset($req['id'],$req['updated_at']);$req['quote_version_id']=$newId;$req['updated_by']=$u['id'];$req['package_requirement_id']=null;QuoteVs2Repository::q($db,'INSERT INTO quote_service_requirements('.implode(',',array_keys($req)).') VALUES('.implode(',',array_fill(0,count($req),'?')).')',array_values($req));$map[$prior]=(int)$db->lastInsertId();}
        foreach($g['requirements'] as $req)if($req['package_requirement_id'])QuoteVs2Repository::q($db,'UPDATE quote_service_requirements SET package_requirement_id=? WHERE id=?',[$map[$req['package_requirement_id']],$map[$req['id']]]);
        foreach($g['variants'] as $variant){$parent=Vs2Variants::parent($db,$u,$newId,$variant['hotel_level']);$lines=$variant['lines'];unset($variant['id'],$variant['hotel_level'],$variant['quote_version_id'],$variant['lines'],$variant['updated_at']);$variant['quote_option_id']=$parent;$variant['is_offered']=0;$variant['updated_by']=$u['id'];$variant['pricing_result_json']=null;$variant['input_hash']=null;
            QuoteVs2Repository::q($db,'INSERT INTO quote_option_variants('.implode(',',array_keys($variant)).') VALUES('.implode(',',array_fill(0,count($variant),'?')).')',array_values($variant));$vid=(int)$db->lastInsertId();$lineMap=[];
            foreach($lines as $line){$prior=$line['id'];unset($line['id'],$line['updated_at']);$line['variant_id']=$vid;$line['requirement_id']=$map[$line['requirement_id']];$line['included_by_line_id']=null;$line['inclusion_rule_id']=null;$line['adjusts_line_id']=null;$line['review_required']=1;$line['reviewed_by']=null;$line['reviewed_context_hash']=null;$line['updated_by']=$u['id'];$line['total_vnd']=null;$line['input_hash']=null;$line['calculated_at']=null;$line['calculation_trace_json']=null;$line['coverage_state']='UNRESOLVED';
                QuoteVs2Repository::q($db,'INSERT INTO quote_variant_cost_lines('.implode(',',array_keys($line)).') VALUES('.implode(',',array_fill(0,count($line),'?')).')',array_values($line));$lineMap[$prior]=(int)$db->lastInsertId();
            }
            foreach($lines as $line)if($line['adjusts_line_id'])QuoteVs2Repository::q($db,'UPDATE quote_variant_cost_lines SET adjusts_line_id=?,total_vnd=?,coverage_state=\'PRICED\' WHERE id=?',[$lineMap[$line['adjusts_line_id']],$line['adjustment_amount_vnd'],$lineMap[$line['id']]]);
        }
        Audit::log($db,(int)$u['company_id'],(int)$u['id'],'VS21_REVISION_COPIED','quote_version',$newId,null,['source_version_id'=>$oldId]);
    }
}
