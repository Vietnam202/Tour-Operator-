<?php
declare(strict_types=1);

/**
 * Reviewable private-group vehicle bands. A vehicle label never implies a price.
 * Each selected transfer scenario uses a real tenant-approved rate through Vs2RateResolver.
 */
final class GroupVehiclePricing {
    public const DEFAULT_VEHICLES=[
        ['min'=>2,'max'=>2,'vehicle'=>'Sedan / 7-seater'],
        ['min'=>3,'max'=>4,'vehicle'=>'7-seater'],
        ['min'=>5,'max'=>9,'vehicle'=>'16-seater'],
        ['min'=>10,'max'=>14,'vehicle'=>'16 / 29-seater'],
        ['min'=>15,'max'=>20,'vehicle'=>'29-seater']
    ];
    public static function normalize(array $vehicles,array $rateIds,array $bands,array $graph): array {
        if(!$vehicles && !$rateIds)return ['vehicles'=>[],'rates'=>[]];
        if(!array_is_list($vehicles)||count($vehicles)!==count($bands)||count($vehicles)>30)
            throw new InvalidArgumentException('One vehicle choice per pax band is required');
        $byKey=[];$keys=array_column($bands,'key');
        foreach($vehicles as $row) {
            if(!is_array($row))throw new InvalidArgumentException('Vehicle band must be structured');
            $min=QuoteVs2Domain::count($row['min']??null);$max=QuoteVs2Domain::count($row['max']??null);
            $key=$min.'-'.$max;
            if(!in_array($key,$keys,true)||isset($byKey[$key]))throw new InvalidArgumentException('Vehicle bands must match non-overlapping pax bands');
            $vehicle=QuoteVs2Domain::text($row['vehicle']??'',90);
            $reason=QuoteVs2Domain::text($row['reason']??'',500,true);
            $byKey[$key]=['min'=>$min,'max'=>$max,'vehicle'=>$vehicle,'reason'=>$reason];
        }
        $sorted=[];foreach($bands as $band)$sorted[]=$byKey[$band['key']]??throw new InvalidArgumentException('Missing vehicle for pax band');
        if(!is_array($rateIds))throw new InvalidArgumentException('Invalid vehicle rate selections');
        $allowed=[];foreach($graph['variants'] as $variant)foreach($variant['lines'] as $line)
            if(($line['line_kind']??'')==='SERVICE'&&($line['formula_code']??'')==='TRANSFER_PACKAGE')
                $allowed[(string)$line['id']]=true;
        $clean=[];
        foreach($rateIds as $lineId=>$selection){
            if(!ctype_digit((string)$lineId)||!isset($allowed[(string)$lineId])||!is_array($selection))throw new InvalidArgumentException('Vehicle rates must target current transfer lines');
            foreach($selection as $key=>$id){
                if(!in_array((string)$key,$keys,true))throw new InvalidArgumentException('Rate must belong to current vehicle band');
                if($id===null||$id==='')continue;
                if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]{0,15}$/D',(string)$id))
                    throw new InvalidArgumentException('Selected approved vehicle rate required');
                $rateId=(int)$id;if($rateId<1)throw new InvalidArgumentException('Invalid rate reference');
                $clean[(string)$lineId][(string)$key]=$rateId;
            }
        }
        return ['vehicles'=>$sorted,'rates'=>$clean];
    }
    public static function band(array $config,int $pay): ?array {
        foreach($config['vehicle_bands']??[] as $b)if($pay>=$b['min']&&$pay<=$b['max'])return $b;
        return null;
    }
    public static function rate(array $config,int $lineId,int $pay): int {
        $band=self::band($config,$pay);
        if(!$band)return 0;
        $key=$band['min'].'-'.$band['max'];
        if(trim($band['reason']??'')==='')throw new DomainException('VEHICLE_SOURCE_REVIEW_REQUIRED');
        $rate=(int)($config['transport_rate_versions'][(string)$lineId][$key]??0);
        if($rate<=0)throw new DomainException('VEHICLE_RATE_REQUIRED');
        return $rate;
    }
}
