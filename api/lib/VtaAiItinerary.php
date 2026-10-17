<?php
declare(strict_types=1);

/**
 * RC6.2 VIE-16. An authenticated, review-only bridge from external AI JSON
 * to a NEW company-scoped Master Tour draft. Never overwrites the source.
 * No LLM service is implied and this API never publishes B2C or adverts.
 */
final class VtaAiItinerary {
    public const SCHEMA='VTA_MASTER_ITINERARY_V1';
    public const LAYOUTS=['detailed','brochure','quick','b2b'];
    public static function priceGrid(array $input):array {
        if(!array_is_list($input)||count($input)!==5)
            throw new InvalidArgumentException('Private Group requires exactly five rows');
        $last=0;$output=[];
        foreach($input as $row){
            if(!is_array($row))throw new InvalidArgumentException('Invalid passenger group');
            $pax=filter_var($row['pax']??null,FILTER_VALIDATE_INT);
            if($pax===false||$pax<1||$pax>1000||$pax<=$last)
                throw new InvalidArgumentException('Private Group sizes must be unique, ascending positive integers');
            $last=$pax;$prices=$row['prices']??null;
            if(!is_array($prices)||count($prices)!==3||array_diff(['3star','4star','5star'],array_keys($prices))||array_diff(array_keys($prices),['3star','4star','5star']))
                throw new InvalidArgumentException('Three hotel tiers are required in order');
            $values=[];
            foreach(['3star','4star','5star'] as $tier){
                $amount=$prices[$tier];
                if($amount===null||$amount===''){$values[$tier]=null;continue;}
                if(!is_string($amount)&&!is_int($amount)&&!is_float($amount))
                    throw new InvalidArgumentException('Selling price must be a decimal value or null');
                $amount=(string)$amount;
                if(!preg_match('/^(?:[1-9]\d{0,6})(?:\.\d{1,2})?$/D',$amount))
                    throw new InvalidArgumentException('Selling prices require positive USD amounts, up to two decimals');
                $values[$tier]=$amount;
            }
            $output[]=['pax'=>$pax,'prices'=>$values];
        }
        return $output;
    }
    public static function defaultGrid():array {
        return array_map(static fn(int $p)=>['pax'=>$p,'prices'=>['3star'=>null,'4star'=>null,'5star'=>null]],[4,6,8,10,12]);
    }
    private static function publicText(array $doc):string {
        $parts=[];$walk=static function(array $node)use(&$walk,&$parts):void{
            if(($node['type']??'')==='text')$parts[]=(string)$node['text'];
            foreach($node['content']??[] as $child)$walk($child);
        };
        $walk($doc['content']);return implode(' ',$parts);
    }
    private static function stripConfidential(array $doc):void {
        $text=self::publicText($doc);
        if(preg_match('/\b(?:supplier\s+(?:net|cost|rate)|net\s+rate|cost\s+price|gross\s+margin|internal\s+(?:cost|margin)|commission\s+(?:rate|margin)|giá\s+net|giá\s+vốn|lợi\s+nhuận)\b/iu',$text))
            throw new DomainException('Internal supplier NET, cost, commission or margin detected. Remove before creating a reusable draft.');
    }
    public static function validate(array $body,array $source):array {
        if(($body['schema']??null)!==self::SCHEMA)
            throw new InvalidArgumentException('Expected VTA_MASTER_ITINERARY_V1 JSON');
        if(($body['source_program_id']??null)!==(int)$source['id'])
            throw new DomainException('Source tour mismatch. Reload the selected program.');
        if(!is_string($body['source_revision']??null)||!hash_equals(TourProgramWorkspace::revision($source),$body['source_revision']))
            throw new DomainException('STALE_REVISION: source tour changed; reload before editing');
        if(($source['status']??'')==='ARCHIVED')throw new DomainException('Archived Master Tour is read-only');
        if(($body['currency']??null)!=='USD')
            throw new InvalidArgumentException('This version supports approved USD selling prices only; do not convert currencies implicitly');
        $title=trim((string)($body['title']??''));
        if($title===''||strlen($title)>180||str_contains($title,"\0"))
            throw new InvalidArgumentException('Use a tour title of 1–180 characters');
        $candidate=$body['document']??null;
        if(!is_array($candidate))throw new InvalidArgumentException('Complete VTA_DOC_2 document is required');
        $document=TourProgramWorkspace::reusableDocument($candidate);
        $document['title']=$title;
        self::stripConfidential($document);
        $sourceDocument=TourProgramWorkspace::document($source);
        if(array_diff(FreeformDocument::imageIds($document),FreeformDocument::imageIds($sourceDocument)))
            throw new DomainException('New images must be uploaded and reviewed in the Word editor before reusing them');
        $days=ScheduleImport::normalize($body['days']??[]);
        $priceRows=self::priceGrid($body['private_groups']??[]);
        $reviewed=($body['selling_prices_reviewed']??false)===true;
        $hasRates=false;foreach($priceRows as $row)foreach($row['prices'] as $rate)if($rate!==null)$hasRates=true;
        if($hasRates&&!$reviewed)throw new DomainException('Selling-price amounts require explicit operator approval; otherwise leave On request');
        if(isset($body['agent'])&&!in_array($body['agent'],['chatgpt','hermes','manual'],true))
            throw new InvalidArgumentException('Unknown proposal source');
        $sourceData=TourLibrary::publicProgram($source);
        $origDoc=$sourceDocument;
        $sameDoc=hash('sha256',MediaLibrary::json($origDoc))===hash('sha256',MediaLibrary::json($document));
        $sameDays=MediaLibrary::json(ScheduleImport::normalize($sourceData['days']))===MediaLibrary::json($days);
        $priorRows=$sourceData['proposal']['private_prices']??[];
        $hasChanges=!$sameDoc||!$sameDays||$title!==(string)$source['title']||$hasRates||count($priorRows)!==5;
        if(!$hasChanges)throw new DomainException('No changes detected. Edit the candidate before creating a draft.');
        $warnings=[];
        $type=(string)($sourceData['tour_type']??'');
        if($type==='SIC')$warnings[]='Source is SIC. A Private Group quotation needs separate transport, guide, service and supplier review.';
        if($days===[])$warnings[]='No structured day records provided. Verify duration and overnight stays manually in the Word document.';
        else {
            $text=self::publicText($document);
            if(preg_match('/\b(\d{1,2})\s*D\s*(\d{1,2})\s*N\b/i',$title,$m)&&((int)$m[1]!==count($days)||(int)$m[2]!==count($days)-1))
                $warnings[]='Tour title day/night count differs from structured day records.';
            if(preg_match('/Ninh\s+Binh|Ninh\s+Bình/iu',$text)&&preg_match('/Phu\s+Quoc|Phú\s+Quốc/iu',$text))
                $warnings[]='Check transfers and flight routing between Ninh Binh and Phu Quoc; this edit does not confirm feasibility.';
        }
        $warnings[]='Human verification required for transfers, meals, hotel availability, pictures and all supplier confirmations.';
        return ['title'=>$title,'document'=>$document,'days'=>$days,'private_groups'=>$priceRows,
            'selling_prices_reviewed'=>$reviewed,'source_type'=>$type,'agent'=>$body['agent']??'manual',
            'warnings'=>$warnings,'changed'=>['document'=>!$sameDoc,'days'=>!$sameDays,'title'=>$title!==(string)$source['title']]];
    }
    public static function tableNodes(array $grid,bool $reviewed):array {
        $cell=static fn(string $s,bool $header=false)=>['type'=>$header?'tableHeader':'tableCell','content'=>[['type'=>'paragraph','content'=>$s===''?[]:[['type'=>'text','text'=>$s]]]]];
        $rows=[['type'=>'tableRow','content'=>array_map(static fn($s)=>$cell($s,true),['Private group','3★ / pax','4★ / pax','5★ / pax'])]];
        foreach($grid as $row){
            $values=[(string)$row['pax'].' pax'];
            foreach(['3star','4star','5star'] as $key)$values[]=($reviewed&&$row['prices'][$key]!==null)?'USD '.$row['prices'][$key]:'On request';
            $rows[]=['type'=>'tableRow','content'=>array_map($cell,$values)];
        }
        return [['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>'Private Group Selling Price']]],
            ['type'=>'table','attrs'=>['vtaSection'=>'pricing'],'content'=>$rows]];
    }
    public static function exportDocument(array $document,array $grid,bool $reviewed):array {
        $doc=TourProgramWorkspace::reusableDocument($document);
        array_push($doc['content']['content'],...self::tableNodes($grid,$reviewed));
        return FreeformDocument::validate($doc);
    }
    public static function render(array $doc,array $grid,bool $reviewed,string $layout):string {
        if(!in_array($layout,self::LAYOUTS,true))throw new InvalidArgumentException('Unknown layout');
        $print=self::exportDocument($doc,$grid,$reviewed);
        $body=FreeformDocument::html($print,static fn($id)=>'api/index.php?route=media/'.(int)$id.'/image');
        $labels=['detailed'=>'Professional Detailed','brochure'=>'Photo-rich Brochure','quick'=>'Quick Itinerary','b2b'=>'Tour Proposal'];
        $label=htmlspecialchars($labels[$layout],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $title=htmlspecialchars((string)$doc['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $css='*{box-sizing:border-box}body{background:#eef3f6;color:#193e54;font:15px/1.7 system-ui;margin:0}main{background:#fff;max-width:940px;margin:auto;padding:35px 42px;min-height:100vh}header{padding:35px;color:#fff;background:#133e58}h1{font-size:30px}h2{color:#115875;border-bottom:1px solid #cedde3}img{max-width:100%}table{border-collapse:collapse;width:100%;font-size:12px;display:block;overflow-x:auto}td,th{border:1px solid #cbd9df;padding:10px;text-align:left}th{background:#eaf4f5}p{overflow-wrap:anywhere}.eyebrow{font-size:11px;letter-spacing:2px;text-transform:uppercase}.mode-brochure header{background:linear-gradient(120deg,#12334e,#2a98a1);padding:65px 35px}.mode-brochure h1,.mode-brochure h2{font-family:Georgia,serif}.mode-quick main{max-width:760px}.mode-quick header{background:#f0f6f7;color:#163d58;border-top:6px solid #157f83}.mode-quick h2{font-size:18px}.mode-b2b header{background:#193659}.mode-b2b main{border-left:5px solid #193659}@media(max-width:600px){main{padding:20px 17px}header{padding:25px 17px}h1{font-size:25px}table{font-size:11px}}';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>'.$css.'</style></head><body class="mode-'.$layout.'"><main><header><div class="eyebrow">Vietnam Travel Advisor · '.$label.' · Unpublished DRAFT</div><h1>'.$title.'</h1><p>Subject to review and supplier confirmation</p></header>'.$body.'<p><small>Draft preview only. Rates not approved are On request. No booking confirmed.</small></p></main></body></html>';
    }
    public static function preview(PDO $db,array $u,int $sourceId,array $body):array {
        $source=TourProgramWorkspace::program($db,$u,$sourceId);
        $v=self::validate($body,$source);
        TourProgramWorkspace::images($db,$u,$v['document']);
        $layout=$body['layout']??'detailed';
        $html=self::render($v['document'],$v['private_groups'],$v['selling_prices_reviewed'],(string)$layout);
        return ['preview_html'=>$html,'warnings'=>$v['warnings'],'changed'=>$v['changed'],
            'source_revision'=>TourProgramWorkspace::revision($source),'source_program_id'=>$sourceId];
    }
    public static function commit(PDO $db,array $u,int $sourceId,array $body):array {
        if(($body['review_acknowledged']??false)!==true)
            throw new DomainException('Review the proposed changes and operational warnings before creating a new Draft');
        $key=(string)($body['creation_key']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))
            throw new InvalidArgumentException('A stable creation key is required to prevent duplicate drafts');
        return QuoteVs2Repository::atomic($db,static function()use($db,$u,$sourceId,$body,$key):array{
            $source=TourProgramWorkspace::program($db,$u,$sourceId,true);
            $v=self::validate($body,$source);
            TourProgramWorkspace::images($db,$u,$v['document']);
            $original=TourLibrary::publicProgram($source);
            $proposal=$original['proposal']??[];
            $proposal['document']=$v['document'];
            $proposal['tour_code']=substr((string)($proposal['tour_code']??''),0,40);
            $proposal['private_prices']=array_map(static fn($g)=>['min'=>$g['pax'],'max'=>$g['pax'],
                'three'=>$g['prices']['3star']??'','four'=>$g['prices']['4star']??'',
                'five'=>$g['prices']['5star']??''],$v['private_groups']);
            $proposal['private_prices_reviewed']=$v['selling_prices_reviewed'];
            $proposal['duration_days']=count($v['days']);
            $copy=$original;
            $copy['title']=$v['title'];
            $copy['days']=$v['days'];
            $copy['proposal']=$proposal;
            $copy['status']='DRAFT';
            // Original binary source is preserved on the source; this is a derived working copy.
            $copy['source_type']='MANUAL';
            $copy['source_name']='';
            $copy['source_url']='';
            $copy['source_text']='';
            $copy['creation_key']=$key;
            $saved=TourLibrary::save($db,$u,$copy);
            if(!empty($saved['replayed']))return ['id'=>$saved['id'],'source_program_id'=>$sourceId,'status'=>'DRAFT','replayed'=>true,'warnings'=>$v['warnings']];
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'AI_ITINERARY_DRAFT_CREATED','tour_library',(int)$saved['id'],null,
                ['source_program_id'=>$sourceId,'agent'=>$v['agent'],'private_group_rows'=>5,'prices_reviewed'=>$v['selling_prices_reviewed']]);
            return ['id'=>$saved['id'],'source_program_id'=>$sourceId,'status'=>'DRAFT','replayed'=>$saved['replayed']??false,
                'warnings'=>$v['warnings']];
        });
    }
}
