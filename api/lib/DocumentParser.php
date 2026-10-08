<?php
declare(strict_types=1);

final class DocumentParser {
    /** Ordered presentation blocks; existing supplier extraction remains unchanged. */
    public static function proposal(string $path,string $extension): array {
        if(strtolower($extension)!=='docx')return self::extract($path,$extension)+['blocks'=>[],'images'=>[]];
        $xml=self::zipEntry($path,'word/document.xml');
        if(!$xml||!class_exists('DOMDocument'))return self::extract($path,'docx')+['blocks'=>[],'images'=>[]];
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{if(!$dom->loadXML($xml,LIBXML_NONET)||$dom->doctype)throw new RuntimeException('Unsafe XML');}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        $xp=new DOMXPath($dom);$xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('a','http://schemas.openxmlformats.org/drawingml/2006/main');$xp->registerNamespace('r','http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationships=[];$rels=self::zipEntry($path,'word/_rels/document.xml.rels');
        $numbering=[];$numberXml=self::zipEntry($path,'word/numbering.xml');
        if($numberXml){$nd=new DOMDocument();if(@$nd->loadXML($numberXml,LIBXML_NONET)&&!$nd->doctype){$nx=new DOMXPath($nd);$nx->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');foreach($nx->query('//w:num') as $num){$abstract=$nx->query('./w:abstractNumId',$num)->item(0)?->getAttribute('w:val');if($abstract!==null&&ctype_digit($abstract))foreach($nx->query('//w:abstractNum[@w:abstractNumId="'.$abstract.'"]/w:lvl') as $level)$numbering[$num->getAttribute('w:numId')][$level->getAttribute('w:ilvl')]=$nx->query('./w:numFmt',$level)->item(0)?->getAttribute('w:val')??'bullet';}}}
        if($rels){$rd=new DOMDocument();if(@$rd->loadXML($rels,LIBXML_NONET)&&!$rd->doctype)foreach($rd->getElementsByTagName('Relationship') as $rel)if($rel->getAttribute('TargetMode')!=='External')$relationships[$rel->getAttribute('Id')]=$rel->getAttribute('Target');}
        $runs=function(DOMNode $node)use($xp):array{$out=[];$sizes=[10,11,12,14,16,18,20,24,28,32];foreach($xp->query('.//w:r',$node) as $run){$text='';foreach($run->childNodes as $n){if($n->localName==='t')$text.=$n->textContent;elseif(in_array($n->localName,['br','cr'],true))$text.="\n";elseif($n->localName==='tab')$text.="\t";}if($text==='')continue;$item=['text'=>$text,'bold'=>(bool)$xp->query('./w:rPr/w:b[not(@w:val="0") and not(@w:val="false")]',$run)->length,'italic'=>(bool)$xp->query('./w:rPr/w:i[not(@w:val="0") and not(@w:val="false")]',$run)->length];if($xp->query('./w:rPr/w:u[not(@w:val="none")]',$run)->length)$item['underline']=true;$raw=$xp->query('./w:rPr/w:sz/@w:val',$run)->item(0)?->nodeValue;if($raw!==null&&ctype_digit((string)$raw)){$points=(int)round((int)$raw/2);$chosen=$sizes[0];foreach($sizes as $size)if(abs($size-$points)<abs($chosen-$points))$chosen=$size;$item['font_size']=$chosen;}$out[]=$item;}return $out;};
        $blocks=[];$images=[];$imageBytes=0;$warnings=[];$text=[];$body=$xp->query('//w:body')->item(0);
        if(!$body)throw new InvalidArgumentException('DOCX body missing');
        foreach($body->childNodes as $node){
            if(count($blocks)>=1500)throw new InvalidArgumentException('Document has too many blocks');
            if($node->localName==='p'){
                $r=$runs($node);$plain=implode('',array_column($r,'text'));$style=$xp->query('./w:pPr/w:pStyle',$node)->item(0)?->getAttribute('w:val')??'';
                if($r){$b=['type'=>preg_match('/^Heading[1-6]$/i',$style)?'heading':'paragraph','runs'=>$r];if($b['type']==='heading')$b['level']=min(4,(int)substr($style,-1));$alignment=$xp->query('./w:pPr/w:jc',$node)->item(0)?->getAttribute('w:val')??'';$alignment=match($alignment){'center'=>'center','right'=>'right','both'=>'justify',default=>''};if($alignment!=='')$b['alignment']=$alignment;
                    if($xp->query('./w:pPr/w:numPr',$node)->length){$num=$xp->query('./w:pPr/w:numPr/w:numId',$node)->item(0)?->getAttribute('w:val');$level=$xp->query('./w:pPr/w:numPr/w:ilvl',$node)->item(0)?->getAttribute('w:val')??'0';$format=$numbering[$num][$level]??null;$b=['type'=>'list','ordered'=>$format!==null&&$format!=='bullet','items'=>[$r]];if($format===null||$level!=='0')$warnings[]='DOCX list nesting / numbering requires review.';}
                    $blocks[]=$b;$text[]=$plain;
                }
                foreach($xp->query('.//a:blip',$node) as $blip){$target=$relationships[$blip->getAttribute('r:embed')]??'';
                    if(!preg_match('#^media/[A-Za-z0-9_. -]+\.(png|jpe?g|webp)$#iD',$target)||count($images)>=12){$warnings[]='An embedded image needs manual Media Library import.';continue;}
                    $bytes=self::zipEntry($path,'word/'.$target);if(!$bytes||strlen($bytes)>4194304||$imageBytes+strlen($bytes)>12582912){$warnings[]='Embedded image size limit; upload separately.';continue;}
                    $info=@getimagesizefromstring($bytes);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$info[0]*$info[1]>16000000){$warnings[]='Unsupported embedded image; upload JPEG/PNG/WebP separately.';continue;}
                    $idx=count($images);$images[]=['index'=>$idx,'name'=>basename($target),'mime'=>$info['mime'],'base64'=>base64_encode($bytes)];$imageBytes+=strlen($bytes);$blocks[]=['type'=>'image_candidate','image_index'=>$idx];
                }
            }elseif($node->localName==='tbl'){
                $rows=[];foreach($xp->query('./w:tr',$node) as $tr){$row=[];foreach($xp->query('./w:tc',$tr) as $tc){$cell=[];foreach($xp->query('./w:p',$tc) as $paragraph){if($cell)$cell[]=['text'=>"\n",'bold'=>false,'italic'=>false];$cell=[...$cell,...$runs($paragraph)];}$row[]=$cell;if($xp->query('./w:tcPr/w:gridSpan|./w:tcPr/w:vMerge',$tc)->length)$warnings[]='Merged table cells require review.';}$rows[]=$row;}
                if(count($rows)>150||max(array_map('count',$rows)?:[0])>12)throw new InvalidArgumentException('Imported table is too large');
                if(!$rows||count(array_unique(array_map('count',$rows)))!==1||!count($rows[0])){$warnings[]='Table could not be fully classified; review text extracted from cells.';foreach($rows as $row)$blocks[]=['type'=>'paragraph','runs'=>[['text'=>implode(" | ",array_map(fn($c)=>implode('',array_column($c,'text')),$row)),'bold'=>false,'italic'=>false]]];}else $blocks[]=['type'=>'table','rows'=>$rows];foreach($rows as $row)$text[]=implode("\t",array_map(fn($c)=>implode('',array_column($c,'text')),$row));
            }
        }
        $plain=implode("\n",$text);if(strlen($plain)>250000)throw new InvalidArgumentException('Document is too long; split before importing');
        return ['text'=>$plain,'quality'=>'MEDIUM','note'=>'Ordered DOCX content extracted. Review numbering, merged tables, images and metadata before import.','blocks'=>$blocks,'images'=>$images,'warnings'=>array_values(array_unique($warnings))];
    }
    /** Parse pasted rich HTML into the same safe typed blocks as DOCX. */
    public static function richHtmlBlocks(string $html): array {
        if(strlen($html)>250000||!preg_match('//u',$html))throw new InvalidArgumentException('Paste rich HTML under 250 KB');
        if(!class_exists('DOMDocument'))throw new InvalidArgumentException('Rich paste parsing is unavailable; use the plain-text paste fallback');
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{$ok=$dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="vta-rich-import">'.$html.'</div></body></html>',LIBXML_NONET|LIBXML_HTML_NODEFDTD);if(!$ok)throw new InvalidArgumentException('Could not parse pasted document');}
        finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        $xp=new DOMXPath($dom);$root=$xp->query('//*[@id="vta-rich-import"]')->item(0);if(!$root)throw new InvalidArgumentException('Pasted document is empty');
        $sizes=[10,11,12,14,16,18,20,24,28,32];$warnings=[];$blocks=[];
        $collectRuns=null;$collectRuns=function(DOMNode $node,array $marks=[])use(&$collectRuns,$sizes,&$warnings):array{$out=[];foreach($node->childNodes as $child){if($child->nodeType===XML_TEXT_NODE){$text=$child->textContent;if($text!=='')$out[]=['text'=>$text,'bold'=>!empty($marks['bold']),'italic'=>!empty($marks['italic'])]+(!empty($marks['underline'])?['underline'=>true]:[])+(!empty($marks['font_size'])?['font_size'=>(int)$marks['font_size']]:[]);continue;}if(!$child instanceof DOMElement)continue;$tag=strtolower($child->tagName);if(in_array($tag,['script','style','iframe','object','svg','img'],true)){if($tag==='img')$warnings[]='Pasted images were skipped; upload them through Media / Add Image.';continue;}$style=$child->getAttribute('style');$next=$marks;if(in_array($tag,['b','strong'],true)||preg_match('/font-weight\s*:\s*(?:bold|[6-9]00)/i',$style))$next['bold']=true;if(in_array($tag,['i','em'],true)||preg_match('/font-style\s*:\s*italic/i',$style))$next['italic']=true;if($tag==='u'||preg_match('/text-decoration(?:-line)?\s*:[^;]*underline/i',$style))$next['underline']=true;if(preg_match('/font-size\s*:\s*([0-9.]+)\s*(pt|px)?/i',$style,$m)){$value=(float)$m[1]*((strtolower($m[2]??'pt')==='px')?0.75:1);$next['font_size']=$sizes[0];foreach($sizes as $size)if(abs($size-$value)<abs($next['font_size']-$value))$next['font_size']=$size;}$out=[...$out,...$collectRuns($child,$next)];}return $out;};
        $align=function(DOMElement $el):string{$style=$el->getAttribute('style');$value='';if(preg_match('/text-align\s*:\s*(left|center|right|justify)/i',$style,$m))$value=strtolower($m[1]);else{$a=strtolower($el->getAttribute('align'));if(in_array($a,['left','center','right','justify'],true))$value=$a;}return $value;};
        $walk=null;$walk=function(DOMNode $parent)use(&$walk,&$blocks,$xp,$collectRuns,$align,&$warnings):void{foreach($parent->childNodes as $node){if($node->nodeType===XML_TEXT_NODE){$text=trim($node->textContent);if($text!=='')$blocks[]=['type'=>'paragraph','runs'=>[['text'=>$text,'bold'=>false,'italic'=>false]]];continue;}if(!$node instanceof DOMElement)continue;$tag=strtolower($node->tagName);if(in_array($tag,['script','style','iframe','object','svg'],true))continue;if($tag==='img'){$warnings[]='Pasted images were skipped; upload them through Media / Add Image.';continue;}if($tag==='table'){$rows=[];$width=0;foreach($xp->query('.//tr',$node) as $tr){$cells=[];foreach($xp->query('./th|./td',$tr) as $cell)$cells[]=$collectRuns($cell);if($cells){$width=max($width,count($cells));$rows[]=$cells;}}if($rows){foreach($rows as &$row)while(count($row)<$width)$row[]=[];unset($row);$blocks[]=['type'=>'table','rows'=>$rows];}continue;}if(in_array($tag,['ul','ol'],true)){$items=[];foreach($xp->query('./li',$node) as $li)$items[]=$collectRuns($li);if($items)$blocks[]=['type'=>'list','ordered'=>$tag==='ol','items'=>$items];continue;}if(preg_match('/^h([1-6])$/',$tag,$m)){$blocks[]=['type'=>'heading','level'=>min(4,(int)$m[1]),'runs'=>$collectRuns($node),...($align($node)!==''?['alignment'=>$align($node)]:[])];continue;}if($tag==='p'||$tag==='blockquote'){$blocks[]=['type'=>'paragraph','runs'=>$collectRuns($node),...($align($node)!==''?['alignment'=>$align($node)]:[])];continue;}if($tag==='div'&&$xp->query('.//p|.//h1|.//h2|.//h3|.//h4|.//ul|.//ol|.//table',$node)->length){$walk($node);continue;}$runs=$collectRuns($node);if($runs)$blocks[]=['type'=>'paragraph','runs'=>$runs,...($align($node)!==''?['alignment'=>$align($node)]:[])];}};
        $walk($root);if(count($blocks)>1500)throw new InvalidArgumentException('Pasted document has too many blocks');return ['blocks'=>$blocks,'warnings'=>array_values(array_unique($warnings))];
    }

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
            if($zip->open($path)===true){$stat=$zip->statName($entry);if($stat&&$stat['size']>4194304){$zip->close();throw new RuntimeException('Expanded entry too large');}$data=$zip->getFromName($entry);$zip->close();return $data===false?null:$data;}
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
        $text=str_replace("\f","\n",(string)@shell_exec($cmd));
        if(trim($text)==='') return ['text'=>'','quality'=>'LOW','note'=>'Searchable PDF text could not be extracted. The PDF may be scanned/image-only; original PDF is preserved for manual review.'];
        return ['text'=>self::limit($text),'quality'=>'MEDIUM','note'=>'Searchable PDF text extracted; verify every rate and term against the original.'];
    }
}
