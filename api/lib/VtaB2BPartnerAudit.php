<?php
declare(strict_types=1);

/**
 * RC6.2 M07 — PartnerHub read-only agency/revision/NET/white-label audit.
 * Preserves existing B2B publishing, quotation, identity and request flows.
 *
 * No pricing recomputation, no public exports, no media reads, no writes.
 * Proof of a SENT quote link is NOT confirmation of supplier availability,
 * a new rate approval, booking, independent FX or owner signoff.
 */
final class VtaB2BPartnerAudit
{
    public const FLAG='VTA_RC62_B2B_APPROVAL_REVIEW';
    public const SCHEMA='VTA_B2B_APPROVAL_REVIEW_1';

    public static function tour(PDO $db,array $user,int $workId,?int $expectedRevision,DateTimeImmutable $clock):array
    {
        self::actor($db,$user);
        if($workId<1||($expectedRevision!==null&&$expectedRevision<1))
            throw new InvalidArgumentException('Valid partner tour ID/revision required');
        $company=(int)$user['company_id'];
        $q=$db->prepare("SELECT w.id,w.company_id,w.agency_id,w.publication_id,w.revision,w.content_json,w.markup_type,w.markup_value,
                a.status agency_status,a.brand_name,a.brand_color,a.logo_path,a.logo_sha256,
                p.status publication_status,p.distribution_scope,p.exclusive_agency_id,p.version_no,
                p.rate_json,p.source_quote_version_id
             FROM b2b_partner_tours w
             JOIN b2b_agencies a ON a.id=w.agency_id AND a.company_id=w.company_id
             JOIN b2b_publications p ON p.id=w.publication_id AND p.company_id=w.company_id
             WHERE w.company_id=? AND w.id=? LIMIT 1");
        $q->execute([$company,$workId]);
        $w=$q->fetch(PDO::FETCH_ASSOC);
        if(!$w)throw new OutOfBoundsException('Partner tour unavailable');
        self::scope($db,$user,$w);
        $warn=[];
        $warnAdd=static function(string $code,string $severity='REVIEW')use(&$warn):void{
            $warn[$code]=['code'=>$code,'severity'=>$severity];
        };
        $rev=(int)$w['revision'];
        $drift=$expectedRevision!==null&&$expectedRevision!==$rev;
        if($drift)$warnAdd('WORKING_REVISION_CHANGED');
        $content=$w['content_json'];
        if(!is_string($content)||strlen($content)>1000000)
            throw new InvalidArgumentException('Partner presentation JSON exceeds review limit');
        try{$document=json_decode($content,true,30,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){$document=null;}
        if(!is_array($document)){
            $warnAdd('PARTNER_PRESENTATION_INVALID','BLOCK');
            $document=[];
        }
        foreach(['supplier_cost','supplier_costs','cost_total_vnd','net_per_pax','supplier_net',
                'margin_amount','profit_amount','internal_notes','source_text','guest_details','rate_sources',
                'pricing_result_json','rate_json'] as $key){
            if(self::containsKey($document,$key))$warnAdd('PRIVATE_FIELD_IN_PARTNER_CONTENT','BLOCK');
        }
        $branding=self::branding($w,$warnAdd);
        $rate=self::rates($w,$clock,$warnAdd);
        $source=self::quoteSource($db,$company,self::positive($w['source_quote_version_id']??null));
        if($rate['has_cells']&&$source['status']!=='SENT_OR_CONFIRMED_SOURCE')
            $warnAdd('PUBLISHED_NET_SOURCE_QUOTE_NOT_VERIFIED');
        if(!$rate['has_cells'])$warnAdd('NO_APPROVED_PUBLICATION_NET_USE_REQUEST_NET','INFO');
        $warnAdd('CLIENT_EXPORT_MUST_EXCLUDE_AGENCY_NET_AND_MARKUP','INFO');
        return [
            'schema'=>self::SCHEMA,
            'state'=>'READ_ONLY_REVIEW_NO_RATE_OR_PUBLICATION_CHANGE',
            'subject'=>'PARTNER_TOUR',
            'partner_tour_id'=>$workId,
            'agency_id'=>(int)$w['agency_id'],
            'publication_id'=>(int)$w['publication_id'],
            'publication_version_no'=>(int)$w['version_no'],
            'distribution_scope'=>(string)$w['distribution_scope'],
            'revision'=>$rev,
            'expected_revision_matches'=>$expectedRevision===null?null:!$drift,
            'content_sha256'=>hash('sha256',$content),
            'content_blocks_or_days'=>[
                'day_count'=>is_array($document['days']??null)?count($document['days']):null,
                'has_inclusions'=>is_string($document['included_text']??null)&&trim($document['included_text'])!=='',
                'has_terms'=>is_string($document['terms_text']??null)&&trim($document['terms_text'])!=='',
            ],
            'branding'=>$branding,
            'net_publication'=>$rate,
            'publication_quote_source_status'=>$source['status'],
            'warnings'=>array_values($warn),
            'limits'=>[
                'Only existing agency-scoped metadata; no raw content, brand contact, logo path, NET, markup, supplier cost or profit in response.',
                'Source quote link and publication-rate metadata do not independently verify supplier booking, net price approval or owner signoff.',
                'Actual white-label DOCX/PDF client/agency export bytes and expiry/revocation require separate authenticated staging UAT.',
            ],
        ];
    }

    public static function netRequest(PDO $db,array $user,int $requestId,DateTimeImmutable $clock):array
    {
        self::actor($db,$user);
        if($requestId<1)throw new InvalidArgumentException('Valid NET request ID required');
        $company=(int)$user['company_id'];
        $q=$db->prepare("SELECT n.id,n.company_id,n.agency_id,n.partner_tour_id,n.publication_id,n.status,
                n.departure_date,n.paying_pax,n.foc_pax,n.mode,n.hotel_level,n.cruise_level,
                n.request_key_hash,n.request_payload_hash,n.source_quote_version_id,n.assigned_to_user_id,
                n.resolved_at,
                a.status agency_status,
                w.agency_id working_agency_id,w.publication_id working_publication_id,w.revision,
                p.status publication_status,p.distribution_scope,p.exclusive_agency_id,p.rate_json,p.version_no
             FROM b2b_net_requests n
             JOIN b2b_agencies a ON a.id=n.agency_id AND a.company_id=n.company_id
             JOIN b2b_partner_tours w ON w.id=n.partner_tour_id AND w.company_id=n.company_id
             JOIN b2b_publications p ON p.id=n.publication_id AND p.company_id=n.company_id
             WHERE n.company_id=? AND n.id=? LIMIT 1");
        $q->execute([$company,$requestId]);
        $n=$q->fetch(PDO::FETCH_ASSOC);
        if(!$n)throw new OutOfBoundsException('NET request unavailable');
        // Inconsistent underlying joins must not disclose a foreign agency's
        // document/NET state or approve a broken request.
        if((int)$n['working_agency_id']!==(int)$n['agency_id']
          ||(int)$n['working_publication_id']!==(int)$n['publication_id'])
            throw new OutOfBoundsException('NET request unavailable');
        self::scope($db,$user,$n);
        $warn=[];
        $warnAdd=static function(string $code,string $severity='REVIEW')use(&$warn):void{
            $warn[$code]=['code'=>$code,'severity'=>$severity];
        };
        $hasKey=is_string($n['request_key_hash']??null)
            &&preg_match('/^[a-f0-9]{64}$/D',(string)$n['request_key_hash'])===1;
        $hasPayload=is_string($n['request_payload_hash']??null)
            &&preg_match('/^[a-f0-9]{64}$/D',(string)$n['request_payload_hash'])===1;
        if(!$hasKey||!$hasPayload)$warnAdd('NET_REQUEST_IDEMPOTENCY_PROOF_MISSING','BLOCK');
        $quoteId=self::positive($n['source_quote_version_id']??null);
        $source=self::quoteSource($db,$company,$quoteId);
        $status=(string)$n['status'];
        if($status==='CLOSED'){
            if($quoteId===null)$warnAdd('HISTORICAL_CLOSED_NET_HAS_NO_QUOTE_SOURCE');
            elseif($source['status']!=='SENT_OR_CONFIRMED_SOURCE')
                $warnAdd('CLOSED_NET_SOURCE_NOT_ISSUED','BLOCK');
        }else{
            $warnAdd('NET_REQUEST_PENDING_OPERATOR_RESOLUTION');
        }
        if($source['status']==='SENT_OR_CONFIRMED_SOURCE'){
            if($source['start_date']!==(string)$n['departure_date']
               ||$source['paying_pax']!==(int)$n['paying_pax']
               ||$source['foc']!==(int)$n['foc_pax'])
                $warnAdd('ISSUED_SOURCE_TRAVEL_OR_PAX_MISMATCH','BLOCK');
            // Only original PartnerHub::matchNetRequest authoritatively
            // evaluates mode/hotel/cruise against selected approved cells.
            $warnAdd('NET_CELL_SELECTION_REQUIRES_EXISTING_APPROVAL_FLOW','INFO');
        }
        $rates=self::rates($n,$clock,$warnAdd);
        if(!$rates['has_cells']||$rates['expiry_status']!=='FUTURE_VALIDITY_DATE')
            $warnAdd('PUBLICATION_NET_NOT_CURRENTLY_QUOTABLE');
        $owner=self::positive($n['assigned_to_user_id']??null);
        if($owner!==null){
            $q=$db->prepare("SELECT id FROM users WHERE id=? AND company_id=? AND status='ACTIVE' LIMIT 1");
            $q->execute([$owner,$company]);
            if(!$q->fetchColumn())$warnAdd('NET_REQUEST_OWNER_NOT_ACTIVE');
        }elseif(in_array($status,['REQUESTED','IN_REVIEW'],true))
            $warnAdd('NET_REQUEST_UNASSIGNED');
        $warnAdd('NET_IS_NOT_AN_OPERATIONS_BOOKING_CONFIRMATION','INFO');
        return [
            'schema'=>self::SCHEMA,
            'state'=>'READ_ONLY_REVIEW_NO_NET_APPROVAL',
            'subject'=>'NET_REQUEST',
            'net_request_id'=>$requestId,
            'agency_id'=>(int)$n['agency_id'],
            'partner_tour_id'=>(int)$n['partner_tour_id'],
            'publication_id'=>(int)$n['publication_id'],
            'request_status'=>$status,
            'idempotency_metadata_complete'=>$hasKey&&$hasPayload,
            'assigned_owner_present'=>$owner!==null,
            'source_quote_version_id'=>$quoteId,
            'source_quote_status'=>$source['status'],
            'source_travel_pax_consistency'=>$source['status']!=='SENT_OR_CONFIRMED_SOURCE'
                ?'UNVERIFIED'
                :($source['start_date']===(string)$n['departure_date']
                    &&$source['paying_pax']===(int)$n['paying_pax']
                    &&$source['foc']===(int)$n['foc_pax']?'MATCH':'MISMATCH'),
            'rate_expiry_status'=>$rates['expiry_status'],
            'warnings'=>array_values($warn),
            'limits'=>[
                'No raw NET request key, rate amount, customer guest/contact, source quote price, markup, margin or supplier quote bytes.',
                'A CLOSED request is not a confirmed booking; original NET closure must check exactly matching hotel/cruise/mode and PAX.',
                'No new supplier approval, price, export, booking request or customer contact is produced.',
            ],
        ];
    }

    private static function actor(PDO $db,array $user):void
    {
        if(getenv(self::FLAG)!=='1')throw new OutOfBoundsException('Review unavailable');
        $session=Auth::requireUser($db);
        if((int)($session['id']??0)!==(int)($user['id']??-1)
           ||(int)($session['company_id']??0)!==(int)($user['company_id']??-1))
            Http::json(['ok'=>false,'error'=>'AUTH_SCOPE_MISMATCH'],401);
        Auth::checkCsrf();
        if(!Auth::can($db,(int)$user['id'],'b2b.admin'))
            Auth::requirePermission($db,$user,'b2b.portal');
    }

    private static function scope(PDO $db,array $user,array $row):void
    {
        if(($row['agency_status']??null)!=='ACTIVE'
           ||($row['publication_status']??null)!=='PUBLISHED')
            throw new OutOfBoundsException('B2B workspace unavailable');
        $agency=(int)$row['agency_id'];
        $scope=(string)($row['distribution_scope']??'');
        if($scope!=='COMMON'&&($scope!=='EXCLUSIVE'
            ||(int)($row['exclusive_agency_id']??0)!==$agency))
            throw new OutOfBoundsException('B2B workspace unavailable');
        if(Auth::can($db,(int)$user['id'],'b2b.admin'))return;
        $q=$db->prepare('SELECT 1 FROM b2b_agency_members WHERE company_id=? AND agency_id=? AND user_id=? LIMIT 1');
        $q->execute([(int)$user['company_id'],$agency,(int)$user['id']]);
        if(!$q->fetchColumn())throw new OutOfBoundsException('B2B workspace unavailable');
    }

    /** Only snapshot metadata, never raw brand strings/path/logo bytes. */
    private static function branding(array $w,callable $warning):array
    {
        $brand=$w['brand_name']??null;
        $color=$w['brand_color']??null;
        $logoPath=$w['logo_path']??null;
        $logoHash=$w['logo_sha256']??null;
        if(!is_string($brand)||trim($brand)==='')$warning('WHITE_LABEL_BRAND_NAME_MISSING');
        if(!is_string($color)||preg_match('/^#[a-f0-9]{6}$/iD',$color)!==1)
            $warning('WHITE_LABEL_COLOR_INVALID');
        if($logoPath!==null&&$logoPath!==''&&
           (!is_string($logoHash)||preg_match('/^[a-f0-9]{64}$/iD',$logoHash)!==1))
            $warning('WHITE_LABEL_LOGO_HASH_UNVERIFIED');
        return [
            'mode'=>'EXISTING_WHITE_LABEL',
            'brand_name_present'=>is_string($brand)&&trim($brand)!=='',
            'brand_color_valid'=>is_string($color)&&preg_match('/^#[a-f0-9]{6}$/iD',$color)===1,
            'logo_configured'=>is_string($logoPath)&&$logoPath!=='',
            'logo_bytes_or_access_checked'=>false,
            'preview_status'=>'METADATA_ONLY_ACTUAL_DOCX_PDF_PENDING',
        ];
    }

    private static function rates(array $row,DateTimeImmutable $clock,callable $warning):array
    {
        $raw=$row['rate_json']??null;
        $rates=null;
        if(is_string($raw)&&strlen($raw)<=100000){
            try{$r=json_decode($raw,true,20,JSON_THROW_ON_ERROR);if(is_array($r))$rates=$r;}
            catch(JsonException $e){}
        }
        if($rates===null){
            $warning('PUBLICATION_NET_SNAPSHOT_INVALID');
            return ['has_cells'=>false,'expiry_status'=>'UNVERIFIED','cells_count_bounded'=>null];
        }
        $cells=$rates['cells']??null;
        $count=is_array($cells)&&array_is_list($cells)?count($cells):0;
        if($count>300){$warning('PUBLICATION_NET_CELLS_EXCEED_AUDIT_LIMIT');$count=0;}
        $expiry=$rates['valid_until']??null;
        $expiryStatus='UNVERIFIED';
        if(is_string($expiry)&&preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$expiry)){
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$expiry,$clock->getTimezone());
            if($d&&$d->format('Y-m-d')===$expiry)
                $expiryStatus=$expiry>=$clock->format('Y-m-d')
                    ?'FUTURE_VALIDITY_DATE':'EXPIRED';
        }
        if($expiryStatus!=='FUTURE_VALIDITY_DATE')$warning('PUBLICATION_NET_EXPIRED_OR_UNKNOWN');
        return ['has_cells'=>$count>0,'expiry_status'=>$expiryStatus,'cells_count_bounded'=>$count];
    }

    private static function quoteSource(PDO $db,int $company,?int $version):array
    {
        if($version===null)return ['status'=>'NO_LINKED_QUOTE','start_date'=>null,'paying_pax'=>null,'foc'=>null];
        $q=$db->prepare('SELECT v.version_status,v.start_date,v.paying_pax,v.foc
            FROM quote_versions v JOIN quotes q ON q.id=v.quote_id AND q.company_id=?
            WHERE v.id=? LIMIT 1');
        $q->execute([$company,$version]);
        $v=$q->fetch(PDO::FETCH_ASSOC);
        if(!$v)return ['status'=>'QUOTE_NOT_FOUND_IN_COMPANY','start_date'=>null,'paying_pax'=>null,'foc'=>null];
        return [
            'status'=>in_array($v['version_status'],['SENT','CONFIRMED'],true)
                ?'SENT_OR_CONFIRMED_SOURCE':'QUOTE_NOT_ISSUED',
            'start_date'=>(string)$v['start_date'],
            'paying_pax'=>(int)$v['paying_pax'],
            'foc'=>(int)$v['foc'],
        ];
    }
    private static function positive(mixed $v):?int
    {
        if(is_int($v))return $v>0?$v:null;
        if(is_string($v)&&preg_match('/^[1-9][0-9]{0,17}$/D',$v))return (int)$v;
        return null;
    }
    private static function containsKey(array $value,string $needle,int $depth=0):bool
    {
        if($depth>32)return true;
        foreach($value as $key=>$entry){
            if($key===$needle)return true;
            if(is_array($entry)&&self::containsKey($entry,$needle,$depth+1))return true;
        }
        return false;
    }
}
