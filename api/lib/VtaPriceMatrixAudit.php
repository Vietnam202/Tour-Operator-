<?php
declare(strict_types=1);
require_once __DIR__.'/Vs2Decimal.php';

/**
 * RC6.2 M04 — optional, immutable PriceMatrix audit/decision-support projection.
 * Existing QuoteVs2Domain, CommercialPolicy, RateEngine, PriceMatrix and
 * Vs2Decimal remain authoritative. No rates, prices, FX, policies, RBAC or
 * approval states are computed or changed here.
 *
 * Inputs must be loaded by an authorized company-scoped server context.
 * This pure helper cannot prove RBAC, supplier ownership or current DB data.
 */
final class VtaPriceMatrixAudit
{
    public const SCHEMA = 'VTA_PRICE_MATRIX_AUDIT_1';
    public const MAX_CELLS = 500;
    private const SAFE_STATES = ['VALID','MARGIN_WARNING','MARGIN_BELOW_POLICY','SCENARIO_REVIEW_REQUIRED'];

    /** @return array<string,mixed> Safe status-only projection without supplier rates, NET or margin */
    public static function inspect(array $quoteVersion,array $matrix,array $cells,array $context): array
    {
        $company = self::intIn($context['company_id'] ?? null,1,1000000000);
        $versionCompany = self::intIn($quoteVersion['company_id'] ?? null,1,1000000000);
        $versionId = self::intIn($quoteVersion['id'] ?? null,1,PHP_INT_MAX);
        $matrixVersion = self::intIn($matrix['quote_version_id'] ?? null,1,PHP_INT_MAX);
        $matrixId = self::intIn($matrix['id'] ?? null,1,PHP_INT_MAX);
        $generation = self::intIn($matrix['generation'] ?? null,1,1000000);
        if ($company === null || $versionCompany !== $company || $versionId === null || $matrixVersion !== $versionId || $matrixId === null || $generation === null) {
            throw new DomainException('SCOPED_MATRIX_SNAPSHOT_REQUIRED');
        }
        if (count($cells) > self::MAX_CELLS || !array_is_list($cells)) {
            throw new InvalidArgumentException('Bounded list of matrix cells required.');
        }

        $warnings=[];
        $add=static function (string $code,string $severity,?int $cell=null) use (&$warnings):void {
            $warnings[]=['code'=>$code,'severity'=>$severity,'cell_id'=>$cell];
        };
        $total=self::intIn($quoteVersion['total_guests'] ?? null,1,10000);
        $pay=self::intIn($quoteVersion['paying_pax'] ?? null,1,10000);
        $foc=self::intIn($quoteVersion['foc'] ?? null,0,10000);
        if ($total===null || $pay===null || $foc===null || $pay+$foc>$total) {
            $add('PAX_SEGMENT_REVIEW_REQUIRED','BLOCK');
        } else {
            $splitPresent=true;$sum=0;
            foreach (['adults','children','infants'] as $key) {
                $v=self::intIn($quoteVersion[$key] ?? null,0,10000);
                if($v===null){$splitPresent=false;break;}
                $sum+=$v;
            }
            if($splitPresent && $sum+$foc!==$total) $add('PAX_BREAKDOWN_CONFLICT','BLOCK');
            // Total can exceed paying+FOC (e.g. infants); never force 7=6+1 globally.
            if($total>$pay+$foc) $add('OTHER_NONPAYING_GUESTS_REQUIRE_REVIEW','INFO');
        }
        if(($matrix['status'] ?? null)==='SENT'||($quoteVersion['version_status']??null)==='SENT') {
            $add('ISSUED_SNAPSHOT_READ_ONLY','INFO');
        }
        if(($matrix['status']??null)==='DRAFT') $add('MATRIX_NOT_LOCKED','REVIEW');
        if(($matrix['dependency_hash']??null)===null || !self::hexDigest($matrix['dependency_hash'])) {
            $add('DEPENDENCY_FINGERPRINT_MISSING','REVIEW');
        }
        $snapshot=self::json($matrix['fx_snapshot_json']??null);
        if($snapshot===null || ($snapshot['currency_pair']??null)!=='USD/VND'
            || !in_array($snapshot['source']??null,['MANUAL','QUOTE_SNAPSHOT'],true)
            || !self::positiveDecimal($snapshot['value']??null,6)) {
            $add('FX_SOURCE_UNVERIFIED','REVIEW');
        }

        // Compare existing immutable snapshots only. This is a discrepancy
        // signal, not an authorization to recompute, refresh or overwrite FX.
        if (is_array($snapshot) && isset($snapshot['value']) && isset($quoteVersion['fx_rate'])) {
            try {
                $fxAtGeneration=Vs2Decimal::parse($snapshot['value'],6);
                $quoteFx=Vs2Decimal::parse($quoteVersion['fx_rate'],6);
                if ($fxAtGeneration <= 0 || $quoteFx <= 0) {
                    $add('FX_SOURCE_UNVERIFIED','REVIEW');
                } elseif ($fxAtGeneration !== $quoteFx) {
                    $add('FX_SNAPSHOT_DIFFERS_FROM_QUOTE','REVIEW');
                }
            } catch (InvalidArgumentException|OverflowException $e) {
                $add('FX_SNAPSHOT_DIFFERS_FROM_QUOTE','REVIEW');
            }
        }
        $inputSnapshot=self::json($matrix['inputs_json']??null);
        if (isset($quoteVersion['costing_revision'])) {
            $currentRevision=self::intIn($quoteVersion['costing_revision'],0,1000000000);
            $frozenRevision=self::intIn($inputSnapshot['graph']['version']['costing_revision']??null,0,1000000000);
            if ($currentRevision===null || $frozenRevision===null) {
                $add('COSTING_REVISION_SNAPSHOT_UNVERIFIED','REVIEW');
            } elseif ($currentRevision!==$frozenRevision) {
                $add('COSTING_REVISION_SNAPSHOT_DRIFT','REVIEW');
            }
        }

        $selected=0;$covered=[];$seen=[];$summaries=[];
        foreach($cells as $cell){
            if(!is_array($cell))throw new InvalidArgumentException('Invalid matrix cell object.');
            $id=self::intIn($cell['id']??null,1,PHP_INT_MAX);
            $parent=self::intIn($cell['matrix_id']??null,1,PHP_INT_MAX);
            $gen=self::intIn($cell['generation']??null,1,1000000);
            if($id===null || $parent!==$matrixId || $gen!==$generation){
                throw new DomainException('MATRIX_CELL_PARENT_OR_GENERATION_MISMATCH');
            }
            if(!in_array($cell['is_selected']??null,[1,'1',true,0,'0',false],true)){
                throw new InvalidArgumentException('Invalid selected flag.');
            }
            if(!in_array($cell['is_selected'],[1,'1',true],true)) continue;
            $selected++;
            $min=self::intIn($cell['min_pax']??null,1,500);
            $max=self::intIn($cell['max_pax']??null,1,500);
            $variant=self::intIn($cell['variant_id']??null,1,PHP_INT_MAX);
            $channel=$cell['channel']??null;
            if($min===null || $max===null || $max<$min || $variant===null || !is_string($channel) || strlen($channel)>24 || trim($channel)===''){
                $add('MATRIX_BAND_INVALID','BLOCK',$id);
                continue;
            }
            $key=$variant.'|'.$channel.'|'.$min.'|'.$max;
            if(isset($seen[$key])) $add('DUPLICATE_SELECTED_MATRIX_CELL','BLOCK',$id);
            $seen[$key]=true;
            $state=$cell['status']??null;
            if(!in_array($state,self::SAFE_STATES,true)) $add('MATRIX_CELL_STATUS_UNVERIFIED','REVIEW',$id);
            if(in_array($state,['MARGIN_BELOW_POLICY','SCENARIO_REVIEW_REQUIRED'],true))
                $add('MATRIX_CELL_NEEDS_COMMERCIAL_REVIEW','REVIEW',$id);
            if($state==='MARGIN_WARNING') $add('MARGIN_WARNING_NEEDS_REVIEW','REVIEW',$id);

            $currency=$cell['selling_currency']??null;
            if(!in_array($currency,['USD','VND'],true)) $add('SELLING_CURRENCY_INVALID','BLOCK',$id);
            $price=$cell['selling_per_pax']??null;
            $priceStatus=self::positiveDecimal($price,2)?'VERIFIED_NONZERO_CANDIDATE':'ON_REQUEST';
            if($priceStatus==='ON_REQUEST')$add('SELLING_PRICE_MISSING_OR_ZERO','REVIEW',$id);
            $result=self::json($cell['result_json']??null);
            $scope=['mode'=>'UNVERIFIED','hotel_level'=>null,'cruise_level'=>null];
            if($result===null || !is_array($result['scenarios']??null) || !array_is_list($result['scenarios'])){
                $add('PRICE_SCENARIOS_UNAVAILABLE','REVIEW',$id);
            } else {
                $scope['mode']=in_array($result['mode']??null,['PRIVATE','SIC','HYBRID'],true)?$result['mode']:'UNVERIFIED';
                foreach(['hotel_level','cruise_level'] as $field){
                    $level=self::intIn($result[$field]??null,3,5);
                    $scope[$field]=$level;
                }
                $seenScenarios=[];
                foreach($result['scenarios'] as $scenario){
                    if(!is_array($scenario)){ $add('SCENARIO_INVALID','BLOCK',$id);continue; }
                    $pax=self::intIn($scenario['pax']??null,1,500);
                    if($pax===null || $pax<$min || $pax>$max || isset($seenScenarios[$pax])){
                        $add('SCENARIO_PAX_OR_DUPLICATE_INVALID','BLOCK',$id);continue;
                    }
                    $seenScenarios[$pax]=true;
                    if(!self::positiveDecimal($scenario['cost']['cost_total_vnd']??null,2))
                        $add('SCENARIO_SUPPLIER_COST_UNVERIFIED','REVIEW',$id);
                    $sources=$scenario['cost']['rate_sources']??null;
                    if(!is_array($sources) || !$sources)
                        $add('RATE_PROVENANCE_UNVERIFIED','REVIEW',$id);
                    $scenarioGuests=$scenario['cost']['guests']??null;
                    if(is_array($scenarioGuests) && $foc!==null){
                        $scenarioFoc=self::intIn($scenarioGuests['foc']??null,0,10000);
                        if($scenarioFoc!==$foc) $add('SCENARIO_FOC_DRIFT','BLOCK',$id);
                    }
                    // Only already persisted, selected scenario prices: no recalc.
                    if(!self::positiveDecimal($scenario['pricing']['selling_per_pax']??null,2))
                        $add('SCENARIO_PRICE_ON_REQUEST','REVIEW',$id);
                }
            }
            $summaries[]=[
                'cell_id'=>$id,'variant_id'=>$variant,'channel'=>$channel,
                'min_pax'=>$min,'max_pax'=>$max,'policy_state'=>$state,
                'price_status'=>$priceStatus,'currency'=>$currency,
                'costing_mode'=>$scope['mode'],'hotel_level'=>$scope['hotel_level'],
                'cruise_level'=>$scope['cruise_level'],
            ];
            $covered[]=['channel'=>$channel,'mode'=>$scope['mode'],'min'=>$min,'max'=>$max];
        }
        if($selected===0)$add('NO_SELECTED_PRICE_MATRIX_CELL','BLOCK');

        $required=$context['review_groups']??[];
        if(!is_array($required)||!array_is_list($required)||count($required)>30)throw new InvalidArgumentException('Up to 30 optional review group values supported.');
        foreach($required as $wanted){
            if(!is_array($wanted))throw new InvalidArgumentException('Review group requires channel, mode and paying pax.');
            $pax=self::intIn($wanted['paying_pax']??null,1,500);
            $channel=$wanted['channel']??null;
            $mode=$wanted['mode']??null;
            if($pax===null||!is_string($channel)||trim($channel)===''||!in_array($mode,['PRIVATE','SIC','HYBRID'],true)) throw new InvalidArgumentException('Invalid review group filter.');
            $match=false;
            foreach($covered as $entry)if($entry['channel']===$channel&&$entry['mode']===$mode&&$pax>=$entry['min']&&$pax<=$entry['max']){$match=true;break;}
            if(!$match)$add('REQUIRED_PAYING_PAX_CHANNEL_NOT_COVERED','REVIEW');
        }
        $block=0;$review=0;
        foreach($warnings as $w){if($w['severity']==='BLOCK')$block++;elseif($w['severity']==='REVIEW')$review++;}
        return [
            'schema'=>self::SCHEMA,'status'=>'READ_ONLY_AUDIT_NO_RATE_OR_PRICE_CHANGE',
            'verdict'=>$block?'BLOCKED_REVIEW':($review?'REVIEW_REQUIRED':'ADVISORY_PASS_NOT_OWNER_APPROVAL'),
            'quote_version_id'=>$versionId,'matrix_id'=>$matrixId,'generation'=>$generation,
            'selected_cells'=>$selected,'safe_cells'=>$summaries,'warnings'=>$warnings,
            'limitations'=>[
                'Only authorized server-side scoped reads can prove tenant and permissions. No direct public exposure.',
                'Stale dependency hashes and actual rate approval require existing PriceMatrix::report()/RBAC with live DB.',
                'No full price recomputation, supplier cost output, approval, publication or mutation.',
            ],
        ];
    }

    private static function json(mixed $value): ?array
    {
        if(is_array($value)) return $value;
        if(!is_string($value)||strlen($value)>900000)return null;
        try {$data=json_decode($value,true,32,JSON_THROW_ON_ERROR);return is_array($data)?$data:null;}
        catch(JsonException $e){return null;}
    }
    private static function hexDigest(mixed $value): bool
    {
        return is_string($value)&&preg_match('/^[0-9a-f]{64}$/Di',$value)===1;
    }
    private static function intIn(mixed $value,int $minimum,int $maximum): ?int
    {
        if(is_int($value))$n=$value;
        elseif(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,17})$/D',$value)===1)$n=(int)$value;
        else return null;
        return $n>=$minimum&&$n<=$maximum?$n:null;
    }
    private static function positiveDecimal(mixed $value,int $scale): bool
    {
        try {
            if(!is_int($value)&&!is_string($value))return false;
            return Vs2Decimal::parse($value,$scale)>0;
        }catch(InvalidArgumentException|OverflowException $e){return false;}
    }
}
