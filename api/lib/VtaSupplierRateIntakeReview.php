<?php
declare(strict_types=1);

/**
 * RC6.2 M03 OPTIONAL, READ-ONLY supplier rate intake preview.
 * No DB/API, supplier order, pricing engine, provider or filesystem writes.
 * XLSX integration should pass parsed rows from a separately reviewed adapter;
 * this class parses only explicit CSV, not arbitrary spreadsheets or URLs.
 */
final class VtaSupplierRateIntakeReview
{
    public const SCHEMA = 'VTA_SUPPLIER_RATE_INTAKE_PREVIEW_1';
    private const MAX_BYTES = 524288;
    private const MAX_ROWS = 250;
    private const REQUIRED = [
        'supplier_ref', 'category', 'product_name', 'rate_basis', 'amount',
        'currency', 'valid_from', 'valid_to', 'source_document_ref'
    ];
    private const OPTIONAL = [
        'option_name', 'rate_type', 'booking_valid_from', 'booking_valid_to',
        'min_pax', 'max_pax', 'contract_ref', 'source_version'
    ];
    private const CATEGORIES = [
        'HOTEL', 'TRANSPORT', 'GUIDE', 'CRUISE', 'TOUR', 'ATTRACTION', 'MEAL', 'VISA', 'OTHER'
    ];
    private const BASES = [
        'PER_PAX', 'PER_ROOM_NIGHT', 'PER_VEHICLE', 'PER_TRANSFER', 'PER_DAY',
        'PER_GUIDE_DAY', 'PER_CABIN', 'PER_GROUP', 'PER_SERVICE'
    ];
    private const TYPES = ['CONTRACT', 'PROMOTION', 'SPECIAL_QUOTE', 'SERIES', 'MANUAL_OVERRIDE'];

