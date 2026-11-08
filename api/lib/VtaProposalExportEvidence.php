<?php
declare(strict_types=1);

/**
 * RC6.2 M05 — read-only post-export evidence for the EXISTING ProposalOutput
 * DOCX and PDF bytes. Opt-in offline QA only; NOT connected to any route,
 * Quote state, Word/Tiptap document store, price calculation, or send action.
 *
 * Security scope: proof of explicitly required markers/known canaries only,
 * NOT a general DLP guarantee or automatic visual/Word rendering acceptance.
 * PDF text/page evidence must be independently extracted by a trusted runner
 * (Poppler pdftotext/pdfinfo in CI); this class never invokes OS commands.
 */
final class VtaProposalExportEvidence
{
    public const SCHEMA = 'VTA_PROPOSAL_EXPORT_EVIDENCE_1';
    public const MAX_FILE_BYTES = 20971520;
    public const MAX_SOURCE_JSON_BYTES = 1048576;
    public const TEMPLATES = [
        'VTA_STANDARD_B2B','VTA_B2B_WHITE_LABEL',
        'VTA_EXPLORER_B2C','VTA_PREMIUM_B2C',
    ];

    /**
     * @param array<string,mixed> $snapshot Existing customer-facing presentation only.
     * @param array<string,mixed> $proof Trusted external extraction, already generated.
     * @param array<string,mixed> $expected Synthetic/approved public markers, forbidden canaries.
     * @return array<string,mixed> Metadata, hashes and codes; NEVER raw DOCX/PDF text.
     */
    public static function inspect(
        array $snapshot,
        string $docxPath,
        string $pdfPath,
        string $html,
        array $proof,
        array $expected
    ): array {
        $alerts = [];
        $warn = static function (string $code,string $severity='BLOCK') use (&$alerts):void {
            foreach ($alerts as $item) if ($item['code']===$code) return;
            $alerts[]=['code'=>$code,'severity'=>$severity];
        };
        $template=$snapshot['presentation']['settings']['template']??null;
        if (!in_array($template,self::TEMPLATES,true)) {
            throw new InvalidArgumentException('Use one supported existing Proposal template.');
        }
        $ref=$snapshot['quote_ref']??null;
        if(!is_string($ref)||!preg_match('/^[A-Za-z0-9_-]{4,90}$/D',$ref)) {
            throw new InvalidArgumentException('Immutable public quote reference is required.');
        }
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>self::MAX_SOURCE_JSON_BYTES)throw new InvalidArgumentException('Public presentation snapshot too large.');
        if(strlen($html)<100 || strlen($html)>self::MAX_FILE_BYTES)throw new InvalidArgumentException('A bounded HTML preview is required.');
        foreach(['cost_total_vnd','rate_sources','internal_notes','supplier_cost','supplier_net','margin_amount','profit_amount','supplier_bank_account'] as $key) {
            if(self::hasKey($snapshot,$key)) $warn('PRIVATE_FIELD_IN_PUBLIC_SNAPSHOT');
        }
        foreach([$docxPath,$pdfPath] as $path) {
            if(!is_file($path)||is_link($path)||!is_readable($path)||
               filesize($path)<100||filesize($path)>self::MAX_FILE_BYTES) {
                throw new InvalidArgumentException('Bounded, regular DOCX/PDF output files required.');
            }
        }
        $zip=new ZipArchive();
        if($zip->open($docxPath)!==true)throw new InvalidArgumentException('DOCX must be a valid ZIP.');
        $xml='';$docText='';$imageCount=0;$tableCount=0;$breakCount=0;$a4=false;
        try {
            if($zip->numFiles<4||$zip->numFiles>250)throw new InvalidArgumentException('DOCX ZIP inventory invalid.');
            $sum=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $st=$zip->statIndex($i);
                if(!is_array($st))throw new InvalidArgumentException('Unreadable DOCX member.');
                $name=$st['name']??'';
                if(!is_string($name)||str_starts_with($name,'/')||
                    str_contains($name,'..')||str_contains($name,'\\')||
                    str_contains(strtolower($name),'vbaproject')||
                    str_ends_with(strtolower($name),'.exe'))throw new InvalidArgumentException('Unsafe DOCX ZIP member.');
                $sum+=(int)$st['size'];
                if($sum>52428800)throw new InvalidArgumentException('DOCX inflated size limit exceeded.');
                if(str_starts_with($name,'word/media/') && !str_ends_with($name,'/'))$imageCount++;
            }
            foreach(['word/document.xml','word/styles.xml','[Content_Types].xml','_rels/.rels','word/_rels/document.xml.rels'] as $file) {
                if($zip->locateName($file)!==false)continue;
                $warn('DOCX_REQUIRED_PART_MISSING');
            }
            $xml=$zip->getFromName('word/document.xml');
            if(!is_string($xml)||strlen($xml)>10485760||self::hasUnsafeXml($xml)) {
                throw new InvalidArgumentException('Invalid Word document XML.');
            }
            $doc=new DOMDocument();
            if(!$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) {
                throw new InvalidArgumentException('Malformed Word document XML.');
            }
            $xp=new DOMXPath($doc);
            $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $parts=[];
            foreach($xp->query('//w:t') as $n)$parts[]=$n->textContent;
            $docText=implode(' ',$parts);
            $tableCount=$xp->query('//w:tbl')->length;
            $breakCount=$xp->query('//w:br[@w:type="page"]')->length;
            foreach($xp->query('//w:pgSz') as $n) {
                if($n->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w')==='11906'
                    && $n->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','h')==='16838')$a4=true;
            }
            if(!$a4)$warn('DOCX_A4_PAGE_GEOMETRY_UNVERIFIED');
            $relation=$zip->getFromName('word/_rels/document.xml.rels');
            if(!is_string($relation)||self::hasUnsafeXml($relation)){
                $warn('DOCX_RELATIONSHIPS_MISSING_OR_UNSAFE');
            }elseif(preg_match('/TargetMode\s*=\s*["\x27]External["\x27]/i',$relation)){
                $warn('EXTERNAL_DOCX_RELATIONSHIP_REQUIRES_REVIEW','REVIEW');
            }
        } finally {
            $zip->close();
        }
        $pdfHead=file_get_contents($pdfPath,false,null,0,32);
        $pdfTail=file_get_contents($pdfPath,false,null,max(0,filesize($pdfPath)-256),256);
        if(!is_string($pdfHead)||!str_starts_with($pdfHead,'%PDF-')||
           !is_string($pdfTail)||!str_contains($pdfTail,'%%EOF'))$warn('PDF_SIGNATURE_OR_EOF_INVALID');
        $pdfText=$proof['pdf_text']??null;
        $pages=$proof['pdf_pages']??null;
        $a4Proof=$proof['pdf_a4']??null;
        if(!is_string($pdfText)||$pdfText===''||strlen($pdfText)>self::MAX_FILE_BYTES) {
            $warn('TRUSTED_PDF_TEXT_PROOF_MISSING');
            $pdfText='';
        }
        if(!is_int($pages)||$pages<1||$pages>1000)$warn('TRUSTED_PDF_PAGE_COUNT_MISSING');
        if($a4Proof!==true)$warn('PDF_A4_PAGE_GEOMETRY_UNVERIFIED');

