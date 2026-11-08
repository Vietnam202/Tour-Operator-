<?php
declare(strict_types=1);

/**
 * RC6.2 M03 — optional supplier rate CSV preflight.
 *
 * Pure read-only adapter, NOT wired to current RateEngine, RateRules, API or DB.
 * Does not authorize supplier ownership or activate rates: a privileged operator
 * must independently prove RBAC, supplier/company ownership and contract review.
 *
 * CSV only. XLSX must go through a separately reviewed/sandboxed reader;
 * never pass untrusted spreadsheet files directly to this method.
 */
final class VtaRateIntakeReview
{
    public const SCHEMA = 'VTA_RATE_INTAKE_REVIEW_1';
    public const MAX_BYTES = 262144;
    public const MAX_ROWS = 300;
    private const CATEGORIES = ['HOTEL','TRANSPORT','GUIDE','CRUISE','TOUR','ATTRACTION','MEAL','VISA','OTHER'];
    private const BASIS = ['PER_PAX','PER_ROOM_NIGHT','PER_VEHICLE','PER_TRANSFER','PER_DAY','PER_GUIDE_DAY','PER_CABIN','PER_GROUP','PER_SERVICE'];
    private const TYPES = ['CONTRACT','PROMOTION','SPECIAL_QUOTE','SERIES','MANUAL_OVERRIDE'];
    private const TAX = ['NET','TAX_INCLUDED','TAX_EXCLUDED','PLUS_PLUS'];
    private const REQUIRED = ['supplier_id','category','product_name','rate_type','rate_basis','currency','amount_vnd','valid_from','valid_to'];
    private const OPTIONAL = ['option_name','destination','market','min_pax','max_pax','booking_valid_from','booking_valid_to','tax_basis','source_document_id','source_locator','linked_trip_ref','season_from','season_to','blackout_from','blackout_to'];

