<?php
declare(strict_types=1);

/**
 * M08 review fixture. Every row is synthetic and remains in disposable SQLite.
 * The review runs with PRAGMA query_only=ON; it must not publish, withdraw,
 * create a lead, record a metric, or mutate the original B2C/Ads flows.
 *
 * Expected helper contract:
 *   program(PDO, actor, program ID, expected revision): array
 *   landing(PDO, actor, landing ID): array
 * Both methods fail closed when VTA_RC62_B2C_PUBLICATION_REVIEW is not `1`.
 */
final class StopM08 extends RuntimeException
{
    public function __construct(public array $payload, public int $status)
    {
        parent::__construct((string)($payload['error'] ?? 'OK'));
    }
}

final class Http
{
    public static function json(array $body, int $status = 200): never
    {
        throw new StopM08($body, $status);
    }
    public static function body(): array { return []; }
    public static function method(): string { return 'GET'; }
}

final class Auth
{
    public static ?array $session = null;
    public static array $permissions = [];
    public static function requireUser(PDO $db): array
    {
        if (self::$session === null) Http::json(['error' => 'AUTH_REQUIRED'], 401);
        return self::$session;
    }
    public static function can(PDO $db, int $id, string $permission): bool
    {
        return in_array($permission, self::$permissions[$id] ?? [], true);
    }
    public static function requirePermission(PDO $db, array $actor, string $permission): void
    {
        if (!self::can($db, (int)$actor['id'], $permission))
            Http::json(['error' => 'FORBIDDEN'], 403);
    }
    public static function checkCsrf(): void {}
}

$lib = __DIR__.'/../api/lib';
$fullTree = is_file($lib.'/TourLibrary.php') && is_file($lib.'/B2CStudio.php')
    && is_file($lib.'/AdsLandingPages.php') && is_file($lib.'/AdsLandingPresentation.php');
