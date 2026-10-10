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
    /** Spreadsheet commands compose existing writers; arithmetic and lifecycle stay in VS2.1. */
    public static function sheet(PDO $db,array $u,array $v,array $b): array {
        Auth::requirePermission($db,$u,'quote.edit');Auth::requirePermission($db,$u,'quote.view_cost');
        $action=$b['action']??'row';$ids=array_map('intval',$b['variant_ids']??[]);
        if($action==='init'){
            $mode=$b['mode']??'PRIVATE';$ids=Vs2Variants::generate($db,$u,$v,['modes'=>[$mode]]);
            return ['variant_ids'=>$ids];
        }
        if(!$ids||count($ids)>3||count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Select one to three existing variants');
        $variants=[];foreach($ids as $id)$variants[$id]=QuoteVs2Repository::variant($db,(int)$v['id'],$id);
        if($action==='variant'){
            $allowed=['is_offered','pricing_mode','pricing_value','rounding_step','selling_currency','cruise_level','label'];$changes=$b['changes']??[];
            if(array_diff(array_keys($changes),$allowed))throw new InvalidArgumentException('Unsupported variant setting');
            if(array_intersect(array_keys($changes),['pricing_mode','pricing_value']))Auth::requirePermission($db,$u,'quote.view_profit');
            foreach($ids as $id)Vs2Variants::save($db,$u,$v,['variant_id'=>$id]+$changes);return ['variant_ids'=>$ids];
        }
        // New package mix: select Hotel and Cruise stars independently without copying the quote.
        // Every copied supplier cost is marked for review, and totals are still resolved server-side.
        if($action==='mix'){
            $hotel=(int)($b['hotel_level']??0);
            $cruise=($b['cruise_level']??'')===''?null:(int)$b['cruise_level'];
            $mode=$b['mode']??$variants[$ids[0]]['costing_mode'];
            if(!in_array($hotel,[3,4,5],true)||($cruise!==null&&!in_array($cruise,[3,4,5],true))||
                !in_array($mode,['PRIVATE','SIC','HYBRID'],true))throw new InvalidArgumentException('Invalid hotel or cruise category');
            $graph=QuoteVs2Repository::graph($db,$v);
            foreach($graph['variants'] as $found){
                if($found['costing_mode']===$mode&&(int)$found['hotel_level']===$hotel&&
                    ($found['cruise_level']===null?null:(int)$found['cruise_level'])===$cruise){
                    return ['variant_id'=>(int)$found['id'],'reused'=>true];
                }
            }
            $newId=Vs2Variants::save($db,$u,$v,[
                'hotel_level'=>$hotel.'*','cruise_level'=>$cruise,'costing_mode'=>$mode,
                'label'=>'Hotel '.$hotel.' star + Cruise '.($cruise===null?'none':$cruise.' star'),
                'is_offered'=>0
            ]);
            $newLines=QuoteVs2Repository::q($db,"SELECT id,requirement_id FROM quote_variant_cost_lines WHERE variant_id=? AND line_kind='SERVICE'",[$newId])->fetchAll();
            foreach($newLines as $target){
                $requirement=null;
                foreach($graph['requirements'] as $candidate)if((int)$candidate['id']===(int)$target['requirement_id']){
                    $requirement=$candidate;break;
                }
                if(!$requirement||$requirement['requirement_state']==='NOT_APPLICABLE')continue;
                // Rate reuse is allowed only when every eligible donor agrees on the supplier,
                // per-service basis and amount. A legacy disagreement must remain unresolved,
                // never silently take the first option's rate.
                $donors=[];
                foreach($graph['variants'] as $source){
                    if($source['costing_mode']!==$mode)continue;
                    if($requirement['category']==='HOTEL'&&(int)$source['hotel_level']!==$hotel)continue;
                    if($requirement['category']==='CRUISE'&&
                        ($source['cruise_level']===null?null:(int)$source['cruise_level'])!==$cruise)continue;
                    foreach($source['lines'] as $candidate){
                        if((int)$candidate['requirement_id']!==(int)$target['requirement_id']||
                            $candidate['line_kind']!=='SERVICE'||
                            ($candidate['rate_version_id']===null&&$candidate['unit_amount_original']===null))continue;
                        $identity=[
                            'rate_version_id'=>$candidate['rate_version_id'],
                            'amount'=>$candidate['unit_amount_original'],
                            'currency'=>$candidate['original_currency'],
                            'supplier'=>$candidate['supplier_id'],
                            'formula'=>$candidate['formula_code'],
                            'quantity_source'=>$candidate['quantity_source'],
                            'custom_quantity'=>$candidate['custom_quantity'],
                            'quantity_override'=>$candidate['quantity_override'],
                            'units_override'=>$candidate['units_override'],
                            'service_mode'=>$candidate['service_mode']
                        ];
                        $donors[QuoteVs2Domain::hash($identity)]=$candidate;
                    }
                }
                if(count($donors)!==1)continue; // Missing / contradictory rates must be reviewed.
                $donor=reset($donors);
                $entry=[
                    'quantity_source'=>$donor['quantity_source'],
                    'quantity_override'=>$donor['quantity_override'],
                    'custom_quantity'=>$donor['custom_quantity'],
                    'units_override'=>$donor['units_override'],
                    'override_reason'=>$donor['override_reason'],
                    'supplier_id'=>$donor['supplier_id'],
                    'original_currency'=>$donor['original_currency'],
                    'manual_reason'=>$donor['manual_reason'],
                    'manual_contract'=>QuoteVs2Repository::decode($donor['manual_contract_json']),
                    'service_mode'=>$donor['service_mode']
                ];
                if($donor['rate_version_id']!==null)$entry['rate_version_id']=$donor['rate_version_id'];
                else $entry['unit_amount_original']=$donor['unit_amount_original'];
                Vs2LineEditor::write($db,$u,$v,$newId,$entry,'save',(int)$target['id']);
            }
            SmartCosting::refresh($db,$v);
            Vs2Inclusions::apply($db,$v);
            return ['variant_id'=>$newId,'reused'=>false,'requires_review'=>true];
        }
        $input=$b['requirement']??[];$reqId=(int)($input['id']??$b['requirement_id']??0);
        if($action==='remove'){
            $dependents=QuoteVs2Repository::q($db,'SELECT id FROM quote_service_requirements WHERE quote_version_id=? AND package_requirement_id=? AND requirement_state<>?',[$v['id'],$reqId,'NOT_APPLICABLE'])->fetchColumn();
            if($dependents)throw new DomainException('SERVICE_HAS_DEPENDENTS');
            QuoteVs2Domain::text($b['reason']??'');
            QuoteSmartCosting::requirement($db,$u,(int)$v['id'],['id'=>$reqId,'requirement_state'=>'NOT_APPLICABLE','metadata'=>['reason'=>$b['reason']]]);
            SmartCosting::refresh($db,$v);Vs2Inclusions::apply($db,$v);return ['requirement_id'=>$reqId,'removed_from_sheet'=>true];
        }
        if(!in_array($action,['row','review'],true))throw new InvalidArgumentException('Unknown sheet command');
        if($input)$reqId=QuoteSmartCosting::requirement($db,$u,(int)$v['id'],$input);
        $req=QuoteVs2Repository::q($db,'SELECT * FROM quote_service_requirements WHERE quote_version_id=? AND id=?',[$v['id'],$reqId])->fetch();if(!$req)throw new OutOfBoundsException('Requirement not found');
        $lineIds=[];$warnings=[];
        foreach($variants as $id=>$variant){
            Vs2Variants::lines($db,$u,$v,$id);
            $line=QuoteVs2Repository::q($db,"SELECT * FROM quote_variant_cost_lines WHERE variant_id=? AND requirement_id=? AND line_kind='SERVICE' ORDER BY id LIMIT 1",[$id,$reqId])->fetch();
            if($action==='review'){Vs2LineEditor::review($db,$u,$v,$id,(int)$line['id'],['review_reason'=>$b['review_reason']??'']);$lineIds[]=(int)$line['id'];continue;}
            $edit=($b['shared']??true)?($b['line']??[]):($b['lines'][(string)$id]??[]);
            if(!is_array($edit))throw new InvalidArgumentException('Structured line input required');
            // A manual entry must explicitly detach an approved rate; never alter its price.
            if(array_key_exists('unit_amount_original',$edit)){
                $edit['rate_version_id']=null;
                $contract=$edit['manual_contract']??QuoteVs2Repository::decode($line['manual_contract_json']);
                $contract['formula_code']=$line['formula_code'];
                if($req['category']==='HOTEL')$contract['star_level']=(int)$variant['hotel_level'];
                if($req['category']==='CRUISE')$contract['star_level']=$variant['cruise_level'];
                $edit['manual_contract']=$contract;
            }
            if(!$line['rate_version_id']&&$line['unit_amount_original']===null&&!array_key_exists('unit_amount_original',$edit)){
                $candidateLine=array_replace($line,$edit);$g=QuoteVs2Repository::graph($db,$v);$guests=QuoteVs2Domain::guests($v,$g['profile']);$valid=[];$conflict=false;
                $candidates=QuoteVs2Repository::q($db,"SELECT t.rate_version_id,t.formula_code,t.star_level,t.rate_eligibility_source FROM rate_version_vs2_terms t JOIN rate_versions rv ON rv.id=t.rate_version_id JOIN rates r ON r.id=rv.rate_id WHERE r.company_id=? AND r.category=? AND rv.approval_status='APPROVED' AND t.approval_state='APPROVED' ORDER BY t.rate_version_id LIMIT 201",[$v['company_id'],$req['category']])->fetchAll();
                if(count($candidates)>200)$conflict=true;
                else foreach($candidates as $c){
                    if($c['formula_code']!==$candidateLine['formula_code']||($req['category']==='HOTEL'&&(int)$c['star_level']!==(int)$variant['hotel_level'])||($req['category']==='CRUISE'&&(int)$c['star_level']!==(int)$variant['cruise_level']))continue;
                    try{Vs2RateResolver::resolve($db,$v,$guests,$req,$variant,array_replace($candidateLine,['rate_version_id'=>$c['rate_version_id']]));$valid[]=(int)$c['rate_version_id'];}
                    catch(DomainException $e){
                        if(str_contains($e->getMessage(),'CONFLICT'))$conflict=true;
                        // Expired, unrelated or blacked-out contracts do not make a valid match ambiguous.
                        if($e->getMessage()==='RATE_UNAVAILABLE'){$scope=QuoteVs2Repository::decode($req['scope_json']);$pax=$guests[strtolower($c['rate_eligibility_source'])]??0;$date=($scope['dates']??[$req['service_date']])[0]??null;if($pax&&$date)foreach(RateEngine::match($db,(int)$v['company_id'],['category'=>$req['category'],'destination'=>QuoteVs2Domain::destination($scope),'travel_date'=>$date,'market'=>$v['market']??'','pax'=>$pax,'trip_ref'=>$v['trip_ref']??'']) as $match)if((int)$match['rate_version_id']===(int)$c['rate_version_id']&&$match['conflict'])$conflict=true;}
                    }
                }
                if(count($valid)===1&&!$conflict)$edit['rate_version_id']=$valid[0];
                else $warnings[]=['variant_id'=>$id,'line_id'=>(int)$line['id'],'code'=>count($valid)>1||$conflict?'RATE_CONFLICT':'RATE_NEEDED'];
            }
            $out=Vs2LineEditor::write($db,$u,$v,$id,$edit,'save',(int)$line['id']);$lineIds[]=$out['line_id'];
        }
        // Requirement changes affect every bound variant, including variants outside the visible sheet.
        SmartCosting::refresh($db,$v,[],false,$input?[['id'=>$reqId]]:[],[]);Vs2Inclusions::apply($db,$v);
        foreach($warnings as $warning)if($warning['code']==='RATE_CONFLICT')QuoteVs2Repository::q($db,"UPDATE quote_variant_cost_lines SET rate_status='RATE_CONFLICT' WHERE id=? AND coverage_state='UNRESOLVED'",[$warning['line_id']]);
        return ['requirement_id'=>$reqId,'line_ids'=>$lineIds,'warnings'=>$warnings];
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
                    if($suffix==='smart-costing/sheet')$out=self::mutate($db,$u,$id,$body,fn($v)=>self::sheet($db,$u,$v,$body),'VS21_SHEET_UPDATED');
                    elseif($suffix==='options')$out=self::mutate($db,$u,$id,$body,fn($v)=>['variant_id'=>Vs2Variants::save($db,$u,$v,$body)],'VS21_VARIANT_SAVED');
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
