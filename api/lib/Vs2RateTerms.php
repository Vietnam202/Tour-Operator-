<?php
declare(strict_types=1);

final class Vs2RateTerms {
    public static function save(PDO $db,array $u,int $id,array $body): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$body){
            Auth::requirePermission($db,$u,'rate.edit');$r=QuoteVs2Repository::q($db,'SELECT rv.*,r.category,r.company_id FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id WHERE r.company_id=? AND rv.id=? FOR UPDATE',[$u['company_id'],$id])->fetch();if(!$r)throw new OutOfBoundsException('Rate not found');
            $old=QuoteVs2Repository::q($db,'SELECT * FROM rate_version_vs2_terms WHERE rate_version_id=? FOR UPDATE',[$id])->fetch();if($old&&$old['approval_state']==='APPROVED')throw new DomainException('APPROVED_TERMS_IMMUTABLE: Create an existing rate revision');
            $formula=$body['formula_code']??'';if(!in_array($formula,[...array_values(QuoteVs2Domain::FORMULAS),'LUMP_SUM'],true))throw new InvalidArgumentException('Invalid formula');
            $source=$body['rate_eligibility_source']??'TOTAL_GUESTS';if(!in_array($source,array_slice(QuoteVs2Domain::SOURCES,0,7),true))throw new InvalidArgumentException('Invalid eligibility source');
            $star=$body['star_level']??null;if($star!==null&&!in_array((int)$star,[3,4,5],true))throw new InvalidArgumentException('Invalid star');
            $evidence=$body['basis_evidence']??[];QuoteVs2Domain::text($evidence['evidence']??'');$scope=$body['package_scope']??[];if(!is_array($scope))throw new InvalidArgumentException('Structured scope required');
            $publish=!empty($body['publish']);if($publish){Auth::requirePermission($db,$u,'rate.approve');if($r['approval_status']!=='APPROVED')throw new DomainException('Approve the existing supplier rate first');}
            $data=['formula_code'=>$formula,'star_level'=>$star,'capacity'=>QuoteVs2Domain::count($body['capacity']??null,true),'rate_eligibility_source'=>$source,'package_scope_json'=>QuoteVs2Repository::json($scope),'basis_evidence_json'=>QuoteVs2Repository::json($evidence),'approval_state'=>$publish?'APPROVED':'DRAFT','updated_by'=>$u['id']];
            if($old)QuoteVs2Repository::q($db,'UPDATE rate_version_vs2_terms SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($data))).' WHERE rate_version_id=?',[...array_values($data),$id]);
            else QuoteVs2Repository::q($db,'INSERT INTO rate_version_vs2_terms(rate_version_id,'.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data)+1,'?')).')',[$id,...array_values($data)]);
            QuoteVs2Repository::q($db,'DELETE FROM rate_version_inclusions WHERE rate_version_id=?',[$id]);$rules=$body['inclusions']??[];if(count($rules)>100)throw new InvalidArgumentException('Too many inclusions');
            foreach($rules as $i=>$rule){if(!isset(QuoteVs2Domain::FORMULAS[$rule['included_category']??'']))throw new InvalidArgumentException('Invalid inclusion category');
                QuoteVs2Repository::q($db,'INSERT INTO rate_version_inclusions(rate_version_id,component_key,included_category,scope_rule_json,coverage_rule_json,description,sort_order) VALUES(?,?,?,?,?,?,?)',[$id,QuoteVs2Domain::text($rule['component_key']??'',80),$rule['included_category'],QuoteVs2Repository::json($rule['scope']??[]),QuoteVs2Repository::json($rule['coverage']??[]),QuoteVs2Domain::text($rule['description']??''),$i]);
            }
            $hash=QuoteVs2Domain::hash([$data,$rules]);if($publish)QuoteVs2Repository::q($db,'UPDATE rate_version_vs2_terms SET content_hash=?,approved_by=?,approved_at=NOW() WHERE rate_version_id=?',[$hash,$u['id'],$id]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],$publish?'VS21_RATE_TERMS_APPROVED':'VS21_RATE_TERMS_SAVED','rate_version',$id,null,['content_hash'=>$hash]);return ['rate_version_id'=>$id,'approval_state'=>$data['approval_state']];
        });
    }
}