    /**
     * @param array<string,mixed> $context Must include authorized positive company_id;
     *                                     company identity is never taken from imported CSV.
     * @param list<array<string,mixed>> $approved Existing approved metadata ONLY, verified by caller.
     * @return array<string,mixed>
     */
    public static function previewCsv(string $csv, array $context, array $approved = []): array
    {
        self::company($context);
        if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Empty or oversized supplier CSV preview');
        }
        if (str_contains($csv, "\0") || preg_match('//u', $csv) !== 1) {
            throw new InvalidArgumentException('Supplier CSV must be valid UTF-8 without NUL');
        }
        $handle = fopen('php://temp/maxmemory:524288', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Temporary preview stream unavailable');
        }
        try {
            fwrite($handle, $csv);
            rewind($handle);
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (!is_array($header)) {
                throw new InvalidArgumentException('CSV header missing');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $names = array_map(static fn($x) => strtolower(trim((string)$x)), $header);
            $allowed = array_merge(self::REQUIRED, self::OPTIONAL);
            if (count($names) !== count(array_unique($names)) ||
                array_diff(self::REQUIRED, $names) || array_diff($names, $allowed)) {
                throw new InvalidArgumentException('CSV headers require unique approved supplier-rate fields');
            }
            $rows = [];
            $position = 1;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $position++;
                if ($row === [null] || $row === ['']) {
                    continue;
                }
                if (count($row) !== count($names)) {
                    throw new InvalidArgumentException('CSV field count mismatch on row '.$position);
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new InvalidArgumentException('Too many supplier rows in one preview');
                }
                $rows[] = array_combine($names, $row);
            }
            return self::previewRows($rows, $context, $approved);
        } finally {
            fclose($handle);
        }
    }

    /**
     * An adapter may provide rows produced by a validated, safely configured XLSX parser.
     * This function does NOT read files, trust supplier identity, or publish/approve rates.
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $context
     * @param list<array<string,mixed>> $approved Read-only existing versions scoped by caller.
     * @return array<string,mixed>
     */
    public static function previewRows(array $rows, array $context, array $approved = []): array
    {
        $company = self::company($context);
        if (!array_is_list($rows) || count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Supplier preview requires a bounded list of rows');
        }
        if (!array_is_list($approved) || count($approved) > 1000) {
            throw new InvalidArgumentException('Existing source snapshots must be bounded');
        }
        $existing = [];
        foreach ($approved as $item) {
            if (!is_array($item) || ($item['company_id'] ?? null) !== $company) {
                throw new DomainException('Cross-tenant or malformed approved rate snapshot rejected');
            }
            if (($item['approval_status'] ?? '') !== 'APPROVED') {
                continue;
            }
            $existing[] = $item;
        }
        $preview = [];
        $normalized = [];
        $seen = [];
        $summary = ['total' => count($rows), 'blocked' => 0, 'review' => 0, 'awaiting_approval' => 0];
        foreach ($rows as $index => $raw) {
            $issues = [];
            $row = [];
            if (!is_array($raw)) {
                $issues[] = 'ROW_INVALID';
            } else {
                foreach (array_merge(self::REQUIRED, self::OPTIONAL) as $key) {
                    $value = $raw[$key] ?? '';
                    if (!is_string($value) && !is_int($value)) {
                        $issues[] = 'FIELD_TYPE_INVALID';
                        $value = '';
                    }
                    $value = trim((string)$value);
                    if (strlen($value) > 190) {
                        $issues[] = 'FIELD_TOO_LONG';
                        $value = '';
                    }
                    // Never execute formulas or turn formula-like cell contents into output.
                    if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
                        $issues[] = 'FORMULA_LIKE_FIELD';
                        $value = '';
                    }
                    $row[$key] = $value;
                }
                foreach (self::REQUIRED as $key) {
                    if ($row[$key] === '') {
                        $issues[] = 'REQUIRED_FIELD_MISSING';
                    }
                }
                foreach (['supplier_ref', 'source_document_ref'] as $key) {
                    if ($row[$key] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,95}$/D', $row[$key])) {
                        $issues[] = 'SOURCE_REFERENCE_INVALID';
                    }
                }
                if (!in_array(strtoupper($row['category']), self::CATEGORIES, true)) {
                    $issues[] = 'CATEGORY_INVALID';
                }
                if (!in_array(strtoupper($row['rate_basis']), self::BASES, true)) {
                    $issues[] = 'RATE_BASIS_INVALID';
                }
                if ($row['rate_type'] !== '' && !in_array(strtoupper($row['rate_type']), self::TYPES, true)) {
                    $issues[] = 'RATE_TYPE_INVALID';
                }
                if ($row['currency'] !== 'VND') {
                    $issues[] = 'VND_SOURCE_REQUIRED';
                }
                if ($row['amount'] !== '') {
                    if (!preg_match('/^[0-9]{1,14}(?:\.[0-9]{1,2})?$/D', $row['amount']) ||
                        !self::positiveMoney($row['amount'])) {
                        $issues[] = 'AMOUNT_INVALID';
                    }
                }
                foreach (['valid_from', 'valid_to', 'booking_valid_from', 'booking_valid_to'] as $field) {
                    if ($row[$field] !== '' && !self::date($row[$field])) {
                        $issues[] = 'DATE_INVALID';
                    }
                }
                if ($row['valid_from'] !== '' && $row['valid_to'] !== '' &&
                    $row['valid_from'] > $row['valid_to']) {
                    $issues[] = 'VALIDITY_REVERSED';
                }
                if ($row['booking_valid_from'] !== '' && $row['booking_valid_to'] !== '' &&
                    $row['booking_valid_from'] > $row['booking_valid_to']) {
                    $issues[] = 'BOOKING_VALIDITY_REVERSED';
                }
                foreach (['min_pax', 'max_pax'] as $field) {
                    if ($row[$field] !== '' &&
                        (!ctype_digit($row[$field]) || (int)$row[$field] < 1 || (int)$row[$field] > 10000)) {
                        $issues[] = 'PAX_LIMIT_INVALID';
                    }
                }
                if ($row['min_pax'] !== '' && $row['max_pax'] !== '' &&
                    (int)$row['min_pax'] > (int)$row['max_pax']) {
                    $issues[] = 'PAX_RANGE_REVERSED';
                }
                if ($row['contract_ref'] === '' || $row['source_version'] === '') {
                    $issues[] = 'CONTRACT_PROVENANCE_UNVERIFIED';
                }
                // A missing blackout or supplier-capacity field is UNKNOWN, never available.
                $issues[] = 'CAPACITY_BLACKOUT_UNVERIFIED';
            }
            $row = array_map('strval', $row);
            if ($row !== []) {
                $fingerprint = self::hash($row);
                if (isset($seen[$fingerprint])) {
                    $issues[] = 'DUPLICATE_SOURCE_ROW';
                }
                $seen[$fingerprint] = true;
                // Compare only accepted existing rate metadata; never read from DB here.
                foreach (array_merge($normalized, $existing) as $compare) {
                    if (!is_array($compare) || !self::sameProduct($row, $compare)) {
                        continue;
                    }
                    if (self::overlap($row['valid_from'] ?? '', $row['valid_to'] ?? '',
                        (string)($compare['valid_from'] ?? ''), (string)($compare['valid_to'] ?? ''))) {
                        $issues[] = 'SEASON_OR_VERSION_OVERLAP';
                        break;
                    }
                }
                $normalized[] = $row;
            } else {
                $fingerprint = null;
            }
            $issues = array_values(array_unique($issues));
            $blockedCodes = [
                'ROW_INVALID', 'FIELD_TYPE_INVALID', 'FIELD_TOO_LONG', 'FORMULA_LIKE_FIELD',
                'REQUIRED_FIELD_MISSING', 'SOURCE_REFERENCE_INVALID', 'CATEGORY_INVALID',
                'RATE_BASIS_INVALID', 'RATE_TYPE_INVALID', 'VND_SOURCE_REQUIRED', 'AMOUNT_INVALID',
                'DATE_INVALID', 'VALIDITY_REVERSED', 'BOOKING_VALIDITY_REVERSED',
                'PAX_LIMIT_INVALID', 'PAX_RANGE_REVERSED', 'DUPLICATE_SOURCE_ROW'
            ];
            $isBlocked = (bool)array_intersect($issues, $blockedCodes);
            $state = $isBlocked ? 'BLOCKED' : ($issues ? 'REVIEW_REQUIRED' : 'AWAITING_HUMAN_APPROVAL');
            if ($state === 'BLOCKED') $summary['blocked']++;
            elseif ($state === 'REVIEW_REQUIRED') $summary['review']++;
            else $summary['awaiting_approval']++;
            $preview[] = [
                'row' => $index + 2,
                'state' => $state,
                'fingerprint' => $fingerprint,
                'issues' => $issues,
                'source_ref' => $row['source_document_ref'] ?? null,
                // Do NOT return the original source row or PII/NET data in a preview log.
            ];
        }
        return [
            'schema' => self::SCHEMA,
            'mode' => 'READ_ONLY_PREVIEW',
            'company_id' => $company,
            'summary' => $summary,
            'rows' => $preview,
            'may_publish' => false,
            'may_price_quote' => false,
            'source_digest' => self::hash($rows),
        ];
    }

    private static function positiveMoney(string $amount): bool
    {
        if (!preg_match('/^[0-9]{1,14}(?:\.[0-9]{1,2})?$/D', $amount)) return false;
        return ltrim(str_replace('.', '', $amount), '0') !== '';
    }
    private static function company(array $context): int
    {
        $id = $context['company_id'] ?? null;
        if (!is_int($id) || $id <= 0) throw new DomainException('Authorized company context required');
        return $id;
    }
    private static function date(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return false;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }
    private static function sameProduct(array $a, array $b): bool
    {
        foreach (['supplier_ref', 'category', 'product_name', 'option_name', 'rate_basis', 'currency'] as $field) {
            if (strtoupper(trim((string)($a[$field] ?? ''))) !== strtoupper(trim((string)($b[$field] ?? '')))) return false;
        }
        return true;
    }
    private static function overlap(string $a, string $b, string $c, string $d): bool
    {
        // Any missing date is UNKNOWN. Do not implicitly approve non-overlap.
        if (!self::date($a) || !self::date($b) || !self::date($c) || !self::date($d)) return true;
        return $a <= $d && $c <= $b;
    }
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
