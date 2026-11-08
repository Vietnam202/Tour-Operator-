<?php
declare(strict_types=1);

/** Optional RC6.2 M08 inspection inside the existing B2C and Ads modules. */
final class VtaB2CPublicationReview
{
    public const FLAG = 'VTA_RC62_B2C_PUBLICATION_REVIEW';
    public const SCHEMA = 'VTA_B2C_PUBLICATION_REVIEW_1';
    private const DIFF_FIELDS = ['title', 'destination', 'language', 'document', 'pricing'];
    private const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'ad_id', 'adset_id'];

    private static function q(PDO $db, string $sql, array $args = []): PDOStatement
    {
        $statement = $db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }

    private static function scope(PDO $db, array $caller, array $permissions): int
    {
        if (getenv(self::FLAG) !== '1') throw new OutOfBoundsException('M08_DISABLED');
        $session = Auth::requireUser($db);
        if ((int)($session['id'] ?? 0) !== (int)($caller['id'] ?? -1)
            || (int)($session['company_id'] ?? 0) !== (int)($caller['company_id'] ?? -1)) {
            Http::json(['ok' => false, 'error' => 'AUTH_SCOPE_MISMATCH'], 401);
        }
        Auth::checkCsrf();
        foreach ($permissions as $permission) Auth::requirePermission($db, $caller, $permission);
        $company = (int)$session['company_id'];
        if ($company < 1) throw new OutOfBoundsException('M08_COMPANY_SCOPE');
        return $company;
    }

    private static function validHash(array $event): bool
    {
        $json = $event['snapshot_json'] ?? null;
        $hash = $event['snapshot_hash'] ?? null;
        return is_string($json) && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1
            && hash_equals($hash, hash('sha256', $json));
    }

    private static function changedFields(array $before, array $after): array
    {
        $changed = [];
        foreach (self::DIFF_FIELDS as $field) {
            if (hash('sha256', MediaLibrary::json($before[$field] ?? null))
                !== hash('sha256', MediaLibrary::json($after[$field] ?? null))) $changed[] = $field;
        }
        return $changed;
    }

    /** Return hashes and changed field names, never the document or private source. */
    public static function program(PDO $db, array $caller, int $programId, ?int $expectedRevision = null): array
    {
        $company = self::scope($db, $caller, ['b2c.view', 'b2c.approve']);
        if ($programId < 1 || ($expectedRevision !== null && $expectedRevision < 1))
            throw new InvalidArgumentException('Positive programme ID and revision required');
        $row = self::q($db, 'SELECT * FROM b2c_programs WHERE company_id=? AND id=? LIMIT 1',
            [$company, $programId])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new OutOfBoundsException('B2C programme unavailable');
        $revision = (int)$row['revision'];
        if ($expectedRevision !== null && $expectedRevision !== $revision)
            throw new DomainException('STALE_REVISION');

        $snapshot = null;
        try {
            $snapshot = AdsLandingPresentation::snapshot(B2CStudio::publicSnapshot($db, $caller, $row));
        } catch (DomainException|InvalidArgumentException|OutOfBoundsException|JsonException $e) {
            // The source may still be an incomplete draft or contain unapproved media.
        }
        $currentHash = $snapshot === null ? null : hash('sha256', MediaLibrary::json($snapshot));
        $lastPublished = self::q($db, "SELECT * FROM b2c_publication_events
            WHERE company_id=? AND program_id=? AND status='PUBLISHED' ORDER BY id DESC LIMIT 1",
            [$company, $programId])->fetch(PDO::FETCH_ASSOC);
        $latest = self::q($db, 'SELECT * FROM b2c_publication_events
            WHERE company_id=? AND program_id=? ORDER BY id DESC LIMIT 1',
            [$company, $programId])->fetch(PDO::FETCH_ASSOC);
        $baselineValid = $lastPublished && self::validHash($lastPublished);
        $changed = null;
        if ($baselineValid && $snapshot !== null) {
            try {
                $before = json_decode((string)$lastPublished['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
                if (is_array($before)) $changed = self::changedFields($before, $snapshot);
            } catch (JsonException $e) {
                $baselineValid = false;
            }
        }
        $approved = $row['approved_revision'] !== null
            && (int)$row['approved_revision'] === $revision && $row['approved_by'] !== null;
        $live = false;
        if ($latest && $latest['status'] === 'PUBLISHED') {
            try {
                B2CStudio::publication($db, $company, (int)$latest['id']);
                $live = true;
            } catch (OutOfBoundsException $e) {
                // Withdrawn, stale or corrupt publication is not publicly eligible.
            }
        }
        return [
            'schema' => self::SCHEMA, 'read_only' => true,
            'program_id' => $programId, 'revision' => $revision, 'status' => $row['status'],
            'approved_revision' => $row['approved_revision'] === null ? null : (int)$row['approved_revision'],
            'application_approval_recorded' => $approved,
            'public_snapshot_valid' => $snapshot !== null,
            'current_public_snapshot_hash' => $currentHash,
            'last_published_event' => $lastPublished ? [
                'id' => (int)$lastPublished['id'], 'revision' => (int)$lastPublished['revision'],
                'snapshot_hash_valid' => (bool)$baselineValid,
            ] : null,
            'latest_event' => $latest ? [
                'id' => (int)$latest['id'], 'revision' => (int)$latest['revision'],
                'status' => $latest['status'], 'snapshot_hash_valid' => self::validHash($latest),
            ] : null,
            'diff' => [
                'state' => !$lastPublished ? 'NO_PUBLISHED_BASELINE'
                    : (!$baselineValid ? 'BASELINE_INTEGRITY_FAILED'
                    : ($snapshot === null ? 'CURRENT_NOT_READY' : 'COMPARABLE')),
                'changed_fields' => $changed,
            ],
            'ready_for_publish_review' => $row['status'] === 'APPROVED' && $approved
                && $snapshot !== null && (!$lastPublished || $baselineValid),
            'publicly_eligible_now' => $live,
            'pricing_policy' => 'REQUEST_QUOTE_ONLY',
            'release_owner_signoff' => 'NOT_ESTABLISHED_BY_THIS_READBACK',
        ];
    }

    private static function safeAttribution(mixed $raw): array
    {
        try {
            $data = json_decode((string)$raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['state' => 'INVALID_STORED_JSON', 'utm' => null];
        }
        if (!is_array($data)) return ['state' => 'INVALID_STORED_JSON', 'utm' => null];
        $utm = [];
        foreach (self::UTM_FIELDS as $field) {
            $value = $data[$field] ?? '';
            $utm[$field] = AdsLandingPages::utmValue($value, $field === 'utm_campaign' ? 100 : 80);
        }
        return ['state' => 'STORED_SANITIZED_READBACK', 'utm' => $utm];
    }

    /** Tenant-scoped landing and LeadHub readback without contact or token values. */
    public static function landing(PDO $db, array $caller, int $landingId): array
    {
        $company = self::scope($db, $caller, ['lead.view', 'b2c.approve', 'campaign.manage']);
        if ($landingId < 1) throw new InvalidArgumentException('Positive landing ID required');
        $landing = self::q($db, 'SELECT * FROM ads_landing_pages WHERE company_id=? AND id=? LIMIT 1',
            [$company, $landingId])->fetch(PDO::FETCH_ASSOC);
        if (!$landing) throw new OutOfBoundsException('Landing unavailable');
        $binding = self::q($db, 'SELECT c.status campaign_status,f.status form_status
            FROM campaigns c JOIN lead_forms f ON f.company_id=c.company_id AND f.campaign_id=c.id
            WHERE c.company_id=? AND c.id=? AND f.id=? LIMIT 1',
            [$company, $landing['campaign_id'], $landing['lead_form_id']])->fetch(PDO::FETCH_ASSOC);
        $activeBinding = $binding && $binding['campaign_status'] === 'ACTIVE' && $binding['form_status'] === 'ACTIVE';
        $live = false;
        if ($landing['status'] === 'PUBLISHED' && $activeBinding) {
            try {
                $public = AdsLandingPages::live($db, (string)$landing['slug']);
                $live = (int)$public['id'] === $landingId && (int)$public['company_id'] === $company
                    && (int)$public['publication_event_id'] === (int)$landing['publication_event_id'];
            } catch (OutOfBoundsException|DomainException|JsonException $e) {
                // Public GET/POST performs this same current-state recheck.
            }
        }
        $total = (int)self::q($db, 'SELECT COUNT(*) FROM ads_landing_lead_links
            WHERE company_id=? AND landing_id=?', [$company, $landingId])->fetchColumn();
        $rows = self::q($db, 'SELECT l.lead_request_id,l.publication_event_id,
                r.id request_id,r.status request_status,r.source,r.form_id,r.campaign_id,r.attribution_json
            FROM ads_landing_lead_links l
            LEFT JOIN lead_requests r ON r.company_id=l.company_id AND r.id=l.lead_request_id
            WHERE l.company_id=? AND l.landing_id=? ORDER BY l.lead_request_id DESC LIMIT 50',
            [$company, $landingId])->fetchAll(PDO::FETCH_ASSOC);
        $traces = [];
        $sampleConsistent = true;
        $new = 0;
        foreach ($rows as $row) {
            $consistent = $row['request_id'] !== null
                && (int)$row['publication_event_id'] === (int)$landing['publication_event_id']
                && (int)$row['form_id'] === (int)$landing['lead_form_id']
                && (int)$row['campaign_id'] === (int)$landing['campaign_id'];
            $sampleConsistent = $sampleConsistent && $consistent;
            if ($row['request_status'] === 'NEW') $new++;
            $traces[] = [
                'lead_request_id' => (int)$row['lead_request_id'],
                'current_status' => $row['request_status'],
                'source' => AdsLandingPages::utmValue($row['source'], 80),
                'binding_consistent' => $consistent,
                'attribution' => self::safeAttribution($row['attribution_json']),
            ];
        }
        $metric = (int)self::q($db, "SELECT COALESCE(SUM(count_total),0) FROM ads_landing_metrics
            WHERE landing_id=? AND event_type='LEAD'", [$landingId])->fetchColumn();
        return [
            'schema' => self::SCHEMA, 'read_only' => true,
            'landing_id' => $landingId, 'status' => $landing['status'],
            'publication_event_id' => (int)$landing['publication_event_id'],
            'campaign_id' => (int)$landing['campaign_id'], 'lead_form_id' => (int)$landing['lead_form_id'],
            'active_campaign_form_binding' => (bool)$activeBinding,
            'publicly_eligible_now' => $live,
            'lead_links_total' => $total, 'lead_metric_total' => $metric,
            'sample_limit' => 50, 'sampled_links' => count($traces),
            'sampled_current_new' => $new, 'sampled_binding_consistent' => $sampleConsistent,
            'lead_metric_matches_links' => $metric === $total,
            'lead_traces' => $traces,
            'contact_consent_evidence' => 'INPUT_REQUIRED; VERSIONED_RECORD_NOT_ESTABLISHED',
            'analytics_consent_evidence' => 'NOT_STORED_OR_VERIFIED',
            'first_touch_evidence' => 'SESSION_CAPTURE; DURABLE_PROOF_NOT_ESTABLISHED',
            'retry_evidence' => 'READ_ONLY_SNAPSHOT_CANNOT_PROVE_RETRY_HISTORY',
            'public_url_contract' => 'EXPLICIT_API_ROUTE_ONLY; NO_PRETTY_URL_CLAIM',
        ];
    }
}
