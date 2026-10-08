<?php
declare(strict_types=1);

/** One public, typed word-processing tree inside the existing proposal_json. */
final class FreeformDocument {
 public static function validate(array $document):array {
  if(($document['schema']??'')!=='VTA_DOC_2')throw new InvalidArgumentException('Invalid freeform document');
  $count=0;$walk=function($n,int $depth=0)use(&$walk,&$count):array{
   if(!is_array($n)||++$count>20000||$depth>32)throw new InvalidArgumentException('Document is too complex');
   $type=$n['type']??'';$attrs=$n['attrs']??[];if(!is_array($attrs))throw new InvalidArgumentException('Invalid node attributes');$out=['type'=>$type];
   $blocks=['paragraph','heading','bulletList','orderedList','blockquote','table','image','pageBreak','horizontalRule'];
   $groups=['doc'=>$blocks, 'paragraph'=>['text','hardBreak'], 'heading'=>['text','hardBreak'], 'bulletList'=>['listItem'], 'orderedList'=>['listItem'], 'listItem'=>$blocks, 'blockquote'=>$blocks, 'table'=>['tableRow'], 'tableRow'=>['tableCell','tableHeader'], 'tableCell'=>$blocks, 'tableHeader'=>$blocks];
   if($type==='text'){
    MediaLibrary::text($n['text']??'',200000);$out['text']=$n['text'];if($out['text']==='')throw new InvalidArgumentException('Empty text node');
    if(isset($n['marks'])){if(!is_array($n['marks'])||!array_is_list($n['marks'])||count($n['marks'])>4)throw new InvalidArgumentException('Invalid text marks');$marks=[];$seen=[];foreach($n['marks'] as $m){$name=$m['type']??'';if(isset($seen[$name]))throw new InvalidArgumentException('Duplicate text mark');$seen[$name]=true;if(in_array($name,['bold','italic','underline'],true))$marks[]=['type'=>$name];elseif($name==='textStyle'){$a=$m['attrs']??[];$style=[];if(!empty($a['color']))$style['color']=self::color($a['color']);if(!empty($a['fontSize'])){if(!preg_match('/^(\d{1,2}(?:\.\d)?)pt$/D',(string)$a['fontSize'],$match)||(float)$match[1]<8||(float)$match[1]>72)throw new InvalidArgumentException('Font size must be 8–72 pt');$style['fontSize']=$a['fontSize'];}if($style)$marks[]=['type'=>'textStyle','attrs'=>$style];}else throw new InvalidArgumentException('Unsupported text mark');}if($marks)$out['marks']=$marks;}
    return $out;
   }
   if(in_array($type,['paragraph','heading'],true)){$a=[];if($type==='heading'){$level=$attrs['level']??2;if(!is_int($level)||$level<1||$level>6)throw new InvalidArgumentException('Heading level must be 1–6');$a['level']=$level;}if(!empty($attrs['textAlign'])){if(!in_array($attrs['textAlign'],['left','center','right','justify'],true))throw new InvalidArgumentException('Invalid alignment');$a['textAlign']=$attrs['textAlign'];}if($a)$out['attrs']=$a;}
   if($type==='orderedList'){$start=$attrs['start']??1;if(!is_int($start)||$start<1||$start>9999)throw new InvalidArgumentException('Invalid list start');$out['attrs']=['start'=>$start];}
   if(in_array($type,['tableCell','tableHeader'],true)){$a=[];foreach(['colspan','rowspan'] as $key){$x=$attrs[$key]??1;if(!is_int($x)||$x<1||$x>20)throw new InvalidArgumentException('Invalid table span');$a[$key]=$x;}$widths=$attrs['colwidth']??null;if($widths!==null){if(!is_array($widths)||!array_is_list($widths)||count($widths)!==$a['colspan'])throw new InvalidArgumentException('Invalid cell widths');foreach($widths as $width)if(!is_int($width)||$width<0||$width>2000)throw new InvalidArgumentException('Invalid cell width');}$a['colwidth']=$widths;$out['attrs']=$a;}
   if($type==='image'){$id=$attrs['assetId']??null;if(!is_int($id)||$id<1)throw new InvalidArgumentException('Use an uploaded document image');$out['attrs']=['assetId'=>$id,'src'=>'api/index.php?route='.rawurlencode('media/'.$id.'/image'),'alt'=>MediaLibrary::text($attrs['alt']??'',500),'title'=>MediaLibrary::text($attrs['title']??'',500)];foreach(['width','height'] as $key)if(!empty($attrs[$key])){$value=$attrs[$key];if(!is_int($value)||$value<16||$value>2000)throw new InvalidArgumentException('Invalid image dimensions');$out['attrs'][$key]=$value;}return $out;}
   if(in_array($type,['hardBreak','pageBreak','horizontalRule'],true)){if(!empty($n['content']))throw new InvalidArgumentException('Invalid leaf node');return $out;}
   if(!isset($groups[$type]))throw new InvalidArgumentException('Unsupported document node');
   $content=$n['content']??[];if(!is_array($content)||!array_is_list($content))throw new InvalidArgumentException('Invalid document children');if(!in_array($type,['doc','paragraph','heading'],true)&&!$content)throw new InvalidArgumentException('Empty container');
   if($type==='doc'&&$depth!==0)throw new InvalidArgumentException('Nested document');
   $children=[];foreach($content as $child){if(!is_array($child)||!in_array($child['type']??'',$groups[$type],true))throw new InvalidArgumentException('Invalid document structure');$children[]=$walk($child,$depth+1);}if($children)$out['content']=$children;
   if($type==='listItem'&&($children[0]['type']??'')!=='paragraph')throw new InvalidArgumentException('A list item starts with a paragraph');
   if($type==='table')self::tableGrid($children);
   return $out;
  };
  $content=$walk($document['content']??[]);if($content['type']!=='doc')throw new InvalidArgumentException('Document root required');
  $out=['schema'=>'VTA_DOC_2','title'=>MediaLibrary::text($document['title']??'',1000),'content'=>$content];
  if(isset($document['legacy'])){if(!is_array($document['legacy'])||($document['legacy']['schema']??'')!=='VTA_DOC_1')throw new InvalidArgumentException('Invalid legacy document');$out['legacy']=QuoteProposal::document($document['legacy']);}
  if(strlen(MediaLibrary::json($out))>900000)throw new InvalidArgumentException('Document exceeds 900 KB');return $out;
 }
 private static function tableGrid(array $rows):void {
  if(count($rows)>150)throw new InvalidArgumentException('Use up to 150 table rows');$spans=[];$width=null;
  foreach($rows as $i=>$row){$column=0;$occupied=[];foreach($spans as $col=>$remaining)if($remaining>0){$occupied[$col]=true;$spans[$col]--;}
   foreach($row['content'] as $cell){while(isset($occupied[$column]))$column++;$a=$cell['attrs'];for($j=0;$j<$a['colspan'];$j++){if(isset($occupied[$column+$j]))throw new InvalidArgumentException('Overlapping table cells');$occupied[$column+$j]=true;$spans[$column+$j]=$a['rowspan']-1;}if($i+$a['rowspan']>count($rows))throw new InvalidArgumentException('Table span exceeds rows');$column+=$a['colspan'];}
   $columns=count($occupied);if($columns>20||$columns<1||max(array_keys($occupied))+1!==$columns||($width!==null&&$width!==$columns))throw new InvalidArgumentException('Malformed table grid');$width=$columns;
  }
 }
 public static function color(string $color):string {
  if(preg_match('/^#[a-f0-9]{6}$/iD',$color))return strtolower($color);
  if(preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/D',$color,$m)&&max((int)$m[1],(int)$m[2],(int)$m[3])<=255)return sprintf('#%02x%02x%02x',$m[1],$m[2],$m[3]);
  throw new InvalidArgumentException('Use a supported text color');
 }
 public static function imageIds(array $document):array {
  $ids=[];$walk=function(array $n)use(&$walk,&$ids){if($n['type']==='image')$ids[]=(int)$n['attrs']['assetId'];foreach($n['content']??[] as $c)$walk($c);};$walk($document['content']);return array_values(array_unique($ids));
 }
 public static function upload(array $file):array {
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>10485760)throw new InvalidArgumentException('Upload DOCX/PDF/TXT under 10 MB');
  $ext=strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION));if(!in_array($ext,['docx','pdf','txt'],true))throw new InvalidArgumentException('Use DOCX, PDF or TXT');
  $r=DocumentParser::proposal($file['tmp_name'],$ext,true);
  if(trim($r['text'])===''&&empty($r['images']))throw new InvalidArgumentException(($r['note']??'No editable content extracted').' OCR is not available.');
  $r['warnings'][]=$ext==='pdf'?'PDF import keeps searchable text only; columns, tables, images and formatting need manual review. OCR is not available.':'Review supported formatting, nested lists and merged cells after import.';
  return $r+['extension'=>$ext];
 }
 /** Safe public HTML from validated nodes; image URL is supplied by the snapshot route. */
 public static function html(array $document,callable $imageUrl):string {
  $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $walk=function(array $n)use(&$walk,$e,$imageUrl):string{
   $t=$n['type'];$a=$n['attrs']??[];if($t==='text'){$value=$e($n['text']);foreach($n['marks']??[] as $m){$type=$m['type'];if($type==='textStyle'){$style='';if(isset($m['attrs']['color']))$style.='color:'.$m['attrs']['color'].';';if(isset($m['attrs']['fontSize']))$style.='font-size:'.$m['attrs']['fontSize'].';';$value='<span style="'.$e($style).'">'.$value.'</span>';}else{$tag=['bold'=>'strong','italic'=>'em','underline'=>'u'][$type];$value='<'.$tag.'>'.$value.'</'.$tag.'>';}}return $value;}
   if($t==='hardBreak')return '<br>';if($t==='horizontalRule')return '<hr>';if($t==='pageBreak')return '<div style="break-before:page;page-break-before:always"></div>';
   if($t==='image')return '<img src="'.$e($imageUrl($a['assetId'])).'" alt="'.$e($a['alt']).'"'.(isset($a['width'])?' width="'.$a['width'].'"':'').' style="max-width:100%;height:auto">';
   $tag=['doc'=>'div','paragraph'=>'p','heading'=>'h'.($a['level']??2),'bulletList'=>'ul','orderedList'=>'ol','listItem'=>'li','blockquote'=>'blockquote','table'=>'table','tableRow'=>'tr','tableCell'=>'td','tableHeader'=>'th'][$t];$attributes='';
   if(isset($a['textAlign']))$attributes.=' style="text-align:'.$a['textAlign'].'"';if($t==='orderedList')$attributes.=' start="'.($a['start']??1).'"';if(in_array($t,['tableCell','tableHeader'],true))$attributes.=' colspan="'.$a['colspan'].'" rowspan="'.$a['rowspan'].'"';
   return '<'.$tag.$attributes.'>'.implode('',array_map($walk,$n['content']??[])).'</'.$tag.'>';
  };return $walk($document['content']);
 }
 /** Adapt only freeform export to the existing shared DOCX/PDF rendering machinery. */
 public static function blocks(array $document,array $media):array {
  $result=[];$images=array_column($media,null,'asset_id');
  $runs=function(array $n)use(&$runs):array{$out=[];foreach($n['content']??[] as $c){if($c['type']==='text'){$r=['text'=>$c['text'],'bold'=>false,'italic'=>false];foreach($c['marks']??[] as $m){if(in_array($m['type'],['bold','italic','underline'],true))$r[$m['type']]=true;else {if(isset($m['attrs']['fontSize']))$r['font_size']=(float)$m['attrs']['fontSize'];if(isset($m['attrs']['color']))$r['color']=$m['attrs']['color'];}}$out[]=$r;}elseif($c['type']==='hardBreak')$out[]=['text'=>"\n"];else{$nested=$runs($c);if($out&&$nested)$out[]=['text'=>"\n"];$out=[...$out,...$nested];}}return $out;};
  $walk=function(array $n,int $depth=0,?string $prefix=null)use(&$walk,&$result,$images,$runs){$t=$n['type'];$a=$n['attrs']??[];
   if($t==='image'){$m=$images[$a['assetId']]??throw new DomainException('Document image is not assigned');$result[]=['type'=>'image','asset_id'=>$a['assetId'],'caption'=>$a['alt'],'width'=>$m['width'],'height'=>$m['height'],'width_pct'=>isset($a['width'])?max(1,min(100,(int)round($a['width']/658*100))):100];}
   elseif($t==='pageBreak')$result[]=['type'=>'pageBreak'];
   elseif($t==='horizontalRule')$result[]=['type'=>'text','text'=>'────────────────'];
   elseif(in_array($t,['paragraph','heading'],true)){$r=$runs($n);if($prefix)$r=[['text'=>$prefix],...$r];$rich=['type'=>$t==='heading'?'heading':'paragraph','runs'=>$r,'alignment'=>$a['textAlign']??'left'];if($t==='heading')$rich['level']=$a['level'];$result[]=['type'=>$t==='heading'?'heading':'text','text'=>implode('',array_column($r,'text')),'rich'=>$rich,'heading_level'=>$a['level']??2,'list_depth'=>$depth];}
   elseif($t==='table'){$rows=[];$carry=[];$imageNodes=[];foreach($n['content'] as $row){$cells=[];$column=0;foreach($carry as $col=>$left)if($left>0){$cells[$col]=[];$carry[$col]--;}foreach($row['content'] as $cell){while(array_key_exists($column,$cells))$column++;$a=$cell['attrs'];for($j=0;$j<$a['colspan'];$j++){$cells[$column+$j]=$j===0?$runs($cell):[];$carry[$column+$j]=$a['rowspan']-1;}$column+=$a['colspan'];$findImages=function(array $child)use(&$findImages,&$imageNodes){if($child['type']==='image')$imageNodes[]=$child;foreach($child['content']??[] as $nested)$findImages($nested);};$findImages($cell);}ksort($cells);$rows[]=array_values($cells);}$head=array_shift($rows);$flat=fn($r)=>implode('',array_column($r,'text'));$result[]=['type'=>'table','headers'=>array_map($flat,$head),'rows'=>array_map(fn($row)=>array_map($flat,$row),$rows),'rich_headers'=>$head,'rich_rows'=>$rows,'document_table'=>true,'freeform_cells'=>$n['content']];foreach($imageNodes as $imageNode)$walk($imageNode);}
   elseif(in_array($t,['bulletList','orderedList'],true)){foreach($n['content'] as $i=>$li)foreach($li['content'] as $j=>$child)$walk($child,$depth+1,$j===0?str_repeat('  ',$depth).($t==='bulletList'?'• ':($a['start']??1)+$i.'. '):null);}
   else foreach($n['content']??[] as $child)$walk($child,$depth,$prefix);
  };$walk($document['content']);return $result;
 }
 /** Serialize the unified tree into native editable Word paragraphs, lists, cells and drawings. */
 public static function word(array $document,ZipArchive $zip,callable $imagePath):array {
  $e=fn($v)=>htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8');$relationships=[];$images=[];$lists=[];$drawing=1;
  $inline=function(array $n)use(&$inline,$e):string {
   if($n['type']==='hardBreak')return '<w:r><w:br/></w:r>';if($n['type']!=='text')return '';
   $props='';foreach($n['marks']??[] as $mark){$t=$mark['type'];if($t==='textStyle'){$a=$mark['attrs'];if(isset($a['color']))$props.='<w:color w:val="'.substr($a['color'],1).'"/>';if(isset($a['fontSize']))$props.='<w:sz w:val="'.(int)round((float)$a['fontSize']*2).'"/>';}else $props.=['bold'=>'<w:b/>','italic'=>'<w:i/>','underline'=>'<w:u w:val="single"/>'][$t];}
   return '<w:r><w:rPr>'.$props.'</w:rPr><w:t xml:space="preserve">'.$e($n['text']).'</w:t></w:r>';
  };
  $walk=function(array $n,int $depth=0,?int $list=null)use(&$walk,$inline,$e,$zip,$imagePath,&$relationships,&$images,&$lists,&$drawing):string {
   $t=$n['type'];$a=$n['attrs']??[];
   if(in_array($t,['paragraph','heading'],true)){$style=$t==='heading'?'Heading'.$a['level']:'Normal';$align=$a['textAlign']??'left';$properties='<w:pStyle w:val="'.$style.'"/>';if($align!=='left')$properties.='<w:jc w:val="'.($align==='justify'?'both':$align).'"/>';if($list!==null)$properties.='<w:numPr><w:ilvl w:val="'.min(8,max(0,$depth-1)).'"/><w:numId w:val="'.$list.'"/></w:numPr>';
    return '<w:p><w:pPr>'.$properties.'</w:pPr>'.implode('',array_map($inline,$n['content']??[])).'</w:p>';
   }
   if($t==='pageBreak')return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
   if($t==='horizontalRule')return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6"/></w:pBdr></w:pPr></w:p>';
   if($t==='image'){$id=$a['assetId'];if(!isset($images[$id])){$rid='rIdImage'.$id;$zip->addFile($imagePath($id),'word/media/image'.$id.'.jpg');$images[$id]=$rid;$relationships[]='<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image'.$id.'.jpg"/>';}
    $info=getimagesize($imagePath($id));if(!$info)throw new RuntimeException('Document image unavailable');$cx=min(5943600,(int)(($a['width']??658)*9525));$cy=(int)($cx*$info[1]/$info[0]);if($cy>8500000){$cx=(int)($cx*8500000/$cy);$cy=8500000;}
    return '<w:p><w:r><w:drawing><wp:inline><wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.$drawing++.'" name="Image" descr="'.$e($a['alt']).'"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Image"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$images[$id].'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
   }
   if(in_array($t,['bulletList','orderedList'],true)){$id=count($lists)+1;$lists[$id]=['ordered'=>$t==='orderedList','start'=>$a['start']??1,'level'=>min(8,$depth)];$xml='';foreach($n['content'] as $item)foreach($item['content'] as $i=>$child)$xml.=$walk($child,$depth+1,$i===0?$id:null);return $xml;}
   if($t==='table'){$width=array_sum(array_map(fn($c)=>$c['attrs']['colspan'],$n['content'][0]['content']));$xml='<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>';foreach(['top','left','bottom','right','insideH','insideV'] as $border)$xml.='<w:'.$border.' w:val="single" w:sz="4" w:color="B8C3CF"/>';$xml.='</w:tblBorders></w:tblPr><w:tblGrid>'.str_repeat('<w:gridCol w:w="'.(int)(9360/$width).'"/>',$width).'</w:tblGrid>';$carry=[];
    foreach($n['content'] as $row){$xml.='<w:tr>';$cells=$row['content'];$index=0;$column=0;while($column<$width){
      if(isset($carry[$column])){$span=$carry[$column]['span'];$xml.='<w:tc><w:tcPr><w:gridSpan w:val="'.$span.'"/><w:vMerge/></w:tcPr><w:p/></w:tc>';if(--$carry[$column]['left']===0)unset($carry[$column]);$column+=$span;continue;}
      $cell=$cells[$index++]??throw new InvalidArgumentException('Invalid Word table grid');$ca=$cell['attrs'];$span=$ca['colspan'];$properties='<w:gridSpan w:val="'.$span.'"/>';if($ca['rowspan']>1){$properties.='<w:vMerge w:val="restart"/>';$carry[$column]=['span'=>$span,'left'=>$ca['rowspan']-1];}if($cell['type']==='tableHeader')$properties.='<w:shd w:fill="F2F5F8"/>';
      $body='';foreach($cell['content'] as $child)$body.=$walk($child);if(($cell['content'][count($cell['content'])-1]['type']??'')!=='paragraph')$body.='<w:p/>';$xml.='<w:tc><w:tcPr>'.$properties.'</w:tcPr>'.$body.'</w:tc>';$column+=$span;
     }$xml.='</w:tr>';}$xml.='</w:tbl>';return $xml;
   }
   $xml='';foreach($n['content']??[] as $child)$xml.=$walk($child,$depth,$list);return $xml;
  };
  $body=$walk($document['content']);if($lists){$numbering='<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';foreach($lists as $id=>$list){$numbering.='<w:abstractNum w:abstractNumId="'.$id.'"><w:multiLevelType w:val="multilevel"/>';for($level=0;$level<9;$level++)$numbering.='<w:lvl w:ilvl="'.$level.'"><w:start w:val="1"/><w:numFmt w:val="'.($list['ordered']?'decimal':'bullet').'"/><w:lvlText w:val="'.($list['ordered']?'%'.($level+1).'.':'•').'"/><w:pPr><w:ind w:left="'.(360*($level+1)).'" w:hanging="240"/></w:pPr></w:lvl>';$numbering.='</w:abstractNum><w:num w:numId="'.$id.'"><w:abstractNumId w:val="'.$id.'"/><w:lvlOverride w:ilvl="'.$list['level'].'"><w:startOverride w:val="'.$list['start'].'"/></w:lvlOverride></w:num>';}$numbering.='</w:numbering>';$zip->addFromString('word/numbering.xml',$numbering);$relationships[]='<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>';}
  return ['body'=>$body,'relationships'=>$relationships,'numbering'=>(bool)$lists];
 }
}
