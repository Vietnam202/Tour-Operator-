<?php
declare(strict_types=1);
require_once __DIR__.'/QuoteSmartCosting.php';
require_once __DIR__.'/SmartCosting.php';
require_once __DIR__.'/Vs2Inclusions.php';
require_once __DIR__.'/Vs2Templates.php';
require_once __DIR__.'/Vs2RateTerms.php';
require_once __DIR__.'/Vs2Variants.php';
require_once __DIR__.'/Vs2LineEditor.php';
require_once __DIR__.'/QuoteVs2Validator.php';
require_once __DIR__.'/Vs2Snapshots.php';
require_once __DIR__.'/QuoteVs2Projection.php';

/** Extension of the existing dispatcher; no independent quote lifecycle. */
final class QuoteVs2 {
    public static function contextDto(PDO $db,array $u,array $v): array {
        $g=QuoteVs2Repository::graph($db,$v);$requirements=[];
        $cost=Auth::can($db,(int)$u['id'],'quote.view_cost');
        foreach($g['requirements'] as $r){$scope=QuoteVs2Repository::decode($r['scope_json']);$r['scope']=$cost?$scope:array_intersect_key($scope,array_flip(QuoteVs2Domain::OPERATIONAL_SCOPE));$r['metadata']=$cost?QuoteVs2Repository::decode($r['metadata_json']):[];unset($r['scope_json'],$r['metadata_json'],$r['updated_at'],$r['updated_by']);$requirements[]=$r;}
        $schedule=QuoteVs2Repository::decode($v['schedule_json']);if(!$cost)$schedule=QuoteVs2Projection::safeSchedule($schedule);
        return ['costing_engine'=>QuoteVs2Repository::engine($v),'costing_revision'=>(int)$v['costing_revision'],'version_id'=>(int)$v['id'],'version_status'=>$v['version_status'],'guests'=>array_intersect_key($v,array_flip(QuoteVs2Domain::BASE)),'profile'=>array_intersect_key($g['profile'],array_flip(QuoteVs2Domain::PROFILE)),'suggestions'=>QuoteVs2Repository::decode($g['profile']['review_metadata_json']??null)['suggestions']??[],'schedule'=>$schedule,'requirements'=>$requirements];
    }
    public static function optionsDto(PDO $db,array $u,array $v): array {
        Auth::requirePermission($db,$u,'quote.view_cost');$g=QuoteVs2Repository::graph($db,$v);$reqs=array_column($g['requirements'],null,'id');$profit=Auth::can($db,(int)$u['id'],'quote.view_profit');$variants=[];
        foreach($g['variants'] as $variant){$variant['option_id']=(int)$variant['quote_option_id'];$variant['variant_id']=(int)$variant['id'];$variant['pricing']=QuoteVs2Repository::decode($variant['pricing_result_json']);unset($variant['pricing_result_json']);
            if(!$profit)unset($variant['pricing']['profit_vnd'],$variant['pricing']['margin_pct'],$variant['pricing']['markup_pct'],$variant['pricing_value']);
            foreach($variant['lines'] as &$line){$req=$reqs[$line['requirement_id']];$line['category']=$req['category'];$line['service_name']=$req['service_name'];$line['service_date']=$req['service_date'];$line['trace']=QuoteVs2Repository::decode($line['calculation_trace_json']);$line['active']=QuoteVs2Domain::applies($req,$variant,$line);unset($line['calculation_trace_json']);}unset($line);$variants[]=$variant;
        }$sent=QuoteVs2Repository::q($db,'SELECT content_hash FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetchColumn();
        $suppliers=QuoteVs2Repository::q($db,"SELECT id,name FROM suppliers WHERE company_id=? AND status='ACTIVE' ORDER BY name",[$u['company_id']])->fetchAll();
        $rates=QuoteVs2Repository::q($db,"SELECT rv.id,r.product_name,r.category,r.destination,r.market,r.min_pax,r.max_pax,rv.amount,rv.currency,rv.valid_from,rv.valid_to,t.star_level,t.rate_eligibility_source FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id JOIN rate_version_vs2_terms t ON t.rate_version_id=rv.id JOIN suppliers s ON s.id=r.supplier_id AND s.company_id=r.company_id WHERE r.company_id=? AND r.status='ACTIVE' AND s.status='ACTIVE' AND rv.approval_status='APPROVED' AND t.approval_state='APPROVED' ORDER BY r.product_name,rv.id",[$u['company_id']])->fetchAll();
        return ['schema'=>'VS2_1','costing_revision'=>(int)$v['costing_revision'],'sent_content_hash'=>$sent?:null,'items'=>$variants,'supplier_choices'=>$suppliers,'rate_choices'=>$rates];
    }
    public static function mutate(PDO $db,array $u,int $version,array $body,callable $fn,string $event): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$version,$body,$fn,$event){$v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$version,QuoteVs2Repository::expected($body));QuoteVs2Repository::mutable($db,$v);if(QuoteVs2Repository::engine($v)!=='VS2_1')throw new DomainException('ACTIVATE_SMART_COSTING');$out=$fn($v);QuoteVs2Repository::finish($db,$v,$u,$event,$out);return $out+['costing_revision'=>(int)$v['costing_revision']+1];});
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        try{
            if(preg_match('#^rate-versions/(\d+)/vs2-terms$#',$route,$m)){
                if($method==='PUT'||$method==='POST')Http::json(['ok'=>true]+Vs2RateTerms::save($db,$u,(int)$m[1],Http::body()));
                if($method==='GET'){Auth::requirePermission($db,$u,'rate.view');$r=QuoteVs2Repository::q($db,'SELECT t.* FROM rate_version_vs2_terms t JOIN rate_versions rv ON rv.id=t.rate_version_id JOIN rates r ON r.id=rv.rate_id WHERE r.company_id=? AND rv.id=?',[$u['company_id'],(int)$m[1]])->fetch();if(!$r)throw new OutOfBoundsException();$r['inclusions']=QuoteVs2Repository::q($db,'SELECT * FROM rate_version_inclusions WHERE rate_version_id=? ORDER BY sort_order,id',[$m[1]])->fetchAll();Http::json(['ok'=>true,'terms'=>$r]);}
            }
            if(preg_match('#^quote-versions/(\d+)(?:/(smart-costing(?:/.*)?|options))?$#',$route,$m)){
                $id=(int)$m[1];$suffix=$m[2]??'';$v=QuoteOptions::version($db,(int)$u['company_id'],$id);$engine=QuoteVs2Repository::engine($v);
                if($suffix==='smart-costing/activate'&&$method==='POST'){Auth::requirePermission($db,$u,'quote.edit');Auth::requirePermission($db,$u,'quote.view_cost');Http::json(['ok'=>true]+QuoteSmartCosting::activate($db,$u,$id,Http::body()));}
                if($engine!=='VS2_1'){if(str_starts_with($suffix,'smart-costing'))throw new DomainException('ACTIVATE_SMART_COSTING');return;}
                if($method==='GET'){
                    Auth::requirePermission($db,$u,'sales.view');
                    if($suffix==='smart-costing/context')Http::json(['ok'=>true]+self::contextDto($db,$u,$v));
                    if($suffix==='options'||$suffix==='smart-costing')Http::json(['ok'=>true]+self::optionsDto($db,$u,$v));
                    if($suffix==='smart-costing/validation')Http::json(['ok'=>true]+QuoteVs2Validator::validate($db,$v));
                }
                if(in_array($method,['POST','PUT','DELETE'],true)){
                    Auth::requirePermission($db,$u,'quote.edit');$body=Http::body();
                    if($suffix===''||$suffix==='smart-costing/context')Http::json(['ok'=>true]+QuoteSmartCosting::context($db,$u,$id,$body));
                    Auth::requirePermission($db,$u,'quote.view_cost');
                    if($suffix==='options')$out=self::mutate($db,$u,$id,$body,fn($v)=>['variant_id'=>Vs2Variants::save($db,$u,$v,$body)],'VS21_VARIANT_SAVED');
                    elseif($suffix==='smart-costing/template')$out=self::mutate($db,$u,$id,$body,fn($v)=>['variant_ids'=>Vs2Variants::generate($db,$u,$v,$body)],'VS21_TEMPLATE_GENERATED');
                    elseif($suffix==='smart-costing/recalculate')$out=self::mutate($db,$u,$id,$body,function($v)use($db,$body){$ids=SmartCosting::refresh($db,$v,[],!isset($body['line_ids']),[],array_map('intval',$body['line_ids']??[]));Vs2Inclusions::apply($db,$v);return ['recalculated_line_ids'=>$ids];},'VS21_RECALCULATED');
                    elseif(preg_match('#^smart-costing/variants/(\d+)/lines(?:/(\d+)(?:/(review|duplicate|reorder))?)?$#',$suffix,$parts)){
                        $vid=(int)$parts[1];$lid=(int)($parts[2]??0);$action=$method==='DELETE'?'delete':($parts[3]??'save');
                        $out=self::mutate($db,$u,$id,$body,function($v)use($db,$u,$vid,$lid,$body,$action){$out=$action==='review'?Vs2LineEditor::review($db,$u,$v,$vid,$lid,$body):Vs2LineEditor::write($db,$u,$v,$vid,$body,$action,$lid);$g=QuoteVs2Repository::graph($db,$v);SmartCosting::aggregate($db,$v,QuoteVs2Repository::variant($db,(int)$v['id'],$vid),array_column($g['requirements'],null,'id'),QuoteVs2Domain::guests($v,$g['profile']));return $out;},'VS21_LINE_'.strtoupper($action));
                    }else throw new InvalidArgumentException('Unknown smart-costing command');
                    Http::json(['ok'=>true]+$out);
                }
            }
            if($method==='POST'&&preg_match('#^quotes/(\d+)/(approve|send|confirm|create-booking)$#',$route,$m)){
                $version=QuoteVs2Repository::q($db,"SELECT v.id FROM quotes q JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=CASE WHEN ?='create-booking' THEN q.confirmed_version_no ELSE q.current_version_no END WHERE q.company_id=? AND q.id=?",[$m[2],$u['company_id'],(int)$m[1]])->fetchColumn();if(!$version)return;
                $v=QuoteOptions::version($db,(int)$u['company_id'],(int)$version);if(QuoteVs2Repository::engine($v)!=='VS2_1')return;
                $action=$m[2];Auth::requirePermission($db,$u,['approve'=>'quote.approve','send'=>'quote.send','confirm'=>'quote.confirm','create-booking'=>'booking.manage'][$action]);if($action==='approve')Auth::requirePermission($db,$u,'quote.view_cost');$b=Http::body();$expected=QuoteVs2Repository::expected($b);
                $out=match($action){'approve'=>QuoteOptions::approve($db,$u,(int)$version,(string)($b['reason']??''),$expected),'send'=>QuoteOptions::send($db,$u,(int)$version,$expected),'confirm'=>QuoteOptions::confirm($db,$u,(int)$version,(int)($b['option_id']??0),(int)($b['variant_id']??0),(string)($b['sent_content_hash']??''),$expected,$b),'create-booking'=>QuoteOptions::booking($db,$u,(int)$version,$expected)};Http::json(['ok'=>true]+$out);
            }
        }catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(InvalidArgumentException|OverflowException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>strtok($e->getMessage(),':'),'message'=>$e->getMessage()],409);}
    }
}
