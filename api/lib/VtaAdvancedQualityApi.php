<?php
declare(strict_types=1);
require_once __DIR__.'/VtaTourItineraryQuality.php';
require_once __DIR__.'/VtaRateIntakeReview.php';
require_once __DIR__.'/VtaPriceMatrixAudit.php';

/**
 * OPTIONAL internal diagnostics for RC6.2 M01/M03/M04.
 *
 * No new tables, no legacy endpoint changes, no pricing or supplier writes.
 * Routes are private and OFF unless an operator sets
 * VTA_RC62_ADVANCED_QUALITY_READONLY=1 in isolated private server config.
 * All requests pass the existing session/Auth/CSRF middleware in api/index.php.
 */
final class VtaAdvancedQualityApi
{
    public const FLAG = 'VTA_RC62_ADVANCED_QUALITY_READONLY';

    public static function enabled(): bool
    {
        return getenv(self::FLAG) === '1';
    }

    public static function handle(string $route, string $method, PDO $db, array $u): void
    {
        if (!str_starts_with($route, 'quality/')) return;
        if (!self::enabled()) Http::json(['ok'=>false,'error'=>'ROUTE_NOT_FOUND'],404);
        // Never trust a caller-supplied user/company context; bind to this session.
        $sessionUser=Auth::requireUser($db);
        if ((int)$sessionUser['id']!==(int)($u['id']??0)
            || (int)$sessionUser['company_id']!==(int)($u['company_id']??0)) {
            Http::json(['ok'=>false,'error'=>'AUTH_SCOPE_MISMATCH'],401);
        }
        Auth::checkCsrf();

        if (preg_match('#^quality/tour-programs/([1-9][0-9]*)$#D',$route,$m)) {
            if ($method !== 'GET') Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
            if (!self::mayReviewTour($db,$u)) Http::json(['ok'=>false,'error'=>'FORBIDDEN'],403);
            Http::json(['ok'=>true,'module'=>'M01','read_only'=>true,
                'quality'=>self::tour($db,$u,(int)$m[1])]);
        }
        if ($route === 'quality/supplier-rates/preview') {
            if ($method !== 'POST') Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
            // Defence in depth; outer api/index.php checks CSRF on every request.
            Auth::checkCsrf();
            Auth::requirePermission($db,$u,'rate.create');
            Auth::requirePermission($db,$u,'supplier.view');
            $body=Http::body();
            if (!is_string($body['csv']??null)) throw new InvalidArgumentException('CSV text is required.');
            $supplier=filter_var($body['supplier_id']??null,FILTER_VALIDATE_INT);
            if ($supplier===false || $supplier===null || $supplier<1) throw new InvalidArgumentException('Valid supplier required.');
            Http::json(['ok'=>true,'module'=>'M03','read_only'=>true,
                'quality'=>self::supplierRatePreview($db,$u,$supplier,$body['csv'])]);
        }
        if (preg_match('#^quality/quote-versions/([1-9][0-9]*)/matrix$#D',$route,$m)) {
            if ($method !== 'GET') Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
            Auth::requirePermission($db,$u,'quote.view_cost');
            Http::json(['ok'=>true,'module'=>'M04','read_only'=>true,
                'quality'=>self::matrix($db,$u,(int)$m[1])]);
        }
        Http::json(['ok'=>false,'error'=>'ROUTE_NOT_FOUND'],404);
    }

