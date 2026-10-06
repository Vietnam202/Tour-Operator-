<?php
declare(strict_types=1);

/** Fixed-point checked integer arithmetic. No binary floating point in VS2 money. */
final class Vs2Decimal {
    public const LIMIT=999999999999999999;
    public static function parse($value,int $scale=2): int {
        if(!is_int($value)&&!is_string($value))throw new InvalidArgumentException('Decimal string required');
        if(!preg_match('/^(-?)(\d{1,16})(?:\.(\d+))?$/',(string)$value,$m))throw new InvalidArgumentException('Invalid decimal precision');
        $m[3]=rtrim($m[3]??'','0');if(strlen($m[3])>$scale)throw new InvalidArgumentException('Invalid decimal precision');
        $digits=ltrim($m[2].str_pad($m[3]??'',$scale,'0'),'0');if(strlen($digits)>18)throw new OverflowException('DECIMAL_OVERFLOW');
        $n=$digits===''?0:(int)$digits;return ($m[1]==='-'?-1:1)*$n;
    }
    public static function format(int $value,int $scale=2): string {
        $sign=$value<0?'-':'';$digits=str_pad((string)abs($value),$scale+1,'0',STR_PAD_LEFT);
        return $scale?$sign.substr($digits,0,-$scale).'.'.substr($digits,-$scale):$sign.$digits;
    }
    public static function add(int $a,int $b): int {
        if(($b>0&&$a>self::LIMIT-$b)||($b<0&&$a< -self::LIMIT-$b))throw new OverflowException('DECIMAL_OVERFLOW');return $a+$b;
    }
    private static function gcd(int $a,int $b): int {while($b){$t=$a%$b;$a=$b;$b=$t;}return max(1,$a);}
    public static function mul(int $a,int $b): int {
        if($a!==0&&abs($b)>intdiv(self::LIMIT,abs($a)))throw new OverflowException('DECIMAL_OVERFLOW');return $a*$b;
    }
    public static function ratio(int $a,int $b,int $d,bool $up=false): int {
        if($d<=0)throw new InvalidArgumentException('Positive divisor required');$sign=($a<0 xor $b<0)?-1:1;$a=abs($a);$b=abs($b);
        $g=self::gcd($a,$d);$a=intdiv($a,$g);$d=intdiv($d,$g);$g=self::gcd($b,$d);$b=intdiv($b,$g);$d=intdiv($d,$g);
        $n=self::mul($a,$b);$q=intdiv($n,$d);$r=$n%$d;
        if($r&&($up||$r>=intdiv($d,2)+($d%2)))$q=self::add($q,1);return $q*$sign;
    }
    public static function convert(int $cents,string $currency,string $fx): int {
        if(!in_array($currency,['USD','VND'],true))throw new DomainException('UNSUPPORTED_CURRENCY');
        return $currency==='VND'?$cents:self::ratio($cents,self::parse($fx,6),1000000);
    }
}
