<?php
declare(strict_types=1);
require_once __DIR__.'/CommercialPolicy.php';
require_once __DIR__.'/PaxScenario.php';

/** Children of the existing version. No writes to supplier costs during projection. */
final class PriceMatrix {
    private static function q(PDO $db,string $s,array $a=[]): PDOStatement {return QuoteVs2Repository::q($db,$s,$a);}
    public static function installed(PDO $db): bool {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?(bool)self::q($db,"SELECT 1 FROM sqlite_master WHERE type='table' AND name='quote_price_matrices'")->fetchColumn():(bool)self::q($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quote_price_matrices'")->fetchColumn();
    }
    public static function row(PDO $db,array $v): ?array {if(!self::installed($db))return null;return self::q($db,'SELECT * FROM quote_price_matrices WHERE quote_version_id=?',[$v['id']])->fetch()?:null;}
    public static function cells(PDO $db,array $m): array {return self::q($db,'SELECT * FROM quote_price_matrix_cells WHERE matrix_id=? AND generation=? ORDER BY channel,min_pax,variant_id',[$m['id'],$m['generation']])->fetchAll();}
    public static function cell(PDO $db,array $m,int $id): array {foreach(self::cells($db,$m) as $c)if((int)$c['id']===$id)return $c;throw new OutOfBoundsException('Current matrix cell not found');}
    private static function stable(array $r): array {foreach(['created_at','updated_at','updated_by','calculated_at'] as $k)unset($r[$k]);return $r;}
    public static function inputs(PDO $db,array $v,array $config): array {
        $g=QuoteVs2Repository::graph($db,$v);$keep=array_flip(['id','quote_id','company_id','trip_id','inquiry_id','quote_ref','version_no','tour_name','start_date','end_date','document_language','schedule_json','included_text','excluded_text','terms_text','fx_rate','market','trip_ref','lead_contact_name','lead_whatsapp','lead_email','sales_owner_id','operations_owner_id',...QuoteVs2Domain::BASE]);$g['version']=array_intersect_key($v,$keep);
        $g['profile']=self::stable($g['profile']);$g['requirements']=array_map([self::class,'stable'],$g['requirements']);
        $g['variants']=array_values(array_filter($g['variants'],fn($x)=>in_array((int)$x['id'],$config['variant_ids'],true)));
        $rates=[];$guests=QuoteVs2Domain::guests($v,$g['profile']);$reqs=array_column($g['requirements'],null,'id');
        foreach($g['variants'] as &$x){$x=self::stable($x);$x['lines']=array_map([self::class,'stable'],$x['lines']);foreach($x['lines'] as $l)if($l['line_kind']==='SERVICE'&&$l['coverage_state']==='PRICED'&&QuoteVs2Domain::applies($reqs[$l['requirement_id']],$x,$l)){$r=Vs2RateResolver::resolve($db,$v,$guests,$reqs[$l['requirement_id']],$x,$l);$rates[$l['id']]=$r['source_hash'];}}unset($x);
        $policies=[];foreach($config['policies'] as $channel=>$id)$policies[$channel]=self::stable(CommercialPolicy::get($db,(int)$v['company_id'],$id));
        $party=self::q($db,'SELECT customer_id,agent_id FROM trips WHERE company_id=? AND id=?',[$v['company_id'],$v['trip_id']])->fetch();$parties=['customer'=>null,'agent'=>null];
        if($party['customer_id'])$parties['customer']=self::q($db,'SELECT id,customer_ref,full_name,company_name,market,email,whatsapp FROM customers WHERE company_id=? AND id=?',[$v['company_id'],$party['customer_id']])->fetch()?:null;
        if($party['agent_id'])$parties['agent']=self::q($db,'SELECT id,agent_ref,company_name,primary_contact,market,email,whatsapp FROM agents WHERE company_id=? AND id=?',[$v['company_id'],$party['agent_id']])->fetch()?:null;
        return ['graph'=>$g,'parties'=>$parties,'policies'=>$policies,'rate_hashes'=>$rates,'config'=>$config];
    }
    public static function digest(array $s): array {
        return ['guests'=>$s['guests'],'cost_total_vnd'=>$s['cost_total_vnd'],'rate_sources'=>$s['rate_sources'],'manual_override_review'=>$s['manual_override_review'],'trace'=>array_map(fn($l)=>array_intersect_key($l,array_flip(['id','formula_code','quantity_source','quantity_override','custom_quantity','resolved_quantity','resolved_units','total_vnd','coverage_state','calculation_trace_json'])),$s['cost_lines'])];
    }
    private static function config(array $b,array $v,array $g): array {
        $method=$b['method']??'SAFE_PRICE';$bands=PaxScenario::bands($b['bands']??PaxScenario::DEFAULT_BANDS,$method);
        $ids=$b['variant_ids']??array_map(fn($x)=>(int)$x['id'],array_filter($g['variants'],fn($x)=>$x['is_offered']));
        if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>12||count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Choose 1–12 offered variants');$ids=array_map(fn($x)=>QuoteVs2Domain::count($x),$ids);
        $available=array_map(fn($x)=>(int)$x['id'],array_filter($g['variants'],fn($x)=>$x['is_offered']));if(array_diff($ids,$available))throw new DomainException('VARIANT_NOT_REVIEWED_AND_OFFERED');
        $scenarioCount=$method==='SAFE_PRICE'?array_sum(array_map(fn($x)=>$x['max']-$x['min']+1,$bands)):count($bands);if($scenarioCount*count($ids)>500)throw new InvalidArgumentException('Use at most 500 variant/pax scenarios per generation');
        $policies=$b['policies']??[];if(!is_array($policies)||!$policies||array_diff(array_keys($policies),['B2B_AGENT','B2C_DIRECT']))throw new InvalidArgumentException('Choose reviewed B2B/B2C policies');foreach($policies as &$id)$id=QuoteVs2Domain::count($id);unset($id);
        $fx=$b['fx_rate']??(string)$v['fx_rate'];if(Vs2Decimal::parse($fx,6)<1)throw new InvalidArgumentException('Positive FX required');$fx=Vs2Decimal::format(Vs2Decimal::parse($fx,6),6);
        $r=['method'=>$method,'bands'=>$bands,'variant_ids'=>$ids,'policies'=>$policies,'fx_rate'=>$fx];
        foreach(['quantity_rules','scenario_profiles','resource_counts','override_reviews'] as $k){$value=$b[$k]??[];if(!is_array($value)||strlen(QuoteVs2Repository::json($value))>120000)throw new InvalidArgumentException('Invalid scenario configuration');$r[$k]=$value;}
        $group=GroupVehiclePricing::normalize($b['vehicle_bands']??[],$b['transport_rate_versions']??[],$bands,$g);
        $r['vehicle_bands']=$group['vehicles'];
        $r['transport_rate_versions']=$group['rates'];
        return $r;
    }
    public static function generate(PDO $db,array $u,int $version,array $b): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$version,$b){
            $v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$version,QuoteVs2Repository::expected($b));QuoteVs2Repository::mutable($db,$v);if(QuoteVs2Repository::engine($v)!=='VS2_1')throw new DomainException('SMART_COSTING_REQUIRED');
            $old=self::row($db,$v);if($old&&in_array($old['status'],['LOCKED','SENT'],true))throw new DomainException('LOCKED_MATRIX: explicitly unlock the draft or create a quote revision');
            $base=QuoteVs2Validator::validate($db,$v,true);$errors=array_filter($base['errors'],fn($e)=>$e['code']!=='MINIMUM_MARGIN');if($errors)throw new DomainException('COST_REVIEW_REQUIRED: '.implode(', ',array_unique(array_column($errors,'code'))));
            $g=QuoteVs2Repository::graph($db,$v);$config=self::config($b,$v,$g);$inputs=self::inputs($db,$v,$config);foreach($inputs['policies'] as $channel=>$p)if($p['channel']!==$channel)throw new InvalidArgumentException('Policy channel mismatch');
            $fx=['value'=>$config['fx_rate'],'currency_pair'=>'USD/VND','source'=>$b['fx_source']??'QUOTE_SNAPSHOT','observed_at'=>gmdate('Y-m-d\TH:i:s\Z')];if(!in_array($fx['source'],['MANUAL','QUOTE_SNAPSHOT'],true))throw new InvalidArgumentException('Unsupported FX source');
            $gen=$old?(int)$old['generation']+1:1;$args=[$config['method'],$gen,QuoteVs2Repository::json($config),QuoteVs2Repository::json($inputs),QuoteVs2Domain::hash($inputs),QuoteVs2Repository::json($fx),$u['id']];
            if($old){self::q($db,"UPDATE quote_price_matrices SET status='DRAFT',method=?,generation=?,config_json=?,inputs_json=?,dependency_hash=?,fx_snapshot_json=?,updated_by=?,generated_at=NOW(),validated_at=NULL,locked_at=NULL,locked_by=NULL,valid_until=NULL WHERE id=?",[...$args,$old['id']]);$mid=(int)$old['id'];}
            else{self::q($db,'INSERT INTO quote_price_matrices(method,generation,config_json,inputs_json,dependency_hash,fx_snapshot_json,updated_by,quote_version_id) VALUES(?,?,?,?,?,?,?,?)',[...$args,$version]);$mid=(int)$db->lastInsertId();}
            foreach($inputs['graph']['variants'] as $variant)foreach($config['bands'] as $band){$paxes=$config['method']==='SAFE_PRICE'?range($band['min'],$band['max']):[$band['representative']];$costs=[];$failures=[];
                foreach($paxes as $pay){try{$costs[$pay]=PaxScenario::calculate($db,$inputs['graph'],$variant,$pay,$config,$config['fx_rate']);}catch(DomainException|InvalidArgumentException|OverflowException $e){$failures[]=['pax'=>$pay,'code'=>$e->getMessage()];}}
                foreach($inputs['policies'] as $channel=>$policy){$net=0;$scenarios=[];$status='VALID';
                    foreach($costs as $pay=>$cost){$required=CommercialPolicy::pricing(Vs2Decimal::parse($cost['cost_total_vnd']),$pay,$config['fx_rate'],$policy);$net=max($net,Vs2Decimal::parse($required['net_per_pax']));}
                    foreach($costs as $pay=>$cost){$p=CommercialPolicy::pricing(Vs2Decimal::parse($cost['cost_total_vnd']),$pay,$config['fx_rate'],$policy,Vs2Decimal::format($net));if($p['pricing_status']==='MARGIN_BELOW_POLICY')$status='MARGIN_BELOW_POLICY';elseif($p['pricing_status']==='MARGIN_WARNING'&&$status==='VALID')$status='MARGIN_WARNING';$scenarios[]=['pax'=>$pay,'cost'=>self::digest($cost),'pricing'=>$p];}
                    if($failures)$status='SCENARIO_REVIEW_REQUIRED';$selling=(!$failures&&$scenarios)?$scenarios[0]['pricing']['selling_per_pax']:null;$result=['method'=>$config['method'],'scenarios'=>$scenarios,'failures'=>$failures,'hotel_level'=>$variant['hotel_level'],'cruise_level'=>$variant['cruise_level'],'mode'=>$variant['costing_mode'],'label'=>$variant['label'],'option_id'=>(int)$variant['quote_option_id']];
                    $hash=QuoteVs2Domain::hash([$inputs,$variant['id'],$band,$policy,$result]);self::q($db,'INSERT INTO quote_price_matrix_cells(matrix_id,generation,variant_id,policy_id,band_key,min_pax,max_pax,channel,status,selling_per_pax,net_per_pax,selling_currency,policy_snapshot_json,result_json,context_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$mid,$gen,$variant['id'],$policy['id'],$band['key'],$band['min'],$band['max'],$channel,$status,$selling,$scenarios?Vs2Decimal::format($net):null,$policy['selling_currency'],QuoteVs2Repository::json($policy),QuoteVs2Repository::json($result),$hash]);
                }
            }
            QuoteVs2Repository::finish($db,$v,$u,'PRICE_MATRIX_GENERATED',['matrix_id'=>$mid,'generation'=>$gen,'method'=>$config['method']]);return self::context($db,$u,QuoteOptions::version($db,(int)$u['company_id'],$version));
        });
    }
    public static function report(PDO $db,array $v,bool $recompute=true): array {
        $m=self::row($db,$v);if(!$m)return ['valid'=>true,'errors'=>[],'warnings'=>[]];$errors=[];$warnings=[];$config=QuoteVs2Repository::decode($m['config_json']);$old=QuoteVs2Repository::decode($m['inputs_json']);
        try{if(!hash_equals($m['dependency_hash'],QuoteVs2Domain::hash(self::inputs($db,$v,$config))))$errors[]=['code'=>'MATRIX_DEPENDENCIES_CHANGED'];}catch(DomainException|InvalidArgumentException|OutOfBoundsException $e){$errors[]=['code'=>'MATRIX_DEPENDENCIES_CHANGED','message'=>$e->getMessage()];}
        if(!empty($m['valid_until'])&&$m['valid_until']<date('Y-m-d'))$errors[]=['code'=>'COMMERCIAL_OFFER_EXPIRED'];
        $selected=array_values(array_filter(self::cells($db,$m),fn($c)=>$c['is_selected']));if(!$selected)$errors[]=['code'=>'MATRIX_OPTION_NEEDED'];$cache=[];
        foreach($selected as $cell){$result=QuoteVs2Repository::decode($cell['result_json']);
            if($cell['status']==='SCENARIO_REVIEW_REQUIRED')$errors[]=['code'=>'SCENARIO_REVIEW_REQUIRED','cell_id'=>(int)$cell['id']];
            if($cell['status']==='MARGIN_BELOW_POLICY'&&!self::overrideExists($db,$cell,$cell['context_hash']))$errors[]=['code'=>'MARGIN_BELOW_POLICY','cell_id'=>(int)$cell['id']];
            if($cell['status']==='MARGIN_WARNING')$warnings[]=['code'=>'MARGIN_WARNING','cell_id'=>(int)$cell['id']];
            if($recompute&&!$errors)foreach($result['scenarios'] as $s){$key=$cell['variant_id'].'-'.$s['pax'];try{
                if(!isset($cache[$key])){$variant=array_values(array_filter($old['graph']['variants'],fn($x)=>(int)$x['id']===(int)$cell['variant_id']))[0];$cache[$key]=self::digest(PaxScenario::calculate($db,$old['graph'],$variant,$s['pax'],$config,$config['fx_rate']));}
                if(QuoteVs2Domain::hash($cache[$key])!==QuoteVs2Domain::hash($s['cost']))$errors[]=['code'=>'MATRIX_SCENARIO_STALE','cell_id'=>(int)$cell['id']];
            }catch(DomainException|InvalidArgumentException|OverflowException $e){$errors[]=['code'=>'MATRIX_SCENARIO_STALE','cell_id'=>(int)$cell['id'],'message'=>$e->getMessage()];}}
        }
        return ['valid'=>!$errors,'errors'=>$errors,'warnings'=>$warnings];
    }
    private static function overrideExists(PDO $db,array $cell,string $hash): bool {return (bool)self::q($db,'SELECT 1 FROM commercial_overrides WHERE cell_id=? AND matrix_id=? AND context_hash=?',[$cell['id'],$cell['matrix_id'],$hash])->fetchColumn();}
    public static function override(PDO $db,array $u,array $v,array $m,array $b): array {
        $cell=self::cell($db,$m,(int)($b['cell_id']??0));$hash=(string)($b['context_hash']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new InvalidArgumentException('Exact override context hash required');$reason=QuoteVs2Domain::text($b['reason']??'',1000);
        if($m['status']==='SENT'){
            $actual=self::recheck($db,$v,$cell,(int)($b['paying_pax']??0),$b['guest_profile']??null);if($actual['status']!=='MARGIN_REVIEW_REQUIRED'||!hash_equals($actual['context_hash'],$hash))throw new DomainException('OVERRIDE_CONTEXT_MISMATCH');
        }elseif($cell['status']!=='MARGIN_BELOW_POLICY'||!hash_equals($cell['context_hash'],$hash))throw new DomainException('OVERRIDE_CONTEXT_MISMATCH');
        if(!self::overrideExists($db,$cell,$hash))self::q($db,'INSERT INTO commercial_overrides(matrix_id,cell_id,context_hash,reason,approved_by) VALUES(?,?,?,?,?)',[$m['id'],$cell['id'],$hash,$reason,$u['id']]);
        Audit::log($db,(int)$u['company_id'],(int)$u['id'],'COMMERCIAL_MARGIN_OVERRIDE_APPROVED','price_matrix_cell',(int)$cell['id'],null,['reason'=>$reason,'context_hash'=>$hash]);return ['cell_id'=>(int)$cell['id'],'context_hash'=>$hash,'approved'=>true];
    }
    private static function manualPrice(PDO $db,array $u,array $v,array $m,array $b): void {
        if($m['status']!=='DRAFT')throw new DomainException('DRAFT_MATRIX_REQUIRED');
        $c=self::cell($db,$m,(int)($b['cell_id']??0));
        $reason=QuoteVs2Domain::text($b['reason']??'',1000);
        $input=$b['selling_per_pax']??null;
        if(!is_string($input)&&!is_int($input))throw new InvalidArgumentException('Selling price per pax required');
        $gross=Vs2Decimal::parse((string)$input);
        if($gross<=0)throw new InvalidArgumentException('Positive selling price required');
        $p=QuoteVs2Repository::decode($c['policy_snapshot_json']);
        $commission=Vs2Decimal::parse((string)$p['commission_pct'],4);
        $net=Vs2Decimal::format(Vs2Decimal::ratio($gross,1000000-$commission,1000000));
        $cfg=QuoteVs2Repository::decode($m['config_json']);
        $result=QuoteVs2Repository::decode($c['result_json']);
        if(!empty($result['failures'])||empty($result['scenarios']))throw new DomainException('SCENARIO_COST_REVIEW_REQUIRED');
        $status='VALID';
        foreach($result['scenarios'] as &$sc){
            $cost=Vs2Decimal::parse((string)$sc['cost']['cost_total_vnd']);
            $price=CommercialPolicy::pricing($cost,(int)$sc['pax'],(string)$cfg['fx_rate'],$p,$net);
            $sc['pricing']=$price;
            if($price['pricing_status']==='MARGIN_BELOW_POLICY')$status='MARGIN_BELOW_POLICY';
            elseif($price['pricing_status']==='MARGIN_WARNING'&&$status==='VALID')$status='MARGIN_WARNING';
        }unset($sc);
        $actual=$result['scenarios'][0]['pricing'];
        $result['manual_price']=['entered_selling_per_pax'=>(string)$input,'reason'=>$reason,'reviewed_by'=>(int)$u['id']];
        $hash=QuoteVs2Domain::hash([$c['context_hash'],$c['id'],$net,$result,$reason]);
        self::q($db,'UPDATE quote_price_matrix_cells SET status=?,selling_per_pax=?,net_per_pax=?,result_json=?,context_hash=? WHERE id=?',
            [$status,$actual['selling_per_pax'],$net,QuoteVs2Repository::json($result),$hash,$c['id']]);
    }
    public static function change(PDO $db,array $u,int $id,string $action,array $b): array {
        return QuoteVs2Repository::atomic($db,function()use($db,$u,$id,$action,$b){$v=QuoteVs2Repository::lock($db,(int)$u['company_id'],$id,QuoteVs2Repository::expected($b));$m=self::row($db,$v)??throw new DomainException('MATRIX_NEEDED');
            if($action==='override')return self::override($db,$u,$v,$m,$b);QuoteVs2Repository::mutable($db,$v);
            if($action==='unlock'){if($m['status']!=='LOCKED')throw new DomainException('LOCKED_MATRIX_REQUIRED');$reason=QuoteVs2Domain::text($b['reason']??'',1000);self::q($db,"UPDATE quote_price_matrices SET status='DRAFT' WHERE id=?",[$m['id']]);}
            else{if($m['status']==='LOCKED')throw new DomainException('LOCKED_MATRIX');
                if($action==='manual-price'){$reason=QuoteVs2Domain::text($b['reason']??'',1000);self::manualPrice($db,$u,$v,$m,$b);}
                elseif($action==='select'){if($m['status']!=='DRAFT')throw new DomainException('DRAFT_MATRIX_REQUIRED');$cell=self::cell($db,$m,(int)($b['cell_id']??0));self::q($db,'UPDATE quote_price_matrix_cells SET is_selected=?,is_recommended=? WHERE id=?',[!empty($b['is_selected'])?1:0,!empty($b['is_recommended'])?1:0,$cell['id']]);}
                else{$report=self::report($db,$v);if(!$report['valid'])throw new DomainException('MATRIX_VALIDATION: '.implode(', ',array_unique(array_column($report['errors'],'code'))));if($action==='lock'&&$m['status']!=='VALIDATED')throw new DomainException('VALIDATE_MATRIX_FIRST');
                    if($action==='lock'){$days=365;foreach(self::cells($db,$m) as $c)if($c['is_selected'])$days=min($days,(int)QuoteVs2Repository::decode($c['policy_snapshot_json'])['validity_days']);self::q($db,"UPDATE quote_price_matrices SET status='LOCKED',locked_by=?,locked_at=NOW(),valid_until=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?",[$u['id'],$days,$m['id']]);}else self::q($db,"UPDATE quote_price_matrices SET status='VALIDATED',validated_at=NOW(),updated_by=? WHERE id=?",[$u['id'],$m['id']]);}
            }
            QuoteVs2Repository::finish($db,$v,$u,'PRICE_MATRIX_'.strtoupper($action),['matrix_id'=>$m['id'],'reason'=>$reason??null]);return self::context($db,$u,QuoteOptions::version($db,(int)$u['company_id'],$id));
        });
    }
    public static function publicMatrix(array $m,array $cells,string $channel): array {
        $rows=[];foreach($cells as $c)if($c['is_selected']&&$c['channel']===$channel){$r=QuoteVs2Repository::decode($c['result_json']);$p=QuoteVs2Repository::decode($c['policy_snapshot_json']);$row=['cell_id'=>(int)$c['id'],'variant_id'=>(int)$c['variant_id'],'option_id'=>$r['option_id'],'pax_min'=>(int)$c['min_pax'],'pax_max'=>(int)$c['max_pax'],'label'=>$r['label'],'hotel_level'=>$r['hotel_level'],'cruise_level'=>$r['cruise_level'],'mode'=>$r['mode'],'selling_per_pax'=>$c['selling_per_pax'],'currency'=>$c['selling_currency'],'recommended'=>(bool)$c['is_recommended'],'deposit_pct'=>$p['deposit_pct'],'validity_days'=>$p['validity_days'],'payment_terms'=>$p['payment_terms'],'cancellation_policy'=>$p['cancellation_policy']];if($channel==='B2B_AGENT')$row+=['net_per_pax'=>$c['net_per_pax'],'commission_pct'=>$p['commission_pct']];$rows[]=$row;}
        return ['matrix_id'=>(int)$m['id'],'method'=>$m['method'],'valid_until'=>$m['valid_until']??null,'channel'=>$channel,'cells'=>$rows];
    }
    public static function publicOptions(array $public,int $pay): array {
        $rows=[];foreach($public['cells'] as $c)if($pay>=$c['pax_min']&&$pay<=$c['pax_max'])$rows[]=['id'=>$c['option_id'],'option_id'=>$c['option_id'],'variant_id'=>$c['variant_id'],'cell_id'=>$c['cell_id'],'label'=>$c['label'],'hotel_level'=>$c['hotel_level'],'cruise_level'=>$c['cruise_level'],'costing_mode'=>$c['mode'],'selling_per_pax'=>$c['selling_per_pax'],'total_selling'=>Vs2Decimal::format(Vs2Decimal::mul(Vs2Decimal::parse($c['selling_per_pax']),$pay)),'currency'=>$c['currency']];return $rows;
    }
    public static function publicCommitment(array $c): array {
        $price=array_intersect_key($c['pricing'],array_flip(['selling_per_pax','total_selling','net_per_pax','net_payable','commission_per_pax','commission_total','deposit','balance','selling_currency']));if($c['channel']==='B2C_DIRECT')foreach(['net_per_pax','net_payable','commission_per_pax','commission_total'] as $key)unset($price[$key]);return array_intersect_key($c,array_flip(['channel','cell_id','option_id','variant_id','mode','hotel_level','cruise_level','guests','selling_currency','payment_terms','cancellation_policy','valid_until']))+['pricing'=>$price];
    }
    public static function transportOptions(PDO $db,array $u,array $v): array {
        $rows=self::q($db,"SELECT rv.id AS rate_version_id,r.product_name,s.name AS supplier_name,t.capacity,
            rv.amount,rv.currency,rv.rate_basis FROM rates r
            JOIN suppliers s ON s.id=r.supplier_id AND s.company_id=r.company_id
            JOIN rate_versions rv ON rv.rate_id=r.id
            JOIN rate_version_vs2_terms t ON t.rate_version_id=rv.id
            WHERE r.company_id=? AND r.category='TRANSPORT' AND r.status='ACTIVE' AND s.status='ACTIVE'
              AND rv.approval_status='APPROVED' AND t.approval_state='APPROVED'
            ORDER BY s.name,r.product_name,rv.id LIMIT 200",[(int)$u['company_id']])->fetchAll();
        return ['items'=>$rows,'note'=>'Dates, pax eligibility, capacity and itinerary scope are revalidated for every paying pax.'];
    }
    public static function context(PDO $db,array $u,array $v): array {
        $m=self::row($db,$v);$base=['version_id'=>(int)$v['id'],'costing_revision'=>(int)$v['costing_revision'],'version_status'=>$v['version_status'],'matrix'=>$m?['id'=>(int)$m['id'],'status'=>$m['status'],'method'=>$m['method'],'generation'=>(int)$m['generation']]:null];if(!$m)return $base;
        $cells=self::cells($db,$m);if(!Auth::can($db,(int)$u['id'],'quote.view_cost'))return $base+['selling'=>in_array($m['status'],['LOCKED','SENT'],true)?[self::publicMatrix($m,$cells,'B2B_AGENT'),self::publicMatrix($m,$cells,'B2C_DIRECT')]:[]];
        foreach($cells as &$c){$c['result']=QuoteVs2Repository::decode($c['result_json']);$c['policy']=QuoteVs2Repository::decode($c['policy_snapshot_json']);$c['override_approved']=self::overrideExists($db,$c,$c['context_hash']);unset($c['result_json'],$c['policy_snapshot_json']);}unset($c);
        return $base+['config'=>QuoteVs2Repository::decode($m['config_json']),'fx'=>QuoteVs2Repository::decode($m['fx_snapshot_json']),'cells'=>$cells,'validation'=>$m['status']==='SENT'?['valid'=>true,'errors'=>[],'warnings'=>[]]:self::report($db,$v,false)];
    }
    public static function validation(PDO $db,array $v): array {$m=self::row($db,$v);if(!$m)return ['valid'=>true,'errors'=>[],'warnings'=>[]];$r=self::report($db,$v);if(!in_array($m['status'],['LOCKED','SENT'],true)){$r['errors'][]=['code'=>'PRICE_MATRIX_LOCK_REQUIRED'];$r['valid']=false;}return $r;}
    public static function bundle(PDO $db,array $v,array $bundle): array {
        $m=self::row($db,$v);if(!$m)return $bundle;if($m['status']!=='LOCKED')throw new DomainException('PRICE_MATRIX_LOCK_REQUIRED');$cells=self::cells($db,$m);$settings=QuoteProposal::config($v);$channel=($settings['channel']??'B2B')==='B2C'?'B2C_DIRECT':'B2B_AGENT';$public=self::publicMatrix($m,$cells,$channel);if(!$public['cells'])throw new DomainException('CHANNEL_MATRIX_REQUIRED');
        $offered=array_column($public['cells'],'variant_id');$bundle['options']=array_values(array_filter($bundle['options'],fn($o)=>in_array($o['variant_id'],$offered,true)));$bundle['commercial']=['public'=>$public,'matrix'=>$m,'cells'=>$cells];return $bundle;
    }
    public static function sent(PDO $db,array $v): void {if(self::row($db,$v))self::q($db,"UPDATE quote_price_matrices SET status='SENT' WHERE quote_version_id=? AND status='LOCKED'",[$v['id']]);}
    public static function recheck(PDO $db,array $v,array $cell,int $pay,?array $profile=null): array {
        $m=self::row($db,$v)??throw new DomainException('MATRIX_NEEDED');if($m['status']!=='SENT'||!$cell['is_selected']||$pay<(int)$cell['min_pax']||$pay>(int)$cell['max_pax'])throw new DomainException('EXACT_PAX_OUTSIDE_OFFER');
        if(!empty($m['valid_until'])&&$m['valid_until']<date('Y-m-d'))throw new DomainException('COMMERCIAL_OFFER_EXPIRED');
        $sent=self::q($db,'SELECT internal_snapshot_json FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetchColumn();$bundle=QuoteVs2Repository::decode($sent);$frozen=$bundle['commercial']??throw new DomainException('FROZEN_MATRIX_MISSING');$found=null;foreach($frozen['cells'] as $c)if((int)$c['id']===(int)$cell['id'])$found=$c;if(!$found||!hash_equals($found['context_hash'],$cell['context_hash']))throw new DomainException('FROZEN_CELL_MISMATCH');$cell=$found;
        if(!array_filter($frozen['public']['cells'],fn($c)=>(int)$c['cell_id']===(int)$cell['id']))throw new DomainException('CELL_NOT_IN_SENT_PROPOSAL');
        $inputs=QuoteVs2Repository::decode($frozen['matrix']['inputs_json']);$config=$inputs['config'];$variant=null;foreach($inputs['graph']['variants'] as $x)if((int)$x['id']===(int)$cell['variant_id'])$variant=$x;if(!$variant)throw new DomainException('FROZEN_VARIANT_MISSING');
        $cost=PaxScenario::calculate($db,$inputs['graph'],$variant,$pay,$config,$config['fx_rate'],$profile);$policy=QuoteVs2Repository::decode($cell['policy_snapshot_json']);$price=CommercialPolicy::pricing(Vs2Decimal::parse($cost['cost_total_vnd']),$pay,$config['fx_rate'],$policy,$cell['net_per_pax']);
        $hash=QuoteVs2Domain::hash([$cell['context_hash'],self::digest($cost),$price]);$status=$price['pricing_status']==='MARGIN_BELOW_POLICY'?'MARGIN_REVIEW_REQUIRED':'VALID';if($status!=='VALID'&&self::overrideExists($db,$cell,$hash))$status='VALID_WITH_OVERRIDE';
        $accommodation=[];foreach($bundle['presentation']['settings']['accommodation']??[] as $a){$level=$a['type']==='HOTEL'?$variant['hotel_level']:$variant['cruise_level'];$key=match(rtrim((string)$level,'*★')){'3'=>'three_star','4'=>'four_star','5'=>'five_star',default=>null};if($key)$accommodation[]=['type'=>$a['type'],'destination'=>$a['destination'],'selected_name'=>$a[$key],'nights'=>$a['nights'],'level'=>$level];}
        return ['status'=>$status,'cell_id'=>(int)$cell['id'],'context_hash'=>$hash,'cost'=>$cost,'pricing'=>$price,'fx'=>QuoteVs2Repository::decode($frozen['matrix']['fx_snapshot_json']),'policy'=>$policy,'variant'=>$variant,'parties'=>$inputs['parties']??['customer'=>null,'agent'=>null],'accommodation'=>$accommodation];
    }
    public static function accept(PDO $db,array $u,array $v,int $option,int $variant,string $hash,array $b): array {
        $sentHash=self::q($db,'SELECT content_hash FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetchColumn();if(!$sentHash||!hash_equals($sentHash,$hash))throw new DomainException('STALE_SENT_HASH');
        $m=self::row($db,$v)??throw new DomainException('MATRIX_NEEDED');$cell=self::cell($db,$m,(int)($b['cell_id']??0));$pay=QuoteVs2Domain::count($b['paying_pax']??0);$r=QuoteVs2Repository::decode($cell['result_json']);if((int)$cell['variant_id']!==$variant||$r['option_id']!==$option)throw new DomainException('EXACT_OPTION_REQUIRED');
        $old=self::q($db,'SELECT * FROM quote_commercial_acceptances WHERE quote_version_id=?',[$v['id']])->fetch();if($old){$commit=QuoteVs2Repository::decode($old['commitment_json']);if((int)$old['cell_id']!==(int)$cell['id']||(int)$old['paying_pax']!==$pay||($b['guest_profile']??null)!==($commit['confirmed_profile_input']??null))throw new DomainException('ACCEPTANCE_IMMUTABLE');return ['option_id'=>$option,'variant_id'=>$variant,'cell_id'=>(int)$cell['id'],'paying_pax'=>$pay];}
        $recheck=self::recheck($db,$v,$cell,$pay,$b['guest_profile']??null);if($recheck['status']==='MARGIN_REVIEW_REQUIRED')throw new DomainException('MARGIN_REVIEW_REQUIRED');
        $accepted=Vs2Snapshots::confirm($db,$u,$v,$option,$variant,$hash);$commit=['channel'=>$cell['channel'],'cell_id'=>(int)$cell['id'],'option_id'=>$option,'variant_id'=>$variant,'mode'=>$recheck['variant']['costing_mode'],'hotel_level'=>$recheck['variant']['hotel_level'],'cruise_level'=>$recheck['variant']['cruise_level'],'guests'=>$recheck['cost']['guests'],'pricing'=>$recheck['pricing'],'fx'=>$recheck['fx'],'selling_currency'=>$cell['selling_currency'],'confirmed_profile_input'=>$b['guest_profile']??null,'source_hash'=>$hash,'payment_terms'=>$recheck['policy']['payment_terms'],'cancellation_policy'=>$recheck['policy']['cancellation_policy'],'valid_until'=>$m['valid_until'],'parties'=>$recheck['parties'],'accommodation'=>$recheck['accommodation']];
        self::q($db,'INSERT INTO quote_commercial_acceptances(quote_version_id,matrix_id,cell_id,paying_pax,commitment_json,recheck_json,commitment_hash,accepted_by) VALUES(?,?,?,?,?,?,?,?)',[$v['id'],$m['id'],$cell['id'],$pay,QuoteVs2Repository::json($commit),QuoteVs2Repository::json($recheck),QuoteVs2Domain::hash($commit),$u['id']]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'EXACT_COMMERCIAL_OPTION_ACCEPTED','quote_version',(int)$v['id'],null,['cell_id'=>$cell['id'],'paying_pax'=>$pay,'commitment_hash'=>QuoteVs2Domain::hash($commit)]);return $accepted+['cell_id'=>(int)$cell['id'],'paying_pax'=>$pay];
    }
    public static function bookingBundle(PDO $db,array $v,array $bundle): array {
        if(!self::row($db,$v))return $bundle;$row=self::q($db,'SELECT * FROM quote_commercial_acceptances WHERE quote_version_id=?',[$v['id']])->fetch()?:throw new DomainException('EXACT_ACCEPTANCE_REQUIRED');$c=QuoteVs2Repository::decode($row['commitment_json']);$r=QuoteVs2Repository::decode($row['recheck_json']);$bundle=array_replace($bundle,array_intersect_key($c['guests'],array_flip(QuoteVs2Domain::BASE)));$bundle['guest_profile']=array_intersect_key($c['guests'],array_flip(QuoteVs2Domain::PROFILE));$bundle['commercial_commitment']=$c;
        foreach($bundle['options'] as &$o)if((int)$o['variant_id']===(int)$c['variant_id']){$o['snapshot']=array_replace($o['snapshot'],$c['guests'],['fx_rate'=>$c['fx']['value'],'pricing'=>$c['pricing'],'lines'=>$r['cost']['lines'],'cost_lines'=>$r['cost']['cost_lines']]);}unset($o);return $bundle;
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        if($route==='commercial-policies'){Auth::requirePermission($db,$u,'sales.view');Auth::requirePermission($db,$u,'quote.view_cost');try{if($method==='GET')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT * FROM commercial_policies WHERE company_id=? ORDER BY policy_key,version_no DESC',[$u['company_id']])->fetchAll()]);if($method==='POST'){Auth::requirePermission($db,$u,'commercial.policy_manage');Http::json(['ok'=>true,'policy'=>CommercialPolicy::create($db,$u,Http::body())],201);}}catch(InvalidArgumentException|DomainException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}return;}
        if(!preg_match('#^quote-versions/(\d+)/price-matrix(?:/(generate|validate|lock|unlock|select|override|recheck|transport-options|manual-price))?$#',$route,$matches))return;Auth::requirePermission($db,$u,'sales.view');$id=(int)$matches[1];$action=$matches[2]??'';
        try{$v=QuoteOptions::version($db,(int)$u['company_id'],$id);if($method==='GET'&&$action==='')Http::json(['ok'=>true]+self::context($db,$u,$v));Auth::requirePermission($db,$u,'quote.view_cost');
            if($method==='GET'&&$action==='transport-options')Http::json(['ok'=>true]+self::transportOptions($db,$u,$v));
            if($method!=='POST')return;$b=Http::body();
            if($action==='recheck'){$m=self::row($db,$v)??throw new DomainException('MATRIX_NEEDED');Http::json(['ok'=>true,'recheck'=>self::recheck($db,$v,self::cell($db,$m,(int)($b['cell_id']??0)),(int)($b['paying_pax']??0),$b['guest_profile']??null)]);}
            if($action==='override')Auth::requirePermission($db,$u,'commercial.margin_override');else{Auth::requirePermission($db,$u,'quote.edit');if(in_array($action,['lock','unlock'],true))Auth::requirePermission($db,$u,'commercial.matrix_lock');}
            Http::json(['ok'=>true]+($action==='generate'?self::generate($db,$u,$id,$b):self::change($db,$u,$id,$action,$b)));
        }catch(InvalidArgumentException|JsonException|OverflowException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}
