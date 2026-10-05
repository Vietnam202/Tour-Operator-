<?php
declare(strict_types=1);
require_once __DIR__.'/QuoteVs2Repository.php';
require_once __DIR__.'/QuoteVs2Domain.php';

final class QuoteSmartCosting {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {return QuoteVs2Repository::q($db,$sql,$args);}
    public static function activate(PDO $db,array $u,int $id,array $body): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$body){
            $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$id,QuoteVs2Repository::expected($body));QuoteVs2Repository::mutable($db,$v);
            if(getenv('VTA_VS21_CREATION')==='0')throw new DomainException('VS21_CREATION_DISABLED');
            if(QuoteVs2Repository::engine($v)==='VS2_1')return ['version_id'=>$id,'costing_revision'=>(int)$v['costing_revision']];
            QuoteVs2Domain::guests($v,[]);$days=QuoteVs2Domain::schedule(QuoteVs2Repository::decode($v['schedule_json']??'[]'));
            self::q($db,"UPDATE quote_versions SET costing_engine='VS2_1',schedule_json=? WHERE id=?",[QuoteVs2Repository::json($days),$id]);
            $metadata=['suggestions'=>['hotel_pax'=>(int)$v['total_guests'],'cruise_pax'=>(int)$v['total_guests'],'meal_pax'=>(int)$v['total_guests']],'reviewed'=>[]];
            self::q($db,'INSERT INTO quote_guest_profiles(quote_version_id,review_metadata_json,updated_by) VALUES(?,?,?)',[$id,QuoteVs2Repository::json($metadata),$u['id']]);
            QuoteVs2Repository::finish($db,$v,$u,'VS21_ACTIVATED',['legacy_inputs_inactive'=>true]);return ['version_id'=>$id,'costing_revision'=>(int)$v['costing_revision']+1];
        });
    }
    public static function context(PDO $db,array $u,int $id,array $body): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$body){
            $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$id,QuoteVs2Repository::expected($body));QuoteVs2Repository::mutable($db,$v);
            if(QuoteVs2Repository::engine($v)!=='VS2_1')throw new DomainException('ACTIVATE_SMART_COSTING');
            $graph=QuoteVs2Repository::graph($db,$v);$profile=$graph['profile'];$before=QuoteVs2Domain::guests($v,$profile);$metadata=QuoteVs2Repository::decode($profile['review_metadata_json']);
            $fields=[];foreach(QuoteVs2Domain::BASE as $key)if(array_key_exists($key,$body))$fields[$key]=QuoteVs2Domain::count($body[$key]);
            foreach(QuoteVs2Domain::PROFILE as $key)if(array_key_exists($key,$body)){
                $profile[$key]=QuoteVs2Domain::count($body[$key],true);$metadata['reviewed'][$key]=['actor'=>(int)$u['id'],'reason'=>QuoteVs2Domain::text($body['review_reason']??'Guest population reviewed',1000)];
            }
            $next=array_replace($v,$fields);$after=QuoteVs2Domain::guests($next,$profile);$changed=[];
            foreach(QuoteVs2Domain::SOURCES as $source)if($source!=='CUSTOM_QTY'&&$before[strtolower($source)]!==$after[strtolower($source)])$changed[]=$source;
            foreach(['tour_name','included_text','excluded_text','terms_text'] as $key)if(isset($body[$key]))$fields[$key]=QuoteVs2Domain::text($body[$key],10000,true);
            foreach(['start_date','end_date'] as $key)if(array_key_exists($key,$body))$fields[$key]=QuoteVs2Domain::date($body[$key]);
            if(isset($body['document_language'])){if(!in_array($body['document_language'],['en','vi'],true))throw new InvalidArgumentException('Invalid language');$fields['document_language']=$body['document_language'];}
            if(isset($body['schedule']))$fields['schedule_json']=QuoteVs2Repository::json(QuoteVs2Domain::schedule($body['schedule']));
            if(isset($body['fx_rate'])){Auth::requirePermission($db,$u,'quote.view_cost');$fx=Vs2Decimal::parse($body['fx_rate'],6);if($fx<1||$fx>1000000000000)throw new InvalidArgumentException('Invalid FX');$fields['fx_rate']=Vs2Decimal::format($fx,6);}
            if($fields)self::q($db,'UPDATE quote_versions SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($fields))).' WHERE id=?',[...array_values($fields),$id]);
            self::q($db,'UPDATE quote_guest_profiles SET hotel_pax=?,cruise_pax=?,visa_pax=?,meal_pax=?,ticket_pax=?,review_metadata_json=?,updated_by=? WHERE quote_version_id=?',[...array_map(fn($key)=>$profile[$key],QuoteVs2Domain::PROFILE),QuoteVs2Repository::json($metadata),$u['id'],$id]);
            foreach($body['requirements']??[] as $req)self::requirement($db,$u,$id,$req);
            $current=QuoteOptions::version($db,(int)$u['company_id'],$id);
            $scopeChanged=QuoteVs2Domain::hash(QuoteVs2Domain::itineraryScope($current))!==QuoteVs2Domain::hash(QuoteVs2Domain::itineraryScope($v));
            $updates=SmartCosting::refresh($db,$current,$changed,isset($fields['fx_rate']),$body['requirements']??[]);
            // Coverage follows explicit populations, but changing an unrelated source must not rerun line arithmetic.
            Vs2Inclusions::apply($db,$current);
            if($changed||$scopeChanged)self::q($db,'UPDATE quote_variant_cost_lines l JOIN quote_option_variants c ON c.id=l.variant_id JOIN quote_options o ON o.id=c.quote_option_id SET l.review_required=1 WHERE o.quote_version_id=? AND (l.quantity_override IS NOT NULL OR l.formula_code=\'TRANSFER_PACKAGE\')',[$id]);
            QuoteVs2Repository::finish($db,$v,$u,'VS21_CONTEXT_UPDATED',['changed_sources'=>$changed,'scope_changed'=>$scopeChanged,'requirement_count'=>count($body['requirements']??[]),'recalculated'=>$updates]);
            return ['costing_revision'=>(int)$v['costing_revision']+1,'recalculated_line_ids'=>$updates];
        });
    }
    public static function requirement(PDO $db,array $u,int $version,array $input): int {
        $old=[];$id=(int)($input['id']??0);
        if($id){$old=self::q($db,'SELECT * FROM quote_service_requirements WHERE quote_version_id=? AND id=?',[$version,$id])->fetch();if(!$old)throw new OutOfBoundsException('Requirement not found');}
        $r=array_replace(['requirement_key'=>'req-'.bin2hex(random_bytes(8)),'day_key'=>null,'sort_order'=>0,'service_date'=>null,'service_end_date'=>null,'service_units'=>null,'service_mode'=>'BOTH','requirement_state'=>'NEEDS_REVIEW','scope_json'=>'{}','metadata_json'=>'{}','package_requirement_id'=>null,'package_component_key'=>null],$old,$input);
        $category=$r['category']??'';if(!isset(QuoteVs2Domain::FORMULAS[$category]))throw new InvalidArgumentException('Invalid service category');
        $source=$r['default_quantity_source']??'';if(!in_array($source,QuoteVs2Domain::SOURCES,true))throw new InvalidArgumentException('Quantity source required');
        if(!in_array($r['service_mode'],['BOTH','PRIVATE','SIC'],true)||!in_array($r['requirement_state'],['NEEDS_REVIEW','REQUIRED','NOT_APPLICABLE'],true))throw new InvalidArgumentException('Invalid service state');
        $scope=$input['scope']??QuoteVs2Repository::decode($r['scope_json']);$meta=$input['metadata']??QuoteVs2Repository::decode($r['metadata_json']);
        if(!is_array($scope)||!is_array($meta))throw new InvalidArgumentException('Structured service scope required');
        if($r['requirement_state']==='NOT_APPLICABLE'){QuoteVs2Domain::text($meta['reason']??'');$meta['reviewed_by']=(int)$u['id'];}
        if($r['day_key']){$days=QuoteVs2Repository::decode(self::q($db,'SELECT schedule_json FROM quote_versions WHERE id=?',[$version])->fetchColumn());if(!in_array($r['day_key'],array_column($days,'day_key'),true))throw new InvalidArgumentException('Day not in this itinerary');}
        if($r['package_requirement_id']){
            $seen=[$id];$parent=(int)$r['package_requirement_id'];while($parent){if(in_array($parent,$seen,true))throw new DomainException('INCLUSION_CYCLE');$seen[]=$parent;$p=self::q($db,'SELECT category,package_requirement_id FROM quote_service_requirements WHERE quote_version_id=? AND id=?',[$version,$parent])->fetch();if(!$p||!in_array($p['category'],['TOUR','CRUISE'],true))throw new InvalidArgumentException('Package not in this version');$parent=(int)$p['package_requirement_id'];}
        }
        $data=['requirement_key'=>QuoteVs2Domain::text($r['requirement_key'],80),'day_key'=>$r['day_key'],'sort_order'=>QuoteVs2Domain::count($r['sort_order']),'category'=>$category,'service_name'=>QuoteVs2Domain::text($r['service_name']??'',255),'service_date'=>QuoteVs2Domain::date($r['service_date']),'service_end_date'=>QuoteVs2Domain::date($r['service_end_date']),'service_units'=>QuoteVs2Domain::count($r['service_units'],true),'default_quantity_source'=>$source,'service_mode'=>$r['service_mode'],'requirement_state'=>$r['requirement_state'],'scope_json'=>QuoteVs2Repository::json($scope),'package_requirement_id'=>$r['package_requirement_id']?:null,'package_component_key'=>$r['package_component_key'],'metadata_json'=>QuoteVs2Repository::json($meta),'updated_by'=>$u['id']];
        if($id)self::q($db,'UPDATE quote_service_requirements SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($data))).' WHERE id=?',[...array_values($data),$id]);
        else{self::q($db,'INSERT INTO quote_service_requirements(quote_version_id,'.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data)+1,'?')).')',[$version,...array_values($data)]);$id=(int)$db->lastInsertId();}
        return $id;
    }
}
