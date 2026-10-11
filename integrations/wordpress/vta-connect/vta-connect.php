<?php
/**
 * Plugin Name: VTA Connect
 * Description: First-party, no-SaaS website chat and quote requests linked to VTA RC6.2.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: vta-connect
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/class-vta-connect-protocol.php';

final class VTA_Connect {
    private const VERSION='0.1.0';
    private const COOKIE='vta_connect_sid';

    public static function init(): void {
        add_action('rest_api_init',[self::class,'routes']);
        add_shortcode('vta_chat',[self::class,'shortcode']);
        add_action('admin_menu',[self::class,'adminMenu']);
    }

    private static function ready(): bool {
        if (!defined('VTA_CONNECT_API_URL') || !defined('VTA_CONNECT_SECRET')) return false;
        $url=(string)VTA_CONNECT_API_URL;
        $parts=wp_parse_url($url);
        return is_array($parts)
            && ($parts['scheme']??'')==='https'
            && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass'])
            && empty($parts['port']) && empty($parts['query']) && empty($parts['fragment'])
            && str_ends_with((string)($parts['path']??''),'/api/index.php')
            && strlen((string)VTA_CONNECT_SECRET)>=32
            && preg_match('/^[a-z0-9_-]{3,64}$/D',self::source())===1;
    }

    private static function source(): string {
        return defined('VTA_CONNECT_SOURCE') ? (string)VTA_CONNECT_SOURCE : 'wordpress_vta';
    }

    private static function contactLink(string $constant,array $hosts): string {
        $value=defined($constant)?(string)constant($constant):'';
        if ($value==='') return '';
        $p=wp_parse_url($value);
        if (!is_array($p) || ($p['scheme']??'')!=='https'
            || !in_array(strtolower((string)($p['host']??'')),$hosts,true)
            || isset($p['user']) || isset($p['pass']) || isset($p['port'])) return '';
        return $value;
    }

    private static function originAllowed(): bool {
        $site=wp_parse_url(home_url('/'));
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if (!is_string($origin) || $origin==='') {
            $siteHeader=$_SERVER['HTTP_SEC_FETCH_SITE']??'';
            return $siteHeader==='' || $siteHeader==='same-origin' || $siteHeader==='none';
        }
        $received=wp_parse_url($origin);
        return is_array($received) && is_array($site)
            && ($received['scheme']??'')===($site['scheme']??'')
            && strtolower((string)($received['host']??''))===strtolower((string)($site['host']??''))
            && ($received['port']??null)===($site['port']??null);
    }

    private static function visitor(bool $create): ?string {
        $cookie=$_COOKIE[self::COOKIE]??null;
        if (!is_string($cookie) || preg_match('/^[a-f0-9]{64}$/D',$cookie)!==1) {
            if (!$create || headers_sent()) return null;
            $cookie=bin2hex(random_bytes(32));
            setcookie(self::COOKIE,$cookie,[
                'expires'=>time()+60*60*24*30,'path'=>'/',
                'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'
            ]);
            $_COOKIE[self::COOKIE]=$cookie;
        }
        return VTA_Connect_Protocol::externalId(hash_hmac('sha256',$cookie,wp_salt('auth')));
    }

    private static function allowed(string $bucket,int $limit,int $seconds): bool {
        // REMOTE_ADDR, never spoofable client forwarding headers. Extra throttle
        // at CDN/WAF level recommended; WP transients are best-effort.
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $key='vta_c_'.substr(hash('sha256',$bucket.':'.$ip),0,32);
        $count=(int)get_transient($key);
        if ($count>=$limit) return false;
        set_transient($key,$count+1,$seconds);
        return true;
    }

    private static function post(string $route,array $event) {
        if (!self::ready()) return new WP_Error('vta_not_configured','Chat has not been connected by the website administrator.',['status'=>503]);
        try {$signed=VTA_Connect_Protocol::encodeSigned($event,(string)VTA_CONNECT_SECRET);}
        catch(Throwable $e) {return new WP_Error('vta_protocol','Chat could not prepare the request.',['status'=>503]);}
        $url=add_query_arg('route',$route,(string)VTA_CONNECT_API_URL);
        $response=wp_safe_remote_post($url,[
            'timeout'=>10,'redirection'=>0,'sslverify'=>true,
            'headers'=>[
                'Content-Type'=>'application/json; charset=utf-8',
                'X-VTA-WEBHOOK-SOURCE'=>self::source(),
                'X-VTA-SIGNATURE-256'=>$signed['signature']
            ],
            'body'=>$signed['body']
        ]);
        if (is_wp_error($response)) return new WP_Error('vta_unreachable','VTA is temporarily unavailable. Please contact us directly.',['status'=>502]);
        $status=wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if ($status<200 || $status>=300 || !is_array($body) || ($body['ok']??false)!==true) {
            return new WP_Error('vta_delivery_failed','VTA could not accept this request. Please try again later.',
                ['status'=>$status===429?429:502]);
        }
        return $body;
    }

    public static function routes(): void {
        foreach (['chat'=>'chat','lead'=>'lead','poll'=>'poll','ack'=>'ack'] as $path=>$handler) {
            register_rest_route('vta-connect/v1','/'.$path,[
                'methods'=>'POST','callback'=>[self::class,$handler],
                'permission_callback'=>'__return_true'
            ]);
        }
    }

    private static function preflight(string $kind,int $limit,int $seconds) {
        if (!self::originAllowed()) return new WP_Error('vta_origin','Cross-site requests are not allowed.',['status'=>403]);
        if (!self::ready()) return new WP_Error('vta_setup','VTA Connect is not configured.',['status'=>503]);
        if (!self::allowed($kind,$limit,$seconds)) return new WP_Error('vta_throttle','Too many requests. Please retry later.',['status'=>429]);
        return null;
    }

    public static function chat(WP_REST_Request $req) {
        if ($error=self::preflight('chat',24,300)) return $error;
        $input=$req->get_json_params();
        if (!is_array($input) || !empty($input['website'])) return new WP_Error('vta_spam','Invalid message.',['status'=>422]);
        $external=self::visitor(true);
        if ($external===null) return new WP_Error('vta_session','Unable to open secure conversation.',['status'=>503]);
        try { $event=VTA_Connect_Protocol::chatEvent($external,$input); }
        catch(Throwable $e) {return new WP_Error('vta_validation','Please check your message.',['status'=>422]);}
        $result=self::post('webhooks/website-message',$event);
        if (is_wp_error($result)) return $result;
        return ['ok'=>true,'accepted'=>true,'replayed'=>!empty($result['replayed'])];
    }

    public static function lead(WP_REST_Request $req) {
        if ($error=self::preflight('lead',5,3600)) return $error;
        $input=$req->get_json_params();
        if (!is_array($input) || !empty($input['website'])) return new WP_Error('vta_spam','Invalid request.',['status'=>422]);
        $external=self::visitor(true);
        if ($external===null) return new WP_Error('vta_session','Unable to open secure conversation.',['status'=>503]);
        try { $event=VTA_Connect_Protocol::leadEvent($external,$input); }
        catch(Throwable $e) {return new WP_Error('vta_validation','Please check contact and guest details.',['status'=>422]);}
        $result=self::post('webhooks/website-lead',$event);
        if (is_wp_error($result)) return $result;
        return ['ok'=>true,'accepted'=>true,'replayed'=>!empty($result['replayed'])];
    }

    public static function poll(WP_REST_Request $req) {
        if ($error=self::preflight('poll',100,300)) return $error;
        $external=self::visitor(false);
        if ($external===null) return ['ok'=>true,'messages'=>[],'last_id'=>0];
        $input=$req->get_json_params();
        $cursor=filter_var($input['after_id']??0,FILTER_VALIDATE_INT);
        if ($cursor===false || $cursor<0) return new WP_Error('vta_cursor','Invalid cursor.',['status'=>422]);
        $result=self::post('webhooks/website-chat-relay',VTA_Connect_Protocol::relayEvent($external,$cursor));
        if (is_wp_error($result)) return $result;
        return ['ok'=>true,'messages'=>array_map(static function($m) {
            return ['id'=>(int)$m['id'],'text'=>(string)$m['text']];
        },$result['messages']??[]),'last_id'=>(int)($result['last_id']??$cursor)];
    }

    public static function ack(WP_REST_Request $req) {
        if ($error=self::preflight('ack',100,300)) return $error;
        $external=self::visitor(false);
        if ($external===null) return new WP_Error('vta_session','Missing chat session.',['status'=>403]);
        $input=$req->get_json_params();
        $ids=$input['message_ids']??null;
        try {
            if (!is_array($ids)) throw new InvalidArgumentException('Invalid ids');
            $event=VTA_Connect_Protocol::ackEvent($external,$ids);
        }catch(Throwable $e){return new WP_Error('vta_ack','Invalid acknowledgement.',['status'=>422]);}
        $result=self::post('webhooks/website-chat-relay',$event);
        return is_wp_error($result)?$result:['ok'=>true,'acknowledged'=>(int)($result['acknowledged']??0)];
    }

    public static function shortcode(): string {
        wp_enqueue_style('vta-connect',plugins_url('assets/widget.css',__FILE__),[],self::VERSION);
        wp_enqueue_script('vta-connect',plugins_url('assets/widget.js',__FILE__),[],self::VERSION,true);
        $api=rest_url('vta-connect/v1/');
        $ready=self::ready();
        ob_start(); ?>
        <section class="vta-connect" data-vta-chat data-api="<?php echo esc_url($api); ?>" aria-label="Vietnam Travel Advisor customer chat">
          <header class="vta-connect__header">
            <strong>Vietnam Travel Advisor</strong>
            <small>Tour consultation · Our team will reply here</small>
          </header>
          <div class="vta-connect__messages" data-messages role="log" aria-live="polite">
            <p class="vta-connect__bubble vta-connect__bubble--staff">Welcome! Ask us about Vietnam tours, dates or a quotation.</p>
          </div>
          <div class="vta-connect__quick" aria-label="Common questions">
            <button type="button" data-question="Do you offer private tours in Vietnam?">Private tours</button>
            <button type="button" data-question="Can I receive a tour itinerary?">Tour itinerary</button>
            <button type="button" data-question="How can I get an accurate tour price?">Request a price</button>
          </div>
          <form data-chat-form class="vta-connect__form">
            <label for="vta-chat-message">Your message</label>
            <textarea id="vta-chat-message" name="text" maxlength="5000" rows="2" required></textarea>
            <input class="vta-connect__trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit" <?php disabled(!$ready); ?>>Send message</button>
          </form>
          <details class="vta-connect__lead">
            <summary>Request a personal tour quotation</summary>
            <form data-lead-form>
              <label>Your name <input name="contact_name" maxlength="190" required></label>
              <label>Email <input name="email" type="email" maxlength="190" required></label>
              <label>Destination <input name="destination" maxlength="160"></label>
              <label>Number of travelers <input name="total_guests" type="number" min="1" max="1000" value="2" required></label>
              <label>Travel preferences <textarea name="message" maxlength="5000" rows="2"></textarea></label>
              <input class="vta-connect__trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
              <button type="submit" <?php disabled(!$ready); ?>>Send request to VTA</button>
            </form>
          </details>
          <?php
          $channels=[
              'WhatsApp'=>self::contactLink('VTA_CONNECT_WHATSAPP_URL',['wa.me','api.whatsapp.com']),
              'Facebook Messenger'=>self::contactLink('VTA_CONNECT_MESSENGER_URL',['m.me','www.facebook.com','facebook.com']),
              'TikTok'=>self::contactLink('VTA_CONNECT_TIKTOK_URL',['www.tiktok.com','tiktok.com'])
          ];
          if (count(array_filter($channels))>0) {
              echo '<nav class="vta-connect__channels" aria-label="Other contact channels">';
              foreach ($channels as $label=>$url) if ($url!=='') {
                  echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a>';
              }
              echo '</nav>';
          }
          ?>
          <p class="vta-connect__notice" data-notice aria-live="polite"><?php
            echo $ready ? esc_html__('FAQ replies are automated; quotations and final prices require staff confirmation.','vta-connect')
                        : esc_html__('Website chat is not yet connected.','vta-connect');
          ?></p>
        </section>
        <?php return (string)ob_get_clean();
    }

    public static function adminMenu(): void {
        add_options_page('VTA Connect','VTA Connect','manage_options','vta-connect',[self::class,'adminPage']);
    }

    public static function adminPage(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>VTA Connect · Website Chat</h1>';
        echo '<p>Connection status: <strong>'.(self::ready()?'Configured (not live-verified)':'Missing private settings').'</strong></p>';
        echo '<p>Place <code>[vta_chat]</code> in a WordPress page or Shortcode block.</p>';
        echo '<p>Set <code>VTA_CONNECT_API_URL</code>, <code>VTA_CONNECT_SECRET</code> and optionally <code>VTA_CONNECT_SOURCE</code> in private wp-config.php. Use the matching <code>integrations.website_webhooks</code> entry in RC6.</p>';
        echo '<p>VTA Connect does not store API secrets in wp_options or expose them to frontend JavaScript. Only staff-approved RC6 tour sharing links may be sent to customers.</p></div>';
    }
}
VTA_Connect::init();
