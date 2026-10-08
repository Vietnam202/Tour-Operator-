<?php
declare(strict_types=1);

$privateConfig = getenv('VTA_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/vta_private/config.php';
require_once __DIR__.'/lib/RuntimeGuard.php';
if (is_file($privateConfig)) {
    $config = require $privateConfig;
} else {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'VTA_CONFIG_MISSING','message'=>'Server configuration is missing.']);
    exit;
}
try { RuntimeGuard::config($privateConfig,$config); } catch(Throwable $e) { http_response_code(503);header('Content-Type: application/json');echo '{"ok":false,"error":"UNSAFE_STAGING_CONFIGURATION"}';exit; }

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Ho_Chi_Minh');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$allowedOrigin=rtrim((string)($config['security']['allowed_origin'] ?? ''),'/');
$origin=rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''),'/');
if($origin!=='' && $allowedOrigin!=='' && $origin!==$allowedOrigin){
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'ORIGIN_NOT_ALLOWED']);
    exit;
}

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Audit.php';
require_once __DIR__ . '/lib/Storage.php';
require_once __DIR__ . '/lib/DocumentParser.php';
require_once __DIR__ . '/lib/RateRules.php';
require_once __DIR__ . '/lib/CoreOS.php';
require_once __DIR__ . '/lib/LeadHub.php';
require_once __DIR__ . '/lib/DomainOutbox.php';
require_once __DIR__ . '/lib/LeadSalesHandover.php';
require_once __DIR__ . '/lib/Customer360.php';
require_once __DIR__ . '/lib/CampaignPilot.php';
require_once __DIR__ . '/lib/MarketingStudio.php';
require_once __DIR__ . '/lib/WebhookCenter.php';
require_once __DIR__ . '/lib/WebsiteInbox.php';
require_once __DIR__ . '/lib/WebsiteChatDelivery.php';
require_once __DIR__ . '/lib/MarketingTourAdvisor.php';
require_once __DIR__ . '/lib/MarketingTourShare.php';
require_once __DIR__ . '/lib/SocialPublishing.php';
require_once __DIR__ . '/lib/LandingPages.php';
require_once __DIR__ . '/lib/AiChat.php';
require_once __DIR__ . '/lib/TourInventory.php';
require_once __DIR__ . '/lib/RateEngine.php';
require_once __DIR__ . '/lib/QuoteOptions.php';
require_once __DIR__ . '/lib/Procurement.php';
require_once __DIR__ . '/lib/TravelDocuments.php';
require_once __DIR__ . '/lib/FinanceLedger.php';
require_once __DIR__ . '/lib/OperationsControl.php';
require_once __DIR__ . '/lib/ControlCenter.php';
require_once __DIR__ . '/lib/WorkspaceCenter.php';
require_once __DIR__ . '/lib/QuoteCostItems.php';
require_once __DIR__ . '/lib/ScheduleImport.php';
require_once __DIR__ . '/lib/QuoteReuse.php';
require_once __DIR__ . '/lib/TourLibrary.php';
require_once __DIR__ . '/lib/ServiceTravelDetails.php';

$db = Database::connect($config['db']);
Auth::startSession($config);

require_once __DIR__ . '/lib/QuoteExport.php';

require_once __DIR__ . '/lib/InvoiceCommercial.php';

require_once __DIR__.'/lib/MediaLibrary.php';
require_once __DIR__.'/lib/MediaDrive.php';
require_once __DIR__.'/lib/QuoteProposal.php';
require_once __DIR__.'/lib/ProposalOutput.php';
