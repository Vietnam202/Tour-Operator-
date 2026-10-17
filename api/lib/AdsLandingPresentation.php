<?php
declare(strict_types=1);

/**
 * Approved B2C publication -> one of four customer-only ad landing templates.
 * Never consumes a Master programme, partner working copy or NET/markup.
 */
final class AdsLandingPresentation {
    public const LAYOUTS=['detailed','brochure','quick','b2b'];
    private static function e($v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false);}
    public static function style($s):string {
        return is_string($s)&&in_array($s,self::LAYOUTS,true)?$s:'detailed';
    }
    public static function snapshot(array $s):array {
        if(($s['schema']??null)!=='VTA_B2C_PUBLICATION_V1'||($s['pricing']??'')!=='REQUEST_QUOTE')
            throw new DomainException('Only reviewed request-quote B2C publication can be advertised');
        foreach(['title','destination','language'] as $key)
            if(!is_string($s[$key]??null)||strlen($s[$key])>500)throw new DomainException('Invalid public content');
        $doc=$s['document']??null;
        if(!is_array($doc)||($doc['schema']??'')!=='VTA_DOC_2'||!is_array($doc['content']??null))
            throw new DomainException('Approved document unavailable');
        return $s;
    }
    public static function html(array $snapshot,array $landing,string $layout,string $csrf,string $nonce):string {
        $s=self::snapshot($snapshot);
        $slug=(string)$landing['slug'];
        if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||strlen($slug)>90)
            throw new InvalidArgumentException('Invalid landing slug');
        $layout=self::style($layout);
        if(!preg_match('/^[a-f0-9]{48}$/D',$csrf))throw new DomainException('Session not ready');
        if(!preg_match('/^[a-zA-Z0-9+\/]{22}==$/D',$nonce))throw new DomainException('Invalid CSP nonce');
        $route='index.php?route=ads/landing/'.$slug;
        $formRoute='index.php?route=ads/landing/'.$slug.'/lead';
        $actionRoute='index.php?route=ads/landing/'.$slug.'/event';
        $eventId=(int)$landing['publication_event_id'];
        $body=FreeformDocument::html($s['document'],
            static fn($id)=>'index.php?route=b2c-public-image/'.$eventId.'/'.(int)$id);
        $labels=['detailed'=>'Professional Detailed','brochure'=>'Photo-rich Brochure',
            'quick'=>'Quick Itinerary','b2b'=>'Tour Proposal'];
        $nav='<nav class="templates" aria-label="Choose tour presentation">';
        foreach($labels as $id=>$label)
            $nav.='<a href="'.self::e($route.'&layout='.$id).'"'.
                ($id===$layout?' aria-current="page"':'').'>'.self::e($label).'</a>';
        $nav.='</nav>';
        $title=self::e($s['title']);
        $dest=self::e($s['destination']);
        $request=bin2hex(random_bytes(24));
        $form='<section id="request" class="lead-card"><div class="lead-intro"><span class="eyebrow">REQUEST A QUOTATION</span><h2>Plan your Vietnam journey</h2>'
            .'<p>Tell us your travel preferences. Our local team will confirm prices and availability. This is not a confirmed booking.</p></div>'
            .'<form id="request-form" method="post" action="'.self::e($formRoute).'">'
            .'<input type="hidden" name="csrf" value="'.self::e($csrf).'">'
            .'<input type="hidden" name="request_id" value="'.$request.'">'
            .'<label>Full name *<input required maxlength="190" name="contact_name" autocomplete="name"></label>'
            .'<label>Email *<input required type="email" maxlength="190" name="email" autocomplete="email"></label>'
            .'<label>WhatsApp / phone<input name="phone" maxlength="64" autocomplete="tel"></label>'
            .'<label>Preferred departure *<input required type="date" name="travel_date"></label>'
            .'<label>Travellers *<input required name="total_guests" type="number" min="1" max="1000" value="2"></label>'
            .'<label>Accommodation<select name="hotel_level"><option value="">To be advised</option><option value="3-star">3-star</option><option value="4-star">4-star</option><option value="5-star">5-star</option></select></label>'
            .'<label class="span2">Special requests<textarea name="message" rows="3" maxlength="3000"></textarea></label>'
            .'<label class="span2 consent"><input type="checkbox" name="consent" value="yes" required>I consent to being contacted by Vietnam Travel Advisor about this tour enquiry.</label>'
            .'<label class="span2 consent"><input type="checkbox" name="analytics_consent" value="yes">Optional: I agree to third-party ad conversion measurement if separately enabled later. No third-party tracking is active on this page.</label>'
            .'<input tabindex="-1" aria-hidden="true" name="website" class="honeypot" autocomplete="off">'
            .'<button class="submit" type="submit">Request Booking / Get a Quote</button></form></section>';
        $hotel='<section class="info-section"><h2>Hotel &amp; Cruise Options</h2><p>Hotels and cruises will be proposed and confirmed in the final quotation.</p>'
            .'<div class="table-scroll"><table><thead><tr><th>Destination</th><th>3★</th><th>4★</th><th>5★</th></tr></thead><tbody>'
            .'<tr><th>Available hotel / cruise</th><td>On request</td><td>On request</td><td>On request</td></tr></tbody></table></div></section>';
        $price='<section class="info-section"><h2>Tour Price</h2><p>Rates depend on travel dates, group size and available services.</p>'
            .'<div class="table-scroll"><table><thead><tr><th>Category</th><th>Price / guest</th><th>Single supplement</th></tr></thead><tbody>'
            .'<tr><td>3★</td><td>Get a quote</td><td>On request</td></tr>'
            .'<tr><td>4★</td><td>Get a quote</td><td>On request</td></tr>'
            .'<tr><td>5★</td><td>Get a quote</td><td>On request</td></tr></tbody></table></div></section>';
        $style=<<<'CSS'
