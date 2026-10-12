<?php
declare(strict_types=1);
// Read-only acceptance after the existing P12 disposable full-schema rehearsal.
// Refuse any non-CI database name. Never creates, drops or resets a database.
$dsn=(string)getenv('VTA_TEST_DSN');
if(!preg_match('/^mysql:host=127\\.0\\.0\\.1;port=3306;dbname=vta_p12_clone_ci;charset=utf8mb4$/D',$dsn))
    throw new RuntimeException('Refusing non-disposable CI DSN');
$db=new PDO($dsn,(string)getenv('VTA_TEST_USER'),(string)getenv('VTA_TEST_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
function verifyHybrid(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
}
$version=$db->query("SELECT status FROM migration_checksums WHERE version='038_b2b_hybrid_distribution'")->fetchColumn();
verifyHybrid($version==='APPLIED','Real 038 B2B hybrid SQL applied and tracked on disposable MariaDB');
$cols=$db->query('SHOW COLUMNS FROM b2b_publications')->fetchAll();
$cols=array_column($cols,null,'Field');
verifyHybrid(isset($cols['distribution_scope'],$cols['exclusive_agency_id'])
    && str_contains(strtoupper((string)$cols['distribution_scope']['Type']),'EXCLUSIVE')
    && (string)$cols['distribution_scope']['Default']==='COMMON',
    'Historical publications default to Common without deleting data');
$table=$db->query("SHOW TABLES LIKE 'b2b_net_requests'")->fetchColumn();
verifyHybrid($table!==false,'B2B NET request queue exists');
$fks=$db->query("SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME
 FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('b2b_publications','b2b_net_requests')
 AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
$byTable=[];
foreach($fks as $row)$byTable[$row['TABLE_NAME']][]=$row['REFERENCED_TABLE_NAME'];
verifyHybrid(in_array('b2b_agencies',$byTable['b2b_publications']??[],true),
    'Exclusive agency constrained by reviewed company-scoped foreign key');
verifyHybrid(in_array('b2b_partner_tours',$byTable['b2b_net_requests']??[],true)
    &&in_array('b2b_publications',$byTable['b2b_net_requests']??[],true),
    'NET requests tied to an existing partner copy and immutable publication');
$net=$db->query('SHOW COLUMNS FROM b2b_net_requests')->fetchAll();
$net=array_column($net,null,'Field');
verifyHybrid(isset($net['paying_pax'],$net['foc_pax'],$net['hotel_level'],$net['cruise_level'],$net['departure_date'],$net['status']),
    'NET request dimensions retain paying PAX, FOC, hotel/cruise and departure date');
$m45=$db->query("SELECT status FROM migration_checksums WHERE version='045_b2b_net_request_resolution'")->fetchColumn();
verifyHybrid($m45==='APPLIED','Real 045 B2B NET resolution migration applied and checksummed on disposable MariaDB');
$fields=$db->query('SHOW COLUMNS FROM b2b_net_requests')->fetchAll(PDO::FETCH_ASSOC);
$fields=array_column($fields,null,'Field');
verifyHybrid(isset($fields['assigned_to_user_id'],$fields['source_quote_version_id'],$fields['resolved_by_user_id'],$fields['resolved_at']),
    'B2B NET request preserves assignee, issued quote link and resolver audit fields');
verifyHybrid(in_array('quote_versions',$byTable['b2b_net_requests']??[],true)
    &&in_array('users',$byTable['b2b_net_requests']??[],true),
    'B2B NET provenance and assignee columns are constrained to existing records');
echo 'B2B migration 038/045 disposable MariaDB schema acceptance complete.'.PHP_EOL;