        $required=$expected['public_markers']??null;
        $forbidden=$expected['forbidden_markers']??null;
        $minimumPages=$expected['minimum_pdf_pages']??1;
        $minimumImages=$expected['minimum_images']??0;
        $minimumTables=$expected['minimum_tables']??0;
        $minimumBreaks=$expected['minimum_docx_page_breaks']??0;
        if(!is_array($required)||!array_is_list($required)||!$required||count($required)>30
           ||!is_array($forbidden)||!array_is_list($forbidden)||!$forbidden||count($forbidden)>30) {
            throw new InvalidArgumentException('Nonempty explicit public and forbidden marker sets are required.');
        }
        foreach([$minimumPages,$minimumImages,$minimumTables,$minimumBreaks] as $n) {
            if(!is_int($n)||$n<0||$n>200)throw new InvalidArgumentException('Invalid evidence minimum.');
        }
        if(is_int($pages)&&$pages<$minimumPages)$warn('PDF_PAGE_COUNT_BELOW_EXPECTED');
        if($imageCount<$minimumImages)$warn('DOCX_IMAGE_COUNT_BELOW_EXPECTED');
        if($tableCount<$minimumTables)$warn('DOCX_TABLE_COUNT_BELOW_EXPECTED');
        if($breakCount<$minimumBreaks)$warn('DOCX_EXPLICIT_PAGE_BREAK_MISSING');
        $publicHtml=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        foreach($required as $marker) {
            if(!is_string($marker)||strlen($marker)<3||strlen($marker)>120||
               preg_match('//u',$marker)!==1)throw new InvalidArgumentException('Invalid required public marker.');
            $needle=self::normalize($marker);
            if(!str_contains(self::normalize($docText),$needle))$warn('DOCX_PUBLIC_CONTENT_MISSING');
            if(!str_contains(self::normalize($pdfText),$needle))$warn('PDF_PUBLIC_CONTENT_MISSING');
            if(!str_contains(self::normalize($publicHtml),$needle))$warn('HTML_PUBLIC_CONTENT_MISSING');
        }
        foreach($forbidden as $token) {
            if(!is_string($token)||strlen($token)<8||strlen($token)>120||
                preg_match('//u',$token)!==1)throw new InvalidArgumentException('Invalid confidential canary.');
            $needle=self::normalize($token);
            if(str_contains(self::normalize($docText),$needle))$warn('CONFIDENTIAL_MARKER_IN_DOCX');
            if(str_contains(self::normalize($pdfText),$needle))$warn('CONFIDENTIAL_MARKER_IN_PDF');
            if(str_contains(self::normalize($publicHtml),$needle))$warn('CONFIDENTIAL_MARKER_IN_HTML');
        }
        $blocks=$snapshot['presentation']['blocks']??[];
        if(is_array($blocks)&&$blocks){
            $sourceTableCount=0;$sourceImageCount=0;
            foreach($blocks as $b) {
                if(!is_array($b))continue;
                if(($b['type']??null)==='table')$sourceTableCount++;
                if(($b['type']??null)==='image')$sourceImageCount++;
            }
            if($sourceTableCount>$tableCount)$warn('DOCX_TABLE_DROPPED');
            if($sourceImageCount>0&&$imageCount===0)$warn('DOCX_IMAGE_DROPPED');
        }
        $block=0;$review=0;
        foreach($alerts as $a) {
            if($a['severity']==='BLOCK')$block++;
            else $review++;
        }
        return [
            'schema'=>self::SCHEMA,
            'status'=>'POST_EXPORT_READ_ONLY_NO_DOCUMENT_CHANGE',
            'verdict'=>$block?'BLOCKED_REVIEW':($review?'REVIEW_REQUIRED':'STRUCTURE_AND_MARKERS_PASS_VISUAL_REVIEW_PENDING'),
            'template'=>$template,
            'quote_ref'=>$ref,
            'source_sha256'=>hash('sha256',$json),
            'docx_sha256'=>hash_file('sha256',$docxPath),
            'pdf_sha256'=>hash_file('sha256',$pdfPath),
            'docx_bytes'=>filesize($docxPath),
            'pdf_bytes'=>filesize($pdfPath),
            'docx_tables'=>$tableCount,
            'docx_embedded_images'=>$imageCount,
            'docx_explicit_page_breaks'=>$breakCount,
            'pdf_pages_externally_verified'=>is_int($pages)?$pages:null,
            'alerts'=>$alerts,
            'limitations'=>[
                'Real DOCX and PDF binary/file structure plus explicit marker/canary verification; not pixel-level layout parity.',
                'PDF text/page evidence is supplied by an external extractor, not independently obtained inside this helper.',
                'Do not infer universal confidential-data absence, true Word reflow/pagination, real user acceptance or issue/approval status.',
            ],
        ];
    }

    private static function hasUnsafeXml(string $xml): bool
    {
        return stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false;
    }
    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u',' ',$text)??'');
    }
    private static function hasKey(array $record,string $key,int $depth=0): bool
    {
        if($depth>32)return true;
        foreach($record as $k=>$value) {
            if($k===$key)return true;
            if(is_array($value)&&self::hasKey($value,$key,$depth+1))return true;
        }
        return false;
    }
}