:root{color-scheme:light}*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:#ebf2f5;color:#173c52;font:15px/1.67 system-ui,-apple-system,Segoe UI,sans-serif}a{color:inherit}h1,h2,h3{line-height:1.2}h1{font-size:clamp(29px,5vw,54px);margin:18px 0}h2{font-size:23px;margin:0 0 10px}.page{background:white;max-width:1050px;margin:auto;min-height:100vh;box-shadow:0 10px 55px #153d5120}.hero{background:#123e56;padding:50px 56px;color:white}.hero .brand{font-weight:850;letter-spacing:2px;font-size:11px}.eyebrow{font-weight:800;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#dabb7e}.hero p{color:#d9e6eb;max-width:740px}.hero-cta{display:inline-block;text-decoration:none;background:#0a8589;color:#fff;border-radius:9px;padding:12px 19px;font-size:13px;font-weight:800;margin-top:15px}.shell{padding:28px 56px 55px}.templates{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:25px}.templates a{padding:9px 15px;border:1px solid #c7dae2;border-radius:99px;text-decoration:none;font-weight:750;font-size:12px}.templates a[aria-current=page]{background:#0b7b83;color:#fff}.intro-note{background:#f2f8f8;border-left:4px solid #14a0a4;padding:13px 18px;font-size:12px;color:#345e72}.document{margin:30px 0;border-top:1px solid #d4e2e6;padding-top:26px;overflow-wrap:anywhere}.document h2{border-bottom:2px solid #cddfe2;padding-bottom:10px}.document p{margin:12px 0}.document img{display:block;max-width:100%;height:auto;border-radius:9px;margin:18px auto;object-fit:cover}.document table,.table-scroll table{border-collapse:collapse;width:100%;font-size:12px}.document table td,.document table th,.table-scroll table td,.table-scroll table th{border:1px solid #d7e4ea;padding:10px;text-align:left;vertical-align:top;overflow-wrap:anywhere}.table-scroll{overflow-x:auto}.table-scroll table{min-width:470px}.table-scroll thead{background:#eaf4f5}.info-section{margin:35px 0}.info-section p{color:#536b7a;font-size:13px}.lead-card{background:#f3faf9;border-radius:15px;border:1px solid #c6e2de;padding:26px;margin-top:40px}.lead-intro{max-width:710px}.lead-card p{font-size:13px;color:#506c7a}.lead-card form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:17px}.lead-card label{display:grid;gap:5px;font-size:12px;font-weight:700}.lead-card input,.lead-card textarea,.lead-card select{width:100%;padding:11px;border:1px solid #bfd1d7;border-radius:8px;font:inherit;background:white}.lead-card .span2{grid-column:1/-1}.lead-card .consent{display:flex;align-items:flex-start;gap:9px;font-weight:500}.lead-card .consent input{width:auto;margin:3px 0}.submit{border:0;background:#0a8287;color:#fff;padding:14px 21px;font-weight:800;border-radius:9px;cursor:pointer}.honeypot{position:absolute!important;left:-100000px;width:1px!important;height:1px!important}.foot{padding:28px 55px;border-top:1px solid #dfebef;color:#577184;font-size:12px}.notice{color:#8d671f;background:#fff9ec;padding:10px 14px;font-size:12px;border-left:3px solid #d7aa55}
.mode-detailed .document p{line-height:1.9}.mode-detailed .document h2{color:#125270;font-size:25px;margin-top:34px}.mode-detailed .hero{background:linear-gradient(140deg,#0f344d,#216979)}
.mode-brochure .hero{background:radial-gradient(ellipse at 78% 12%,#348c9b,#19465f 57%,#0a283b);min-height:385px;display:flex;justify-content:end;flex-direction:column}.mode-brochure .hero h1{font-family:Georgia,serif;font-size:clamp(38px,6vw,65px);max-width:800px}.mode-brochure .document h2{font-family:Georgia,serif;color:#164963;border-bottom:2px solid #cba76a}.mode-brochure .document img{max-height:360px;width:100%}.mode-brochure .document>div>p{font-family:Georgia,serif;font-size:16px}
.mode-quick .hero{background:#e5f3f5;color:#123d54;padding:30px 56px}.mode-quick .hero p{color:#496a79}.mode-quick .hero .eyebrow{color:#0b7380}.mode-quick .document p{font-size:13px;line-height:1.5;margin:8px 0}.mode-quick .document h2{font-size:18px;margin:19px 0 7px}.mode-quick .document img{max-height:140px;object-fit:cover}
.mode-b2b .hero{background:#173954;border-top:8px solid #caac74}.mode-b2b .document h2{font-size:21px;color:#123d62}.mode-b2b .info-section{background:#f6f8fa;padding:18px;border-top:4px solid #193d5a}.mode-b2b .document table thead{background:#dfeaf1}
@media(max-width:650px){.hero{padding:34px 20px}.mode-quick .hero{padding:25px 20px}.shell{padding:19px 16px 40px}.lead-card{padding:17px}.lead-card form{grid-template-columns:1fr}.lead-card .span2{grid-column:1}.foot{padding:20px}.templates a{padding:7px 10px;font-size:11px}}
@media print{@page{size:A4;margin:13mm 11mm}body{background:white}.page{max-width:none;box-shadow:none}.shell{padding:14px 0}.hero{padding:28px}.templates,.lead-card,.track-explain{display:none!important}.document img,.info-section{break-inside:avoid}.table-scroll{overflow:visible}.table-scroll table{min-width:0!important}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
CSS;
        $js=<<<'JS'
document.addEventListener('DOMContentLoaded',()=>{
  const cta=document.querySelector('#request-form');
  if(!cta)return;
  let once=false;const payload={csrf:cta.elements.csrf.value,event:'cta'};
  const track=()=>{if(once)return;once=true;fetch(document.body.dataset.clickRoute,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),keepalive:true}).catch(()=>{});};
  document.querySelector('.hero-cta')?.addEventListener('click',track,{once:true});
  cta.querySelector('.submit')?.addEventListener('click',track,{once:true});
});
JS;
        return '<!doctype html><html lang="'.($s['language']==='vi'?'vi':'en').'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex,follow"><meta name="referrer" content="same-origin">'
            .'<title>'.$title.' | Vietnam Travel Advisor</title><meta name="description" content="'.$dest.' — Vietnam Travel Advisor tour overview and enquiry.">'
            .'<style>'.$style.'</style></head>'
            .'<body data-click-route="'.self::e($actionRoute).'"><div class="page mode-'.$layout.'">'
            .'<header class="hero"><span class="brand">VIETNAM TRAVEL ADVISOR</span><span class="eyebrow" style="display:block;margin-top:18px">PLAN YOUR NEXT JOURNEY</span>'
            .'<h1>'.$title.'</h1><p>'.$dest.' · Private and group travel · Local tour operator</p><a class="hero-cta" href="#request">Request Booking / Get a Quote</a></header>'
            .'<main class="shell">'.$nav.'<p class="intro-note">This tour description comes from a reviewed VTA B2C publication. Schedules, accommodations and prices are subject to reconfirmation.</p>'
            .'<section class="document" aria-label="Approved itinerary">'.$body.'</section>'
            .$hotel.$price.$form
            .'<p class="notice">Sending a request creates an enquiry only. Vietnam Travel Advisor will confirm services before any booking.</p></main>'
            .'<footer class="foot">Vietnam Travel Advisor · info@vietnamtraveladvisor.com.vn · WhatsApp +84 582 230 993</footer>'
            .'</div><script nonce="'.$nonce.'">'.$js.'</script></body></html>';
    }
}
