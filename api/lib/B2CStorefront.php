<?php
declare(strict_types=1);

/** Optional native presentation over approved B2C events; never prices or books. */
final class B2CStorefront {
    public static function settings(array $cfg): array {
        $s=$cfg['integrations']['b2c_storefront']??[];
        $company=filter_var($s['company_id']??null,FILTER_VALIDATE_INT);
        if(($s['enabled']??false)!==true||!$company||$company<1||!is_string($s['form_token']??null)||!preg_match('/^[a-f0-9]{64}$/D',$s['form_token']))throw new OutOfBoundsException('Storefront unavailable');
        return ['company_id'=>(int)$company,'form_token'=>$s['form_token']];
    }
    public static function listing(PDO $db,int $company,int $offset=0): array {
        if($offset<0||$offset>100000)throw new InvalidArgumentException('Invalid page');
        $q=$db->prepare("SELECT e.id,e.snapshot_json,e.snapshot_hash FROM b2c_publication_events e JOIN b2c_programs p ON p.company_id=e.company_id AND p.id=e.program_id WHERE e.company_id=? AND e.status='PUBLISHED' AND p.status='PUBLISHED' AND e.revision=p.revision AND p.approved_revision=p.revision AND e.id=(SELECT MAX(x.id) FROM b2c_publication_events x WHERE x.company_id=e.company_id AND x.program_id=e.program_id) ORDER BY e.id DESC LIMIT 51 OFFSET ".$offset);
        $q->execute([$company]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>50;
        return ['items'=>array_map(static function($r){if(!hash_equals($r['snapshot_hash'],hash('sha256',$r['snapshot_json'])))throw new OutOfBoundsException('Publication integrity failed');$s=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);return ['publication_id'=>(int)$r['id'],'title'=>$s['title'],'destination'=>$s['destination'],'language'=>$s['language']];},array_slice($rows,0,50)),'has_more'=>$more];
    }
    public static function lead(array $input): array {
        if(($input['consent']??false)!==true)throw new InvalidArgumentException('Contact consent required');
        if(!is_string($input['request_id']??null)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$input['request_id']))throw new InvalidArgumentException('Invalid request');
        if(!empty($input['website']))throw new InvalidArgumentException('Invalid request');
        $total=filter_var($input['total_guests']??null,FILTER_VALIDATE_INT);
        if(!$total||$total<1||$total>1000)throw new InvalidArgumentException('Invalid travellers');
        $lead=['contact_name'=>$input['contact_name']??'','email'=>$input['email']??'','phone'=>$input['phone']??'','travel_date'=>$input['travel_date']??'','total_guests'=>(int)$total,'paying_pax'=>(int)$total,'foc'=>0,'message'=>$input['message']??''];
        $end=$input['travel_date_end']??'';
        if(!is_string($end))throw new InvalidArgumentException('Invalid date range');
        if($end!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$end);if(!$d||$d->format('Y-m-d')!==$end||!is_string($lead['travel_date'])||$lead['travel_date']===''||$end<$lead['travel_date'])throw new InvalidArgumentException('Invalid date range');}
        // Preserve the existing single-date field; range and consent remain in the lead record.
        if(!is_string($lead['message'])||strlen($lead['message'])>7400)throw new InvalidArgumentException('Message too long');
        $lead['message']="Contact consent: yes.\n".($end!==''?'Travel date range: '.$lead['travel_date'].' to '.$end.".\n":'').$lead['message'];
        $attr=$input['attribution']??[];if(!is_array($attr))throw new InvalidArgumentException('Invalid attribution');
        foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id','referrer','landing_url'] as $k)$lead[$k]=$attr[$k]??'';
        LeadHub::validateRequest($lead);return $lead;
    }
    public static function inquire(PDO $db,array $cfg,int $event,array $input): array {
        $s=self::settings($cfg);$r=B2CStudio::publication($db,$s['company_id'],$event);$snapshot=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $lead=self::lead($input);$lead['destination']=$snapshot['destination'];
        $lead['message']='B2C: '.$snapshot['title'].' [publication '.$event."]\n".$lead['message'];
        $lead['submission_key']='b2c_'.hash('sha256',$s['company_id'].':'.$event.':'.$input['request_id']);
        // The fixed company/form binding is checked before any lead can be written.
        $q=$db->prepare("SELECT f.id FROM lead_forms f JOIN campaigns c ON c.id=f.campaign_id AND c.company_id=f.company_id WHERE f.public_token=? AND f.company_id=? AND f.status='ACTIVE' AND c.status='ACTIVE'");$q->execute([$s['form_token'],$s['company_id']]);if(!$q->fetchColumn())throw new OutOfBoundsException('Storefront unavailable');
        return LeadHub::submit($db,$s['form_token'],$lead,static function(int $company,int $leadId)use($db,$s,$r,$event){
            if($company!==$s['company_id'])throw new OutOfBoundsException('Storefront unavailable');
            // Serialize with publish/edit/withdraw before committing the intake + provenance.
            B2CStudio::row($db,['company_id'=>$company],(int)$r['program_id'],true);
            B2CStudio::publication($db,$company,$event);
            $db->prepare('INSERT IGNORE INTO b2c_lead_links(company_id,lead_request_id,publication_event_id) VALUES(?,?,?)')->execute([$company,$leadId,$event]);
        });
    }
    public static function escape($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function node(array $n,string $imageBase,int $depth=0): string {
        if($depth>64)return '';$type=$n['type']??'';
        if($type==='text'){
            $text=self::escape($n['text']??'');
            foreach($n['marks']??[] as $mark){$t=$mark['type']??'';$tags=['bold'=>'strong','italic'=>'em','underline'=>'u','strike'=>'s','code'=>'code'];
                if(isset($tags[$t]))$text='<'.$tags[$t].'>'.$text.'</'.$tags[$t].'>';
                elseif($t==='link'){$url=$mark['attrs']['href']??'';if(is_string($url)&&filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true))$text='<a href="'.self::escape($url).'" rel="nofollow noopener noreferrer">'.$text.'</a>';}
            }return $text;
        }
        if($type==='image'){$id=(int)($n['attrs']['assetId']??0);return $id>0?'<img loading="lazy" src="'.self::escape($imageBase.$id).'" alt="'.self::escape($n['attrs']['alt']??'Tour image').'">':'';}
        if($type==='hardBreak')return '<br>';
        $inner='';foreach($n['content']??[] as $child)if(is_array($child))$inner.=self::node($child,$imageBase,$depth+1);
        if($type==='doc')return $inner;
        $tags=['paragraph'=>'p','bulletList'=>'ul','orderedList'=>'ol','listItem'=>'li','blockquote'=>'blockquote','table'=>'table','tableRow'=>'tr','tableCell'=>'td','tableHeader'=>'th'];$tag=$type==='heading'?'h'.max(2,min(6,(int)($n['attrs']['level']??2))):($tags[$type]??'div');return '<'.$tag.'>'.$inner.'</'.$tag.'>';
    }
    public static function publicHandle(string $route,string $method,PDO $db,array $cfg): void {
        if(!preg_match('#^b2c-storefront/(\d+)/inquiry$#D',$route,$m))return;
        if($method!=='POST')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        $token=$_SERVER['HTTP_X_VTA_B2C_CSRF']??'';$expected=$_SESSION['b2c_inquiry_csrf']??'';
        if(!is_string($token)||!is_string($expected)||$expected===''||!hash_equals($expected,$token))Http::json(['ok'=>false,'error'=>'FORBIDDEN'],403);
        $raw=file_get_contents('php://input');if(!is_string($raw)||strlen($raw)>16384)Http::json(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
        try{$input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Invalid request');Http::json(['ok'=>true]+self::inquire($db,$cfg,(int)$m[1],$input));}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'PUBLICATION_UNAVAILABLE'],404);}
        catch(OverflowException $e){Http::json(['ok'=>false,'error'=>'RATE_LIMITED'],429);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'REQUEST_ID_REUSED'],409);}
        catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);}
        catch(Throwable $e){Http::json(['ok'=>false,'error'=>'INQUIRY_UNAVAILABLE'],503);}
    }
}
