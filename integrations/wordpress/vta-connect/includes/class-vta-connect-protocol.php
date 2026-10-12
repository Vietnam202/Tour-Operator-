<?php
declare(strict_types=1);

/**
 * First-party WordPress -> RC6.2 envelope builder.
 * This file contains no WordPress dependencies and can be unit tested with PHP CLI.
 */
final class VTA_Connect_Protocol {
    public static function id(string $value, string $field): string {
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $value)) {
            throw new InvalidArgumentException('Invalid ' . $field);
        }
        return $value;
    }

    public static function clean($value, int $limit, string $field, bool $required=false): string {
        if (!is_string($value) || strlen($value)>$limit || !preg_match('//u', $value) ||
            str_contains($value, "\0")) {
            throw new InvalidArgumentException('Invalid ' . $field);
        }
        $value=trim($value);
        if ($required && $value==='') throw new InvalidArgumentException('Missing ' . $field);
        return $value;
    }

    public static function externalId(string $session): string {
        if (!preg_match('/^[a-f0-9]{64}$/D', $session)) {
            throw new InvalidArgumentException('Invalid private chat session');
        }
        // Browser never chooses a conversation ID. Different WP installations
        // cannot collide when VTA Connect keys are distinct.
        return 'wp_' . hash('sha256', $session);
    }

    public static function attribution(array $source): array {
        $result=[];
        foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term',
                  'ad_id','adset_id','referrer','landing_url'] as $key) {
            $result[$key]=self::clean($source[$key]??'',1000,$key);
        }
        return $result;
    }

    public static function chatEvent(string $external, array $input): array {
        self::id((string)($input['request_id']??''),'request_id');
        $request=(string)$input['request_id'];
        $body=self::clean($input['text']??null,8000,'text',true);
        $name=self::clean($input['contact_name']??'',190,'contact_name');
        $email=self::clean($input['email']??'',190,'email');
        $phone=self::clean($input['phone']??'',64,'phone');
        if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        return [
            'event_type'=>'conversation.message',
            'event_id'=>hash('sha256',$external.':msg:'.$request),
            'conversation'=>[
                'external_id'=>$external,'contact_name'=>$name,
                'email'=>$email,'phone'=>$phone
            ],
            'message'=>[
                'message_id'=>hash('sha256',$external.':message:'.$request),
                'text'=>$body
            ],
            'attribution'=>self::attribution(is_array($input['attribution']??null)?$input['attribution']:[])
        ];
    }

    public static function leadEvent(string $external, array $input): array {
        self::id((string)($input['request_id']??''),'request_id');
        $name=self::clean($input['contact_name']??null,190,'contact_name',true);
        $email=self::clean($input['email']??'',190,'email');
        $phone=self::clean($input['phone']??'',64,'phone');
        if (($email==='' && $phone==='') ||
            ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('Email or phone required');
        }
        $guests=filter_var($input['total_guests']??1,FILTER_VALIDATE_INT);
        if ($guests===false || $guests<1 || $guests>1000) {
            throw new InvalidArgumentException('Invalid guest count');
        }
        $request=(string)$input['request_id'];
        $lead=[
            'contact_name'=>$name,'email'=>$email,'phone'=>$phone,
            'total_guests'=>$guests,'paying_pax'=>$guests,'foc'=>0,
            'destination'=>self::clean($input['destination']??'',500,'destination'),
            'message'=>self::clean($input['message']??'',8000,'message')
        ] + self::attribution(is_array($input['attribution']??null)?$input['attribution']:[]);
        if(isset($input['b2c_publication_id'])){
            $id=filter_var($input['b2c_publication_id'],FILTER_VALIDATE_INT);if(!$id||$id<1)throw new InvalidArgumentException('Invalid publication');
            if(($input['consent']??false)!==true)throw new InvalidArgumentException('Contact consent required');
            $date=self::clean($input['travel_date']??'',10,'travel_date');$end=self::clean($input['travel_date_end']??'',10,'travel_date_end');
            foreach([$date,$end] as $value)if($value!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Invalid travel date');}
            if($end!==''&&($date===''||$end<$date))throw new InvalidArgumentException('Invalid date range');
            $lead['b2c_publication_id']=$id;$lead['travel_date']=$date;
            $lead['message']="Contact consent: yes.\n".($end!==''?'Travel date range: '.$date.' to '.$end.".\n":'').self::clean($input['message']??'',7400,'message');
        }
        return [
            'event_type'=>'lead.created',
            'event_id'=>hash('sha256',$external.':lead:'.$request),
            'lead'=>$lead
        ];
    }

    public static function relayEvent(string $external, int $after=0): array {
        if ($after<0) throw new InvalidArgumentException('Invalid cursor');
        return [
            'action'=>'pull','timestamp'=>time(),
            'external_conversation_id'=>$external,'after_id'=>$after
        ];
    }

    public static function ackEvent(string $external, array $ids): array {
        $ids=array_values(array_unique($ids));
        if (!$ids || count($ids)>50) throw new InvalidArgumentException('Invalid acknowledgement');
        foreach ($ids as $id) if (!is_int($id) || $id<1) {
            throw new InvalidArgumentException('Invalid message ID');
        }
        return [
            'action'=>'ack','timestamp'=>time(),
            'external_conversation_id'=>$external,'message_ids'=>$ids
        ];
    }

    public static function encodeSigned(array $event, string $secret): array {
        if (strlen($secret)<32) throw new DomainException('Missing private HMAC secret');
        $raw=json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return ['body'=>$raw,'signature'=>'sha256='.hash_hmac('sha256',$raw,$secret)];
    }
}