    /** @return array<string,mixed> */
    public static function previewCsv(string $csv, array $context): array
    {
        $companyId = self::positiveInt($context['company_id'] ?? null);
        $supplierId = self::positiveInt($context['supplier_id'] ?? null);
        if ($companyId === null || $supplierId === null) {
            throw new InvalidArgumentException('An existing company and supplier scope is required before preview.');
        }
        if (strlen($csv) === 0 || strlen($csv) > self::MAX_BYTES || preg_match('//u', $csv) !== 1 || str_contains($csv, "\0")) {
            throw new InvalidArgumentException('CSV must be UTF-8 without NUL and below 256 KiB.');
        }
        $sourceHash = hash('sha256', $csv);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv, 1);
        $fh = fopen('php://temp/maxmemory:524288', 'w+');
        if ($fh === false) {
            throw new RuntimeException('Cannot create in-memory CSV preview.');
        }
        try {
            fwrite($fh, $csv);
            rewind($fh);
            $header = fgetcsv($fh, 0, ',', '"', '');
            if (!is_array($header) || count($header) < count(self::REQUIRED) || count($header) > 40) {
                throw new InvalidArgumentException('A header row with required fields is required.');
            }
            $header = array_map(static fn($v): string => strtolower(trim((string) $v)), $header);
            if (count(array_unique($header)) !== count($header)) {
                throw new InvalidArgumentException('Duplicate column names are not accepted.');
            }
            foreach ($header as $col) {
                if (!in_array($col, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                    throw new InvalidArgumentException('Unsupported CSV column: '.$col);
                }
            }
            foreach (self::REQUIRED as $col) {
                if (!in_array($col, $header, true)) {
                    throw new InvalidArgumentException('Missing mandatory CSV column: '.$col);
                }
            }
            $rows = [];
            $line = 1;
            $fingerprints = [];
            while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                ++$line;
                if (count($cells) === 1 && ($cells[0] === null || trim((string) $cells[0]) === '')) {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new InvalidArgumentException('Maximum 300 supplier rate candidates per file.');
                }
                $issues = [];
                if (count($cells) !== count($header)) {
                    $rows[] = ['row'=>$line,'status'=>'BLOCKED','issues'=>[['code'=>'COLUMN_COUNT_MISMATCH','severity'=>'BLOCK']]];
                    continue;
                }
                $raw = array_combine($header, $cells);
                foreach ($raw as $key => &$value) {
                    if (!is_string($value) || strlen($value) > 1500) {
                        $issues[] = ['code'=>'CELL_LENGTH_INVALID','severity'=>'BLOCK','field'=>$key];
                        $value = '';
                        continue;
                    }
                    $value = trim($value);
                    // Spreadsheet formula injection: prohibit control chars and formula-like cells.
                    if (preg_match('/^[=+\-@]/u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
                        $issues[] = ['code'=>'UNSAFE_SPREADSHEET_CELL','severity'=>'BLOCK','field'=>$key];
                        $value = '';
                    }
                }
                unset($value);
                $supplier = self::positiveInt($raw['supplier_id'] ?? null);
                if ($supplier === null || $supplier !== $supplierId) {
                    $issues[] = ['code'=>'SUPPLIER_SCOPE_MISMATCH','severity'=>'BLOCK','field'=>'supplier_id'];
                }
                $cat = strtoupper($raw['category'] ?? '');
                $type = strtoupper($raw['rate_type'] ?? '');
                $basis = strtoupper($raw['rate_basis'] ?? '');
                $currency = strtoupper($raw['currency'] ?? '');
                if (!in_array($cat, self::CATEGORIES, true)) $issues[] = ['code'=>'CATEGORY_INVALID','severity'=>'BLOCK','field'=>'category'];
                if (!in_array($type, self::TYPES, true)) $issues[] = ['code'=>'RATE_TYPE_INVALID','severity'=>'BLOCK','field'=>'rate_type'];
                if (!in_array($basis, self::BASIS, true)) $issues[] = ['code'=>'RATE_BASIS_INVALID','severity'=>'BLOCK','field'=>'rate_basis'];
                if ($currency !== 'VND') $issues[] = ['code'=>'SUPPLIER_VND_REQUIRED','severity'=>'BLOCK','field'=>'currency'];
                if (($raw['product_name'] ?? '') === '' || strlen($raw['product_name']) > 190) {
                    $issues[] = ['code'=>'PRODUCT_NAME_INVALID','severity'=>'BLOCK','field'=>'product_name'];
                }
                if (strlen($raw['option_name'] ?? '') > 190 || strlen($raw['destination'] ?? '') > 160 || strlen($raw['market'] ?? '') > 120 || strlen($raw['source_locator'] ?? '') > 255 || strlen($raw['linked_trip_ref'] ?? '') > 64) {
                    $issues[] = ['code'=>'DESCRIPTION_TOO_LONG','severity'=>'BLOCK'];
                }
                // Validate 18,2 amount via strings. Never use float for supplier money.
                $money = $raw['amount_vnd'] ?? '';
                if (preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D', $money) !== 1
                    || preg_match('/^0(?:\.0{1,2})?$/D', $money) === 1) {
                    $issues[] = ['code'=>'POSITIVE_VND_AMOUNT_REQUIRED','severity'=>'BLOCK','field'=>'amount_vnd'];
                }
                $from = self::date($raw['valid_from'] ?? null);
                $to = self::date($raw['valid_to'] ?? null);
                if ($from === null || $to === null || $from > $to) {
                    $issues[] = ['code'=>'TRAVEL_VALIDITY_INVALID','severity'=>'BLOCK'];
                }
                foreach ([['booking_valid_from','booking_valid_to'],['season_from','season_to'],['blackout_from','blackout_to']] as [$first,$last]) {
                    $a = $raw[$first] ?? '';
                    $b = $raw[$last] ?? '';
                    if (($a !== '' || $b !== '') && (self::date($a) === null || self::date($b) === null || $a > $b)) {
                        $issues[] = ['code'=>'PERIOD_INVALID','severity'=>'BLOCK','field'=>$first];
                    }
                    if ($a !== '' && $b !== '' && $from !== null && $to !== null && ($a < $from || $b > $to) && !str_starts_with($first, 'booking_')) {
                        $issues[] = ['code'=>'PERIOD_OUTSIDE_VALIDITY','severity'=>'BLOCK','field'=>$first];
                    }
                }
                $min = self::optionalPositiveInt($raw['min_pax'] ?? '');
                $max = self::optionalPositiveInt($raw['max_pax'] ?? '');
                if ($min === false || $max === false || (is_int($min) && $min > 10000) || (is_int($max) && $max > 10000) || (is_int($min) && is_int($max) && $max < $min)) {
                    $issues[] = ['code'=>'PAX_RANGE_INVALID','severity'=>'BLOCK'];
                }
                if (($min === null) !== ($max === null)) {
                    $issues[] = ['code'=>'PAX_RANGE_INCOMPLETE','severity'=>'REVIEW'];
                }
                $evidence = self::optionalPositiveInt($raw['source_document_id'] ?? '');
                if ($evidence === false) $issues[] = ['code'=>'SOURCE_DOCUMENT_INVALID','severity'=>'BLOCK'];
                elseif ($evidence === null) $issues[] = ['code'=>'SOURCE_DOCUMENT_UNVERIFIED','severity'=>'REVIEW'];
                $tax = strtoupper($raw['tax_basis'] ?? '');
                if ($tax === '' || $tax === 'UNKNOWN') {
                    $issues[] = ['code'=>'TAX_BASIS_UNVERIFIED','severity'=>'REVIEW'];
                } elseif (!in_array($tax, self::TAX, true)) {
                    $issues[] = ['code'=>'TAX_BASIS_INVALID','severity'=>'BLOCK'];
                }
                if ($type === 'SPECIAL_QUOTE' && trim($raw['linked_trip_ref'] ?? '') === '') {
                    $issues[] = ['code'=>'SPECIAL_QUOTE_TRIP_REQUIRED','severity'=>'BLOCK'];
                }
                if (($raw['season_from'] ?? '') !== '' || ($raw['blackout_from'] ?? '') !== '') {
                    $issues[] = ['code'=>'DATE_PERIOD_REQUIRES_RATE_RULES_REVIEW','severity'=>'REVIEW'];
                }
                // No DB read here: never imply real supplier ownership, availability or approved status.
                $issues[] = ['code'=>'SUPPLIER_OWNERSHIP_AND_EXISTING_RATES_NOT_CHECKED','severity'=>'REVIEW'];
                $candidate = [
                    'company_id'=>$companyId,'supplier_id'=>$supplier,'category'=>$cat,
                    'product_name'=>$raw['product_name'] ?? '', 'option_name'=>$raw['option_name'] ?? '',
                    'destination'=>$raw['destination'] ?? '', 'market'=>$raw['market'] ?? '',
                    'rate_type'=>$type, 'rate_basis'=>$basis,'currency'=>$currency,
                    'amount'=>$money, 'tax_basis'=>$tax, 'source_locator'=>$raw['source_locator'] ?? '', 'linked_trip_ref'=>$raw['linked_trip_ref'] ?? '', 'valid_from'=>$from, 'valid_to'=>$to,
                    'min_pax'=>is_int($min)?$min:null, 'max_pax'=>is_int($max)?$max:null,
                    'source_document_id'=>is_int($evidence)?$evidence:null,
                    'booking_valid_from'=>$raw['booking_valid_from'] ?? '',
                    'booking_valid_to'=>$raw['booking_valid_to'] ?? '',
                    'season_from'=>$raw['season_from'] ?? '', 'season_to'=>$raw['season_to'] ?? '',
                    'blackout_from'=>$raw['blackout_from'] ?? '', 'blackout_to'=>$raw['blackout_to'] ?? '',
                ];
                $fingerprint = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR));
                $blocked = self::hasBlock($issues);
                $rows[] = [
                    'row'=>$line,'status'=>$blocked?'BLOCKED':'NEEDS_REVIEW',
                    'candidate'=>$blocked?null:$candidate,'fingerprint'=>$fingerprint,
                    'issues'=>$issues,
                ];
                if (!$blocked) $fingerprints[$fingerprint][] = count($rows)-1;
            }
            // Repeated records and same supplier/product with intersecting dates+pax need human resolution.
            foreach ($fingerprints as $positions) {
                if (count($positions) > 1) foreach ($positions as $idx) self::flag($rows[$idx], 'DUPLICATE_IMPORT_ROW');
            }
            for ($i=0; $i<count($rows); $i++) {
                if ($rows[$i]['status']==='BLOCKED') continue;
                for ($j=$i+1; $j<count($rows); $j++) {
                    if ($rows[$j]['status']==='BLOCKED') continue;
                    $a = $rows[$i]['candidate'];$b=$rows[$j]['candidate'];
                    if ($a['supplier_id'] !== $b['supplier_id'] || $a['category'] !== $b['category']
                        || strcasecmp($a['product_name'], $b['product_name']) !== 0
                        || strcasecmp($a['option_name'], $b['option_name']) !== 0
                        || strcasecmp($a['market'], $b['market']) !== 0
                        || $a['rate_basis'] !== $b['rate_basis']
                        || $a['rate_type'] !== $b['rate_type']) continue;
                    $dateOverlap = $a['valid_from'] <= $b['valid_to'] && $a['valid_to'] >= $b['valid_from'];
                    $paxOverlap = ($a['min_pax'] ?? 1) <= ($b['max_pax'] ?? PHP_INT_MAX)
                              && ($a['max_pax'] ?? PHP_INT_MAX) >= ($b['min_pax'] ?? 1);
                    if ($dateOverlap && $paxOverlap) {
                        self::flag($rows[$i], 'CANDIDATE_RATE_OVERLAP');
                        self::flag($rows[$j], 'CANDIDATE_RATE_OVERLAP');
                    }
                }
            }
            $blocked=0;$review=0;
            foreach($rows as $row){if($row['status']==='BLOCKED')$blocked++;else $review++;}
            return [
                'schema'=>self::SCHEMA,'status'=>'PREVIEW_ONLY_NO_DB_WRITE',
                'source_sha256'=>$sourceHash,'company_id'=>$companyId,'supplier_id'=>$supplierId,
                'total_rows'=>count($rows),'blocked_rows'=>$blocked,'review_rows'=>$review,'rows'=>$rows,
                'limitations'=>[
                    'No live supplier or document ownership check, cross-batch conflict, supplier approval, FX, availability or price publication.',
                    'XLSX not parsed by this CSV-only helper; validate separately before integration.',
                ],
            ];
        } finally {
            fclose($fh);
        }
    }

    private static function flag(array &$row, string $code): void
    {
        foreach ($row['issues'] as $issue) if ($issue['code']===$code) return;
        $row['issues'][]=['code'=>$code,'severity'=>'REVIEW'];
    }
    private static function hasBlock(array $issues): bool
    {
        foreach($issues as $issue) if($issue['severity']==='BLOCK') return true;
        return false;
    }
    private static function date(?string $value): ?string
    {
        if(!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $d!==false && $d->format('Y-m-d')===$value ? $value:null;
    }
    private static function positiveInt(mixed $value): ?int
    {
        if(is_int($value)) return $value>0?$value:null;
        if(!is_string($value) || preg_match('/^[1-9][0-9]{0,12}$/D',$value)!==1) return null;
        $n=(int)$value;
        return $n>0?$n:null;
    }
    private static function optionalPositiveInt(mixed $value): int|false|null
    {
        if($value===null||$value==='')return null;
        return self::positiveInt($value) ?? false;
    }
}
