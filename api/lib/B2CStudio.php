<?php
declare(strict_types=1);
require_once __DIR__.'/TourLibrary.php';

/** Independent B2C editorial revisions. Master and B2B records are never updated. */
final class B2CStudio {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {return MediaLibrary::q($db,$sql,$args);}
    public static function row(PDO $db,array $u,int $id,bool $lock=false): array {
        $r=self::q($db,'SELECT * FROM b2c_programs WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$u['company_id'],$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new OutOfBoundsException('B2C programme not found');return $r;
    }
    public static function content(array $r): array {return json_decode($r['content_json'],true,512,JSON_THROW_ON_ERROR);}
    public static function projection(array $r): array {
        $c=self::content($r);return ['id'=>(int)$r['id'],'master_program_id'=>(int)$r['master_program_id'],'title'=>$c['title'],'destination'=>$c['destination'],'language'=>$c['language'],'revision'=>(int)$r['revision'],'status'=>$r['status'],'approved_revision'=>$r['approved_revision']===null?null:(int)$r['approved_revision'],'updated_at'=>$r['updated_at']];
    }
    private static function expected(array $r,array $b): void {
        if(!is_int($b['expected_revision']??null)||(int)$r['revision']!==$b['expected_revision'])throw new DomainException('STALE_REVISION: reopen the B2C programme');
    }
    private static function history(PDO $db,array $u,int $id,int $revision,array $c): void {
        self::q($db,'INSERT INTO b2c_revisions(program_id,revision,content_json,actor_id) VALUES(?,?,?,?)',[$id,$revision,MediaLibrary::json($c),$u['id']]);
    }
    public static function create(PDO $db,array $u,array $b): array {
        Auth::requirePermission($db,$u,'b2c.edit');Auth::requirePermission($db,$u,'tour_library.view');
        $id=filter_var($b['master_program_id']??null,FILTER_VALIDATE_INT);$key=$b['creation_key']??'';
        if(!$id||!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Choose a master and a creation key');
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$key){
            // Serialize creation retries within the company; a lost response must not duplicate a working copy.
            self::q($db,'SELECT id FROM companies WHERE id=? FOR UPDATE',[$u['company_id']]);
            $prior=self::q($db,'SELECT * FROM b2c_programs WHERE company_id=? AND creation_key=?',[$u['company_id'],$key])->fetch(PDO::FETCH_ASSOC);
            if($prior){if((int)$prior['master_program_id']!==$id||(int)$prior['created_by']!==(int)$u['id'])throw new DomainException('CREATION_KEY_REUSED');return self::projection($prior);}
            $p=TourProgramWorkspace::program($db,$u,(int)$id,true);if($p['status']==='ARCHIVED')throw new DomainException('Master is archived');
            $c=['title'=>$p['title'],'destination'=>$p['destination'],'language'=>$p['language'],'document'=>TourProgramWorkspace::document($p)];
            TourProgramWorkspace::images($db,$u,$c['document']);
            self::q($db,'INSERT INTO b2c_programs(company_id,master_program_id,content_json,creation_key,created_by,updated_by) VALUES(?,?,?,?,?,?)',[$u['company_id'],$id,MediaLibrary::json($c),$key,$u['id'],$u['id']]);$new=(int)$db->lastInsertId();self::history($db,$u,$new,1,$c);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'B2C_CREATED','b2c_program',$new,null,['master_program_id'=>$id]);return self::projection(self::row($db,$u,$new));
        });
    }
    public static function context(PDO $db,array $u,array $r): array {
        $c=self::content($r);return ['program'=>self::projection($r),'settings'=>['document'=>$c['document']],'links'=>TourProgramWorkspace::images($db,$u,$c['document']),'days'=>[],'selling_options'=>[],'immutable'=>false,'costing_revision'=>(int)$r['revision']];
    }
    private static function event(PDO $db,array $u,array $r,string $status,array $snapshot=[]): int {
        $json=MediaLibrary::json($snapshot);
        self::q($db,'INSERT INTO b2c_publication_events(company_id,program_id,revision,status,snapshot_json,snapshot_hash) VALUES(?,?,?,?,?,?)',[$u['company_id'],$r['id'],$r['revision'],$status,$json,hash('sha256',$json)]);return (int)$db->lastInsertId();
    }
    public static function save(PDO $db,array $u,int $id,array $b): array {
        Auth::requirePermission($db,$u,'b2c.edit');return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$b){
            $r=self::row($db,$u,$id,true);self::expected($r,$b);$c=self::content($r);
            $d=TourProgramWorkspace::reusableDocument($b['settings']['document']??[]);TourProgramWorkspace::images($db,$u,$d);$c['document']=$d;$c['title']=$d['title'];
            if($c['title']==='')throw new InvalidArgumentException('Title is required');
            foreach(['destination','language'] as $k)if(isset($b['metadata'][$k]))$c[$k]=MediaLibrary::text($b['metadata'][$k],190);
            if(!preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})?$/D',$c['language']))throw new InvalidArgumentException('Use a language code such as vi or en');
            // Withdrawal precedes the new draft. Existing immutable events/quote/booking snapshots survive.
            if($r['status']==='PUBLISHED')self::event($db,$u,$r,'WITHDRAWN');
            self::q($db,"UPDATE b2c_programs SET content_json=?,revision=revision+1,status='DRAFT',approved_revision=NULL,approved_by=NULL,updated_by=? WHERE company_id=? AND id=?",[MediaLibrary::json($c),$u['id'],$u['company_id'],$id]);
            self::history($db,$u,$id,(int)$r['revision']+1,$c);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'B2C_REVISION_CREATED','b2c_program',$id,null,['revision'=>(int)$r['revision']+1]);return self::context($db,$u,self::row($db,$u,$id));
        });
    }
    public static function transition(PDO $db,array $u,int $id,string $action,array $b): array {
        Auth::requirePermission($db,$u,$action==='review'?'b2c.edit':'b2c.approve');return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$action,$b){
            $r=self::row($db,$u,$id,true);self::expected($r,$b);$status=$r['status'];
            if($action==='review'){
                if(!in_array($status,['DRAFT','REVIEW'],true)||!TourProgramWorkspace::hasContent(self::content($r)['document']))throw new DomainException('Review requires a nonempty draft');$next='REVIEW';
            }elseif($action==='approve'){
                if(!in_array($status,['REVIEW','APPROVED'],true)||($b['public_content_reviewed']??false)!==true)throw new DomainException('Review public content and remove private data before approval');
                self::publicSnapshot($db,$u,$r);$next='APPROVED';
                self::q($db,'UPDATE b2c_programs SET approved_revision=revision,approved_by=? WHERE company_id=? AND id=?',[$u['id'],$u['company_id'],$id]);
            }elseif($action==='publish'){
                if($status==='PUBLISHED'&&(int)$r['approved_revision']===(int)$r['revision'])return self::projection($r);
                if($status!=='APPROVED'||(int)$r['approved_revision']!==(int)$r['revision'])throw new DomainException('Current revision is not approved');
                self::event($db,$u,$r,'PUBLISHED',self::publicSnapshot($db,$u,$r));$next='PUBLISHED';
            }elseif($action==='withdraw'){
                if($status==='WITHDRAWN')return self::projection($r);
                if($status==='PUBLISHED')self::event($db,$u,$r,'WITHDRAWN');$next='WITHDRAWN';
                self::q($db,'UPDATE b2c_programs SET approved_revision=NULL,approved_by=NULL WHERE company_id=? AND id=?',[$u['company_id'],$id]);
            }else throw new InvalidArgumentException('Invalid B2C action');
            self::q($db,'UPDATE b2c_programs SET status=?,updated_by=? WHERE company_id=? AND id=?',[$next,$u['id'],$u['company_id'],$id]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'B2C_'.strtoupper($action),'b2c_program',$id,['status'=>$status],['status'=>$next,'revision'=>(int)$r['revision']]);return self::projection(self::row($db,$u,$id));
        });
    }
    public static function apply(PDO $db,array $u,int $id,array $b): array {
        Auth::requirePermission($db,$u,'b2c.view');Auth::requirePermission($db,$u,'quote.edit');Auth::requirePermission($db,$u,'proposal.edit');
        $version=filter_var($b['version_id']??null,FILTER_VALIDATE_INT);if(!$version)throw new InvalidArgumentException('Choose a draft quotation');
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$b,$version){
            $r=self::row($db,$u,$id,true);if(!is_int($b['program_revision']??null)||(int)$r['revision']!==$b['program_revision'])throw new DomainException('B2C programme changed; reload');
            $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],(int)$version,QuoteVs2Repository::expected($b));QuoteVs2Repository::mutable($db,$v);Auth::requireQuoteRead($db,$u,$v);
            $c=self::content($r);$document=TourProgramWorkspace::reusableDocument($c['document']);$links=TourProgramWorkspace::images($db,$u,$document);$ctx=QuoteProposal::context($db,$u,$v);$settings=$ctx['settings'];
            $settings['document']=$document;$settings['template']='VTA_EXPLORER_B2C';$settings['channel']='B2C';$settings['brand_mode']='VTA';
            $keep=array_values(array_filter($ctx['links'],fn($l)=>$l['reference_key']!=='FREEFORM'));
            $result=QuoteProposal::save($db,$u,(int)$version,['expected_revision'=>$b['expected_revision'],'settings'=>$settings,'links'=>array_merge($keep,$links)]);
            self::q($db,'INSERT INTO b2c_quote_sources(company_id,quote_version_id,program_id,program_revision,snapshot_hash) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE program_id=VALUES(program_id),program_revision=VALUES(program_revision),snapshot_hash=VALUES(snapshot_hash)',[$u['company_id'],$version,$id,$r['revision'],hash('sha256',MediaLibrary::json($document))]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'B2C_APPLIED_TO_QUOTE','quote_version',(int)$version,null,['program_id'=>$id,'program_revision'=>(int)$r['revision']]);return $result;
        });
    }
    public static function publicSnapshot(PDO $db,array $u,array $r): array {
        $c=self::content($r);$d=TourProgramWorkspace::reusableDocument($c['document']);
        foreach(FreeformDocument::imageIds($d) as $id){$a=MediaLibrary::asset($db,$u,$id);if($a['status']!=='APPROVED'||$a['visibility']==='PERSONAL'||!empty($a['quote_id']))throw new DomainException('Public images require approved reusable company media');}
        // No master metadata, customer, agency, costing, supplier, NET, markup or draft price fields.
        return ['schema'=>'VTA_B2C_PUBLICATION_V1','title'=>$c['title'],'destination'=>$c['destination'],'language'=>$c['language'],'document'=>$d,'pricing'=>'REQUEST_QUOTE'];
    }
    public static function publication(PDO $db,int $company,int $event): array {
        $r=self::q($db,"SELECT e.* FROM b2c_publication_events e JOIN b2c_programs p ON p.company_id=e.company_id AND p.id=e.program_id WHERE e.company_id=? AND e.id=? AND e.status='PUBLISHED' AND p.status='PUBLISHED' AND p.approved_revision=p.revision AND e.revision=p.revision AND e.id=(SELECT MAX(x.id) FROM b2c_publication_events x WHERE x.company_id=e.company_id AND x.program_id=e.program_id)",[$company,$event])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new OutOfBoundsException('Publication is withdrawn or stale');return $r;
    }
    public static function handle(string $route,string $method,PDO $db,array $cfg,array $u): void {
        if($route!=='b2c'&&!str_starts_with($route,'b2c/'))return;
        try{
            Auth::requirePermission($db,$u,'b2c.view');
            if($route==='b2c'&&$method==='GET'){$rows=self::q($db,'SELECT * FROM b2c_programs WHERE company_id=? ORDER BY updated_at DESC,id DESC LIMIT 500',[$u['company_id']])->fetchAll(PDO::FETCH_ASSOC);Http::json(['ok'=>true,'items'=>array_map([self::class,'projection'],$rows)]);}
            if($route==='b2c'&&$method==='POST')Http::json(['ok'=>true,'program'=>self::create($db,$u,Http::body())],201);
            if(!preg_match('#^b2c/(\d+)(?:/(document|review|approve|publish|withdraw|apply-to-document)(?:/(html|docx|pdf|document-import))?)?$#D',$route,$m))Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
            $id=(int)$m[1];$action=$m[2]??'';$format=$m[3]??'';if($action==='apply-to-document'&&$method==='POST')Http::json(['ok'=>true]+self::apply($db,$u,$id,Http::body()));$r=self::row($db,$u,$id);
            if($action==='document'&&$format==='document-import'&&$method==='POST'){
                Auth::requirePermission($db,$u,'b2c.edit');Http::json(['ok'=>true]+FreeformDocument::upload($_FILES['file']??[]));
            }
            if($action==='document'&&$format!==''&&$method==='GET'){
                $ctx=self::context($db,$u,$r);$snapshot=['quote_ref'=>'B2C-'.$id,'tour_name'=>self::content($r)['title'],'presentation'=>['settings'=>$ctx['settings'],'media'=>$ctx['links']]];
                ProposalOutput::respond($format,$snapshot,$cfg,fn($asset)=>'index.php?route=media/'.$asset.'/image',function($asset)use($db,$cfg,$u){$a=MediaLibrary::asset($db,$u,(int)$asset);$p=MediaLibrary::path($cfg,$a);if(!hash_equals($a['content_sha256'],hash_file('sha256',$p)))throw new OutOfBoundsException('Media changed');return $p;},true);
            }
            if($method==='GET')Http::json(['ok'=>true]+self::context($db,$u,$r));
            if($action==='document'&&$method==='PUT')Http::json(['ok'=>true]+self::save($db,$u,$id,Http::body()));
            if(in_array($action,['review','approve','publish','withdraw'],true)&&$method==='POST')Http::json(['ok'=>true,'program'=>self::transition($db,$u,$id,$action,Http::body())]);
            Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(Throwable $e){Http::json(['ok'=>false,'error'=>'B2C_UNAVAILABLE'],503);}
    }
}
