<?php
declare(strict_types=1);

/** Locked-down server-side Meta Graph transport. Never log token or raw provider response. */
final class MetaGraphTransport {
    public static function request(string $method,string $url,array $fields,string $token):array {
        if(!in_array($method,['GET','POST'],true)
            ||!preg_match('#^https://graph\.facebook\.com/v[0-9]{1,2}\.[0-9]/[0-9]{5,40}(?:/(?:media|media_publish|feed))?$#D',$url)
            ||!is_string($token)||strlen($token)<30)throw new InvalidArgumentException('Invalid Meta Graph request');
        if(!function_exists('curl_init'))throw new RuntimeException('PHP curl extension required');
        $ch=curl_init($url.($method==='GET'&&$fields?'?'.http_build_query($fields,'','&',PHP_QUERY_RFC3986):''));
        if($ch===false)throw new RuntimeException('Unable to initialize Meta Graph request');
        try {
            $options=[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_USERAGENT=>'VTA-Meta-Direct/1.0',
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],
                CURLOPT_HEADER=>false
            ];
            if($method==='POST') {
                $options[CURLOPT_POST]=true;
                $options[CURLOPT_POSTFIELDS]=http_build_query($fields,'','&',PHP_QUERY_RFC3986);
            }
            curl_setopt_array($ch,$options);
            $response=curl_exec($ch);
            $httpStatus=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            if(!is_string($response)||$httpStatus<200||$httpStatus>=300||strlen($response)>65536)
                throw new RuntimeException('Meta Graph request outcome not confirmed');
            $data=json_decode($response,true);
            if(!is_array($data)||array_is_list($data))
                throw new RuntimeException('Meta Graph response not confirmed');
            return $data;
        }finally{curl_close($ch);}
    }
}
