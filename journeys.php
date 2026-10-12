<?php
declare(strict_types=1);
require __DIR__.'/api/bootstrap.php';
header('Cache-Control: no-store, max-age=0');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Content-Type: text/html; charset=utf-8');
if(Http::method()!=='GET'){http_response_code(405);exit('Method not allowed');}
$escape=[B2CStorefront::class,'escape'];$publication=null;$snapshot=null;$collection=['items'=>[],'has_more'=>false];$page=1;
try{
    $settings=B2CStorefront::settings($config);
    if(isset($_GET['publication'])){
        $event=filter_var($_GET['publication'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$event)throw new OutOfBoundsException('Unavailable');
        $publication=B2CStudio::publication($db,$settings['company_id'],$event);$snapshot=json_decode($publication['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
    }else{
        $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2000]]);if(!$page)throw new OutOfBoundsException('Unavailable');
        $collection=B2CStorefront::listing($db,$settings['company_id'],($page-1)*50);
    }
}catch(OutOfBoundsException|InvalidArgumentException $e){http_response_code(404);exit('This tour collection is unavailable.');}
catch(Throwable $e){http_response_code(503);exit('Tour collection is temporarily unavailable.');}
if(empty($_SESSION['b2c_inquiry_csrf']))$_SESSION['b2c_inquiry_csrf']=bin2hex(random_bytes(32));
$tracking=[];foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id'] as $key)if(is_string($_GET[$key]??null))$tracking[$key]=substr($_GET[$key],0,1000);
$journeyUrl=static fn(array $query=[])=>'journeys.php'.(($query+$tracking)?'?'.http_build_query($query+$tracking,'','&',PHP_QUERY_RFC3986):'');
$base=rtrim((string)$config['app']['base_url'],'/');
$canonical=$base.'/journeys.php'.($publication?'?publication='.(int)$publication['id']:($page>1?'?page='.$page:''));
$title=$snapshot?$snapshot['title'].' | Vietnam Travel Advisor':'Journeys | Vietnam Travel Advisor';
$description=$snapshot?'Explore '.$snapshot['title'].' in '.$snapshot['destination'].'. Request a tailored quotation from Vietnam Travel Advisor.':'Explore Vietnam journeys and request a tailored quotation from Vietnam Travel Advisor.';
?>
<!doctype html>
<html lang="<?= $escape($snapshot['language']??'en') ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $escape($title) ?></title><meta name="description" content="<?= $escape($description) ?>">
<link rel="canonical" href="<?= $escape($canonical) ?>">
<meta property="og:type" content="website"><meta property="og:title" content="<?= $escape($title) ?>"><meta property="og:description" content="<?= $escape($description) ?>"><meta property="og:url" content="<?= $escape($canonical) ?>">
<link rel="stylesheet" href="b2c-storefront.css?v=B2C-NATIVE1"><script src="b2c-storefront.js?v=B2C-NATIVE1" defer></script>
</head>
<body><header class="site-header"><a href="journeys.php" class="brand">Vietnam Travel Advisor<span>Journeys made personal</span></a><a href="journeys.php">Explore journeys</a></header>
<main>
<?php if($snapshot): ?>
<nav aria-label="Breadcrumb"><a href="journeys.php">Journeys</a> / <?= $escape($snapshot['destination']) ?></nav>
<article class="journey" itemscope itemtype="https://schema.org/Service"><p class="eyebrow"><?= $escape($snapshot['destination']) ?></p><h1 itemprop="name"><?= $escape($snapshot['title']) ?></h1><meta itemprop="serviceType" content="Tour planning"><meta itemprop="provider" content="Vietnam Travel Advisor"><meta itemprop="url" content="<?= $escape($canonical) ?>">
<div class="itinerary"><?= B2CStorefront::node($snapshot['document']['content'],'api/index.php?route=b2c-public-image/'.(int)$publication['id'].'/') ?></div>
<aside class="inquiry"><h2>Request your quotation</h2><p>Tell us your dates and preferences. Our team will confirm availability and send a reviewed quotation.</p>
<form data-vta-b2c-inquiry data-api="api/index.php?route=b2c-storefront/<?= (int)$publication['id'] ?>/inquiry" data-csrf="<?= $escape($_SESSION['b2c_inquiry_csrf']) ?>">
<div class="form-grid"><label>Name<input name="contact_name" required maxlength="190" autocomplete="name"></label><label>Email<input name="email" type="email" required maxlength="190" autocomplete="email"></label>
<label>Phone / WhatsApp (optional)<input name="phone" type="tel" maxlength="64" autocomplete="tel"></label><label>Travellers<input name="total_guests" type="number" min="1" max="1000" value="2" required></label>
<label>Travel date (optional)<input name="travel_date" type="date"></label><label>Flexible dates until (optional)<input name="travel_date_end" type="date"></label></div>
<label>Preferences and requirements<textarea name="message" maxlength="7400" rows="4"></textarea></label>
<label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
<label class="consent"><input name="consent" type="checkbox" required>I agree to be contacted about this travel request.</label>
<button type="submit">Request quotation</button><p role="status" aria-live="polite"></p></form></aside></article>
<?php else: ?>
<section class="intro"><p class="eyebrow">Explore Vietnam</p><h1>Your next journey starts here</h1><p>Discover our tour programmes. Every quotation is reviewed for your dates and group.</p></section>
<section class="journey-grid" aria-label="Tour programmes">
<?php foreach($collection['items'] as $item): ?><article class="journey-card"><p class="eyebrow"><?= $escape($item['destination']) ?></p><h2><a href="<?= $escape($journeyUrl(['publication'=>$item['publication_id']])) ?>"><?= $escape($item['title']) ?></a></h2><p>Request a tailored quotation</p><a class="view" href="<?= $escape($journeyUrl(['publication'=>$item['publication_id']])) ?>">View programme →</a></article><?php endforeach; ?>
<?php if(!$collection['items']): ?><p>New journeys are being prepared. Please check back soon.</p><?php endif; ?></section>
<nav class="pagination" aria-label="Pagination"><?php if($page>1): ?><a href="<?= $escape($journeyUrl(['page'=>$page-1])) ?>">← Previous</a><?php endif; ?><?php if($collection['has_more']): ?><a href="<?= $escape($journeyUrl(['page'=>$page+1])) ?>">Next →</a><?php endif; ?></nav>
<?php endif; ?>
</main><footer>Vietnam Travel Advisor · Availability and bookings require staff confirmation.</footer></body></html>
