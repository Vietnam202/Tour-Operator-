<?php
declare(strict_types=1);
require __DIR__.'/operations.php';
require_once __DIR__.'/../api/lib/QuoteExport.php';
$details=ServiceTravelDetails::clean(['address'=>'Customer hotel address','room_type'=>'Deluxe twin','rooming'=>'Room 1: Guest A and Guest B','check_in'=>'2027-01-02','check_out'=>'2027-01-03','inclusions'=>'Breakfast','guest_instructions'=>'Meet at lobby <script>alert(1)</script>','internal_cost'=>'never export']);
check(!isset($details['internal_cost']),'voucher detail schema excludes unknown internal fields');
rejects(fn()=>ServiceTravelDetails::clean(['check_in'=>'2027-01-03','check_out'=>'2027-01-02']),InvalidArgumentException::class,'voucher checkout must follow check-in');
$q=$db->prepare('INSERT INTO service_travel_details(service_id,customer_details_json,updated_by) VALUES(?,?,1)');$q->execute([$service,json_encode($details)]);
$q=$db->prepare("INSERT INTO flights(booking_id,flight_number,flight_date,origin_code,destination_code,notes) VALUES(?,'VN-TEST','2027-01-02','SGN','HAN','PRIVATE FLIGHT NOTE')");$q->execute([$bid]);
$doc=TravelDocuments::create($db,$user,$bid,['kind'=>'TRAVEL_PACK']);foreach(['review','ready','issue'] as $action)TravelDocuments::transition($db,$user,$doc['id'],$action);
$row=$db->query('SELECT * FROM travel_document_versions WHERE id='.(int)$doc['id'])->fetch();$html=TravelDocuments::html($row);
check(str_contains($html,'Customer hotel address')&&str_contains($html,'Deluxe twin')&&str_contains($html,'VN-TEST'),'Travel Pack includes voucher details and flights');
check(!str_contains($html,'PRIVATE FLIGHT NOTE')&&!str_contains($html,'<script>'),'Travel Pack excludes flight notes and escapes guest text');
$raw=$db->query('SELECT public_snapshot_json FROM quote_sent_bundles WHERE quote_version_id='.$qvid)->fetchColumn();$quoteHtml=QuoteExport::html(json_decode($raw,true));
check(str_contains($quoteHtml,'3*')&&str_contains($quoteHtml,'4*')&&str_contains($quoteHtml,'5*')&&str_contains($quoteHtml,'Transfer to hotel'),'issued customer quotation renders all options and full itinerary');
check(!str_contains($quoteHtml,'supplier')&&!str_contains($quoteHtml,'cost_total')&&!str_contains($quoteHtml,'margin_pct'),'customer quotation never renders private cost fields');
echo "Detailed voucher, flight and issued customer quotation checks passed.\n";