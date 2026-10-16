<?php
declare(strict_types=1);

/**
 * Customer tour-share booking requests: NEVER a confirmed Booking.
 *
 * Uses an already approved/revocable MarketingTourShare bearer link and the
 * existing B2C lead form + LeadHub::submit. All authorization/attribution
 * and sensitive pricing remain server-side. This is an optional, fail-closed
 * extension: when the B2C form is not configured, no input form is shown.
 */
final class MarketingShareBooking {
    private static function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }
    private static function secureHeaders(bool $form=true): void {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action ".($form?"'self'":"'none'")."; frame-ancestors 'none'");
    }
    private static function validToken(string $token): string {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new OutOfBoundsException('Link unavailable');
        return $token;
    }
    /** Recheck live approval and revocation at both form display and lead commit. */
    private static function share(PDO $db,string $token): array {
        $token=self::validToken($token);
        $st=$db->prepare(
            'SELECT s.id AS share_id,s.company_id,s.program_id,s.program_snapshot_hash,
                    p.id,p.title,p.destination,p.language,p.status,p.tags_json,p.days_json,
                    p.marketing_share_approved_hash
             FROM marketing_tour_shares s
             JOIN tour_library_programs p ON p.company_id=s.company_id AND p.id=s.program_id
             WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>NOW()
             LIMIT 1'
        );
        $st->execute([hash('sha256',$token)]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Link unavailable');
        try{$current=MarketingTourAdvisor::snapshotHash($row);}
        catch(Throwable $e){throw new OutOfBoundsException('Program unavailable');}
        if($row['status']!=='ACTIVE'
            ||!hash_equals((string)$row['program_snapshot_hash'],$current)
            ||!hash_equals((string)$row['marketing_share_approved_hash'],$current))
            throw new OutOfBoundsException('Link approval is no longer current');
        return $row;
    }
    private static function activeLeadForm(PDO $db,array $cfg,array $share):array {
        $s=B2CStorefront::settings($cfg);
        if($s['company_id']!==(int)$share['company_id'])throw new OutOfBoundsException('Lead form not configured for company');
        $st=$db->prepare(
            "SELECT f.id FROM lead_forms f JOIN campaigns c ON c.id=f.campaign_id AND c.company_id=f.company_id
             WHERE f.public_token=? AND f.company_id=? AND f.status='ACTIVE' AND c.status='ACTIVE' LIMIT 1"
        );
        $st->execute([$s['form_token'],$s['company_id']]);
        if(!$st->fetchColumn())throw new OutOfBoundsException('Lead form unavailable');
        return $s;
    }
    private static function form(string $token): string {
        if(session_status()!==PHP_SESSION_ACTIVE)throw new RuntimeException('Session unavailable');
        if(!is_string($_SESSION['tour_share_booking_csrf']??null))$_SESSION['tour_share_booking_csrf']=bin2hex(random_bytes(24));
        $csrf=self::esc($_SESSION['tour_share_booking_csrf']);
        $request=bin2hex(random_bytes(24));
        $action='index.php?route=marketing/tour-share/request-booking&amp;token='.self::esc($token);
        return '<section id="book-tour" class="vta-lead-cta"><p class="vta-lead-kicker">REQUEST BOOKING · NOT A CONFIRMED RESERVATION</p>'
            .'<h2>Plan this journey</h2><p>Share your preferred dates and guest details. Our team will confirm availability and prepare a quotation. No payment is taken here.</p>'
            .'<form action="'.$action.'" method="post" accept-charset="UTF-8">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'">'
            .'<input type="hidden" name="request_id" value="'.$request.'">'
            .'<label>Full name *<input name="contact_name" maxlength="190" required autocomplete="name"></label>'
            .'<label>Email *<input name="email" type="email" maxlength="190" required autocomplete="email"></label>'
            .'<label>WhatsApp / phone<input name="phone" maxlength="64" autocomplete="tel"></label>'
            .'<label>Departure date *<input name="travel_date" type="date" required></label>'
            .'<label>Number of guests *<input name="total_guests" type="number" min="1" max="1000" value="2" required></label>'
            .'<label>Hotel category<select name="hotel_level"><option value="">Please advise</option><option value="3-star">3-star</option><option value="4-star">4-star</option><option value="5-star">5-star</option></select></label>'
            .'<label class="vta-full">Special requests<textarea name="message" maxlength="3000" rows="3"></textarea></label>'
            .'<label class="vta-full vta-consent"><input name="consent" type="checkbox" value="yes" required>I agree that Vietnam Travel Advisor may contact me about this enquiry. I understand this is not a confirmed booking.</label>'
            .'<input aria-hidden="true" tabindex="-1" autocomplete="off" name="website" class="vta-honeypot">'
            .'<button type="submit">Request Booking</button></form></section>';
    }
    private static function formCss():string {
        return '.vta-lead-cta{background:#f4f9f8;border:1px solid #c9e1df;border-radius:16px;padding:24px;margin:35px 0}'
        .'.vta-lead-kicker{font-size:11px;color:#137c7c;letter-spacing:.12em;font-weight:800}'
        .'.vta-lead-cta h2{margin:6px 0}.vta-lead-cta p{color:#496776}'
        .'.vta-lead-cta form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}'
        .'.vta-lead-cta label{display:flex;flex-direction:column;font-size:12px;font-weight:700;gap:5px}'
        .'.vta-lead-cta input,.vta-lead-cta textarea,.vta-lead-cta select{padding:10px;border-radius:8px;border:1px solid #c1d2da;max-width:100%;font:inherit}'
        .'.vta-lead-cta .vta-full{grid-column:1/-1}.vta-lead-cta .vta-consent{flex-direction:row;align-items:flex-start;font-weight:500}'
        .'.vta-lead-cta .vta-consent input{margin-top:3px}.vta-lead-cta button{background:#0c7d7d;color:#fff;border:0;padding:12px 19px;border-radius:8px;font:inherit;font-weight:750;cursor:pointer}'
        .'.vta-honeypot{position:absolute!important;left:-99999px!important;width:1px!important;height:1px!important}'
        .'@media(max-width:620px){.vta-lead-cta{padding:18px}.vta-lead-cta form{grid-template-columns:1fr}.vta-lead-cta .vta-full{grid-column:1}}'
        .'@media print{.vta-lead-cta{display:none!important}}';
    }
    private static function publicGet(PDO $db,array $cfg,string $token):void {
        self::secureHeaders();
        $token=self::validToken($token);
        $snapshot=MarketingTourShare::view($db,$token);
        $html=MarketingTourShare::html($snapshot);
        $form='';
        try{
            $row=self::share($db,$token);
            self::activeLeadForm($db,$cfg,$row);
            $form=self::form($token);
        }catch(OutOfBoundsException $e){
            // Read-only public tour remains available; no fake working lead form.
            $form='<p role="status">Online enquiry is temporarily unavailable. Please contact Vietnam Travel Advisor directly.</p>';
        }
        $html=str_replace('</style>',''.self::formCss().'</style>',$html);
        $html=str_replace('</main>','</main>'.$form,$html);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
    private static function publicPost(PDO $db,array $cfg,string $token):void {
        self::secureHeaders(false);
        if(session_status()!==PHP_SESSION_ACTIVE)throw new OutOfBoundsException('Session unavailable');
        if(!isset($_SESSION['tour_share_booking_csrf'])
            ||!is_string($_POST['csrf']??null)
            ||!hash_equals((string)$_SESSION['tour_share_booking_csrf'],(string)$_POST['csrf']))
            throw new DomainException('Invalid request session');
        if(strlen((string)file_get_contents('php://input'))>16384)throw new InvalidArgumentException('Payload too large');
        $share=self::share($db,$token);
        $cfgForm=self::activeLeadForm($db,$cfg,$share);
        $request=$_POST;
        $request['consent']=($request['consent']??'')==='yes';
        $request['attribution']=[
            'utm_source'=>'vta_tour_share', 'utm_medium'=>'customer_link',
            'utm_campaign'=>'tour_program_'.(int)$share['program_id'],
            'utm_content'=>'share_'.(int)$share['share_id']
        ];
        if(!is_string($request['travel_date']??null)||$request['travel_date']==='')
            throw new InvalidArgumentException('Departure date required');
        $level=$request['hotel_level']??'';
        if(!is_string($level)||!in_array($level,['','3-star','4-star','5-star'],true))
            throw new InvalidArgumentException('Hotel category invalid');
        $lead=B2CStorefront::lead($request);
        $lead['hotel_level']=$level;
        $lead['destination']=$share['destination'];
        $lead['message']='BOOKING ENQUIRY (not confirmed) | VTA tour share #'.(int)$share['share_id']
            .' | Master program #'.(int)$share['program_id']
            .' | Approved snapshot: '.(string)$share['program_snapshot_hash']
            .' | Tour: '.(string)$share['title']."\n".$lead['message'];
        $lead['submission_key']='vta_share_'.hash('sha256',(int)$share['company_id'].':'.(int)$share['share_id'].':'.$request['request_id']);
        $expectedCompany=(int)$share['company_id'];
        $expectedId=(int)$share['share_id'];
        LeadHub::submit($db,$cfgForm['form_token'],$lead,static function(int $company,int $leadId)use($db,$token,$expectedCompany,$expectedId):void{
            $latest=self::share($db,$token);
            if($company!==$expectedCompany||(int)$latest['share_id']!==$expectedId)
                throw new OutOfBoundsException('Share approval changed');
            // LeadHub writes the canonical company-scoped NEW request with audit.
            // No Booking, Quote SENT, supplier acceptance or payment is created.
        });
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(201);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"><title>Request Received · Vietnam Travel Advisor</title>'
            .'<main style="max-width:650px;margin:14vh auto;font:17px/1.7 system-ui;padding:25px;color:#18445a">'
            .'<h1>Thank you. Your tour enquiry has been received.</h1>'
            .'<p>Our team will contact you to confirm prices, availability and services. This is <b>not</b> a confirmed booking.</p>'
            .'<p>Vietnam Travel Advisor · info@vietnamtraveladvisor.com.vn</p></main></html>';
        exit;
    }
    public static function publicHandle(string $route,string $method,PDO $db,array $cfg):void {
        $view=$route==='marketing/tour-share/view'&&$method==='GET';
        $post=$route==='marketing/tour-share/request-booking'&&$method==='POST';
        if(!$view&&!$post)return;
        try{
            $token=is_string($_GET['token']??null)?$_GET['token']:'';
            if($view)self::publicGet($db,$cfg,$token);
            self::publicPost($db,$cfg,$token);
        }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'LINK_OR_ENQUIRY_UNAVAILABLE'],404);}
        catch(OverflowException $e){Http::json(['ok'=>false,'error'=>'RATE_LIMITED'],429);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT_OR_INVALID_CSRF'],409);}
        catch(InvalidArgumentException|JsonException $e){Http::json(['ok'=>false,'error'=>'INVALID_ENQUIRY'],422);}
        catch(Throwable $e){Http::json(['ok'=>false,'error'=>'ENQUIRY_UNAVAILABLE'],503);}
    }
}