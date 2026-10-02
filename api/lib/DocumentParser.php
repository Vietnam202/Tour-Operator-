<?php
declare(strict_types=1);

final class DocumentParser {
    public static function extract(string $path, string $extension): array {
        $extension=strtolower($extension);
        try {
            return match($extension) {
                'docx' => self::docx($path),
                'xlsx' => self::xlsx($path),
                'csv','txt' => self::text($path),
                'pdf' => self::pdf($path),
                default => ['text'=>'','quality'=>'NONE','note'=>'No parser for this format.'],
            };
        } catch(Throwable $e) {
            return ['text'=>'','quality'=>'LOW','note'=>'Extraction failed; original file was preserved for manual review.'];
        }
    }

    private static function limit(string $text, int $max=250000): string {
        if(function_exists('mb_substr')) return mb_substr($text,0,$max,'UTF-8');
        if(function_exists('iconv_substr')) return (string)iconv_substr($text,0,$max,'UTF-8');
        return substr($text,0,$max);
    }

    private static function zipEntry(string $path, string $entry): ?string {
        if(class_exists('ZipArchive')) {
            $zip=new ZipArchive();
            if($zip->open($path)===true){$stat=$zip->statName($entry);if($stat&&$stat['size']>4194304){$zip->close();throw new RuntimeException('Expanded entry too large');}$data=$zip->getFromName($entry);$zip->close();if($data!==false)return $data;}
        }
        if(function_exists('shell_exec')) {
            $cmd='unzip -p '.escapeshellarg($path).' '.escapeshellarg($entry).' 2>/dev/null';
            $data=@shell_exec($cmd);
            if(is_string($data) && $data!=='') return $data;
        }
        return null;
    }

    private static function xmlText(string $xml): string {
        $xml=str_replace(['</w:p>','</w:tr>','</w:tc>','<w:tab/>','<w:br/>','<w:cr/>'],["\n","\n","\t","\t","\n","\n"],$xml);
        $text=html_entity_decode(strip_tags($xml),ENT_QUOTES|ENT_XML1,'UTF-8');
        $text=preg_replace('/[ \t]+/u',' ',$text) ?? $text;
        $text=preg_replace('/\n{3,}/u',"\n\n",$text) ?? $text;
        return trim($text);
    }

    private static function docx(string $path): array {
        $xml=self::zipEntry($path,'word/document.xml');
        if(!$xml) return ['text'=>'','quality'=>'LOW','note'=>'DOCX content could not be extracted on this server. Original file is preserved.'];
        $text=self::xmlText($xml);
        return ['text'=>self::limit($text),'quality'=>$text!==''?'MEDIUM':'LOW','note'=>'DOCX text extracted. Tables and formatting must be verified against the original.'];
    }

    private static function xlsx(string $path): array {
        $strings=[];
        $ss=self::zipEntry($path,'xl/sharedStrings.xml');
        if($ss && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si',$ss,$items)) {
            foreach($items[1] as $si){
                $parts=[];
                if(preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si',$si,$ts)) foreach($ts[1] as $t)$parts[]=html_entity_decode(strip_tags($t),ENT_QUOTES|ENT_XML1,'UTF-8');
                $strings[]=implode('',$parts);
            }
        }
        $lines=[];$rowsSeen=0;$sheetsSeen=0;
        for($i=1;$i<=12;$i++){
            $xml=self::zipEntry($path,"xl/worksheets/sheet{$i}.xml");
            if(!$xml) continue;
            $sheetsSeen++;$lines[]="=== SHEET {$i} ===";
            if(!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/si',$xml,$rows)) continue;
            foreach($rows[1] as $row){
                $vals=[];
                if(preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/si',$row,$cells,PREG_SET_ORDER)){
                    foreach($cells as $cell){
                        $attrs=$cell[1];$body=$cell[2];$type='';if(preg_match('/\bt="([^"]+)"/i',$attrs,$tm))$type=$tm[1];
                        $v='';
                        if($type==='inlineStr' && preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si',$body,$its)) $v=implode('',array_map(fn($x)=>html_entity_decode(strip_tags($x),ENT_QUOTES|ENT_XML1,'UTF-8'),$its[1]));
                        elseif(preg_match('/<v\b[^>]*>(.*?)<\/v>/si',$body,$vm)){$v=trim(html_entity_decode(strip_tags($vm[1]),ENT_QUOTES|ENT_XML1,'UTF-8'));if($type==='s' && isset($strings[(int)$v]))$v=$strings[(int)$v];}
                        $vals[]=$v;
                    }
                }
                if(array_filter($vals,fn($x)=>trim((string)$x)!==''))$lines[]=implode("\t",$vals);
                if(++$rowsSeen>=600){$lines[]='[Preview truncated after 600 rows]';break 2;}
            }
        }
        $text=trim(implode("\n",$lines));
        return ['text'=>self::limit($text),'quality'=>$text!==''?'MEDIUM':'LOW','note'=>$text!==''?"XLSX preview extracted from {$sheetsSeen} worksheet(s). Formulas, merged cells and complex layouts require original-file review.":'XLSX content could not be extracted on this server. Original file is preserved.'];
    }

    private static function text(string $path): array {
        $text=file_get_contents($path); if($text===false) throw new RuntimeException('Read failed');
        return ['text'=>self::limit($text),'quality'=>'HIGH','note'=>'Plain text extracted.'];
    }

    private static function pdf(string $path): array {
        if(!function_exists('shell_exec')) return ['text'=>'','quality'=>'LOW','note'=>'PDF text extraction is unavailable on this server. Original PDF is preserved.'];
        $cmd='pdftotext -layout '.escapeshellarg($path).' - 2>'.(PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null');
        $text=(string)@shell_exec($cmd);
        if(trim($text)==='') return ['text'=>'','quality'=>'LOW','note'=>'Searchable PDF text could not be extracted. The PDF may be scanned/image-only; original PDF is preserved for manual review.'];
        return ['text'=>self::limit($text),'quality'=>'MEDIUM','note'=>'Searchable PDF text extracted; verify every rate and term against the original.'];
    }
}
