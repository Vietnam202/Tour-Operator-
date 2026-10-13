<?php
declare(strict_types=1);

/**
 * Fixture-only workflow continuity acceptance. Reuses the existing disposable
 * MariaDB tour/document/pricing/booking run: checks cross-module identifiers
 * and immutable snapshots in ONE database, not separate mocks.
 *
 * Never run on staging or production. The included suite creates and changes
 * a disposable random vta_test_* database.
 */
require __DIR__ . '/tour-program-workspace-native.php';

if (!isset($book['id'])) {
    throw new RuntimeException('GOLDEN_PATH_FIXTURE_BOOKING_MISSING');
}
$bookingId = (int) $book['id'];
$chain = v2q(
    'SELECT b.id booking_id, b.company_id booking_company_id, b.quote_id booking_quote_id,
            b.trip_id booking_trip_id, b.confirmed_quote_version_no,
            b.paying_pax booking_paying_pax, b.foc booking_foc,
            b.total_guests booking_total_guests, b.confirmed_selling,
            q.id quote_id, q.company_id quote_company_id, q.trip_id quote_trip_id,
            q.inquiry_id quote_inquiry_id,
            v.id version_id, v.version_no, v.paying_pax version_paying_pax,
            v.foc version_foc, v.total_guests version_total_guests,
            t.company_id trip_company_id, i.id inquiry_id,
            i.company_id inquiry_company_id, i.trip_id inquiry_trip_id
       FROM bookings b
       JOIN quotes q ON q.id=b.quote_id AND q.company_id=b.company_id
       JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=b.confirmed_quote_version_no
       JOIN trips t ON t.id=b.trip_id AND t.id=q.trip_id AND t.company_id=b.company_id
       JOIN inquiries i ON i.id=q.inquiry_id AND i.trip_id=t.id AND i.company_id=b.company_id
      WHERE b.id=?', [$bookingId]
)->fetch();
check((bool)$chain, 'Golden path: booking retains company/quote/trip/inquiry/version joins');
check((int)$chain['booking_company_id']===(int)$user['company_id']
    && (int)$chain['quote_company_id']===(int)$user['company_id']
    && (int)$chain['trip_company_id']===(int)$user['company_id']
    && (int)$chain['inquiry_company_id']===(int)$user['company_id'],
    'Golden path: booking and source objects remain in one company');
check((int)$chain['booking_paying_pax']===(int)$chain['version_paying_pax']
    && (int)$chain['booking_foc']===(int)$chain['version_foc']
    && (int)$chain['booking_total_guests']===(int)$chain['version_total_guests'],
    'Golden path: booking retains reviewed guest quantities and FOC');

