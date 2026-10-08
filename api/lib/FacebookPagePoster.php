<?php
declare(strict_types=1);

/** Strict Meta Graph API adapter, Facebook Page text-only (P5). */
final class FacebookPagePoster {
    public static function endpoint(string $pageId,string $version='v26.0'):string {
        if(!preg_match('/^[0-9]{5,30}$/D',$pageId)||!preg_match('/^v[0-9]{1,2}\.[0-9]$/D',$version))
            throw new InvalidArgumentException('Invalid Graph API account configuration');
        return 'https://graph.facebook.com/'.$version.'/'.$pageId.'/feed';
    }
    public static function publish(array $account,string $message):string {
        $source=$account['config']??[];
        $token=$source['access_token']??null;
        if(!is_string($token)||strlen($token)<30)throw new DomainException('Page token unavailable');
        if($message===''||strlen($message)>5000)throw new InvalidArgumentException('Unsupported post length');
        if(!function_exists('curl_init'))throw new RuntimeException('cURL extension unavailable');
        $url=self::endpoint((string)$source['page_id'],(string)($source['graph_version']??'v26.0'));
        $c=curl_init($url);
        if($c===false)throw new RuntimeException('Unable to initialize provider');
        try {
            curl_setopt_array($c,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['message'=>$message,'access_token'=>$token],'','&',PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_USERAGENT=>'VTA-Social-Publishing/1.0'
            ]);
            $raw=curl_exec($c);
            $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
            // NEVER log raw provider errors: Graph may echo request secrets or customer data.
            if($raw===false||$status<200||$status>=300)
                throw new RuntimeException('Provider response not confirmed (HTTP '.$status.')');
            $result=json_decode((string)$raw,true);
            $id=$result['id']??null;
            if(!is_string($id)||!preg_match('/^[0-9_]{5,80}$/D',$id))
                throw new RuntimeException('Missing confirmed Page post ID');
            return $id;
        }finally{curl_close($c);}
    }
}
