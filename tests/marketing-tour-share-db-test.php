<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MarketingTourShare.php';
require __DIR__.'/marketing-tour-advisor-db-test.php';

echo PHP_EOL."VTA P4 Tour Share integration tests".PHP_EOL;
$cid=(int)$first['conversation_id'];
$db->prepare("UPDATE social_conversations SET status='NEW' WHERE company_id=1 AND id=?")->execute([$cid]);
$manager=['company_id'=>1,'id'=>1];
$search=MarketingTourAdvisor::programs($db,$manager,$cid,'Changed',true);
$program=array_values(array_filter($search,fn($p)=>(int)$p['id']===100))[0]??null;
checked($program!==null,'updated ACTIVE tour is visible for review');
MarketingTourAdvisor::approve($db,$manager,['program_id'=>100,'review_hash'=>$program['review_hash']]);

$body=['conversation_id'=>$cid,'program_id'=>100,'request_key'=>'share_req_00000000001','expires_in_days'=>7];
$created=MarketingTourShare::create($db,$manager,$body);
checked(strlen((string)$created['token'])===64&&$created['id']>0,'share created with unguessable token');
$public=MarketingTourShare::view($db,$created['token']);
checked(($public['schema']??'')==='VTA_PUBLIC_TOUR_OUTLINE_V1'&&count($public['day_titles'])===3,'public link returns reviewed day titles only');
checked(!array_key_exists('source_text',$public)&&!array_key_exists('proposal',$public)&&!array_key_exists('price',$public),'public snapshot excludes private contents and prices');
$html=MarketingTourShare::html($public);
checked(str_contains($html,'Changed itinerary title')&&str_contains($html,'Arrival Hanoi'),'public HTML includes actual approved tour');
checked(!str_contains($html,'Supplier Cost')&&!str_contains($html,'commission'),'public HTML excludes supplier and cost fields');
$stored=$db->query('SELECT token_hash,view_count FROM marketing_tour_shares LIMIT 1')->fetch();
checked($stored['token_hash']===hash('sha256',$created['token'])&&$stored['token_hash']!==$created['token'],'database only stores hash of secret token');
checked((int)$stored['view_count']===1,'public page-open logged (not treated as read receipt)');
$duplicate=MarketingTourShare::create($db,$manager,$body);
checked($duplicate['replayed']===true&&$duplicate['token']===null&&$duplicate['requires_new_link']===true,'share request replay never discloses the token again');
checked((int)$db->query('SELECT COUNT(*) FROM marketing_tour_shares')->fetchColumn()===1,'same request key does not create duplicate shares');
$conflict=false;try{MarketingTourShare::create($db,$manager,array_replace($body,['program_id'=>101]));}
catch(DomainException $e){$conflict=true;}
checked($conflict,'request key reuse for different program blocked');
$tenant=false;try{MarketingTourShare::list($db,['company_id'=>2,'id'=>2],$cid);}
catch(OutOfBoundsException $e){$tenant=true;}
checked($tenant,'other company cannot enumerate conversation shares');
$one=MarketingTourShare::list($db,$manager,$cid);
checked(count($one)===1&&!array_key_exists('token',$one[0]),'list shares without returning bearer tokens');
$wrong=false;try{MarketingTourShare::view($db,str_repeat('a',64));}
catch(OutOfBoundsException $e){$wrong=true;}
checked($wrong,'invalid secret token returns not found');

$revoke=MarketingTourShare::revoke($db,$manager,(int)$created['id']);
checked($revoke['revoked']===true,'authorized staff can revoke link');
$replayRevoke=MarketingTourShare::revoke($db,$manager,(int)$created['id']);
checked($replayRevoke['replayed']===true,'revocation is idempotent');
$notFound=false;try{MarketingTourShare::view($db,$created['token']);}
catch(OutOfBoundsException $e){$notFound=true;}
checked($notFound,'revoked link cannot be opened');

$second=MarketingTourShare::create($db,$manager,array_replace($body,['request_key'=>'share_req_00000000002','expires_in_days'=>1]));
$db->prepare('UPDATE marketing_tour_shares SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE company_id=1 AND id=?')->execute([$second['id']]);
$expired=false;try{MarketingTourShare::view($db,$second['token']);}
catch(OutOfBoundsException $e){$expired=true;}
checked($expired,'expired bearer link does not open');

$third=MarketingTourShare::create($db,$manager,array_replace($body,['request_key'=>'share_req_00000000003']));
$db->exec("UPDATE tour_library_programs SET destination='Other destination' WHERE id=100");
$stale=false;try{MarketingTourShare::view($db,$third['token']);}
catch(OutOfBoundsException $e){$stale=true;}
checked($stale,'tour modification immediately invalidates existing public link');
$blocked=false;try{MarketingTourShare::create($db,$manager,array_replace($body,['request_key'=>'share_req_00000000004']));}
catch(DomainException $e){$blocked=true;}
checked($blocked,'cannot create public links without current approval');

$db->exec("UPDATE tour_library_programs SET title='<img src=x onerror=alert(1)>' WHERE id=100");
$latest=MarketingTourAdvisor::programs($db,$manager,$cid,'',true);
$unsafe=array_values(array_filter($latest,fn($x)=>$x['id']===100))[0];
MarketingTourAdvisor::approve($db,$manager,['program_id'=>100,'review_hash'=>$unsafe['review_hash']]);
$xssShare=MarketingTourShare::create($db,$manager,array_replace($body,['request_key'=>'share_req_00000000005']));
$xssHtml=MarketingTourShare::html(MarketingTourShare::view($db,$xssShare['token']));
checked(!str_contains($xssHtml,'<img')&&str_contains($xssHtml,'&lt;img'),'untrusted title is HTML-escaped even if incorrectly approved');

$badDuration=false;try{MarketingTourShare::create($db,$manager,array_replace($body,['request_key'=>'share_req_00000000006','expires_in_days'=>365]));}
catch(InvalidArgumentException $e){$badDuration=true;}
checked($badDuration,'long lived share links rejected');
echo "P4 public tour share MariaDB integration checks completed.".PHP_EOL;
