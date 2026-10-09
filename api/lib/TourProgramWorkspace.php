<?php
declare(strict_types=1);
require_once __DIR__.'/QuoteProposal.php';

/** Reusable public documents; quote identity, schedule and costing stay in their own records. */
final class TourProgramWorkspace {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {return MediaLibrary::q($db,$sql,$args);}
    public static function program(PDO $db,array $u,int $id,bool $lock=false): array {
        $p=self::q($db,'SELECT * FROM tour_library_programs WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$u['company_id'],$id])->fetch(PDO::FETCH_ASSOC);
        if(!$p)throw new OutOfBoundsException('Tour program not found');return $p;
    }
    public static function revision(array $p): string {
        return hash('sha256',MediaLibrary::json(TourLibrary::publicProgram($p)));
    }
    public static function hasContent(array $document): bool {
        $walk=function(array $node)use(&$walk):bool{if($node['type']==='image'||($node['type']==='text'&&trim($node['text'])!==''))return true;foreach($node['content']??[] as $child)if($walk($child))return true;return false;};
        return $walk($document['content']);
    }
    /** Only explicitly marked price blocks are removed; unmarked prose needs operator review. */
    public static function reusableDocument(array $document): array {
        $document=QuoteProposal::document($document);
        if($document['schema']!=='VTA_DOC_2')throw new InvalidArgumentException('Use the Word document editor first');
        $walk=function(array $n)use(&$walk):array {
            if(isset($n['content']))$n['content']=array_values(array_map($walk,array_filter($n['content'],fn($x)=>($x['attrs']['vtaSection']??'')!=='pricing')));
            if(in_array($n['type'],['listItem','tableCell','tableHeader','blockquote'],true)&&empty($n['content']))$n['content']=[['type'=>'paragraph']];
            if($n['type']==='listItem'&&($n['content'][0]['type']??'')!=='paragraph')array_unshift($n['content'],['type'=>'paragraph']);
            return $n;
        };
        $document['content']=$walk($document['content']);unset($document['legacy']);
        return QuoteProposal::document($document);
    }
    public static function document(array $p): array {
        $proposal=json_decode($p['proposal_json']??'null',true)?:[];
        if(isset($proposal['document']))return self::reusableDocument($proposal['document']);
        $nodes=[];$text=fn($s)=>['type'=>'paragraph','content'=>[['type'=>'text','text'=>$s]]];
        $heading=fn($s,$level=2)=>['type'=>'heading','attrs'=>['level'=>$level],'content'=>[['type'=>'text','text'=>$s]]];
        $lines=function(string $s)use(&$nodes,$text){foreach(preg_split('/\r?\n/u',$s) as $line)if(trim($line)!=='')$nodes[]=$text($line);};
        $nodes[]=$heading($p['title'],1);$lines((string)($proposal['overview']??''));
        foreach($proposal['highlights']??[] as $s)$lines($s);
        foreach(json_decode($p['days_json']??'[]',true)?:[] as $i=>$day){
            $nodes[]=$heading('Day '.($i+1).' · '.($day['title']??''));$lines((string)($day['description']??''));
            foreach(['meals'=>'Meals','overnight'=>'Overnight'] as $key=>$label)if(!empty($day[$key]))$lines($label.': '.$day[$key]);
        }
        if(!empty($proposal['hotels'])){
            $nodes[]=$heading('Hotels & Cruise');$cell=fn($s,$header=false)=>['type'=>$header?'tableHeader':'tableCell','content'=>[$s!==''?$text($s):['type'=>'paragraph']]];
            $rows=[['type'=>'tableRow','content'=>array_map(fn($s)=>$cell($s,true),['Destination','3★','4★','5★'])]];
            foreach($proposal['hotels'] as $hotel)$rows[]=['type'=>'tableRow','content'=>array_map(fn($key)=>$cell((string)($hotel[$key]??'')),['destination','three','four','five'])];
            $nodes[]=['type'=>'table','content'=>$rows];
        }
        foreach(['included_text'=>'Included','excluded_text'=>'Excluded','terms_text'=>'Terms'] as $key=>$label)if(!empty($p[$key])){$nodes[]=$heading($label);$lines($p[$key]);}
        foreach(['children','payment','cancellation','notes'] as $key)if(!empty($proposal['policies'][$key])){$nodes[]=$heading(ucfirst($key));$lines($proposal['policies'][$key]);}
        return QuoteProposal::document(['schema'=>'VTA_DOC_2','title'=>$p['title'],'content'=>['type'=>'doc','content'=>$nodes]]);
    }
    public static function images(PDO $db,array $u,array $document,bool $reusable=true): array {
        $links=[];$bytes=0;
        foreach(FreeformDocument::imageIds($document) as $id){
            $a=MediaLibrary::asset($db,$u,$id);
            if($a['status']==='ARCHIVED'||($reusable&&!empty($a['quote_id'])))throw new DomainException('Use reusable images from the media library. Quote-specific images must be copied through Save to library.');
            $bytes+=(int)$a['byte_size'];if($bytes>25165824)throw new InvalidArgumentException('Optimized document images exceed 24 MB');
            $links[]=['asset_id'=>$id,'role'=>'SERVICE','day_key'=>'','reference_key'=>'FREEFORM','caption'=>$a['title'],'sort_order'=>count($links),'width'=>(int)$a['width'],'height'=>(int)$a['height']];
        }
        return $links;
    }
    public static function context(PDO $db,array $u,array $p): array {
        $document=self::document($p);
        return ['settings'=>['document'=>$document],'links'=>self::images($db,$u,$document),'days'=>[],'selling_options'=>[],
            'immutable'=>$p['status']==='ARCHIVED','costing_revision'=>self::revision($p),'program'=>TourLibrary::publicProgram($p)];
    }
    public static function saveDocument(PDO $db,array $u,int $id,array $b): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$b){
            $p=self::program($db,$u,$id,true);
            if(!is_string($b['expected_revision']??null)||!hash_equals(self::revision($p),$b['expected_revision']))throw new DomainException('STALE_REVISION');
            if($p['status']==='ARCHIVED')throw new DomainException('Archived programs cannot be edited');
            $d=self::reusableDocument($b['settings']['document']??[]);self::images($db,$u,$d);
            $data=TourLibrary::publicProgram($p);$data['title']=$d['title'];$data['proposal']['document']=$d;
            if(isset($b['metadata'])){
                if(!is_array($b['metadata']))throw new InvalidArgumentException('Invalid program metadata');
                foreach(['title','destination','language','status'] as $key)if(array_key_exists($key,$b['metadata']))$data[$key]=$b['metadata'][$key];
                foreach(['tour_code','tour_type'] as $key)if(array_key_exists($key,$b['metadata']))$data['proposal'][$key]=$b['metadata'][$key];
                $data['proposal']['document']['title']=$data['title'];
            }
            TourLibrary::save($db,$u,$data,$id);
            return self::context($db,$u,self::program($db,$u,$id));
        });
    }
    public static function apply(PDO $db,array $u,int $id,int $version,array $b): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$version,$b){
            $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$version,QuoteVs2Repository::expected($b));
            QuoteVs2Repository::mutable($db,$v);Auth::requireQuoteRead($db,$u,$v);
            $p=self::program($db,$u,$id,true);if($p['status']==='ARCHIVED')throw new DomainException('Archived programs cannot be copied');
            if(!is_string($b['program_revision']??null)||!hash_equals(self::revision($p),$b['program_revision']))throw new DomainException('The program changed. Reload its preview before applying.');
            $d=self::document($p);$links=self::images($db,$u,$d);$ctx=QuoteProposal::context($db,$u,$v);
            // Existing price calculations, trip/customer identifiers and structured dates never enter this payload.
            $settings=$ctx['settings'];$settings['document']=$d;
            $keep=array_values(array_filter($ctx['links'],fn($l)=>$l['reference_key']!=='FREEFORM'));
            $ctx=QuoteProposal::save($db,$u,$version,['expected_revision'=>$b['expected_revision'],'settings'=>$settings,'links'=>array_merge($keep,$links)]);
            $snapshot=TourLibrary::publicProgram($p);
            self::q($db,'INSERT INTO tour_library_quote_sources(company_id,quote_version_id,program_id,snapshot_json,created_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE program_id=VALUES(program_id),snapshot_json=VALUES(snapshot_json),created_by=VALUES(created_by)',[$u['company_id'],$version,$id,MediaLibrary::json($snapshot),$u['id']]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'TOUR_PROGRAM_APPLIED','quote_version',$version,null,['program_id'=>$id]);
            return $ctx;
        });
    }
    public static function fromQuote(PDO $db,array $cfg,array $u,int $version,array $b): array {
        if(($b['reviewed']??false)!==true)throw new InvalidArgumentException('Review the document and remove customer details, confidential data and unmarked prices before saving a reusable program.');
        $newFiles=[];
        try{return QuoteVs2Repository::atomic($db,function()use($db,$cfg,$u,$version,$b,&$newFiles){
            $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$version,QuoteVs2Repository::expected($b));
            QuoteVs2Repository::mutable($db,$v);Auth::requireQuoteRead($db,$u,$v);
            $ctx=QuoteProposal::context($db,$u,$v);$d=self::reusableDocument($ctx['settings']['document']??[]);
            $data=TourLibrary::normalize(['title'=>$b['title']??'','destination'=>$b['destination']??'','language'=>$b['language']??'en','status'=>'DRAFT','tags'=>[], 'days'=>[],
                'source_type'=>'MANUAL','source_name'=>'Word document','proposal'=>['tour_code'=>$b['tour_code']??'','tour_type'=>$b['tour_type']??'PRIVATE','duration_days'=>count($ctx['days']),'document'=>$d]]);
            $d['title']=$data['title'];
            $mapping=[];
            foreach(FreeformDocument::imageIds($d) as $id){
                $a=MediaLibrary::asset($db,$u,$id);if($a['status']==='ARCHIVED')throw new DomainException('Archived document image');
                if(!empty($a['quote_id'])){
                    if((int)$a['quote_id']!==(int)$v['quote_id'])throw new DomainException('Image belongs to another quote');
                    Auth::requirePermission($db,$u,'media.manage');Auth::requirePermission($db,$u,'media.view');
                    $path=MediaLibrary::path($cfg,$a);if(!hash_equals($a['content_sha256'],hash_file('sha256',$path)))throw new OutOfBoundsException('Media bytes changed');
                    // A reusable copy remains personal until the existing Media review workflow approves company access.
                    $copy=MediaLibrary::ingest($db,$cfg,$u,(string)file_get_contents($path),['title'=>$a['title'],'filename'=>$a['original_filename'],'visibility'=>'PERSONAL']);
                    $mapping[$id]=(int)$copy['asset']['id'];
                    if(!$copy['duplicate']){$saved=MediaLibrary::asset($db,$u,$mapping[$id]);foreach(['original_path','storage_path','thumbnail_path'] as $key)$newFiles[]=$saved[$key];}
                }
            }
            $walk=function(array $n)use(&$walk,$mapping):array{if($n['type']==='image'&&isset($mapping[$n['attrs']['assetId']]))$n['attrs']['assetId']=$mapping[$n['attrs']['assetId']];if(isset($n['content']))$n['content']=array_map($walk,$n['content']);return $n;};
            $d['content']=$walk($d['content']);$d=QuoteProposal::document($d);self::images($db,$u,$d);
            $data['proposal']['document']=$d;
            // Do not copy structured internal notes, guest names, travel dates or supplier inputs into a company master.
            $result=TourLibrary::save($db,$u,$data+['creation_key'=>$b['creation_key']??'']);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'TOUR_PROGRAM_SAVED_FROM_QUOTE','tour_library',(int)$result['id'],null,['quote_version_id'=>$version]);
            return $result;
        });}catch(Throwable $e){foreach($newFiles as $path)if(is_file($path))unlink($path);throw $e;}
    }
    public static function output(PDO $db,array $cfg,array $u,array $p,string $format): never {
        $ctx=self::context($db,$u,$p);$snapshot=['quote_ref'=>'PROGRAM-'.$p['id'],'tour_name'=>$p['title'],'presentation'=>['settings'=>$ctx['settings'],'media'=>$ctx['links']]];
        $path=function($id)use($db,$cfg,$u){$a=MediaLibrary::asset($db,$u,(int)$id);$p=MediaLibrary::path($cfg,$a);if(!hash_equals($a['content_sha256'],hash_file('sha256',$p)))throw new OutOfBoundsException('Media bytes changed');return $p;};
        ProposalOutput::respond($format,$snapshot,$cfg,fn($id)=>'index.php?route=media/'.$id.'/image',$path,true);
    }
}
