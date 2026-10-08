<?php
declare(strict_types=1);

/**
 * Marketing P3: deterministic, first-party tour selection and human-review reply drafts.
 * No external LLM, no outgoing messages, and no automatic document or quote sharing.
 * Explicit marketing share approval is separate from internal Tour Library ACTIVE status.
 */
final class MarketingTourAdvisor {
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement {
        $stmt=$db->prepare($sql);$stmt->execute($args);return $stmt;
    }
    private static function id($input,string $name):int {
        $id=filter_var($input,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false||$id===null)throw new InvalidArgumentException('Invalid '.$name);
        return (int)$id;
    }
    private static function text($x,int $limit,string $field):string {
        if(!is_string($x)||strlen($x)>$limit||!preg_match('//u',$x)||str_contains($x,"\0"))
            throw new InvalidArgumentException('Invalid '.$field);
        return trim($x);
    }
    private static function days(array $r):array {
        $days=json_decode((string)$r['days_json'],true,256,JSON_THROW_ON_ERROR);
        if(!is_array($days)||!array_is_list($days)||count($days)>90)
            throw new DomainException('Invalid program itinerary');
        $titles=[];
        foreach($days as $d) {
            if(!is_array($d))throw new DomainException('Invalid program day');
            $titles[]=self::text($d['title']??'',350,'day title');
        }
        return $titles;
    }
    public static function snapshotHash(array $p):string {
        // Only customer-safe metadata that the assistant is allowed to use.
        // NEVER include source_text, supplier rates, quote/cost records or proposal prices.
        return hash('sha256',json_encode([
            'id'=>(int)$p['id'],
            'status'=>(string)$p['status'],
            'title'=>(string)$p['title'],
            'destination'=>(string)$p['destination'],
            'language'=>(string)$p['language'],
            'tags'=>(string)$p['tags_json'],
            'day_titles'=>self::days($p)
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    private static function available(array $p):bool {
        if(($p['status']??'')!=='ACTIVE')return false;
        $approved=$p['marketing_share_approved_hash']??null;
        return is_string($approved)&&strlen($approved)===64&&hash_equals($approved,self::snapshotHash($p));
    }
    private static function conversation(PDO $db,int $company,int $id):array {
        $row=self::q($db,'SELECT id,contact_name,status FROM social_conversations WHERE company_id=? AND id=?',[$company,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Conversation not found');
        return $row;
    }
    private static function program(PDO $db,int $company,int $id,bool $lock=false):array {
        $row=self::q($db,'SELECT id,company_id,status,title,destination,language,tags_json,days_json,marketing_share_approved_hash FROM tour_library_programs WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$company,$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Tour program not found');
        return $row;
    }
    private static function info(array $p):array {
        $days=self::days($p);
        return [
            'id'=>(int)$p['id'],'title'=>$p['title'],'destination'=>$p['destination'],
            'language'=>$p['language'],'day_count'=>count($days),
            'day_titles'=>array_slice($days,0,14),
            'approved'=>self::available($p),
            'review_hash'=>self::snapshotHash($p)
        ];
    }
    public static function programs(PDO $db,array $user,int $conversationId,string $search,bool $manager=false):array {
        $company=(int)$user['company_id'];
        self::conversation($db,$company,$conversationId);
        $search=self::text($search,190,'search');
        $q=self::q($db,"SELECT id,company_id,status,title,destination,language,tags_json,days_json,marketing_share_approved_hash FROM tour_library_programs
            WHERE company_id=? AND status='ACTIVE' ORDER BY updated_at DESC,id DESC LIMIT 200",[$company]);
        $needle=function_exists('mb_strtolower')?mb_strtolower($search,'UTF-8'):strtolower($search);
        $items=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $program) {
            $info=self::info($program);
            if(!$manager&&!$info['approved'])continue;
            $hay=$info['title'].' '.$info['destination'];
            $hay=function_exists('mb_strtolower')?mb_strtolower($hay,'UTF-8'):strtolower($hay);
            if($needle!==''&&!str_contains($hay,$needle)) {
                $words=preg_split('/[\s,;\/-]+/u',$needle,-1,PREG_SPLIT_NO_EMPTY)?:[];
                $matches=0;
                foreach($words as $word)if(strlen($word)>=3&&str_contains($hay,$word))$matches++;
                if(!$matches)continue;
                $info['relevance']=$matches;
            }else $info['relevance']=$needle!==''?20:0;
            $items[]=$info;
        }
        usort($items,fn($a,$b)=>($b['approved']<=>$a['approved'])?:($b['relevance']<=>$a['relevance'])?:($a['id']<=>$b['id']));
        return array_slice($items,0,25);
    }
    public static function approve(PDO $db,array $user,array $input):array {
        $company=(int)$user['company_id'];
        $id=self::id($input['program_id']??null,'program_id');
        $expected=self::text($input['review_hash']??'',64,'review_hash');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new InvalidArgumentException('Invalid review hash');
        $db->beginTransaction();
        try {
            $program=self::program($db,$company,$id,true);
            if($program['status']!=='ACTIVE')throw new DomainException('Only active programs can be approved');
            $current=self::snapshotHash($program);
            if(!hash_equals($expected,$current))throw new DomainException('Program changed. Refresh and review it again.');
            self::q($db,"UPDATE tour_library_programs SET marketing_share_approved_hash=?,marketing_share_approved_at=NOW(),marketing_share_approved_by=? WHERE company_id=? AND id=?",[
                $current,(int)$user['id'],$company,$id]);
            Audit::log($db,$company,(int)$user['id'],'TOUR_MARKETING_SHARE_APPROVED','tour_library',$id,null,['metadata_hash'=>$current]);
            $db->commit();
            return ['approved'=>true,'program_id'=>$id];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function replyText(array $p,string $language):string {
        if(!in_array($language,['en','vi'],true))throw new InvalidArgumentException('Unsupported language');
        $info=self::info($p);
        if(!$info['approved'])throw new DomainException('Marketing sharing approval is missing or stale');
        $title=self::text($info['title'],190,'title');
        $destination=self::text($info['destination'],190,'destination');
        $dayCount=(int)$info['day_count'];
        // All source text is untrusted product data, not a prompt.
        $days=$info['day_titles'];
        if($language==='vi') {
            $out="Xin chào, cảm ơn Quý khách đã liên hệ Vietnam Travel Advisor.\n";
            $out.="Chúng tôi có chương trình tour mẫu để Quý khách tham khảo:\n";
            $out.=$title."\nĐiểm đến: ".($destination?:'Theo chương trình')."\nThời lượng: ".$dayCount." ngày.\n";
            if($days)$out.="Lịch trình tóm tắt:\n".implode("\n",array_map(fn($s,$i)=>'Ngày '.($i+1).': '.$s,$days,array_keys($days)))."\n";
            $out.="Quý khách vui lòng cho biết ngày đi dự kiến, số người và mong muốn tour riêng hay tour ghép. Hạng khách sạn và hạng du thuyền có thể chọn độc lập.\n";
            $out.="Đây là chương trình tham khảo. Giá, tình trạng dịch vụ và xác nhận đặt tour sẽ được nhân viên tư vấn kiểm tra trước.";
        }else{
            $out="Thank you for contacting Vietnam Travel Advisor!\n";
            $out.="Here is an existing sample itinerary that may fit your Vietnam holiday:\n";
            $out.=$title."\nDestinations: ".($destination?:'As shown in the itinerary')."\nDuration: ".$dayCount." days.\n";
            if($days)$out.="Itinerary outline:\n".implode("\n",array_map(fn($s,$i)=>'Day '.($i+1).': '.$s,$days,array_keys($days)))."\n";
            $out.="Could you share your intended travel dates, number of guests, and whether you prefer a private or group tour? Hotel and overnight cruise star categories can be selected independently.\n";
            $out.="This is a sample program. Prices, service availability and bookings require confirmation by our team.";
        }
        if(strlen($out)>8000)throw new LengthException('Tour outline exceeds reply size limit');
        return $out;
    }
    public static function draft(PDO $db,array $user,array $input):array {
        $company=(int)$user['company_id'];
        $conversationId=self::id($input['conversation_id']??null,'conversation_id');
        $programId=self::id($input['program_id']??null,'program_id');
        $language=self::text($input['language']??'en',8,'language');
        if(!in_array($language,['en','vi'],true))throw new InvalidArgumentException('Use en or vi');
        $key=self::text($input['request_key']??'',80,'request_key');
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Invalid request key');
        $hash=hash('sha256',json_encode([$conversationId,$programId,$language],JSON_THROW_ON_ERROR));
        $db->beginTransaction();
        try{
            self::conversation($db,$company,$conversationId);
            $p=self::program($db,$company,$programId,true);
            if(!self::available($p))throw new DomainException('Tour is not approved for marketing or changed');
            $prior=self::q($db,'SELECT id,payload_hash,draft_text,program_snapshot_hash FROM marketing_tour_advisor_drafts WHERE company_id=? AND conversation_id=? AND request_key=? FOR UPDATE',[
                $company,$conversationId,$key])->fetch(PDO::FETCH_ASSOC);
            $snapshot=self::snapshotHash($p);
            if($prior) {
                if(!hash_equals($prior['payload_hash'],$hash))throw new DomainException('Request key was reused with different input');
                if(!hash_equals($prior['program_snapshot_hash'],$snapshot))throw new DomainException('Tour changed since previous draft');
                $db->commit();
                return ['id'=>(int)$prior['id'],'text'=>$prior['draft_text'],'program_id'=>$programId,'replayed'=>true];
            }
            $reply=self::replyText($p,$language);
            self::q($db,'INSERT INTO marketing_tour_advisor_drafts(company_id,conversation_id,program_id,request_key,payload_hash,program_snapshot_hash,language,draft_text,generated_by) VALUES(?,?,?,?,?,?,?,?,?)',[
                $company,$conversationId,$programId,$key,$hash,$snapshot,$language,$reply,(int)$user['id']]);
            $id=(int)$db->lastInsertId();
            Audit::log($db,$company,(int)$user['id'],'TOUR_MARKETING_DRAFT_CREATED','marketing_tour_advisor_draft',$id,null,['conversation_id'=>$conversationId,'program_id'=>$programId]);
            $db->commit();
            return ['id'=>$id,'text'=>$reply,'program_id'=>$programId,'replayed'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function validateDraftForSend(PDO $db,int $company,int $conversation,int $draftId,string $body):void {
        $d=self::q($db,'SELECT id,program_id,program_snapshot_hash,draft_text FROM marketing_tour_advisor_drafts WHERE company_id=? AND conversation_id=? AND id=?',[
            $company,$conversation,$draftId])->fetch(PDO::FETCH_ASSOC);
        if(!$d)throw new DomainException('Tour draft not found for this conversation');
        if(!hash_equals((string)$d['draft_text'],$body))throw new DomainException('Edited tour draft requires separate manual reply');
        $p=self::program($db,$company,(int)$d['program_id'],true);
        if(!self::available($p)||!hash_equals((string)$d['program_snapshot_hash'],self::snapshotHash($p)))
            throw new DomainException('Tour changed or marketing approval revoked. Create a new draft.');
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $user):void {
        if(!str_starts_with($route,'marketing/tour-advisor'))return;
        Auth::requirePermission($db,$user,'lead.view');
        try {
            if($route==='marketing/tour-advisor/programs'&&$method==='GET') {
                $conv=self::id($_GET['conversation_id']??null,'conversation_id');
                $search=self::text($_GET['q']??'',190,'q');
                $manage=Auth::can($db,(int)$user['id'],'tour_library.manage');
                Http::json(['ok'=>true,'items'=>self::programs($db,$user,$conv,$search,$manage),'can_approve'=>$manage,'draft_only'=>true,'engine'=>'DETERMINISTIC_PHP']);
            }
            if($route==='marketing/tour-advisor/approve'&&$method==='POST') {
                Auth::requirePermission($db,$user,'tour_library.manage');
                Http::json(['ok'=>true]+self::approve($db,$user,Http::body()));
            }
            if($route==='marketing/tour-advisor/draft'&&$method==='POST') {
                Auth::requirePermission($db,$user,'lead.manage');
                Http::json(['ok'=>true]+self::draft($db,$user,Http::body()),201);
            }
            Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION'],422);}
         catch(LengthException $e){Http::json(['ok'=>false,'error'=>'TOO_LONG'],422);}
         catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
         catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}