    private static function mayReviewTour(PDO $db, array $u): bool
    {
        foreach (['tour_library.view','product.view','supplier.view'] as $permission) {
            if (Auth::can($db,(int)$u['id'],$permission)) return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    public static function tour(PDO $db, array $u, int $programId): array
    {
        if($programId<1)throw new InvalidArgumentException('Valid tour program required.');
        $query=$db->prepare('SELECT * FROM tour_library_programs WHERE company_id=? AND id=? LIMIT 1');
        $query->execute([(int)$u['company_id'],$programId]);
        $program=$query->fetch(PDO::FETCH_ASSOC);
        if(!$program)Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        $source=TourLibrary::publicProgram($program,true);
        $document=$source['proposal']['document']??null;
        if(!is_array($document))$document=null;
        // Only emits warning codes; original Word, pricing and media are untouched.
        $review=VtaTourItineraryQuality::review($source,$document);
        return ['program_id'=>$programId,'source_revision'=>hash('sha256',json_encode($source,JSON_THROW_ON_ERROR)),
            'report'=>$review,'feature_state'=>'READ_ONLY'];
    }

    /** @return array<string,mixed> */
    public static function supplierRatePreview(PDO $db,array $u,int $supplierId,string $csv): array
    {
        $company=(int)$u['company_id'];
        $supplier=$db->prepare("SELECT id FROM suppliers WHERE company_id=? AND id=? AND status='ACTIVE' LIMIT 1");
        $supplier->execute([$company,$supplierId]);
        if(!$supplier->fetchColumn())Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);

        $review=VtaRateIntakeReview::previewCsv($csv,['company_id'=>$company,'supplier_id'=>$supplierId]);
        if($review['total_rows']>100)throw new InvalidArgumentException('Limit authenticated supplier previews to 100 rows.');
        $doc=$db->prepare('SELECT id,review_status FROM documents WHERE company_id=? AND supplier_id=? AND id=? LIMIT 1');
        $existing=$db->prepare("SELECT rv.id FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id
            WHERE r.company_id=? AND r.supplier_id=? AND r.category=? AND LOWER(r.product_name)=LOWER(?)
              AND COALESCE(LOWER(r.option_name),'')=LOWER(?) AND rv.rate_basis=?
              AND r.rate_type=? AND r.status='ACTIVE' AND rv.approval_status='APPROVED'
              AND (rv.valid_from IS NULL OR rv.valid_from<=?)
              AND (rv.valid_to IS NULL OR rv.valid_to>=?)
              AND (r.min_pax IS NULL OR r.min_pax<=?)
              AND (r.max_pax IS NULL OR r.max_pax>=?)
            LIMIT 1");
        foreach($review['rows'] as &$row){
            if(!is_array($row['candidate']??null))continue;
            $c=$row['candidate'];
            if($c['source_document_id']!==null) {
                $doc->execute([$company,$supplierId,$c['source_document_id']]);
                $d=$doc->fetch(PDO::FETCH_ASSOC);
                if(!$d){
                    $row['issues'][]=['code'=>'SOURCE_DOCUMENT_OWNERSHIP_REJECTED','severity'=>'BLOCK'];
                    $row['status']='BLOCKED';
                    $row['candidate']=null;
                    continue;
                }
                if(($d['review_status']??'')!=='APPROVED'){
                    $row['issues'][]=['code'=>'SOURCE_DOCUMENT_NOT_APPROVED','severity'=>'REVIEW'];
                }
            }
            $existing->execute([$company,$supplierId,$c['category'],$c['product_name'],
                (string)$c['option_name'],$c['rate_basis'],$c['rate_type'],
                $c['valid_to'],$c['valid_from'],$c['max_pax']??10000,$c['min_pax']??1]);
            if($existing->fetchColumn()){
                $row['issues'][]=['code'=>'EXISTING_APPROVED_RATE_OVERLAP','severity'=>'REVIEW'];
            }
            $row['issues'][]=['code'=>'HUMAN_SUPPLIER_CONTRACT_REVIEW_REQUIRED','severity'=>'REVIEW'];
        }
        unset($row);
        $review['blocked_rows']=count(array_filter($review['rows'],static fn($row)=>$row['status']==='BLOCKED'));
        $review['review_rows']=$review['total_rows']-$review['blocked_rows'];
        $review['scope_checked']='SUPPLIER_ACTIVE_AND_DOCUMENT_SCOPED';
        $review['status']='PREVIEW_ONLY_NO_DB_WRITE';
        return $review;
    }

    /** @return array<string,mixed> */
    public static function matrix(PDO $db,array $u,int $versionId): array
    {
        if($versionId<1)throw new InvalidArgumentException('Valid quote version required.');
        $company=(int)$u['company_id'];
        $query=$db->prepare('SELECT v.* FROM quote_versions v JOIN quotes q ON q.id=v.quote_id WHERE q.company_id=? AND v.id=? LIMIT 1');
        $query->execute([$company,$versionId]);
        $v=$query->fetch(PDO::FETCH_ASSOC);
        if(!$v)Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        $v['company_id']=$company; // trusted company from scoped query, not client input
        $matrix=PriceMatrix::row($db,$v);
        if(!$matrix)return ['schema'=>VtaPriceMatrixAudit::SCHEMA,'status'=>'NO_MATRIX_AVAILABLE',
            'quote_version_id'=>$versionId,'read_only'=>true,'warnings'=>[['code'=>'PRICE_MATRIX_NOT_GENERATED','severity'=>'REVIEW']]];
        $cells=PriceMatrix::cells($db,$matrix);
        $report=VtaPriceMatrixAudit::inspect($v,$matrix,$cells,['company_id'=>$company]);
        // No NET, supplier costs, prices or margin in output; no server-side regeneration.
        return $report;
    }
}
