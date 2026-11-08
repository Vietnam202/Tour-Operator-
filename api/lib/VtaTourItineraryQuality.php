<?php
declare(strict_types=1);

/**
 * RC6.2 additive/read-only Master Itinerary QA helper.
 * Inputs: existing TourLibrary::publicProgram() + optional VTA_DOC_2 array.
 * Never writes/normalizes source data, assumes a booking, or calls providers.
 * This class is NOT wired into existing UI/API (feature remains OFF).
 */
final class VtaTourItineraryQuality
{
    public const SCHEMA = 'VTA_ITINERARY_QA_1';
    private const MAX_DAYS = 120;
    private const MAX_DOCUMENT_NODES = 10000;

    /** @return array<string,mixed> */
    public static function review(array $program, ?array $document = null): array
    {
        $warnings = [];
        $add = static function (string $code, string $severity, ?int $day, string $detail) use (&$warnings): void {
            $warnings[] = compact('code', 'severity', 'day', 'detail');
        };

        $days = $program['days'] ?? null;
        if (!is_array($days) || !array_is_list($days)) {
            $days = [];
            $add('SOURCE_DAYS_NOT_LOADED', 'REVIEW', null, 'Load existing Master source days before comparison.');
        }
        $total = count($days);
        if ($total > self::MAX_DAYS) {
            $add('SOURCE_DAY_LIMIT', 'REVIEW', null, 'Review limit exceeded; the source remains unchanged.');
            $days = array_slice($days, 0, self::MAX_DAYS);
        }
        if ($total === 0) {
            $add('SOURCE_DAYS_EMPTY', 'REVIEW', null, 'No structured source days. Do not invent an itinerary.');
        }

        $proposal = is_array($program['proposal'] ?? null) ? $program['proposal'] : [];
        $declared = self::positiveInt($program['day_count'] ?? null)
            ?? self::positiveInt($proposal['duration_days'] ?? null);
        if ($declared !== null && $total > 0 && $declared !== $total) {
            $add('SOURCE_DAY_COUNT_CONFLICT', 'REVIEW', null, 'Declared duration differs from loaded source day count.');
        }

        $title = is_string($program['title'] ?? null) ? $program['title'] : '';
        if (preg_match('/\b(\d{1,3})\s*D\s*(\d{1,3})\s*N\b/i', $title, $match) === 1) {
            $titleDays = (int) $match[1];
            $titleNights = (int) $match[2];
            if ($total > 0 && $titleDays !== $total) {
                $add('TITLE_DAY_COUNT_CONFLICT', 'REVIEW', null, 'Title duration differs from source day count.');
            }
            if ($titleDays > 0 && $titleNights !== $titleDays - 1) {
                $add('TITLE_DAY_NIGHT_LABEL', 'REVIEW', null, 'Day/night label needs human verification; no accommodation assumed.');
            }
        }

        $used = [];
        foreach ($days as $index => $sourceDay) {
            $dayNo = $index + 1;
            if (!is_array($sourceDay)) {
                $add('SOURCE_DAY_INVALID', 'REVIEW', $dayNo, 'Source day record is invalid.');
                continue;
            }
            $explicit = self::positiveInt($sourceDay['day'] ?? null);
            if ($explicit !== null) {
                if (isset($used[$explicit])) {
                    $add('SOURCE_DAY_DUPLICATE', 'REVIEW', $dayNo, 'Duplicate source day number.');
                }
                $used[$explicit] = true;
                if ($explicit !== $dayNo) {
                    $add('SOURCE_DAY_SEQUENCE', 'REVIEW', $dayNo, 'Explicit day number differs from source sequence.');
                }
            }
            if (!is_string($sourceDay['title'] ?? null) || trim($sourceDay['title']) === '') {
                $add('DAY_TITLE_MISSING', 'INFO', $dayNo, 'Source day title absent; do not generate a destination.');
            }
            if (!is_string($sourceDay['meals'] ?? null) || trim($sourceDay['meals']) === '') {
                $add('DAY_MEALS_UNVERIFIED', 'INFO', $dayNo, 'Meals not explicitly confirmed; do not claim B/L/D.');
            }
            if ($index < $total - 1
                && (!is_string($sourceDay['overnight'] ?? null) || trim($sourceDay['overnight']) === '')) {
                $add('DAY_OVERNIGHT_UNVERIFIED', 'INFO', $dayNo, 'Overnight city not explicitly stated in source.');
            }
        }

        $docLoaded = is_array($document) && ($document['schema'] ?? null) === 'VTA_DOC_2';
        $docDays = [];
        if ($docLoaded) {
            $tree = $document['content'] ?? null;
            if (!is_array($tree) || ($tree['type'] ?? null) !== 'doc') {
                $add('DOCUMENT_CONTENT_UNAVAILABLE', 'REVIEW', null, 'Saved Word document has no supported root; preserve original.');
            } else {
                $docDays = self::documentDays($tree, $add);
                if ($total > 0) {
                    $counts = array_count_values($docDays);
                    for ($i = 1; $i <= min($total, self::MAX_DAYS); $i++) {
                        if (!isset($counts[$i])) {
                            $add('DOCUMENT_DAY_NOT_FOUND', 'INFO', $i, 'Matching numbered Word heading not found.');
                        }
                    }
                    foreach ($counts as $day => $occurrences) {
                        if ($occurrences > 1) {
                            $add('DOCUMENT_DAY_DUPLICATE', 'REVIEW', (int) $day, 'Word has repeated numbered day headings.');
                        }
                        if ((int) $day > $total) {
                            $add('DOCUMENT_DAY_OUTSIDE_SOURCE', 'REVIEW', (int) $day, 'Word day number not present in Master source.');
                        }
                    }
                }
            }
        } elseif ($document !== null) {
            $add('DOCUMENT_SCHEMA_NOT_SUPPORTED', 'INFO', null, 'Only VTA_DOC_2 can be compared; legacy document untouched.');
        }

        $hasReview = false;
        foreach ($warnings as $warning) {
            if ($warning['severity'] === 'REVIEW') {
                $hasReview = true;
                break;
            }
        }
        return [
            'schema' => self::SCHEMA,
            'verdict' => $hasReview ? 'REVIEW_REQUIRED' : (count($warnings) ? 'ADVISORY_ONLY' : 'NO_WARNINGS'),
            'source_day_count' => $total,
            'document_checked' => $docLoaded,
            'document_days' => $docDays,
            'warnings' => $warnings,
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,3}$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /** @param callable(string,string,?int,string):void $add @return list<int> */
    private static function documentDays(array $tree, callable $add): array
    {
        $days = [];
        $stack = [[$tree, 0]];
        $visited = 0;
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if (++$visited > self::MAX_DOCUMENT_NODES || $depth > 32) {
                $add('DOCUMENT_COMPLEXITY_LIMIT', 'REVIEW', null, 'Document review limit reached; no source data changed.');
                break;
            }
            if (($node['type'] ?? null) === 'heading') {
                $text = self::headingText($node);
                if (preg_match('/^Day\s+(\d{1,3})(?:\s|[.\-:–—·]|$)/iu', $text, $match) === 1) {
                    $number = (int) $match[1];
                    if ($number > 0) {
                        $days[] = $number;
                    }
                }
            }
            foreach (array_reverse(is_array($node['content'] ?? null) ? $node['content'] : []) as $child) {
                if (is_array($child)) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }
        return $days;
    }

    private static function headingText(array $node): string
    {
        $out = '';
        $stack = [$node];
        $remaining = 512;
        while ($stack !== [] && $remaining-- > 0 && strlen($out) < 200) {
            $candidate = array_pop($stack);
            if (($candidate['type'] ?? null) === 'text' && is_string($candidate['text'] ?? null)) {
                $out .= $candidate['text'];
            }
            $children = $candidate['content'] ?? null;
            if (is_array($children)) {
                foreach (array_reverse($children) as $child) {
                    if (is_array($child)) {
                        $stack[] = $child;
                    }
                }
            }
        }
        return trim($out);
    }
}
