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

// No fake PASS for WordPress inbound, real supplier confirmation, Operations
// acceptance, posting Finance entries or real browser/agency staging sessions.
echo "RC6.2 fixture-only cross-module continuity acceptance complete.\n";
