<?php
declare(strict_types=1);

/**
 * RC6.2 M06 — additive, opt-in, read-only LeadHub request journey review.
 *
 * This DOES NOT replace LeadHub::submit() submission_key idempotency, the
 * LeadSalesHandover identity review/accept/convert workflow, or Customer360.
 * Candidate matches are suggestions, NEVER verified customer identities.
 *
 * Intentionally no INSERT/UPDATE/DELETE, network, logging, phone provider,
 * customer merge, outbox event, task assignment or automated contact.
 */
final class VtaLeadJourneyReview
{
    public const SCHEMA='VTA_LEAD_JOURNEY_REVIEW_1';
    public const FLAG='VTA_RC62_LEAD_JOURNEY_REVIEW';
    public const MAX_SCAN=300;
    private const MATCH_LIMIT=15;
    private const UTM=['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id','referrer','landing_url'];

    /**
     * Authorized, bounded database inspection. Authorization is rechecked
     * even though LeadHub and api/index.php already enforce session+CSRF.
     *
     * @return array<string,mixed> Safe internal metadata without PII or values.
     */
    public static function review(PDO $db,array $caller,int $requestId,DateTimeImmutable $asOf):array
    {
        if(getenv(self::FLAG)!=='1')throw new DomainException('M06_FEATURE_DISABLED');
        if($requestId<=0)throw new InvalidArgumentException('Positive lead request ID required');
        $session=Auth::requireUser($db);
        if((int)($session['id']??0)!==(int)($caller['id']??-1)
            ||(int)($session['company_id']??0)!==(int)($caller['company_id']??-1))
            Http::json(['ok'=>false,'error'=>'AUTH_SCOPE_MISMATCH'],401);
        Auth::checkCsrf();
        Auth::requirePermission($db,$caller,'lead.view');
        Auth::requirePermission($db,$caller,'sales.view');
        $cid=(int)$session['company_id'];
        if($cid<=0)throw new DomainException('MISSING_COMPANY_SCOPE');

        $s=$db->prepare('SELECT r.id,r.company_id,r.form_id,r.campaign_id,r.source,r.submission_key,r.payload_hash,
                r.contact_name,r.email,r.phone,r.travel_date,r.destination,r.status,r.created_at,
                r.attribution_json,r.version_no,r.next_action_due request_due,r.followup_owner_user_id,
                l.id lead_id,l.status lead_status,l.handover_status,l.owner_user_id,l.sales_owner_user_id,
                l.next_action_due lead_due,l.inquiry_id
            FROM lead_requests r
            LEFT JOIN leads l ON l.company_id=r.company_id AND l.request_id=r.id
            WHERE r.company_id=? AND r.id=? LIMIT 1');
        $s->execute([$cid,$requestId]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('LEAD_REQUEST_NOT_FOUND');

        // Intentionally use existing company id and existing unique submission
        // key, without returning the token or the original guest contact.
        $dup=$db->prepare('SELECT id,contact_name,email,phone,travel_date,destination,created_at,status
            FROM lead_requests WHERE company_id=? AND id<>?
            ORDER BY id DESC LIMIT 301');
        $dup->execute([$cid,$requestId]);
        $neighbors=$dup->fetchAll(PDO::FETCH_ASSOC);

        $warn=[];
        $add=static function(string $code,string $severity='REVIEW')use(&$warn):void{
            if(!isset($warn[$code]))$warn[$code]=['code'=>$code,'severity'=>$severity];
        };
        $email=self::email($row['email']??null);
        $phone=self::phone($row['phone']??null);
        $name=self::name($row['contact_name']??null);
        $day=self::validDate($row['travel_date']??null);
        $destination=self::name($row['destination']??null);
        $matches=[];
        foreach(array_slice($neighbors,0,self::MAX_SCAN) as $candidate){
            if(!is_array($candidate))continue;
            $candidateId=(int)($candidate['id']??0);
            if($candidateId<=0)continue;
            $reasons=[];
            if($email!==null&&$email===self::email($candidate['email']??null))
                $reasons[]='EMAIL_EXACT_NORMALIZED';
            if($phone!==null&&$phone===self::phone($candidate['phone']??null))
                $reasons[]='PHONE_DIGITS_EXACT_UNVERIFIED_COUNTRY';
            // Name/date/destination is a weak itinerary similarity ONLY,
            // never evidence that customers are the same individual.
            if($name!==null&&$day!==null&&$destination!==null
                &&$name===self::name($candidate['contact_name']??null)
                &&$day===self::validDate($candidate['travel_date']??null)
                &&$destination===self::name($candidate['destination']??null))
                $reasons[]='SAME_NAME_DATE_DESTINATION_WEAK';
            if(!$reasons)continue;
            if(count($matches)>=self::MATCH_LIMIT){
                $add('MATCH_RESULTS_BOUNDED');
                break;
            }
            $matches[]=[
                'request_id'=>$candidateId,
                'confidence'=>in_array('EMAIL_EXACT_NORMALIZED',$reasons,true)
                    ||in_array('PHONE_DIGITS_EXACT_UNVERIFIED_COUNTRY',$reasons,true)
                    ?'CONTACT_MATCH_REVIEW':'TRAVEL_INTENT_ONLY',
                'reason_codes'=>$reasons,
                'status'=>self::safeStatus($candidate['status']??null),
            ];
        }
        if(count($neighbors)>self::MAX_SCAN)$add('RECENT_REQUEST_SCAN_BOUNDED');
        if($matches)$add('POTENTIAL_REQUEST_DUPLICATE_REQUIRES_HUMAN_REVIEW');
        $add('NOT_A_CUSTOMER_IDENTITY_DECISION','INFO');

        $rawAttribution=$row['attribution_json']??null;
        $attribution=null;
        if(is_string($rawAttribution)&&strlen($rawAttribution)<=16384){
            try{$a=json_decode($rawAttribution,true,12,JSON_THROW_ON_ERROR);if(is_array($a))$attribution=$a;}
            catch(JsonException $e){}
        }elseif(is_array($rawAttribution)){$attribution=$rawAttribution;}
        if($attribution===null){$add('ATTRIBUTION_SNAPSHOT_INVALID');$attribution=[];}
        $utm=[];
        foreach(self::UTM as $key){
            $value=$attribution[$key]??null;
            $utm[$key]=is_string($value)&&trim($value)!=='';
        }
        if(!$utm['utm_source']||!$utm['utm_medium']||!$utm['utm_campaign'])
            $add('INCOMPLETE_UTM_SNAPSHOT');
        // LeadHub migration 007 stores UTM but has NO trusted consent
        // record, consent version, opt-in timestamp or first response timestamp.
        $add('CONSENT_VERSION_NOT_VERIFIED_IN_LEAD_REQUEST');
        $add('FIRST_TOUCH_NOT_INDEPENDENTLY_ESTABLISHED','INFO');
        $add('RESPONSE_SLA_NOT_MEASURABLE_WITHOUT_POLICY_AND_RESPONSE_EVENT','INFO');
        if($email===null&&$phone===null)$add('CONTACT_FIELDS_MISSING_OR_UNVERIFIED');
        if($day===null)$add('TRAVEL_DATE_UNVERIFIED','INFO');

        $created=self::localDateTime($row['created_at']??null,$asOf->getTimezone());
        $ageMinutes=null;
        if($created===null)$add('LEAD_CREATED_AT_INVALID');
        else{
            $seconds=$asOf->getTimestamp()-$created->getTimestamp();
            if($seconds<0)$add('LEAD_CREATED_AFTER_REVIEW_CLOCK');
            else $ageMinutes=intdiv($seconds,60);
        }
        $owner= self::positiveInt($row['sales_owner_user_id']??null)
            ?? self::positiveInt($row['owner_user_id']??null)
            ?? self::positiveInt($row['followup_owner_user_id']??null);
        $ownerValid=null;
        if($owner!==null){
            $q=$db->prepare("SELECT id FROM users WHERE company_id=? AND id=? AND status='ACTIVE' LIMIT 1");
            $q->execute([$cid,$owner]);
            $ownerValid=(bool)$q->fetchColumn();
            if(!$ownerValid)$add('ASSIGNED_OWNER_NOT_ACTIVE');
        }else $add('FOLLOW_UP_OWNER_UNASSIGNED');
        $dueRaw=$row['lead_due']??null;
        if(!is_string($dueRaw)||trim($dueRaw)==='')$dueRaw=$row['request_due']??null;
        $due=self::localDateTime($dueRaw,$asOf->getTimezone());
        if($dueRaw!==null&&$dueRaw!==''&&$due===null)$add('NEXT_ACTION_DUE_INVALID');
        $dueStatus=$due===null?'NOT_SCHEDULED':($due->getTimestamp()<$asOf->getTimestamp()?'OVERDUE':'UPCOMING');
        $lifecycle=self::safeStatus($row['handover_status']??$row['status']??null);
        if($dueStatus==='OVERDUE'&&!in_array($lifecycle,['CONVERTED','NOT_POTENTIAL'],true))
            $add('NEXT_ACTION_OVERDUE');
        if($due===null&&in_array($lifecycle,['NEW','PENDING','ACCEPTED','FOLLOW_LATER','RETURNED'],true))
            $add('NEXT_ACTION_NOT_SCHEDULED');

        $inquiryId=self::positiveInt($row['inquiry_id']??null);
        $inquiryStatus='NOT_LINKED';
        $customer360Link='NOT_LINKED';
        $quoteCount=null;$latestQuoteStatus=null;$latestQuoteId=null;
        if($inquiryId!==null){
            // Reuse the original Inquiry -> Trip -> Customer360 hierarchy.
            // Every join is tenant-constrained; a foreign customer/trip is
            // never treated as a legitimate customer identity.
            $q=$db->prepare('SELECT i.id,i.status,t.id trip_id,t.customer_id,
                    c.id customer_id_scoped,c.status customer_status
                FROM inquiries i
                LEFT JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id
                LEFT JOIN customers c ON c.id=t.customer_id AND c.company_id=t.company_id
                WHERE i.company_id=? AND i.id=? LIMIT 1');
            $q->execute([$cid,$inquiryId]);
            $inquiry=$q->fetch(PDO::FETCH_ASSOC);
            if(!$inquiry){
                $inquiryStatus='BROKEN_OR_FOREIGN_LINK';
                $customer360Link='INQUIRY_NOT_FOUND';
                $add('LINKED_INQUIRY_NOT_FOUND_IN_COMPANY');
            }else{
                $inquiryStatus=self::safeStatus($inquiry['status']??null);
                $tripId=self::positiveInt($inquiry['trip_id']??null);
                $customerId=self::positiveInt($inquiry['customer_id']??null);
                $scopedCustomerId=self::positiveInt($inquiry['customer_id_scoped']??null);
                if($tripId===null){
                    $customer360Link='TRIP_NOT_FOUND_IN_COMPANY';
                    $add('INQUIRY_TRIP_LINK_NOT_FOUND_IN_COMPANY');
                }elseif($customerId===null){
                    $customer360Link='CUSTOMER_NOT_LINKED';
                    $add('INQUIRY_TRIP_CUSTOMER_NOT_LINKED');
                }elseif($scopedCustomerId!==$customerId){
                    $customer360Link='CUSTOMER_NOT_FOUND_IN_COMPANY';
                    $add('CUSTOMER360_CROSS_TENANT_OR_BROKEN_LINK');
                }elseif(($inquiry['customer_status']??null)!=='ACTIVE'){
                    $customer360Link='CUSTOMER_INACTIVE_REVIEW';
                    $add('CUSTOMER360_CUSTOMER_NOT_ACTIVE');
                }else{
                    $customer360Link='ACTIVE_COMPANY_CUSTOMER_LINK';
                }
                $q=$db->prepare('SELECT id,status FROM quotes WHERE company_id=? AND inquiry_id=? ORDER BY id DESC LIMIT 21');
                $q->execute([$cid,$inquiryId]);
                $quotes=$q->fetchAll(PDO::FETCH_ASSOC);
                $quoteCount=count($quotes)>20?null:count($quotes);
                if(count($quotes)>20)$add('QUOTE_HISTORY_BOUNDED');
                if(isset($quotes[0])){
                    $latestQuoteId=self::positiveInt($quotes[0]['id']??null);
                    $latestQuoteStatus=self::safeStatus($quotes[0]['status']??null);
                }
            }
        }elseif(($row['handover_status']??null)==='CONVERTED'||($row['lead_status']??null)==='CONVERTED'){
            $add('CONVERTED_LEAD_HAS_NO_INQUIRY_LINK','BLOCK');
        }

        return [
            'schema'=>self::SCHEMA,
            'state'=>'READ_ONLY_ADVISORY_NO_MERGE_NO_CONTACT',
            'request_id'=>$requestId,
            'source_version_no'=>self::positiveInt($row['version_no']??null),
            'source_channel'=>self::safeChannel($row['source']??null),
            'attribution'=>[
                'status'=>'SNAPSHOT_ONLY_NOT_VERIFIED',
                'campaign_id'=>self::positiveInt($row['campaign_id']??null),
                'form_id'=>self::positiveInt($row['form_id']??null),
                'fields_present'=>$utm,
                'consent_status'=>'NOT_ESTABLISHED_FROM_EXISTING_SCHEMA',
                'first_touch_status'=>'NOT_ESTABLISHED',
                'snapshot_sha256'=>is_string($rawAttribution)?hash('sha256',$rawAttribution):null,
            ],
            'duplicate_review'=>[
                'status'=>$matches?'POSSIBLE_MATCHES_REQUIRE_HUMAN_REVIEW':'NO_MATCH_IN_BOUNDED_RECENT_SCAN',
                'scanned_rows'=>min(count($neighbors),self::MAX_SCAN),
                'scan_complete'=>count($neighbors)<=self::MAX_SCAN,
                'candidates'=>$matches,
                'no_auto_merge'=>true,
            ],
            'follow_up'=>[
                'as_of_local'=>$asOf->format('Y-m-d H:i:s'),
                'timezone'=>$asOf->getTimezone()->getName(),
                'age_minutes'=>$ageMinutes,
                'owner_user_id'=>$owner,
                'owner_verified_active'=>$ownerValid,
                'next_action_status'=>$dueStatus,
                'first_response_status'=>'NOT_RECORDED_IN_REQUEST_SNAPSHOT',
                'sla_status'=>'NOT_MEASURABLE_WITHOUT_VERIFIED_POLICY',
            ],
            'journey'=>[
                'request_status'=>self::safeStatus($row['status']??null),
                'lead_id'=>self::positiveInt($row['lead_id']??null),
                'handover_status'=>self::safeStatus($row['handover_status']??null),
                'inquiry_id'=>$inquiryId,
                'inquiry_status'=>$inquiryStatus,
                'customer360_link_status'=>$customer360Link,
                'latest_quote_id'=>$latestQuoteId,
                'latest_quote_status'=>$latestQuoteStatus,
                'quote_count_bounded'=>$quoteCount,
            ],
            'warnings'=>array_values($warn),
            'limits'=>[
                'No PII values, raw UTM/consent text, submission key, duplicate contact or internal notes are returned.',
                'Follow-up DATETIME is compared in the explicitly reported review timezone; confirm actual company DB timezone at staging.',
                'Candidate matching is recent bounded and is not a complete tenant-wide identity search.',
                'Never infer consent, first-response SLA, booking, quote availability or customer identity.',
                'Existing LeadSalesHandover identity review, permissions and command idempotency remain authoritative.',
            ],
        ];
    }

    private static function email(mixed $input):?string
    {
        if(!is_string($input))return null;
        $email=strtolower(trim($input));
        return $email!==''&&strlen($email)<=190&&filter_var($email,FILTER_VALIDATE_EMAIL)?$email:null;
    }
    private static function phone(mixed $input):?string
    {
        if(!is_string($input)||strlen($input)>64)return null;
        $digits=preg_replace('/[^0-9]/','',$input);
        return is_string($digits)&&strlen($digits)>=7&&strlen($digits)<=18?$digits:null;
    }
    private static function name(mixed $input):?string
    {
        if(!is_string($input)||strlen($input)>500||!preg_match('//u',$input))return null;
        $s=trim(mb_strtolower(preg_replace('/\s+/u',' ',$input)??$input,'UTF-8'));
        return $s!==''?$s:null;
    }
    private static function validDate(mixed $input):?string
    {
        if(!is_string($input)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$input))return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$input);
        return $d&&$d->format('Y-m-d')===$input?$input:null;
    }
    private static function localDateTime(mixed $input,DateTimeZone $timezone):?DateTimeImmutable
    {
        if(!is_string($input)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$input))return null;
        $t=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$input,$timezone);
        return $t!==false&&$t->format('Y-m-d H:i:s')===$input?$t:null;
    }
    private static function positiveInt(mixed $value):?int
    {
        if(is_int($value))return $value>0?$value:null;
        if(is_string($value)&&preg_match('/^[1-9][0-9]{0,17}$/D',$value))return(int)$value;
        return null;
    }
    private static function safeStatus(mixed $value):string
    {
        if(!is_string($value)||strlen($value)>32||!preg_match('/^[A-Z_]+$/D',$value))return 'UNVERIFIED';
        return $value;
    }
    private static function safeChannel(mixed $value):string
    {
        if(!is_string($value)||strlen($value)>80||!preg_match('/^[A-Za-z0-9_-]+$/D',$value))return 'UNVERIFIED';
        return strtoupper($value);
    }
}
