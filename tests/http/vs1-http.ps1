param([string]$PhpPath=$env:VTA_TEST_PHP)
$ErrorActionPreference='Stop'
if(-not $PhpPath -or -not (Test-Path -LiteralPath $PhpPath)){throw 'Set VTA_TEST_PHP to the prepared local native php.exe.'}
$base='http://127.0.0.1:8873/api/index.php?route='
$session=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$script:token='';$script:vs1Pass=0;$script:vs1Checks=[Collections.Generic.List[string]]::new()
function Check($condition,$name){if(-not $condition){throw "FAIL $name"};$script:vs1Pass++;$script:vs1Checks.Add("PASS $name")}
function CallApi($route,$method='GET',$body=$null,$csrf=$script:token,$web=$session){
 $params=@{Uri=($base+$route);Method=$method;WebSession=$web;SkipHttpErrorCheck=$true;Headers=@{'X-VTA-CSRF'=$csrf};ContentType='application/json'}
 if($null -ne $body){$params.Body=ConvertTo-Json $body -Depth 20 -Compress}
 $r=Invoke-WebRequest @params
 try{$data=$r.Content|ConvertFrom-Json}catch{throw "Non-JSON dispatcher response for $route (HTTP $($r.StatusCode))"}
 return @{Status=[int]$r.StatusCode;Data=$data}
}
function ActionKey{return ('vs1-'+[guid]::NewGuid().ToString())}
function Counts{ $s=& $PhpPath "$PSScriptRoot/vs1-fixtures.php" counts;if($LASTEXITCODE -ne 0){throw 'Native HTTP count verification failed'};return ($s|ConvertFrom-Json) }
function SameCounts($a,$b,$label){Check ((ConvertTo-Json $a -Compress) -eq (ConvertTo-Json $b -Compress)) $label}
function GetLead($request){$r=CallApi "lead-hub/leads&request_id=$request";Check ($r.Status -eq 200 -and @($r.Data.items).Count -eq 1) "resolve scoped lead for request $request";return $r.Data.items[0]}
function Qualify($request,$owner=$fixtures.users.'lead-manager'.id){
 $r=CallApi "lead-hub/requests&request_id=$request";$body=@{qualification_note='Dates and commercial scope reviewed';owner_user_id=$owner;next_action_due='2027-02-01 12:00:00';expected_version=$r.Data.items[0].version_no;action_key=(ActionKey)}
 $r=CallApi "lead-hub/requests/$request/qualify" 'POST' $body;Check ($r.Status -eq 200) "qualify request $request";return @{Lead=(GetLead $request);Body=$body;Result=$r.Data}
}
function Accept($lead){$body=@{expected_version=$lead.handover_version;action_key=(ActionKey);sales_owner_user_id=$fixtures.users.'convert-no-create'.id;next_action_due='2027-02-02 09:00:00'};$r=CallApi "lead-hub/leads/$($lead.id)/accept" 'POST' $body;Check ($r.Status -eq 200) "Sales accepts lead $($lead.id)";return @{Lead=(GetLead $lead.request_id);Body=$body;Result=$r.Data}}
function Review($lead,$web=$session,$csrf=$script:token){$r=CallApi "lead-hub/leads/$($lead.id)/customer-candidates" 'GET' $null $csrf $web;Check ($r.Status -eq 200 -and $r.Data.identity_review_key -and $r.Data.handover_version -eq $lead.handover_version) 'identity candidate review receives version-bound nonce';return $r.Data}
function ConvertBody($lead,$review){return @{expected_version=$lead.handover_version;action_key=(ActionKey);identity_mode='LINK_EXISTING';identity_reviewed=$true;identity_review_key=$review.identity_review_key;customer_id=$fixtures.customer;agent_id=$fixtures.agent}}

