<?php
declare(strict_types=1);

/** First-party Messenger Page Send API. No network activity outside explicit CLI worker. */
final class MessengerSender {
    public static function endpoint(string $pageId,string $version='v26.0'):string {
        if(!preg_match('/^[0-9]{8,40}$/D',$pageId)
            ||!preg_match('/^v[0-9]{1,2}\.[0-9]$/D',$version))
            throw new InvalidArgumentException('Invalid Messenger endpoint');
        return 'https://graph.facebook.com/'.$version.'/'.$pageId.'/messages';
    }
    public static function request(string $pageId,string $recipient,string $message,array $account,?callable $transport=null):string {
        if(!preg_match('/^[0-9]{8,40}$/D',$recipient)||$recipient===$pageId||strlen($message)<1||strlen($message)>1000)
            throw new InvalidArgumentException('Invalid Messenger recipient or message');
        $token=$account['page_access_token']??null;
        if(!is_string($token)||strlen($token)<30)throw new DomainException('Page token missing');
        $url=self::endpoint($pageId,(string)($account['graph_version']??'v26.0'));
        $payload=['recipient'=>['id'=>$recipient],'messaging_type'=>'RESPONSE','message'=>['text'=>$message]];
        if($transport!==null)$result=$transport($url,$payload,$token);
        else $result=self::sendHttp($url,$payload,$token);
        $id=$result['message_id']??null;
        if(!is_string($id)||preg_match('/^[A-Za-z0-9._:-]{4,256}$/D',$id)!==1)
            throw new RuntimeException('Messenger API response not confirmed');
        if(isset($result['recipient_id'])&&(string)$result['recipient_id']!==$recipient)
            throw new RuntimeException('Messenger recipient mismatch');
        return $id;
    }
    private static function sendHttp(string $url,array $body,string $token):array{
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension required');
        $json=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $handle=curl_init($url);
        if($handle===false)throw new RuntimeException('Messenger transport unavailable');
        try{
            curl_setopt_array($handle,[
                CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json',
                    'Authorization: Bearer '.$token],
                CURLOPT_USERAGENT=>'VTA-Messenger/1.0'
            ]);
            $response=curl_exec($handle);$code=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
            if(!is_string($response)||strlen($response)>32768||$code<200||$code>=300)
                throw new RuntimeException('Messenger API response not confirmed');
            $parsed=json_decode($response,true);
            if(!is_array($parsed)||array_is_list($parsed))
                throw new RuntimeException('Invalid Messenger API response');
            return $parsed;
        }finally{curl_close($handle);}
    }
}
