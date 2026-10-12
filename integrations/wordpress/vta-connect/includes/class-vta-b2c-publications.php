<?php
/** Signed RC6 publication mirror; WordPress owns presentation, never costing or booking. */
final class VTA_B2C_Publications {
    public static function init(): void {
        add_action('init',static function(){register_post_type('vta_journey',['label'=>'VTA Journeys','public'=>true,'show_in_rest'=>false,'supports'=>['title','excerpt'],'rewrite'=>['slug'=>'journeys'],'has_archive'=>true]);});
        add_shortcode('vta_journeys',[self::class,'collection']);add_shortcode('vta_journey',[self::class,'detail']);
        add_filter('the_content',static function($content){return is_singular('vta_journey')?self::detail(['id'=>get_the_ID()]):$content;});
        add_action('template_redirect',static function(){if((is_singular('vta_journey')||is_post_type_archive('vta_journey'))&&!self::fresh()){status_header(503);wp_die('Tour collection is temporarily unavailable. Please contact Vietnam Travel Advisor.','',['response'=>503]);}});
        add_action('admin_post_vta_b2c_sync',[self::class,'adminSync']);
        add_filter('cron_schedules',static function($s){$s['vta_minute']=['interval'=>60,'display'=>'VTA publication sync'];return $s;});
        add_action('vta_b2c_sync',[self::class,'sync']);
        add_action('init',static function(){if(defined('VTA_B2C_SECRET')&&!wp_next_scheduled('vta_b2c_sync'))wp_schedule_event(time()+60,'vta_minute','vta_b2c_sync');});
    }
    private static function settings(): ?array {
        if(!defined('VTA_CONNECT_API_URL')||!defined('VTA_B2C_SECRET')||!defined('VTA_B2C_SOURCE'))return null;
        $url=(string)VTA_CONNECT_API_URL;$p=wp_parse_url($url);
        if(!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['port'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!str_ends_with($p['path']??'','/api/index.php')||strlen((string)VTA_B2C_SECRET)<32||!preg_match('/^[a-z0-9_-]{3,64}$/D',(string)VTA_B2C_SOURCE))return null;
        return ['url'=>$url,'secret'=>(string)VTA_B2C_SECRET,'source'=>(string)VTA_B2C_SOURCE];
    }
    public static function verify(string $raw,string $signature,array $request,array $settings): array {
        if(strlen($raw)>10000000||!hash_equals('sha256='.hash_hmac('sha256',$raw,$settings['secret']),$signature))throw new RuntimeException('Invalid publication signature');
        $b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(($b['ok']??false)!==true||($b['source']??'')!==$settings['source']||($b['nonce']??'')!==$request['nonce']||($b['after_id']??null)!==$request['after_id']||!is_int($b['generated_at']??null)||abs(time()-$b['generated_at'])>120||!is_array($b['events']??null)||count($b['events'])>100)throw new RuntimeException('Stale publication response');
        $last=$request['after_id'];foreach($b['events'] as $event){if(!is_int($event['event_id']??null)||$event['event_id']<=$last||!is_int($event['program_id']??null)||$event['program_id']<1||!is_int($event['revision']??null)||$event['revision']<1||!in_array($event['status']??null,['PUBLISHED','WITHDRAWN'],true)||!is_array($event['snapshot']??null))throw new RuntimeException('Invalid publication ordering');$last=$event['event_id'];}
        return $b;
    }
    public static function sync(): bool {
        $cfg=self::settings();if(!$cfg)return false;
        // An atomic option mutex prevents concurrent cron/page/manual sync overwriting a newer version.
        if(!add_option('vta_b2c_sync_lock',time(),'','no'))return false;
        try{
            $binding=hash('sha256',$cfg['url'].'|'.$cfg['source']);$old=get_option('vta_b2c_binding','');if($old!==''&&!hash_equals($old,$binding))throw new RuntimeException('Publication source changed; operator reconciliation required');update_option('vta_b2c_binding',$binding,false);
            $cursor=(int)get_option('vta_b2c_cursor',0);$complete=false;
            for($page=0;$page<10;$page++){
                $request=['timestamp'=>time(),'nonce'=>bin2hex(random_bytes(16)),'after_id'=>$cursor];$raw=wp_json_encode($request);
                $response=wp_safe_remote_post(add_query_arg('route','webhooks/b2c-publications',$cfg['url']),['timeout'=>12,'redirection'=>0,'sslverify'=>true,'headers'=>['Content-Type'=>'application/json','X-VTA-WEBHOOK-SOURCE'=>$cfg['source'],'X-VTA-SIGNATURE-256'=>'sha256='.hash_hmac('sha256',$raw,$cfg['secret'])],'body'=>$raw]);
                if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new RuntimeException('Publication source unavailable');
                $b=self::verify((string)wp_remote_retrieve_body($response),(string)wp_remote_retrieve_header($response,'x-vta-signature-256'),$request,$cfg);
                foreach($b['events'] as $event){self::apply($event,$cfg);$cursor=$event['event_id'];update_option('vta_b2c_cursor',$cursor,false);}
                if(empty($b['has_more'])){$complete=true;break;}
            }
            if($complete)update_option('vta_b2c_verified_at',time(),false);
            return $complete;
        }catch(Throwable $e){return false;}finally{delete_option('vta_b2c_sync_lock');}
    }
    private static function apply(array $event,array $cfg): void {
        $existing=get_posts(['post_type'=>'vta_journey','post_status'=>'any','numberposts'=>1,'meta_key'=>'_vta_b2c_id','meta_value'=>$event['program_id']]);$id=$existing?(int)$existing[0]->ID:0;$slug='vta-b2c-'.$cfg['source'].'-'.$event['program_id'];if(!$id){$prior=get_page_by_path($slug,OBJECT,'vta_journey');if($prior)$id=(int)$prior->ID;}
        if($id&&(int)get_post_meta($id,'_vta_b2c_event',true)>=$event['event_id'])return;
        if($event['status']==='WITHDRAWN'){
            if($id){$r=wp_update_post(['ID'=>$id,'post_status'=>'draft'],true);if(is_wp_error($r))throw new RuntimeException('Withdraw failed');update_post_meta($id,'_vta_b2c_event',$event['event_id']);delete_post_meta($id,'_vta_b2c_snapshot');clean_post_cache($id);}return;
        }
        $s=$event['snapshot'];if(($s['schema']??'')!=='VTA_B2C_PUBLICATION_V1'||!is_string($s['title']??null)||!is_array($s['document']??null)||($s['pricing']??'')!=='REQUEST_QUOTE')throw new RuntimeException('Invalid public snapshot');
        // Never accept an image origin supplied by arbitrary payload; pin to private API configuration.
        $base=$cfg['url'].'?route=b2c-public-image/'.$event['event_id'].'/';
        $post=['post_type'=>'vta_journey','post_name'=>$slug,'post_title'=>sanitize_text_field($s['title']),'post_excerpt'=>sanitize_text_field($s['destination']??''),'post_status'=>'draft'];if($id)$post['ID']=$id;
        $id=wp_insert_post($post,true);if(is_wp_error($id))throw new RuntimeException('Publication write failed');
        update_post_meta($id,'_vta_b2c_id',$event['program_id']);update_post_meta($id,'_vta_b2c_snapshot',$s);update_post_meta($id,'_vta_b2c_publication',$event['event_id']);update_post_meta($id,'_vta_b2c_revision',$event['revision']);update_post_meta($id,'_vta_b2c_image_base',$base);
        $done=wp_update_post(['ID'=>$id,'post_status'=>'publish'],true);if(is_wp_error($done))throw new RuntimeException('Publish failed');update_post_meta($id,'_vta_b2c_event',$event['event_id']);clean_post_cache($id);
    }
    private static function fresh(): bool {
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);if(!headers_sent())nocache_headers();
        if(time()-(int)get_option('vta_b2c_verified_at',0)>60)self::sync();
        // An unavailable or delayed source hides public content rather than serving an unverified old revision.
        return time()-(int)get_option('vta_b2c_verified_at',0)<=120;
    }
    public static function collection(): string {
        if(!self::fresh())return '<p>Tour collection is temporarily unavailable. Please contact Vietnam Travel Advisor.</p>';
        $out='<section class="vta-journeys">';foreach(get_posts(['post_type'=>'vta_journey','post_status'=>'publish','numberposts'=>100]) as $p)$out.='<article><h2><a href="'.esc_url(get_permalink($p)).'">'.esc_html($p->post_title).'</a></h2><p>'.esc_html($p->post_excerpt).'</p><p>Request a quotation</p></article>';return $out.'</section>';
    }
    public static function node(array $n,string $imageBase): string {
        $type=$n['type']??'';if($type==='text'){
            $text=esc_html($n['text']??'');$tags=['bold'=>'strong','italic'=>'em','underline'=>'u','strike'=>'s','code'=>'code'];
            foreach($n['marks']??[] as $mark){$t=$mark['type']??'';
                if(isset($tags[$t]))$text='<'.$tags[$t].'>'.$text.'</'.$tags[$t].'>';
                elseif($t==='link'){$url=$mark['attrs']['href']??'';if(is_string($url)&&filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true))$text='<a href="'.esc_url($url).'" rel="nofollow noopener noreferrer">'.$text.'</a>';}
            }return $text;
        }
        if($type==='image'){$id=(int)($n['attrs']['assetId']??0);return $id?'<img loading="lazy" src="'.esc_url($imageBase.$id).'" alt="'.esc_attr($n['attrs']['alt']??'Tour image').'">':'';}
        $inner='';foreach($n['content']??[] as $child)$inner.=self::node($child,$imageBase);
        if($type==='doc')return $inner;if($type==='hardBreak')return '<br>';
        $tags=['paragraph'=>'p','bulletList'=>'ul','orderedList'=>'ol','listItem'=>'li','blockquote'=>'blockquote','table'=>'table','tableRow'=>'tr','tableCell'=>'td','tableHeader'=>'th'];$tag=$type==='heading'?'h'.max(2,min(6,(int)($n['attrs']['level']??2))):($tags[$type]??'div');return '<'.$tag.'>'.$inner.'</'.$tag.'>';
    }
    public static function detail($attrs=[]): string {
        if(!self::fresh())return '<p>This tour is temporarily unavailable. Please contact Vietnam Travel Advisor.</p>';
        $id=(int)($attrs['id']??get_the_ID());if(get_post_type($id)!=='vta_journey'||get_post_status($id)!=='publish')return '';
        $s=get_post_meta($id,'_vta_b2c_snapshot',true);if(!is_array($s))return '';
        wp_enqueue_script('vta-b2c-inquiry',plugins_url('../assets/b2c-inquiry.js',__FILE__),[],'0.2.0',true);
        $out='<article class="vta-journey"><h1>'.esc_html($s['title']).'</h1>'.self::node($s['document']['content'],(string)get_post_meta($id,'_vta_b2c_image_base',true)).'<p>Request a reviewed quotation. Availability and booking require staff confirmation.</p>';
        return $out.'<form data-vta-b2c-inquiry data-api="'.esc_url(rest_url('vta-connect/v1/lead')).'" data-publication="'.(int)get_post_meta($id,'_vta_b2c_publication',true).'"><label>Name<input name="contact_name" required maxlength="190"></label><label>Email<input name="email" type="email" required maxlength="190"></label><label>Phone / WhatsApp (optional)<input name="phone" type="tel" maxlength="64"></label><label>Travel date (optional)<input name="travel_date" type="date"></label><label>Flexible dates until (optional)<input name="travel_date_end" type="date"></label><label>Travellers<input name="total_guests" type="number" min="1" max="1000" value="2" required></label><label>Dates and requirements<textarea name="message" maxlength="7400"></textarea></label><label><input name="consent" type="checkbox" required>I agree to be contacted about this request.</label><button type="submit">Request quotation</button><p role="status"></p></form></article>';
    }
    public static function adminSync(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden');check_admin_referer('vta_b2c_sync');self::sync();wp_safe_redirect(admin_url('options-general.php?page=vta-connect'));exit;
    }
}
