<?php
declare(strict_types=1);

/**
 * M02 — OPTIONAL source-linked review for the EXISTING VtaAiItinerary::validate
 * result. This class is pure, has no AI/provider calls, database or state writes.
 *
 * Important: submitted evidence links are UNVERIFIED claims. The existing
 * preview->session token->explicit human ack->NEW DRAFT chain stays authoritative.
 * These reports must NEVER be used as authorization for commit/publishing.
 */
final class VtaAiEvidenceReview
{
    public const SCHEMA = 'VTA_AI_EVIDENCE_REVIEW_1';
    public const MAX_DAYS = 90;
    public const MAX_CHANGES = 600;
    private const DAY_FIELDS = ['date','title','description','meals','overnight','notes'];
    private const PRICE_FIELDS = ['3star'=>'three','4star'=>'four','5star'=>'five'];
    private const REASONS = [
        'GUEST_REQUEST','SOURCE_CORRECTION','ROUTE_OPTIMIZATION',
        'SUPPLIER_UPDATE','SEASONAL_REVIEW','LANGUAGE_REFINEMENT','OTHER',
    ];
    private const SOURCES = [
        'MASTER_PROGRAM','SUPPLIER_DOCUMENT','OPERATOR_NOTE',
        'OFFICIAL_PUBLIC_SOURCE','CUSTOMER_REQUEST',
    ];

