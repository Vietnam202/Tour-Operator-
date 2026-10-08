<?php
declare(strict_types=1);

/**
 * Instagram Professional image-only content publishing via Facebook Login / Graph.
 * Container creation -> status FINISHED -> media_publish -> published media ID.
 * Never attempts to resend a possibly published container on ambiguous errors.
 */
final class InstagramImagePoster {
    public static function endpoint(string $igId,string $path,string $version='v26.0'):string{
        if(!preg_match('/^[0-9]{5,40}$/D',$igId)
            ||!in_array($path,['media','media_publish'],true)
            ||!preg_match('/^v[0-9]{1,2}\.[0-9]$/D',$version))
            throw new InvalidArgumentException('Invalid Instagram configuration');
        return 'https://graph.facebook.com/'.$version.'/'.$igId.'/'.$path;
    }
    public static function imageUrl(string $input,array $hosts):string {
        if(strlen($input)<14||strlen($input)>1000||!filter_var($input,FILTER_VALIDATE_URL))
            throw new DomainException('Instagram image URL invalid');
        $p=parse_url($input);
        if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'
           ||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])
           ||isset($p['port'])||isset($p['query'])||isset($p['fragment']))
            throw new DomainException('Instagram image must be a public HTTPS JPEG URL without embedded credentials');
        $host=strtolower((string)($p['host']??''));
        if(!preg_match('/^[a-z0-9][a-z0-9.-]{2,250}$/D',$host)
            ||filter_var($host,FILTER_VALIDATE_IP)
            ||!is_array($hosts)||!in_array($host,$hosts,true))
            throw new DomainException('Image host is not approved for this Instagram account');
        if(!preg_match('/\.jpe?g$/i',(string)($p['path']??'')))
            throw new DomainException('Instagram P6 supports public JPG/JPEG URLs only');
        return $input;
    }
    public static function publish(array $account,string $caption,string $imageUrl,callable $recordContainer,?callable $transport=null,?callable $pause=null):string {
        $v=$account['config']??[];
        $token=$v['access_token']??null;
        if(!is_string($token)||strlen($token)<30)throw new DomainException('Instagram Page access token not configured');
        if($caption===''||strlen($caption)>2200)throw new DomainException('Instagram caption exceeds 2200 bytes');
        $url=self::imageUrl($imageUrl,$v['allowed_media_hosts']??[]);
        $version=(string)($v['graph_version']??'v26.0');
        $baseId=(string)($v['ig_user_id']??'');
        $create=self::endpoint($baseId,'media',$version);
        $publish=self::endpoint($baseId,'media_publish',$version);
        $call=$transport??[MetaGraphTransport::class,'request'];
        $rest=$pause??static function():void{sleep(2);};
        $made=$call('POST',$create,['image_url'=>$url,'caption'=>$caption],$token);
        $id=$made['id']??null;
        if(!is_string($id)||preg_match('/^[0-9]{5,40}$/D',$id)!==1)
            throw new RuntimeException('Instagram media container not confirmed');
        // Critical: commit container tracking before any media_publish API call.
        $recordContainer($id);
        $statusUrl='https://graph.facebook.com/'.$version.'/'.$id;
        $finished=false;
        for($i=0;$i<3;$i++){
            $state=$call('GET',$statusUrl,['fields'=>'status_code'],$token);
            if(($state['status_code']??null)==='FINISHED'){$finished=true;break;}
            if(!in_array($state['status_code']??null,['IN_PROGRESS','EXPIRED'],true)||$state['status_code']==='EXPIRED')break;
            if($i<2)$rest();
        }
        if(!$finished)throw new RuntimeException('Instagram media container not ready; reconcile manually');
        $result=$call('POST',$publish,['creation_id'=>$id],$token);
        $media=$result['id']??null;
        if(!is_string($media)||preg_match('/^[0-9]{5,40}$/D',$media)!==1)
            throw new RuntimeException('Instagram published media ID not confirmed');
        return $media;
    }
}