if ($fullTree) {
    require_once $lib.'/MediaLibrary.php';
    require_once $lib.'/FreeformDocument.php';
    require_once $lib.'/B2CStudio.php';
    require_once $lib.'/AdsLandingPresentation.php';
    require_once $lib.'/AdsLandingPages.php';
} else {
    if (getenv('M08_REQUIRE_REAL_CLASSES') === '1')
        throw new RuntimeException('M08 CI requires original source classes');
    // These narrow stand-ins allow a standalone helper review before its files
    // are placed in an exact-SHA checkout. CI uses the real source classes.
    final class MediaLibrary {
        public static function json(mixed $value): string {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    }
    final class AdsLandingPresentation {
        public static function snapshot(array $value): array {
            if (($value['schema'] ?? '') !== 'VTA_B2C_PUBLICATION_V1'
                || ($value['pricing'] ?? '') !== 'REQUEST_QUOTE')
                throw new DomainException('Unapproved public snapshot');
            return $value;
        }
    }
    final class B2CStudio {
        public static function publicSnapshot(PDO $db, array $actor, array $row): array {
            $c = json_decode((string)$row['content_json'], true, 32, JSON_THROW_ON_ERROR);
            return ['schema' => 'VTA_B2C_PUBLICATION_V1', 'title' => $c['title'],
                'destination' => $c['destination'], 'language' => $c['language'],
                'document' => $c['document'], 'pricing' => 'REQUEST_QUOTE'];
        }
        public static function publication(PDO $db, int $company, int $event): array {
            $q = $db->prepare("SELECT e.* FROM b2c_publication_events e
                JOIN b2c_programs p ON p.company_id=e.company_id AND p.id=e.program_id
                WHERE e.company_id=? AND e.id=? AND e.status='PUBLISHED'
                  AND p.status='PUBLISHED' AND p.approved_revision=p.revision
                  AND e.revision=p.revision AND e.id=(SELECT MAX(x.id)
                    FROM b2c_publication_events x WHERE x.company_id=e.company_id
                      AND x.program_id=e.program_id)");
            $q->execute([$company, $event]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals($row['snapshot_hash'], hash('sha256', $row['snapshot_json'])))
                throw new OutOfBoundsException('Publication unavailable');
            return $row;
        }
    }
    final class AdsLandingPages {
        public static function utmValue(mixed $value, int $limit = 80): string {
            if (!is_string($value) || strlen($value) > $limit) return '';
            $value = trim($value);
            return preg_match('/^[a-zA-Z0-9_.-]*$/D', $value) ? $value : '';
        }
        public static function live(PDO $db, string $slug): array {
            $q = $db->prepare("SELECT a.* FROM ads_landing_pages a
                JOIN campaigns c ON c.company_id=a.company_id AND c.id=a.campaign_id AND c.status='ACTIVE'
                JOIN lead_forms f ON f.company_id=a.company_id AND f.id=a.lead_form_id
                    AND f.campaign_id=a.campaign_id AND f.status='ACTIVE'
                WHERE a.slug=? AND a.status='PUBLISHED'");
            $q->execute([$slug]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new OutOfBoundsException('Landing unavailable');
            B2CStudio::publication($db, (int)$row['company_id'], (int)$row['publication_event_id']);
            return $row;
        }
    }
}
$helper = $lib.'/VtaB2CPublicationReview.php';
if (!is_file($helper)) throw new RuntimeException('M08 helper missing');
require_once $helper;

function m08(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('M08 FAILED: '.$label);
}

function m08Status(callable $fn, int $status, string $label): void
{
    try { $fn(); }
    catch (StopM08 $response) {
        m08($response->status === $status, $label.' (got HTTP '.$response->status.')');
        return;
    }
    throw new RuntimeException('M08 FAILED: '.$label.' (no HTTP response)');
}

function m08Unavailable(callable $fn, string $label): void
{
    try { $fn(); }
    catch (OutOfBoundsException $e) { return; }
    throw new RuntimeException('M08 FAILED: '.$label.' (not hidden)');
}

function m08Json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function m08Fingerprint(PDO $db): string
{
    $tables = ['b2c_programs', 'b2c_publication_events', 'ads_landing_pages',
        'ads_landing_metrics', 'ads_landing_lead_links', 'lead_requests', 'audit_logs'];
    $all = [];
    foreach ($tables as $table) {
        $all[$table] = $db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', m08Json($all));
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(<<<'SQL'
CREATE TABLE b2c_programs (
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, master_program_id INTEGER NOT NULL,
 content_json TEXT NOT NULL, revision INTEGER NOT NULL, status TEXT NOT NULL,
 approved_revision INTEGER, approved_by INTEGER, created_by INTEGER, updated_by INTEGER,
 created_at TEXT, updated_at TEXT);
CREATE TABLE b2c_revisions (
 program_id INTEGER, revision INTEGER, content_json TEXT, actor_id INTEGER,
 created_at TEXT, PRIMARY KEY(program_id,revision));
CREATE TABLE b2c_publication_events (
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, program_id INTEGER NOT NULL,
 revision INTEGER NOT NULL, status TEXT NOT NULL, snapshot_json TEXT NOT NULL,
 snapshot_hash TEXT NOT NULL, created_at TEXT);
CREATE TABLE campaigns (
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, name TEXT, source TEXT,
 status TEXT NOT NULL);
CREATE TABLE lead_forms (
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, campaign_id INTEGER NOT NULL,
 name TEXT, public_token TEXT NOT NULL, status TEXT NOT NULL);
CREATE TABLE ads_landing_pages (
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, slug TEXT NOT NULL,
 publication_event_id INTEGER NOT NULL, campaign_id INTEGER NOT NULL,
 lead_form_id INTEGER NOT NULL, layout TEXT NOT NULL, status TEXT NOT NULL,
 default_utm_campaign TEXT NOT NULL, created_by INTEGER NOT NULL,
 withdrawn_by INTEGER, withdrawn_at TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE ads_landing_metrics (
 landing_id INTEGER, day TEXT, event_type TEXT, utm_source TEXT,
 utm_medium TEXT, utm_campaign TEXT, count_total INTEGER,
 PRIMARY KEY(landing_id,day,event_type,utm_source,utm_medium,utm_campaign));
CREATE TABLE lead_requests (
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, form_id INTEGER NOT NULL,
 campaign_id INTEGER NOT NULL, source TEXT NOT NULL, status TEXT NOT NULL,
 submission_key TEXT NOT NULL, payload_hash TEXT NOT NULL,
 contact_name TEXT NOT NULL, email TEXT, phone TEXT, message TEXT,
 attribution_json TEXT NOT NULL);
CREATE TABLE ads_landing_lead_links (
 landing_id INTEGER, company_id INTEGER, lead_request_id INTEGER,
 publication_event_id INTEGER, PRIMARY KEY(landing_id,lead_request_id));
CREATE TABLE audit_logs (
 id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER,
 action_code TEXT, entity_type TEXT, entity_id INTEGER, before_json TEXT,
 after_json TEXT, created_at TEXT);
SQL);

$doc = ['schema' => 'VTA_DOC_2', 'title' => 'Reviewed Hanoi Tour',
    'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hanoi itinerary']]],
    ]]];
$contentV1 = ['title' => 'Old Hanoi Tour', 'destination' => 'Hanoi', 'language' => 'en', 'document' => $doc];
$contentV2 = ['title' => 'Reviewed Hanoi Tour', 'destination' => 'Hanoi / Ha Long', 'language' => 'en', 'document' => $doc];
$snapshotV1 = ['schema' => 'VTA_B2C_PUBLICATION_V1', 'title' => 'Old Hanoi Tour',
    'destination' => 'Hanoi', 'language' => 'en', 'document' => $doc, 'pricing' => 'REQUEST_QUOTE'];
$snapshotV2 = ['schema' => 'VTA_B2C_PUBLICATION_V1', 'title' => 'Reviewed Hanoi Tour',
    'destination' => 'Hanoi / Ha Long', 'language' => 'en', 'document' => $doc,
    'pricing' => 'REQUEST_QUOTE'];
$oldJson = m08Json($snapshotV1);
$newJson = m08Json($snapshotV2);
$insert = $db->prepare('INSERT INTO b2c_programs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute([41, 7, 12, m08Json($contentV2), 2, 'APPROVED', 2, 5, 5, 5,
    '2026-11-08 08:00:00', '2026-11-08 09:00:00']);
$insert->execute([42, 8, 13, m08Json($contentV1), 1, 'PUBLISHED', 1, 6, 6, 6,
    '2026-11-08 08:00:00', '2026-11-08 08:00:00']);
$rev = $db->prepare('INSERT INTO b2c_revisions VALUES (?,?,?,?,?)');
$rev->execute([41, 1, m08Json($contentV1), 5, '2026-11-07 08:00:00']);
$rev->execute([41, 2, m08Json($contentV2), 5, '2026-11-08 09:00:00']);
$event = $db->prepare('INSERT INTO b2c_publication_events VALUES (?,?,?,?,?,?,?,?)');
$event->execute([51, 7, 41, 1, 'PUBLISHED', $oldJson, hash('sha256', $oldJson), '2026-11-07 10:00:00']);
$event->execute([54, 8, 42, 1, 'PUBLISHED', $oldJson, hash('sha256', $oldJson), '2026-11-08 08:00:00']);
$db->exec("INSERT INTO campaigns VALUES (21,7,'Synthetic Ads','facebook','ACTIVE'),(22,8,'Foreign Ads','google','ACTIVE')");
$form = $db->prepare('INSERT INTO lead_forms VALUES (?,?,?,?,?,?)');
$form->execute([31, 7, 21, 'Synthetic Form', str_repeat('a', 64), 'ACTIVE']);
$form->execute([32, 8, 22, 'Foreign Form', str_repeat('b', 64), 'ACTIVE']);
$db->exec("INSERT INTO audit_logs VALUES
 (1,7,5,'B2C_APPROVE','b2c_program',41,NULL,'{}','2026-11-08 09:00:00'),
 (2,7,5,'B2C_PUBLISH','b2c_program',41,NULL,'{}','2026-11-08 09:30:00')");

$actor = ['id' => 5, 'company_id' => 7];
$foreign = ['id' => 6, 'company_id' => 8];
Auth::$session = $actor;
Auth::$permissions = [5 => ['b2c.view', 'b2c.approve', 'lead.view', 'campaign.manage'],
    6 => ['b2c.view', 'b2c.approve', 'lead.view', 'campaign.manage']];
$flag = 'VTA_RC62_B2C_PUBLICATION_REVIEW';
putenv($flag);
m08Unavailable(fn() => VtaB2CPublicationReview::program($db, $actor, 41, 2), 'OFF program review');
m08Unavailable(fn() => VtaB2CPublicationReview::landing($db, $actor, 61), 'OFF landing review');
putenv($flag.'=1');

$pre = VtaB2CPublicationReview::program($db, $actor, 41, 2);
m08(($pre['program_id'] ?? null) === 41 && ($pre['revision'] ?? null) === 2,
    'prepublish binds exact B2C revision');
m08(($pre['status'] ?? null) === 'APPROVED' &&
    ($pre['application_approval_recorded'] ?? null) === true,
    'prepublish reports current approved revision');
m08(($pre['latest_event']['id'] ?? null) === 51 &&
    ($pre['latest_event']['revision'] ?? null) === 1,
    'previous publication remains identifiable for content diff');
m08(in_array('title', $pre['diff']['changed_fields'] ?? [], true) &&
    in_array('destination', $pre['diff']['changed_fields'] ?? [], true),
    'revision 2 differs from last published snapshot');
m08(($pre['ready_for_publish_review'] ?? null) === true &&
    ($pre['publicly_eligible_now'] ?? null) === false,
    'review readiness is distinct from a live publication');
try {
    VtaB2CPublicationReview::program($db, $actor, 41, 1);
    throw new RuntimeException('M08 FAILED: stale operator revision was accepted');
} catch (DomainException $e) {
    m08($e->getMessage() === 'STALE_REVISION', 'stale operator revision fails closed');
}
m08Unavailable(fn() => VtaB2CPublicationReview::program($db, $actor, 42, 1),
    'foreign company programme is hidden');

Auth::$permissions[5] = ['b2c.view', 'lead.view', 'campaign.manage'];
m08Status(fn() => VtaB2CPublicationReview::program($db, $actor, 41, 2), 403,
    'publish review requires b2c.approve');
Auth::$permissions[5][] = 'b2c.approve';
Auth::$session = $foreign;
m08Status(fn() => VtaB2CPublicationReview::program($db, $actor, 41, 2), 401,
    'forged actor company or user rejected');
Auth::$session = $actor;

// Move the synthetic fixture through the same immutable event/status shapes
// as B2CStudio and AdsLandingPages. No public URL or real lead is invoked.
$db->exec("UPDATE b2c_programs SET status='PUBLISHED' WHERE id=41");
$event->execute([52, 7, 41, 2, 'PUBLISHED', $newJson, hash('sha256', $newJson), '2026-11-08 10:00:00']);
$landing = $db->prepare('INSERT INTO ads_landing_pages VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$landing->execute([61, 7, 'hanoi-reviewed-ads', 52, 21, 31, 'brochure', 'PUBLISHED',
    'nov_campaign', 5, null, null, '2026-11-08 10:05:00', '2026-11-08 10:05:00']);
$landing->execute([62, 8, 'foreign-ads', 54, 22, 32, 'quick', 'PUBLISHED',
    'foreign_campaign', 6, null, null, '2026-11-08 10:05:00', '2026-11-08 10:05:00']);
$attrs = ['utm_source' => 'facebook', 'utm_medium' => 'paid_social',
    'utm_campaign' => 'nov_campaign', 'utm_content' => 'hero', 'utm_term' => '',
    'ad_id' => 'ad_77', 'adset_id' => 'set_2', 'referrer' => '',
    'landing_url' => 'api/index.php?route=ads/landing/hanoi-reviewed-ads'];
$lead = $db->prepare('INSERT INTO lead_requests VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
$lead->execute([71, 7, 31, 21, 'facebook', 'NEW', 'ads_'.str_repeat('c', 64),
    str_repeat('d', 64), 'Private Synthetic Person', 'private@example.invalid',
    '+84-000-000-000', 'Contact consent: yes.\nSynthetic enquiry', m08Json($attrs)]);
$db->exec('INSERT INTO ads_landing_lead_links VALUES (61,7,71,52)');
$db->exec("INSERT INTO ads_landing_metrics VALUES
 (61,'2026-11-08','VIEW','facebook','paid_social','nov_campaign',2),
 (61,'2026-11-08','LEAD','facebook','paid_social','nov_campaign',1)");
$db->exec("INSERT INTO audit_logs VALUES
 (3,7,5,'ADS_LANDING_PUBLISHED','ads_landing',61,NULL,'{}','2026-11-08 10:05:00'),
 (4,7,NULL,'LEAD_REQUEST_RECEIVED','lead_request',71,NULL,'{}','2026-11-08 10:10:00')");

$before = m08Fingerprint($db);
$db->exec('PRAGMA query_only=ON');
$published = VtaB2CPublicationReview::program($db, $actor, 41, 2);
m08(($published['status'] ?? null) === 'PUBLISHED' &&
    ($published['latest_event']['id'] ?? null) === 52 &&
    ($published['publicly_eligible_now'] ?? null) === true,
    'current publication event is readable');
$trace = VtaB2CPublicationReview::landing($db, $actor, 61);
m08(($trace['landing_id'] ?? null) === 61 &&
    ($trace['status'] ?? null) === 'PUBLISHED' &&
    ($trace['publicly_eligible_now'] ?? null) === true,
    'landing binds a current published B2C event');
m08(($trace['lead_links_total'] ?? null) === 1 &&
    ($trace['sampled_current_new'] ?? null) === 1 &&
    ($trace['lead_metric_matches_links'] ?? null) === true,
    'one canonical LeadHub NEW request is linked');
$utm = $trace['lead_traces'][0]['attribution']['utm'] ?? [];
m08(($utm['utm_source'] ?? null) === 'facebook' &&
    ($utm['utm_campaign'] ?? null) === 'nov_campaign' &&
    ($utm['ad_id'] ?? null) === 'ad_77',
    'first party UTM provenance is summarized');
m08(str_contains((string)($trace['contact_consent_evidence'] ?? ''), 'NOT_ESTABLISHED'),
    'readback does not claim versioned consent proof');
$serialized = m08Json([$published, $trace]);
foreach (['Private Synthetic Person', 'private@example.invalid', '+84-000-000-000',
    str_repeat('a', 64), 'ads_'.str_repeat('c', 64)] as $private)
    m08(!str_contains($serialized, $private), 'review excludes private data');
m08Unavailable(fn() => VtaB2CPublicationReview::landing($db, $actor, 62),
    'foreign company landing is hidden');
Auth::$permissions[5] = ['b2c.view', 'b2c.approve', 'campaign.manage'];
m08Status(fn() => VtaB2CPublicationReview::landing($db, $actor, 61), 403,
    'landing trace requires lead.view');
Auth::$permissions[5][] = 'lead.view';
Auth::$permissions[5] = ['b2c.view', 'b2c.approve', 'lead.view'];
m08Status(fn() => VtaB2CPublicationReview::landing($db, $actor, 61), 403,
    'landing trace requires campaign.manage');
Auth::$permissions[5][] = 'campaign.manage';
m08(m08Fingerprint($db) === $before, 'review made zero database writes');

// The original handlers call Http::json(), which exits in production. A
// throwing test stub would be caught by their broad Throwable handlers and
// recast as 503. Check their additive hooks in source; the helper itself is
// exercised above with the original model classes in full-tree CI.
$studioPath = __DIR__.'/../api/lib/B2CStudio.php';
$adsPath = __DIR__.'/../api/lib/AdsLandingPages.php';
if ($fullTree) {
    $studioSource = file_get_contents($studioPath);
    $adsSource = file_get_contents($adsPath);
    m08(is_string($studioSource) && str_contains($studioSource, 'publication-quality')
        && str_contains($studioSource, 'VtaB2CPublicationReview::program')
        && str_contains($studioSource, 'VtaB2CPublicationReview::FLAG'),
        'B2C module has an opt-in review hook');
    m08(is_string($adsSource) && str_contains($adsSource, '/quality')
        && str_contains($adsSource, 'VtaB2CPublicationReview::landing')
        && str_contains($adsSource, 'VtaB2CPublicationReview::FLAG'),
        'Ads module has an opt-in review hook');
} elseif (getenv('M08_REQUIRE_ROUTES') === '1') {
    throw new RuntimeException('M08 full-tree route test required but source handlers missing');
}

// A readback after withdrawal must retain historical links and reject public
// availability. Toggle query_only only to evolve this synthetic fixture.
$db->exec('PRAGMA query_only=OFF');
$db->exec("UPDATE b2c_programs SET status='WITHDRAWN',approved_revision=NULL WHERE id=41");
$event->execute([53, 7, 41, 2, 'WITHDRAWN', '{}', hash('sha256', '{}'), '2026-11-08 11:00:00']);
$db->exec('PRAGMA query_only=ON');
$withdrawn = VtaB2CPublicationReview::program($db, $actor, 41, 2);
m08(($withdrawn['status'] ?? null) === 'WITHDRAWN' &&
    ($withdrawn['latest_event']['status'] ?? null) === 'WITHDRAWN' &&
    ($withdrawn['publicly_eligible_now'] ?? null) === false,
    'B2C withdrawal is visible without erasing event history');
$revoked = VtaB2CPublicationReview::landing($db, $actor, 61);
m08(($revoked['publicly_eligible_now'] ?? null) === false &&
    ($revoked['lead_links_total'] ?? null) === 1,
    'B2C withdrawal revokes landing eligibility but retains historic lead link');
$db->exec('PRAGMA query_only=OFF');
$db->exec("UPDATE ads_landing_pages SET status='WITHDRAWN',withdrawn_by=5,withdrawn_at='2026-11-08 11:10:00' WHERE id=61");
$db->exec('PRAGMA query_only=ON');
$adsWithdrawn = VtaB2CPublicationReview::landing($db, $actor, 61);
m08(($adsWithdrawn['status'] ?? null) === 'WITHDRAWN' &&
    ($adsWithdrawn['publicly_eligible_now'] ?? null) === false,
    'Ads withdrawal is visible and remains nonpublic');

putenv($flag);
echo "PASS M08: OFF, exact revision/diff, RBAC/tenant, publish/withdraw, UTM/consent/LeadHub NEW, no PII and query-only review\n";