    /**
     * @param array $source Existing company-scoped TourLibrary::publicProgram(...)
     * @param array $v Already normalized/validated by VtaAiItinerary::validate()
     * @param array $review Input reason/evidence records, never trusted as verified
     */
    public static function inspect(array $source, array $v, array $review): array
    {
        $sourceId=self::positiveInt($source['id']??null);
        $targetId=self::positiveInt($review['source_program_id']??null);
        $sourceRevision=$review['source_revision']??null;
        if($sourceId===null||$sourceId!==$targetId
            ||!is_string($sourceRevision)||preg_match('/^[a-f0-9]{64}$/iD',$sourceRevision)!==1)
            throw new DomainException('VERIFIED_MASTER_SOURCE_CONTEXT_REQUIRED');
        if(!in_array($v['agent']??null,['chatgpt','hermes','manual'],true)
            ||!is_array($v['days']??null)||!array_is_list($v['days'])
            ||count($v['days'])>self::MAX_DAYS)
            throw new InvalidArgumentException('Validated original AI itinerary shape required.');
        $days=$source['days']??[];
        if(!is_array($days)||!array_is_list($days)||count($days)>self::MAX_DAYS)
            throw new InvalidArgumentException('Bounded Master Tour days required.');
        if(!is_array($v['document']??null)||($v['document']['schema']??null)!=='VTA_DOC_2'
            ||!is_array($v['private_groups']??null)||count($v['private_groups'])!==5)
            throw new InvalidArgumentException('Validated editable Word and five existing private bands required.');
        $json=static function(mixed $data):string {
            return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        };
        $sourceBytes=$json($source);$validatedBytes=$json($v);
        if(strlen($sourceBytes)>1500000||strlen($validatedBytes)>1500000)
            throw new InvalidArgumentException('Review data exceeds the bounded source size.');

        $reasonMap=self::indexClaims($review['change_reasons']??[],'reason');
        $evidenceMap=self::indexClaims($review['evidence']??[],'evidence');
        $changes=[];$warnings=[];
        $addWarning=static function(string $code,string $severity='REVIEW') use (&$warnings):void {
            foreach($warnings as $w)if($w['code']===$code)return;
            $warnings[]=['code'=>$code,'severity'=>$severity];
        };
        $addChange=static function(string $path,string $kind,string $risk) use (&$changes,$reasonMap,$evidenceMap,$addWarning):void {
            if(count($changes)>=self::MAX_CHANGES)throw new InvalidArgumentException('Too many changes for independent review.');
            $reason=$reasonMap[$path]??null;$evidence=$evidenceMap[$path]??null;
            $changes[]=[
                'path'=>$path,'kind'=>$kind,'risk'=>$risk,
                'reason_code'=>$reason['code']??null,
                'reason_documented'=>$reason!==null,
                'evidence_status'=>$evidence===null?'MISSING':'SUPPLIED_NOT_INDEPENDENTLY_VERIFIED',
                'evidence_source_kind'=>$evidence['source_kind']??null,
            ];
            if($reason===null)$addWarning('CHANGE_REASON_REQUIRED');
            if($evidence===null)$addWarning('SOURCE_EVIDENCE_MISSING');
            else $addWarning('EVIDENCE_REQUIRES_INDEPENDENT_VERIFICATION');
        };

        if(($source['title']??'')!==($v['title']??''))$addChange('tour:title','TITLE','MEDIUM');
        $oldDoc=$source['proposal']['document']??null;
        if($json($oldDoc)!==$json($v['document'])){
            $addChange('word:document','WORD_DOCUMENT','HIGH');
            $addWarning('WORD_SOURCE_REVIEW_REQUIRED');
        }
        $max=max(count($days),count($v['days']));
        for($i=0;$i<$max;$i++){
            if(!isset($days[$i])||!isset($v['days'][$i])){
                $addChange('day:'.($i+1).':record','DAY_ADDED_OR_REMOVED','HIGH');
                continue;
            }
            if(!is_array($days[$i])||!is_array($v['days'][$i]))
                throw new InvalidArgumentException('Invalid day structure.');
            foreach(self::DAY_FIELDS as $key){
                $before=trim((string)($days[$i][$key]??''));
                $after=trim((string)($v['days'][$i][$key]??''));
                if($before!==$after){
                    $risk=in_array($key,['date','meals','overnight'],true)?'HIGH':'MEDIUM';
                    $addChange('day:'.($i+1).':'.$key,'DAY_FIELD',$risk);
                    if($key==='meals')$addWarning('MEALS_NOT_VERIFIED_BY_A_SUPPLIER');
                    if($key==='overnight')$addWarning('OVERNIGHT_AVAILABILITY_NOT_CHECKED');
                    if($key==='date')$addWarning('DATE_FEASIBILITY_NOT_CHECKED');
                    if($key==='description' && preg_match('/\b(?:confirmed|guaranteed|booked|sold out|đã xác nhận|chắc chắn)\b/iu',$after))
                        $addWarning('UNVERIFIED_CONFIRMATION_LANGUAGE');
                    if($key==='description' && preg_match('/\b(?:flight|airport|cable car|showtime|train|ferry|chuyến bay|vé cáp treo|giờ biểu diễn)\b/iu',$after))
                        $addWarning('TRANSFER_TICKET_SHOWTIME_RECHECK');
                }
            }
        }

        $oldGroups=[];
        foreach(($source['proposal']['private_prices']??[]) as $row){
            if(!is_array($row))continue;
            $min=self::positiveInt($row['min']??null);$max=self::positiveInt($row['max']??null);
            if($min!==null&&$min===$max)$oldGroups[$min]=$row;
        }
        $presentPax=[];$hasNumericPrice=false;
        foreach($v['private_groups'] as $row){
            if(!is_array($row)||!is_array($row['prices']??null))
                throw new InvalidArgumentException('Invalid validated private price grid.');
            $pax=self::positiveInt($row['pax']??null);
            if($pax===null||isset($presentPax[$pax]))throw new InvalidArgumentException('Duplicate/malformed pax band.');
            $presentPax[$pax]=true;
            foreach(self::PRICE_FIELDS as $tier=>$field){
                $value=$row['prices'][$tier]??null;
                if($value!==null)$hasNumericPrice=true;
                $original=$oldGroups[$pax][$field]??null;
                $original=$original===null||$original===''?null:(string)$original;
                $next=$value===null||$value===''?null:(string)$value;
                if($original!==$next)$addChange('price:'.$pax.':'.$tier,'SELLING_PRICE','CRITICAL');
            }
        }
        if($hasNumericPrice){
            $addWarning('SELLING_PRICES_NOT_VERIFIED_AGAINST_SUPPLIER_OR_FX');
            if(($v['selling_prices_reviewed']??false)!==true)$addWarning('SELLING_PRICE_OPERATOR_ACK_MISSING','BLOCK');
            else $addWarning('SELLING_PRICE_OPERATOR_ACK_IS_NOT_SUPPLIER_APPROVAL');
        }else $addWarning('NO_VERIFIED_PRICE_KEEP_ON_REQUEST','INFO');
        if(count($v['days'])===0)$addWarning('DAY_LIST_EMPTY_REVIEW_WORD','REVIEW');
        if(($v['source_type']??null)==='SIC')$addWarning('PRIVATE_VS_SIC_SERVICE_REVIEW_REQUIRED');
        $addWarning('HUMAN_PREVIEW_AND_SEPARATE_COMMIT_ACK_REQUIRED');

        // Reasons/evidence can only refer to actual differences, preventing
        // silent acceptance of generic/broad blanket approvals.
        $paths=array_fill_keys(array_column($changes,'path'),true);
        foreach(array_keys($reasonMap) as $path)if(!isset($paths[$path]))$addWarning('REASON_REFERENCES_UNCHANGED_FIELD');
        foreach(array_keys($evidenceMap) as $path)if(!isset($paths[$path]))$addWarning('EVIDENCE_REFERENCES_UNCHANGED_FIELD');
        $blocks=array_filter($warnings,static fn($w)=>$w['severity']==='BLOCK');
        $missing=count(array_filter($changes,static fn($c)=>!$c['reason_documented']||$c['evidence_status']==='MISSING'));
        $hash=hash('sha256',$validatedBytes);
        return [
            'schema'=>self::SCHEMA,
            'state'=>'ADVISORY_ONLY_NO_REVIEW_TOKEN_NO_COMMIT',
            'verdict'=>$blocks?'BLOCKED_REVIEW':'REVIEW_REQUIRED',
            'source_program_id'=>$sourceId,'source_revision'=>$sourceRevision,
            'source_sha256'=>hash('sha256',$sourceBytes),
            'validated_candidate_sha256'=>$hash,
            'source_day_count'=>count($days),'candidate_day_count'=>count($v['days']),
            'private_group_pax'=>array_map(static fn($row)=>(int)$row['pax'],$v['private_groups']),
            'changed_fields'=>count($changes),'changes_needing_explanation_or_evidence'=>$missing,
            'changes'=>$changes,'warnings'=>$warnings,
            'constraints'=>[
                'Provenance references supplied in the request are never proof of actual supplier/public facts.',
                'No raw document text, monetary value or customer/supplier data is returned in change records.',
                'No provider call, no saved source mutation, no session review token, no draft creation or price activation.',
                'The existing authenticated VtaAiItinerary::preview/commit and owner confirmation remain mandatory.',
            ],
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        if(is_int($value))return $value>0?$value:null;
        if(is_string($value)&&preg_match('/^[1-9][0-9]{0,8}$/D',$value)===1)return (int)$value;
        return null;
    }
    /** @return array<string,array<string,mixed>> */
    private static function indexClaims(mixed $input,string $type):array
    {
        if(!is_array($input)||!array_is_list($input)||count($input)>self::MAX_CHANGES)
            throw new InvalidArgumentException('Evidence and reason records must be bounded lists.');
        $map=[];
        foreach($input as $item){
            if(!is_array($item))throw new InvalidArgumentException('Invalid review record.');
            $path=$item['path']??null;
            if(!is_string($path)||strlen($path)>80||
               preg_match('/^(?:tour:title|word:document|day:[1-9][0-9]{0,2}:(?:record|date|title|description|meals|overnight|notes)|price:[1-9][0-9]{0,3}:(?:3star|4star|5star))$/D',$path)!==1)
                throw new InvalidArgumentException('Review record path invalid.');
            if(isset($map[$path]))throw new InvalidArgumentException('Duplicate review record.');
            if($type==='reason'){
                $code=$item['code']??null;$note=$item['note']??null;
                if(!in_array($code,self::REASONS,true)||!is_string($note)||strlen(trim($note))<12
                   ||strlen($note)>300||preg_match('//u',$note)!==1)
                    throw new InvalidArgumentException('Specific reason code and a 12–300 character note are required.');
                $map[$path]=['code'=>$code];
            }else{
                $kind=$item['source_kind']??null;$ref=$item['reference']??null;
                $hash=$item['source_sha256']??null;
                if(!in_array($kind,self::SOURCES,true)||!is_string($ref)||strlen(trim($ref))<3||strlen($ref)>160
                   ||preg_match('//u',$ref)!==1
                   ||!is_string($hash)||preg_match('/^[a-f0-9]{64}$/iD',$hash)!==1)
                    throw new InvalidArgumentException('Evidence source kind, reference and content digest required.');
                $map[$path]=['source_kind'=>$kind];
            }
        }
        return $map;
    }
}
