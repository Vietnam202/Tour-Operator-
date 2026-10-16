<?php
declare(strict_types=1);

/**
 * RC6.2 client share design presentation.
 * Only a MarketingTourShare-approved VTA_PUBLIC_TOUR_OUTLINE_V1 snapshot enters
 * here. The renderer intentionally does NOT read internal proposal_json, prices,
 * document/source text, accommodation names, supplier data or media.
 */
final class ClientTourPresentation {
    public const STYLES=['detailed','brochure','quick','b2b'];
    private static function esc($v):string {
        return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false);
    }
    private static function valid(array $s):array {
        if(($s['schema']??null)!=='VTA_PUBLIC_TOUR_OUTLINE_V1')
            throw new InvalidArgumentException('Unapproved public tour snapshot');
        foreach(['title','destination','language'] as $key){
            if(!is_string($s[$key]??null)||strlen($s[$key])>500)
                throw new InvalidArgumentException('Invalid share metadata');
        }
        if(!is_array($s['day_titles']??null)||!array_is_list($s['day_titles'])
          ||count($s['day_titles'])<1||count($s['day_titles'])>90)
            throw new InvalidArgumentException('Invalid shared day list');
        foreach($s['day_titles'] as $title){
            if(!is_string($title)||strlen($title)>350)
                throw new InvalidArgumentException('Invalid itinerary text');
        }
        return $s;
    }
    public static function allowedLayout($input):string {
        if(!is_string($input)||!in_array($input,self::STYLES,true))
            return 'detailed';
        return $input;
    }
    private static function sharedNav(string $token,string $active):string {
        $out='<nav class="vta-layouts" aria-label="Tour presentation styles">';
        $labels=['detailed'=>'Detailed','brochure'=>'Brochure','quick'=>'Quick Itinerary','b2b'=>'Tour Proposal'];
        foreach($labels as $id=>$label){
            $url='index.php?route=marketing/tour-share/view&amp;token='.self::esc($token).'&amp;layout='.$id;
            $out.='<a href="'.$url.'"'.($active===$id?' aria-current="page"':'').'>'.self::esc($label).'</a>';
        }
        return $out.'</nav>';
    }
    private static function dayCards(array $s,string $style):string {
        $out='<div class="vta-days vta-days-'.$style.'">';
        foreach($s['day_titles'] as $index=>$title) {
            $num=str_pad((string)($index+1),2,'0',STR_PAD_LEFT);
            $out.='<section class="vta-day" data-day="'.$num.'">'
                .'<span class="vta-day-number">DAY '.$num.'</span><div><h3>'.self::esc($title).'</h3>'
                .'<p>Activities, meals, transfers and accommodation are subject to your confirmed itinerary.</p>'
                .'</div></section>';
        }
        return $out.'</div>';
    }
    private static function tables():string {
        $hotel='<section class="vta-section vta-table-section"><h2>Hotel &amp; Cruise Options</h2>'
            .'<p class="vta-muted">Proposed hotel/cruise names and categories will be confirmed by our team.</p>'
            .'<div class="vta-table-scroll"><table><thead><tr><th>Destination</th><th>3 ★</th><th>4 ★</th><th>5 ★</th></tr></thead>'
            .'<tbody><tr><th>Hotels / Cruise</th><td>On request</td><td>On request</td><td>On request</td></tr></tbody></table></div></section>';
        $price='<section class="vta-section vta-table-section"><h2>Tour Price</h2>'
            .'<p class="vta-muted">An official selling quotation requires travel dates, guest numbers and available services.</p>'
            .'<div class="vta-table-scroll"><table><thead><tr><th>Accommodation</th><th>Price / person</th><th>Single supplement</th></tr></thead>'
            .'<tbody><tr><td>3 ★</td><td>Request quote</td><td>On request</td></tr>'
            .'<tr><td>4 ★</td><td>Request quote</td><td>On request</td></tr>'
            .'<tr><td>5 ★</td><td>Request quote</td><td>On request</td></tr></tbody></table></div></section>';
        return $hotel.$price;
    }
    private static function css():string {
        return <<<'CSS'
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;color:#153349;background:#edf2f6;font:15px/1.65 system-ui,-apple-system,Segoe UI,sans-serif}a{color:inherit}h1,h2,h3{line-height:1.2}h1{font-size:clamp(28px,4.2vw,51px);margin:12px 0 17px}h2{font-size:23px;margin:0 0 14px}h3{font-size:15px;margin:0 0 6px}
.vta-shell{max-width:1000px;margin:0 auto;background:#fff;min-height:100vh;box-shadow:0 20px 55px #1738531d}.vta-hero{position:relative;overflow:hidden;padding:55px 54px 50px;background:#123d55;color:#fff}
.vta-brand{font-size:12px;letter-spacing:1.5px;font-weight:800;text-transform:uppercase}.vta-kicker{letter-spacing:1.8px;text-transform:uppercase;font-size:11px;font-weight:800;color:#d7bb84}.vta-hero p{max-width:700px;margin:0;color:#dbe9ee}.vta-panel{padding:30px 54px 60px}
.vta-layouts{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 25px}.vta-layouts a{font-size:12px;font-weight:750;border:1px solid #bcd2da;border-radius:999px;padding:9px 13px;text-decoration:none}.vta-layouts a[aria-current=page]{background:#0c7b7b;color:#fff;border-color:#0c7b7b}
.vta-section{margin:30px 0 0}.vta-muted{color:#607789;font-size:13px;margin:0 0 15px}.vta-days{display:grid;gap:12px}.vta-day{padding:18px;border:1px solid #d8e5eb;border-radius:12px;display:grid;grid-template-columns:90px minmax(0,1fr);gap:14px;page-break-inside:avoid}.vta-day-number{font-size:12px;color:#087a80;font-weight:900}.vta-day p{color:#688092;font-size:12px;margin:4px 0}
.vta-table-scroll{width:100%;overflow-x:auto}.vta-table-section table{border-collapse:collapse;width:100%;font-size:13px}.vta-table-section th,.vta-table-section td{padding:12px;border:1px solid #d7e3e8;text-align:left}.vta-table-section thead{background:#edf5f6}.vta-caveat{margin:25px 0;padding:17px;background:#fff8e9;border-left:4px solid #cea663;font-size:12px;color:#785c31}
.vta-client-foot{border-top:1px solid #dce5e9;padding:25px 54px;color:#5c7483;font-size:12px}.vta-cta-anchor{display:inline-block;background:#0d8184;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:750}
.vta-theme-brochure .vta-hero{min-height:415px;display:flex;flex-direction:column;justify-content:end;background:radial-gradient(ellipse at 75% 20%,#448d9b,#214963 45%,#0c293c 90%)}.vta-theme-brochure .vta-days{grid-template-columns:repeat(2,minmax(0,1fr))}.vta-theme-brochure .vta-day{display:block;min-height:128px;border:0;border-top:4px solid #bc9b68;border-radius:0;background:#faf8f3}
.vta-theme-quick .vta-hero{background:#ebf5f5;color:#123b52;padding:38px 54px}.vta-theme-quick .vta-kicker{color:#0e7b80}.vta-theme-quick .vta-hero p{color:#4e6979}.vta-theme-quick .vta-days{gap:0}.vta-theme-quick .vta-day{border:0;border-radius:0;border-bottom:1px solid #dce6e9;padding:12px 2px}.vta-theme-quick .vta-day p{display:none}
.vta-theme-b2b{border-top:8px solid #193d63}.vta-theme-b2b .vta-hero{background:#183452}.vta-theme-b2b .vta-days{gap:0}.vta-theme-b2b .vta-day{border-radius:0;border-left:0;border-right:0;border-top:0}.vta-theme-b2b .vta-table-section thead{background:#173c5d;color:white}
.vta-lead-cta{margin-top:25px!important}
@media(max-width:650px){.vta-shell{max-width:100%}.vta-hero{padding:40px 19px}.vta-panel{padding:23px 17px 45px}.vta-brand{font-size:11px}.vta-client-foot{padding:20px}.vta-day{grid-template-columns:62px minmax(0,1fr);gap:8px;padding:14px}.vta-theme-brochure .vta-days{grid-template-columns:1fr}.vta-theme-brochure .vta-hero{min-height:300px}.vta-theme-quick .vta-hero{padding:32px 19px}.vta-layouts a{padding:7px 11px;font-size:11px}}
@media print{body{background:white}.vta-shell{box-shadow:none;max-width:none}.vta-layouts,.vta-cta-anchor{display:none!important}.vta-hero{padding:35px}.vta-panel{padding:22px}.vta-day{break-inside:avoid}.vta-table-section{break-inside:avoid}}
CSS;
    }
    public static function render(array $snapshot,string $layout,string $token):string {
        $s=self::valid($snapshot);
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Invalid share token');
        $layout=self::allowedLayout($layout);
        $en=$s['language']!=='vi';
        $days=count($s['day_titles']);
        $brand='Vietnam Travel Advisor';
        $subtitle='Personalised travel · Local expertise · Subject to confirmation';
        $title=self::esc($s['title']);
        $destination=self::esc($s['destination']);
        $nav=self::sharedNav($token,$layout);
        $classes='vta-shell vta-theme-'.$layout;
        $label=$layout==='b2b'?'PARTNER TOUR PROPOSAL':($layout==='quick'?'ITINERARY AT A GLANCE':($layout==='brochure'?'DISCOVER VIETNAM':'PROFESSIONAL TOUR ITINERARY'));
        $content=self::dayCards($s,$layout).self::tables();
        return '<!doctype html><html lang="'.($en?'en':'vi').'"><head><meta charset="utf-8">'
            .'<meta name="robots" content="noindex,nofollow,noarchive"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$title.' | '.$brand.'</title><style>'.self::css().'</style></head><body>'
            .'<div class="'.$classes.'"><header class="vta-hero"><div class="vta-brand">'.$brand.'</div>'
            .'<div class="vta-kicker">'.self::esc($label).' · '.$days.' DAYS</div>'
            .'<h1>'.$title.'</h1><p>'.$destination.' · '.self::esc($subtitle).'</p></header>'
            .'<main class="vta-panel">'.$nav.'<section class="vta-section"><h2>Day-by-Day Program</h2>'
            .$content.'</section><p class="vta-caveat">This page displays only an approved tour outline. Full activities, image media, hotels, cruises, terms and selling prices require separate publication approval. Submitting an enquiry does not confirm a booking.</p>'
            .'<a class="vta-cta-anchor" href="#book-tour">Request Booking ↗</a></main>'
            .'<footer class="vta-client-foot">Vietnam Travel Advisor · info@vietnamtraveladvisor.com.vn · +84 582 230 993</footer>'
            .'</div></body></html>';
    }
}