$versionId=(int)$chain['version_id'];
$sentBundle=v2q('SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$versionId])->fetch();
$acceptance=v2q('SELECT * FROM quote_acceptances WHERE quote_version_id=?',[$versionId])->fetch();
$bookingSnapshot=v2q('SELECT * FROM booking_quote_snapshots WHERE booking_id=?',[$bookingId])->fetch();
check((bool)$sentBundle && (bool)$acceptance && (bool)$bookingSnapshot,
    'Golden path: SENT bundle, accepted option and booking snapshot exist');
check((int)$bookingSnapshot['quote_version_id']===$versionId
    && (int)$bookingSnapshot['option_id']===(int)$acceptance['option_id']
    && hash_equals((string)$sentBundle['content_hash'],(string)$bookingSnapshot['source_hash'])
    && hash_equals((string)$acceptance['sent_content_hash'],(string)$bookingSnapshot['source_hash']),
    'Golden path: booking snapshot matches accepted SENT version and hash');

$public=QuoteVs2Repository::decode($bookingSnapshot['public_snapshot_json']);
$internal=QuoteVs2Repository::decode($bookingSnapshot['internal_snapshot_json']);
$selected=$internal['accepted_option']??null;
check(is_array($selected) && isset($selected['snapshot']['lines'])
    && count($public['options']??[])===1
    && (int)($public['options'][0]['id']??0)===(int)$acceptance['option_id'],
    'Golden path: customer proposal contains exactly the accepted option');
check(abs((float)$chain['confirmed_selling']-(float)$selected['snapshot']['pricing']['total_selling'])<0.01,
    'Golden path: booking confirmed total equals accepted immutable selling total');
$serviceCount=(int)v2q('SELECT COUNT(*) FROM booking_services WHERE booking_id=?',[$bookingId])->fetchColumn();
check($serviceCount===count($selected['snapshot']['lines']),
    'Golden path: booking services retain accepted option service count');

// Retry returns the existing booking instead of creating a duplicate.
$retry=QuoteOptions::booking($db,$user,$versionId);
check((int)$retry['id']===$bookingId
    && (int)v2q('SELECT COUNT(*) FROM bookings WHERE company_id=? AND quote_id=?',
        [(int)$user['company_id'],(int)$chain['quote_id']])->fetchColumn()===1,
    'Golden path: repeated booking submission is idempotent');
check(v2q('SELECT * FROM booking_quote_snapshots WHERE booking_id=?',[$bookingId])->fetch()===$bookingSnapshot
    && v2q('SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$versionId])->fetch()===$sentBundle,
    'Golden path: retry and template operations preserve issued snapshot bytes');

// The original fixture began with one idempotent public Lead request, a qualified
// Sales handover and a customer/inquiry conversion. The SAME trip reaches Booking.
check((int)$chain['inquiry_id']===(int)$conversion['inquiry_id']
    && (int)$chain['booking_trip_id']===(int)$conversion['trip_id'],
    'Golden path: qualified Lead conversion reaches this exact Inquiry and Booking');
check((int)v2q('SELECT COUNT(*) FROM lead_requests WHERE company_id=?',
    [(int)$user['company_id']])->fetchColumn()===1,
    'Golden path: inbound Lead submission retry did not create another lead request');

// Exercise the real handover state machine against this Booking's accepted
// immutable snapshot. This does NOT assert supplier availability/confirmation.
$handoverCreated=SalesHandover::create($db,$user,$bookingId);
$handover=SalesHandover::context($db,$user,$bookingId);
check((int)$handover['handover']['booking_id']===$bookingId
    && count($handover['services'])>0
    && (int)$handoverCreated['booking_id']===$bookingId,
    'Golden path: Sales handover is linked to this Booking and its services');
$handoverAgain=SalesHandover::create($db,$user,$bookingId);
check((int)$handoverAgain['booking_id']===$bookingId
    && $handoverAgain['source_hash']===$handoverCreated['source_hash']
    && (int)v2q('SELECT COUNT(*) FROM booking_handovers WHERE booking_id=?',
        [$bookingId])->fetchColumn()===1,
    'Golden path: repeated Sales handover remains idempotent');
$handoverChecklist=array_fill_keys(SalesHandover::CHECKLIST,true);
$handoverChecklist['flights']='NOT_PROVIDED';
$handover=SalesHandover::transition($db,$user,$bookingId,'prepare',[
    'expected_revision'=>(int)$handover['handover']['revision'],
    'checklist'=>$handoverChecklist
]);
$handover=SalesHandover::transition($db,$user,$bookingId,'submit',[
    'expected_revision'=>(int)$handover['handover']['revision']
]);
check($handover['handover']['intake_status']==='NEW_HANDOVER',
    'Golden path: submitted Sales handover enters Operations intake');
$handover=SalesHandover::transition($db,$user,$bookingId,'accept',[
    'expected_revision'=>(int)$handover['handover']['revision']
]);
check($handover['handover']['intake_status']==='READY_TO_BOOK'
    && (int)$handover['booking']['id']===$bookingId,
    'Golden path: Operations accepts the same Booking without creating another');
rejects(fn()=>SalesHandover::transition($db,$user,$bookingId,'accept',[
    'expected_revision'=>(int)$handover['handover']['revision']
]),DomainException::class,'Golden path: duplicate Operations acceptance is rejected');

// Draft commercial/proforma invoice only: no fake supplier invoice, real
// customer payment, e-invoice, or final profit is created by this test.
require_once __DIR__.'/../api/lib/FinanceLedger.php';
$invoice=FinanceLedger::createInvoice($db,$user,$bookingId,[
    'invoice_type'=>'PROFORMA',
    'subtotal'=>(string)$chain['confirmed_selling'],
    'issue_date'=>'2027-01-01',
    'due_date'=>'2027-01-02'
]);
$storedInvoice=v2q('SELECT * FROM customer_invoices WHERE id=? AND company_id=?',
    [(int)$invoice['id'],(int)$user['company_id']])->fetch();
check((bool)$storedInvoice
    && (int)$storedInvoice['booking_id']===$bookingId
    && $storedInvoice['status']==='DRAFT'
    && $storedInvoice['currency']===$selected['snapshot']['selling_currency']
    && FinanceLedger::cents($storedInvoice['total'])===FinanceLedger::cents($chain['confirmed_selling']),
    'Golden path: draft Finance document matches Booking selling value/currency');
rejects(fn()=>FinanceLedger::createInvoice($db,$user,$bookingId,[
    'invoice_type'=>'PROFORMA','subtotal'=>(string)$chain['confirmed_selling'],
    'issue_date'=>'2027-01-01'
]),DomainException::class,'Golden path: duplicate full-value billing is denied');
$finance=FinanceLedger::profit($db,(int)$user['company_id'],$bookingId);
check(abs((float)$finance['selling']-(float)$chain['confirmed_selling'])<0.01
    && (float)$finance['allocated_payments']===0.0
    && $finance['actual_final']===false,
    'Golden path: Finance recognizes confirmed selling but no invented receipts/final profit');
check(v2q('SELECT * FROM booking_quote_snapshots WHERE booking_id=?',[$bookingId])->fetch()===$bookingSnapshot
    && v2q('SELECT * FROM quote_sent_bundles WHERE quote_version_id=?',[$versionId])->fetch()===$sentBundle,
    'Golden path: Operations handover and draft Finance preserve issued snapshots');

// No fake PASS for WordPress inbound, real supplier confirmation, Operations
// acceptance, posting Finance entries or real browser/agency staging sessions.
echo "RC6.2 fixture-only cross-module continuity acceptance complete.\n";
