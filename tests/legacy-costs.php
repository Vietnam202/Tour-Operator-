<?php
declare(strict_types=1);
require __DIR__.'/lead-hub.php';
require_once __DIR__.'/../api/lib/CoreOS.php';
require_once __DIR__.'/../api/lib/QuoteOptions.php';
require_once __DIR__.'/../api/lib/QuoteCostItems.php';
$db->exec("UPDATE quote_versions SET total_guests=11,paying_pax=10,start_date='2027-01-02',fx_rate=25000 WHERE id=$qvid");
$db->exec("UPDATE rates SET category='MEAL' WHERE id=1");$db->exec("UPDATE rate_versions SET rate_basis='PER_PAX',tax_basis='NET',approval_status='APPROVED' WHERE id=1");
$line=['rate_version_id'=>1,'pax'=>11,'qty'=>1,'unit_price'=>0.01,'currency'=>'VND','service_date'=>'2027-01-02'];
$item=QuoteCostItems::write($db,$user,$qvid,$line);
$saved=$db->query('SELECT * FROM quote_cost_items WHERE id='.(int)$item['id'])->fetch();
check((float)$saved['unit_price']===110.0&&$saved['currency']==='USD'&&(float)$saved['total']===1210.0,'legacy rate-backed cost ignores caller price and currency');
$db->exec("UPDATE quote_versions SET version_status='APPROVED' WHERE id=$qvid");QuoteCostItems::write($db,$user,$qvid,[],(int)$item['id']);
check($db->query('SELECT version_status FROM quote_versions WHERE id='.$qvid)->fetchColumn()==='DRAFT','legacy cost deletion invalidates quote approval');
rejects(fn()=>QuoteCostItems::write($db,$user,$qvid,['pax'=>1,'qty'=>1,'unit_price'=>2,'currency'=>'USD','service_date'=>'2027-01-02','service_name'=>'Manual']),InvalidArgumentException::class,'legacy manual cost requires reason');
$db->exec("UPDATE rate_versions SET approval_status='UNREVIEWED' WHERE id=1");rejects(fn()=>QuoteCostItems::write($db,$user,$qvid,$line),DomainException::class,'legacy write rejects unapproved rate');
$db->exec("UPDATE quote_versions SET version_status='SENT' WHERE id=$qvid");rejects(fn()=>QuoteCostItems::write($db,$user,$qvid,$line),DomainException::class,'legacy cost write cannot alter sent quote');
$public=QuoteOptions::publicSchedule([['title'=>'Arrival','description'=>'Meet guide','internal_cost'=>123,'notes'=>'Private supplier']]);
check(!isset($public[0]['internal_cost'])&&!isset($public[0]['notes']),'legacy public schedule strips private fields');
