<?php
declare(strict_types=1);
require_once __DIR__.'/Vs2Decimal.php';

/** Read-only, source-verified Cost Sheet/Quote arithmetic check. Not a pricing engine. */
final class VtaQuoteCostEvidenceAudit
{
    public const SCHEMA = 'VTA_COST_EVIDENCE_AUDIT_1';
    private const MAX_LINES = 200;
    private const MAX_SCENARIOS = 20;

    /** @return array<string,mixed> */
    public static function review(array $actor, array $quote, array $lines): array
    {
        $cid = $actor['company_id'] ?? null;
        if (!is_int($cid) || $cid < 1) throw new DomainException('Authenticated company required');
        if (($quote['company_id'] ?? null) !== $cid) throw new DomainException('Cross-company Quote rejected');
        if (!array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException('Bounded source-rate lines required');
        }
        $errors=[];
        $total = self::people($quote['total_guests'] ?? null);
        $paying = self::people($quote['paying_pax'] ?? null);
        $foc = self::people($quote['foc'] ?? null);
        if ($total === null || $paying === null || $foc === null || $total<1 || $paying<1 || $total !== $paying + $foc) {
            $errors[]='PAX_FOC_CONFLICT';
        }
        $mode = $quote['tour_mode'] ?? '';
        if (!in_array($mode,['PRIVATE','SIC'],true)) $errors[]='TOUR_MODE_UNVERIFIED';
        $hotel = $quote['hotel_star'] ?? null;
        $cruise = $quote['cruise_star'] ?? null;
        foreach ([$hotel,$cruise] as $star) {
            if ($star !== null && !in_array($star,[3,4,5],true)) $errors[]='HOTEL_CRUISE_CLASS_INVALID';
        }
        if (!is_int($quote['quote_version_id'] ?? null) || $quote['quote_version_id'] <= 0) {
            $errors[]='QUOTE_REVISION_UNVERIFIED';
        }
        $rows=[];
        $subtotal=0;
        $focCost=0;
        $seen=[];
        foreach ($lines as $index=>$line) {
            $issues=[];
            $value=null;
            $freeCost=null;
            if (!is_array($line)) {
                $issues[]='SOURCE_LINE_INVALID';
                $line=[];
            }
            if (($line['company_id'] ?? null)!==$cid) {
                $issues[]='RATE_TENANT_MISMATCH';
            }
            $key=$line['service_key'] ?? null;
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,90}$/D',$key)) {
                $issues[]='SERVICE_KEY_INVALID';
                $key='line-'.($index+1);
            }
            if (isset($seen[$key])) $issues[]='SERVICE_KEY_DUPLICATE';
            $seen[$key]=true;
            if (!is_int($line['rate_version_id'] ?? null) || $line['rate_version_id']<1) {
                $issues[]='RATE_VERSION_UNVERIFIED';
            }
            if (!is_string($line['source_document_ref'] ?? null) || trim($line['source_document_ref'])==='') {
                $issues[]='RATE_PROVENANCE_MISSING';
            }
            if (($line['approval_status'] ?? null)!=='APPROVED') $issues[]='RATE_NOT_APPROVED';
            if (($line['currency'] ?? null)!=='VND') $issues[]='SOURCE_VND_REQUIRED';
            $unit=$line['unit_vnd'] ?? null;
            $cents=null;
            try {
                if (!is_string($unit) && !is_int($unit)) throw new InvalidArgumentException('No amount');
                $cents=Vs2Decimal::parse($unit,2);
                if ($cents<=0) throw new InvalidArgumentException('Non-positive amount');
            } catch (Throwable $e) {
                $issues[]='UNIT_AMOUNT_INVALID';
            }
            $units=self::people($line['units'] ?? 1);
            if ($units===null || $units<1) $issues[]='QUANTITY_INVALID';
            $basis=$line['guest_basis'] ?? null;
            if (!in_array($basis,['TOTAL_PAX','PAYING_PAX','PER_UNIT'],true)) $issues[]='GUEST_BASIS_UNVERIFIED';
            $from=$line['valid_from'] ?? null;
            $to=$line['valid_to'] ?? null;
            $date=$line['service_date'] ?? null;
            if (!self::day($from) || !self::day($to) || !self::day($date)) {
                $issues[]='RATE_VALIDITY_UNVERIFIED';
            } elseif ($from>$to || $date<$from || $date>$to) {
                $issues[]='SERVICE_OUTSIDE_RATE_VALIDITY';
            }
            $issues=array_values(array_unique($issues));
            // Do not calculate any price from a mismatched Quote or unapproved supplier line.
            if (!$issues && !$errors && $units!==null && $cents!==null) {
                try {
                    $multiplier=match($basis) {
                        'TOTAL_PAX'=>Vs2Decimal::mul($units,$total),
                        'PAYING_PAX'=>Vs2Decimal::mul($units,$paying),
                        'PER_UNIT'=>$units,
                    };
                    $value=Vs2Decimal::mul($cents,$multiplier);
                    $freeCost=$basis==='TOTAL_PAX'?Vs2Decimal::mul($cents,Vs2Decimal::mul($units,$foc)):0;
                    $subtotal=Vs2Decimal::add($subtotal,$value);
                    $focCost=Vs2Decimal::add($focCost,$freeCost);
                } catch (Throwable $e) {
                    $issues[]='ARITHMETIC_OVERFLOW';
                }
            }
            if ($issues) $errors=array_merge($errors,$issues);
            $rows[]=[
                'row'=>$index+1,
                'service_key'=>$key,
                'rate_version_id'=>is_int($line['rate_version_id']??null)?$line['rate_version_id']:null,
                'issues'=>$issues,
                'cost_vnd'=>$value===null?null:Vs2Decimal::format($value),
                'foc_included_vnd'=>$freeCost===null?null:Vs2Decimal::format($freeCost)
            ];
        }
        if ($lines===[]) $errors[]='NO_SOURCE_COSTS';
        $errors=array_values(array_unique($errors));
        // Any error invalidates aggregate display, even if prior rows passed.
        return [
            'schema'=>self::SCHEMA,
            'mode'=>'READ_ONLY_AUDIT',
            'verdict'=>$errors?'BLOCKED_SOURCE_REVIEW':'EXPLAINED_COST_NOT_A_QUOTE',
            'company_id'=>$cid,
            'quote_version_id'=>is_int($quote['quote_version_id']??null)?$quote['quote_version_id']:null,
            'source_digest'=>hash('sha256',json_encode([$quote,$lines],JSON_THROW_ON_ERROR)),
            'pax'=>['total'=>$total,'paying'=>$paying,'foc'=>$foc],
            'tour_mode'=>$mode,
            'hotel_star'=>$hotel,
            'cruise_star'=>$cruise,
            'cost_total_vnd'=>$errors?null:Vs2Decimal::format($subtotal),
            'foc_cost_included_vnd'=>$errors?null:Vs2Decimal::format($focCost),
            'errors'=>$errors,
            'lines'=>$rows,
            'can_sell'=>false,
            'can_commit'=>false,
            'fx_applied'=>false,
            'supplier_availability_confirmed'=>false,
        ];
    }

    /** Read-only comparison of explicit separate scenarios. Never extrapolate an unprovided rate. */
    public static function compare(array $actor, array $scenarios): array
    {
        if (!array_is_list($scenarios) || count($scenarios)>self::MAX_SCENARIOS) {
            throw new InvalidArgumentException('Bounded scenarios required');
        }
        $items=[];
        $ids=[];
        foreach ($scenarios as $scenario) {
            if (!is_array($scenario) || !is_string($scenario['id']??null) ||
                !preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$scenario['id']) ||
                isset($ids[$scenario['id']])) {
                throw new InvalidArgumentException('Unique scenario IDs required');
            }
            $ids[$scenario['id']]=true;
            $item=self::review($actor,$scenario['quote']??[],$scenario['lines']??[]);
            $items[]=['scenario'=>$scenario['id'],'audit'=>$item];
        }
        return ['schema'=>self::SCHEMA,'mode'=>'SCENARIO_COMPARISON_READ_ONLY','items'=>$items,
            'all_verified'=>!$items?false:count(array_filter($items,static fn($x)=>$x['audit']['errors']!==[]))===0,
            'can_sell'=>false];
    }

    private static function people(mixed $number): ?int
    {
        return is_int($number) && $number>=0 && $number<=10000?$number:null;
    }
    private static function day(mixed $date): bool
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) return false;
        $value=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return $value!==false && $value->format('Y-m-d')===$date;
    }
}