try {
& $PhpPath "$PSScriptRoot/vs1-fixtures.php"
if($LASTEXITCODE -ne 0){throw 'VS1 fixture preparation failed'}
$configPath=if($env:VTA_CONFIG_FILE){$env:VTA_CONFIG_FILE}else{Join-Path (Resolve-Path "$PSScriptRoot/../../..").Path 'runtime/http-config.php'}
$fixtures=Get-Content -LiteralPath (Join-Path (Split-Path $configPath -Parent) 'vs1-http-fixtures.json') -Raw | ConvertFrom-Json
$anonymous=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r=CallApi "customers/$($fixtures.customer)/360" 'GET' $null '' $anonymous;Check ($r.Status -eq 401) 'Customer360 requires authenticated dispatcher session'
$r=CallApi 'auth/login' 'POST' @{email='admin@example.invalid';password='Local-Http-Test-2026'};Check ($r.Status -eq 200) 'VS1 admin login';$script:token=$r.Data.user.csrf
$roles=@{}
foreach($name in @('lead-manager','sales-accept','sales-read','crm-reader','finance-one','finance-full','convert-no-create','lead-read','admin-deny')){
 $web=New-Object Microsoft.PowerShell.Commands.WebRequestSession;$r=CallApi 'auth/login' 'POST' @{email=$fixtures.users.$name.email;password='Local-Http-Test-2026'} '' $web
 Check ($r.Status -eq 200) "login scoped VS1 $name";$roles[$name]=@{Session=$web;Token=$r.Data.user.csrf;User=$r.Data.user}
}
$before=Counts
$r=CallApi "lead-hub/requests/$($fixtures.requests.invalid)/qualify" 'POST' @{qualification_note='Reviewed';expected_version=1;action_key=(ActionKey)} 'wrong';Check ($r.Status -eq 419) 'VS1 qualify requires CSRF'
foreach($owner in @($fixtures.foreign_user,$fixtures.inactive_user)){
 $r=CallApi "lead-hub/requests/$($fixtures.requests.invalid)/qualify" 'POST' @{qualification_note='Reviewed';owner_user_id=$owner;expected_version=1;action_key=(ActionKey);next_action_due='2027-02-02 09:00:00'}
 Check ($r.Status -eq 422) 'qualify rejects foreign or inactive owner before writes'
}
$r=CallApi "lead-hub/requests/$($fixtures.foreign_request)/qualify" 'POST' @{qualification_note='Reviewed';expected_version=1;action_key=(ActionKey)};Check ($r.Status -eq 404) 'foreign request qualification remains hidden'
SameCounts $before (Counts) 'failed qualify/CSRF/tenant checks leave no partial lead/history/task/event'

$q=Qualify $fixtures.requests.main;$main=$q.Lead;$qualifiedCounts=Counts
Check ($main.handover_status -eq 'PENDING' -and $main.status -eq 'QUALIFIED') 'MQL starts PENDING without an inquiry'
$r=CallApi "lead-hub/requests/$($main.request_id)/qualify" 'POST' $q.Body;Check ($r.Status -eq 200 -and $r.Data.id -eq $main.id) 'qualify exact command retry reuses same MQL'
SameCounts $qualifiedCounts (Counts) 'qualify retry does not duplicate internal tasks or events'
$r=CallApi "lead-hub/leads/$($main.id)/convert" 'POST' @{expected_version=$main.handover_version;action_key=(ActionKey);identity_reviewed=$true;identity_mode='LINK_EXISTING';customer_id=$fixtures.customer};Check ($r.Status -eq 409) 'PENDING MQL cannot bypass Sales acceptance'
$badAccept=@{expected_version=$main.handover_version;action_key=(ActionKey);sales_owner_user_id=$fixtures.foreign_user;next_action_due='2027-02-02 09:00:00'}
$r=CallApi "lead-hub/leads/$($main.id)/accept" 'POST' $badAccept;Check ($r.Status -eq 422) 'accept validates same-company active Sales owner'
foreach($name in @('lead-manager','lead-read','admin-deny')){$role=$roles[$name];$r=CallApi "lead-hub/leads/$($main.id)/accept" 'POST' @{expected_version=$main.handover_version;action_key=(ActionKey)} $role.Token $role.Session;Check ($r.Status -eq 403) "$name cannot accept without effective Sales handover permission"}
SameCounts $qualifiedCounts (Counts) 'blocked acceptance/early conversion has no downstream effects'
$a=Accept $main;$main=$a.Lead;$acceptedCounts=Counts
Check ($main.handover_status -eq 'ACCEPTED' -and $main.sales_owner_user_id -eq $fixtures.users.'convert-no-create'.id) 'Sales acceptance records owner and stage'
$r=CallApi "lead-hub/leads/$($main.id)/accept" 'POST' $a.Body;Check ($r.Status -eq 200) 'accept exact key replay succeeds'
SameCounts $acceptedCounts (Counts) 'accept retry has exactly one effect set'
$changed=@{}+$a.Body;$changed.sales_owner_user_id=$fixtures.users.'crm-reader'.id
$r=CallApi "lead-hub/leads/$($main.id)/accept" 'POST' $changed;Check ($r.Status -eq 409) 'accept retry key reused with different payload conflicts'
$stale=@{}+$a.Body;$stale.action_key=ActionKey;$r=CallApi "lead-hub/leads/$($main.id)/return" 'POST' ($stale+@{reason='Stale acceptance must be reviewed'});Check ($r.Status -eq 409) 'stale handover version blocks mutation'
$review=Review $main
$candidateIds=@($review.items | ForEach-Object {[long]$_.id})
Check ($candidateIds -contains $fixtures.customer -and $candidateIds -notcontains $fixtures.same_name_customer -and $candidateIds -notcontains $fixtures.foreign_customer -and $candidateIds -notcontains $fixtures.inactive_customer) 'duplicate suggestions use exact contacts and exclude same-name foreign/inactive customers'
$r=CallApi "lead-hub/leads/$($main.id)/customer-candidates" 'GET' $null $roles['lead-read'].Token $roles['lead-read'].Session;Check ($r.Status -eq 403) 'candidate contact list needs sales.view as well as lead.view'
$convert=ConvertBody $main $review
foreach($patch in @(@{identity_reviewed=$false},@{identity_review_key='invalid'},@{customer_id=$fixtures.foreign_customer},@{customer_id=$fixtures.inactive_customer},@{agent_id=$fixtures.foreign_agent},@{agent_id=$fixtures.inactive_agent})){
 $body=@{}+$convert;foreach($key in $patch.Keys){$body[$key]=$patch[$key]};$body.action_key=ActionKey
 $r=CallApi "lead-hub/leads/$($main.id)/convert" 'POST' $body
 Check ($r.Status -in @(409,422)) 'conversion requires explicit reviewed identity and active same-company links'
}
SameCounts $acceptedCounts (Counts) 'failed identity/link checks create no customer/trip/inquiry/tasks/event'
$r=CallApi "lead-hub/leads/$($main.id)/convert" 'POST' $convert;Check ($r.Status -eq 200 -and $r.Data.trip_id -and $r.Data.inquiry_id) 'accepted reviewed identity converts using shared customer/trip/inquiry'
$conversion=$r.Data;$convertedCounts=Counts
Check ($convertedCounts.trips -eq ($acceptedCounts.trips+1) -and $convertedCounts.inquiries -eq ($acceptedCounts.inquiries+1) -and $convertedCounts.customers -eq $acceptedCounts.customers) 'LINK_EXISTING produces exactly one trip/inquiry and no duplicate customer'
$r=CallApi "lead-hub/leads/$($main.id)/convert" 'POST' $convert;Check ($r.Status -eq 200 -and $r.Data.trip_id -eq $conversion.trip_id -and $r.Data.inquiry_id -eq $conversion.inquiry_id) 'convert command retry replays original downstream identities'
$r=CallApi "lead-hub/leads/$($main.id)/convert" 'POST' @{};Check ($r.Status -eq 200 -and $r.Data.inquiry_id -eq $conversion.inquiry_id) 'historical converted retry without new payload remains readable'
SameCounts $convertedCounts (Counts) 'all converted retries leave customer/trip/inquiry/task/outbox counts unchanged'
$r=CallApi "inquiries&id=$($conversion.inquiry_id)";Check ($r.Status -eq 200 -and $r.Data.items[0].trip_id -eq $conversion.trip_id) 'existing inquiry dispatcher receives the converted opportunity'
$main=GetLead $main.request_id
Check ($main.campaign_id -eq $fixtures.campaign -and $main.source -eq 'FACEBOOK' -and $main.attribution_json -match 'VS1_ATTRIBUTION') 'source/campaign attribution survives full handover'

$q=Qualify $fixtures.requests.return;$returned=$q.Lead
$before=Counts
$r=CallApi "lead-hub/leads/$($returned.id)/return" 'POST' @{expected_version=$returned.handover_version;action_key=(ActionKey);reason=''};Check ($r.Status -eq 422) 'Sales return requires a nonempty review reason'
SameCounts $before (Counts) 'invalid return reason leaves state and effects unchanged'
$body=@{expected_version=$returned.handover_version;action_key=(ActionKey);reason='Need confirmed dates and a direct contact'}
$role=$roles['sales-accept'];$r=CallApi "lead-hub/leads/$($returned.id)/return" 'POST' $body $role.Token $role.Session;Check ($r.Status -eq 200) 'authorized Sales-only role returns MQL with reason'
$returned=GetLead $returned.request_id;Check ($returned.handover_status -eq 'RETURNED' -and $returned.return_reason -eq $body.reason) 'returned stage preserves reason and original lead'
$returnCounts=Counts;$r=CallApi "lead-hub/leads/$($returned.id)/return" 'POST' $body $role.Token $role.Session;Check ($r.Status -eq 200) 'Sales return exact retry replays receipt';SameCounts $returnCounts (Counts) 'return retry adds no history/event/task'
$r=CallApi "lead-hub/leads/$($returned.id)/accept" 'POST' @{expected_version=$returned.handover_version;action_key=(ActionKey)};Check ($r.Status -eq 409) 'returned lead must be requalified before acceptance'
$body=@{expected_version=$returned.handover_version;action_key=(ActionKey);qualification_note='Dates verified after Sales review';owner_user_id=$fixtures.users.'lead-manager'.id;next_action_due='2027-02-03 11:00:00'}
$role=$roles['lead-manager'];$r=CallApi "lead-hub/leads/$($returned.id)/resubmit" 'POST' $body $role.Token $role.Session;Check ($r.Status -eq 200) 'Marketing resubmits returned lead using original identity'
$returned=GetLead $returned.request_id;Check ($returned.handover_status -eq 'PENDING') 'resubmit restores PENDING review gate'
$resubmitCounts=Counts;$r=CallApi "lead-hub/leads/$($returned.id)/resubmit" 'POST' $body $role.Token $role.Session;Check ($r.Status -eq 200) 'resubmit exact retry succeeds';SameCounts $resubmitCounts (Counts) 'resubmit retry does not duplicate effects'
$a=Accept $returned;$returned=$a.Lead;$rev=Review $returned;$body=ConvertBody $returned $rev;$r=CallApi "lead-hub/leads/$($returned.id)/convert" 'POST' $body;Check ($r.Status -eq 200) 'return/requalify/accept reaches existing opportunity flow'

$q=Qualify $fixtures.requests.'new-customer';$a=Accept $q.Lead;$new=$a.Lead
$role=$roles['convert-no-create'];$review=Review $new $role.Session $role.Token
$body=@{expected_version=$new.handover_version;action_key=(ActionKey);identity_mode='CREATE_NEW';identity_reviewed=$true;identity_review_key=$review.identity_review_key;duplicate_reason='Reviewed separate traveler using a shared family contact';new_customer=@{full_name='VS1 reviewed new customer';email=('new-'+$fixtures.suffix+'@example.invalid');whatsapp='+84000123';market='VN'}}
$before=Counts;$r=CallApi "lead-hub/leads/$($new.id)/convert" 'POST' $body $role.Token $role.Session;Check ($r.Status -eq 403) 'CREATE_NEW requires customer.manage beyond conversion permissions';SameCounts $before (Counts) 'customer permission denial creates no partial opportunity'
$review=Review $new;$body.identity_review_key=$review.identity_review_key;$body.action_key=ActionKey
$r=CallApi "lead-hub/leads/$($new.id)/convert" 'POST' $body;Check ($r.Status -eq 200) 'explicit reviewed new identity creates customer and shared opportunity'
$after=Counts;Check ($after.customers -eq ($before.customers+1) -and $after.trips -eq ($before.trips+1) -and $after.inquiries -eq ($before.inquiries+1)) 'new reviewed identity is persisted once in each shared table'
$r=CallApi "lead-hub/leads/$($new.id)/convert" 'POST' $body;Check ($r.Status -eq 200) 'CREATE_NEW retry replays shared identity';SameCounts $after (Counts) 'CREATE_NEW retry has no duplicate customer or effects'

# Expanded requests never bypass section and field permissions.
$expanded="customers/$($fixtures.customer)/360&include=all&expand=passport,payments,documents,audit,conversations&fields=*"
foreach($name in @('sales-read','crm-reader','finance-one','finance-full')){
 $role=$roles[$name];$r=CallApi $expanded 'GET' $null $role.Token $role.Session
 Check ($r.Status -eq 200 -and $r.Data.customer.id -eq $fixtures.customer) "$name can open permitted Customer360 projection"
 $json=ConvertTo-Json $r.Data -Depth 30 -Compress
 Check ($json -notmatch 'VS1_PRIVATE_|VS1_SECRET_|passport_number|snapshot_json|storage_key|transaction_reference|after_json|cost_json|profit_amount') "$name expanded projection omits passport snapshots costs payments conversations and raw audit canaries"
 if($name -eq 'sales-read'){
  foreach($section in @('leads','bookings','tasks','documents','finance')){Check (-not $r.Data.sections.$section.available -and @($r.Data.sections.$section.items).Count -eq 0 -and $null -eq $r.Data.sections.$section.total) "sales-read section $section is explicitly redacted"}
  Check (@($r.Data.sections.activity.items|Where-Object entity_type -in @('lead','booking')).Count -eq 0) 'activity does not expose events from redacted sections'
  Check (@($r.Data.sections.activity.items|Where-Object entity_type -eq 'inquiry').Count -gt 0 -and @($r.Data.sections.activity.items|Where-Object entity_type -eq 'inquirie').Count -eq 0) 'Customer360 activity uses the shared inquiry entity type'
 }
 if($name -eq 'crm-reader'){
  foreach($section in @('leads','bookings','tasks','documents')){Check ($r.Data.sections.$section.available -and @($r.Data.sections.$section.items).Count -gt 0) "CRM reader receives permitted $section metadata"}
  Check ($json -notmatch 'VS1_OTHER_OWNER_TASK') 'Customer360 task section preserves owner scope without approval.manage'
 }
 if($name -ne 'finance-full'){Check (-not $r.Data.sections.finance.available -and @($r.Data.sections.finance.items).Count -eq 0) "$name cannot read finance without both required permissions"}
 else{
  $usd=@($r.Data.sections.finance.items|Where-Object currency -eq 'USD')[0];$vnd=@($r.Data.sections.finance.items|Where-Object currency -eq 'VND')[0]
  Check ($r.Data.sections.finance.available -and [decimal]$usd.total -eq 100 -and [decimal]$usd.paid -eq 25 -and [decimal]$usd.balance -eq 75 -and $usd.invoice_count -eq 1) 'USD finance excludes draft cancelled receipt and credit-note documents'
  Check ([decimal]$vnd.total -eq 500000 -and [decimal]$vnd.paid -eq 100000 -and [decimal]$vnd.balance -eq 400000 -and $vnd.invoice_count -eq 1) 'Customer360 finance groups VND independently without raw currency sum'
 }
}
foreach($name in @('lead-read','admin-deny')){$role=$roles[$name];$r=CallApi $expanded 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 403) "$name cannot bypass sales.view on Customer360"}
$r=CallApi "customers/$($fixtures.foreign_customer)/360&include=all";Check ($r.Status -eq 404) 'Customer360 foreign tenant customer returns no metadata'
$r=CallApi "customers/$($fixtures.customer)/360" 'POST' @{};Check ($r.Status -eq 405) 'Customer360 read projection does not accept mutations'
} finally { foreach($passedCheck in $script:vs1Checks){Write-Output $passedCheck} }
Write-Output "VS1 actual-dispatcher checks complete: $script:vs1Pass passed assertions."
