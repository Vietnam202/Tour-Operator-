param([string]$PhpPath=$env:VTA_TEST_PHP)
$ErrorActionPreference='Stop'
if(-not $PhpPath -or -not (Test-Path -LiteralPath $PhpPath)){throw 'Set VTA_TEST_PHP to the prepared local native php.exe before running this suite.'}
# This deliberately executes the real dispatcher chain once and leaves its identifiers/session available.
. "$PSScriptRoot/extended-http.ps1"
& $PhpPath "$PSScriptRoot/vs0-fixtures.php"
if($LASTEXITCODE -ne 0){throw 'VS0 fixture preparation failed.'}
$configPath=if($env:VTA_CONFIG_FILE){$env:VTA_CONFIG_FILE}else{Join-Path (Resolve-Path "$PSScriptRoot/../../..").Path 'runtime/http-config.php'}
$fixturePath=Join-Path (Split-Path $configPath -Parent) 'vs0-http-fixtures.json'
$fixtures=Get-Content -LiteralPath $fixturePath -Raw | ConvertFrom-Json
Check ($fixtures.expected_new_leads -gt 300) 'fixture count exceeds old UI list limit'

$anonymous=New-Object Microsoft.PowerShell.Commands.WebRequestSession
foreach($route in @('workspace/home','workspace/sales')){$r=CallApi $route 'GET' $null '' $anonymous;Check ($r.Status -eq 401) "anonymous denied $route"}
$roles=@{}
foreach($name in @('lead-only','campaign-only','task-only','booking-only','sales-only','report-only','admin-deny')){
 $roleSession=New-Object Microsoft.PowerShell.Commands.WebRequestSession
 $r=CallApi 'auth/login' 'POST' @{email=$fixtures.users.$name.email;password='Local-Http-Test-2026'} '' $roleSession
 Check ($r.Status -eq 200) "login scoped $name user"
 $roles[$name]=@{Session=$roleSession;Token=$r.Data.user.csrf;User=$r.Data.user}
}

$role=$roles['lead-only'];$r=CallApi 'workspace/home' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and $r.Data.access.marketing -and $r.Data.access.sales -and -not $r.Data.access.operations -and -not $r.Data.access.tasks) 'lead-only Home access follows lead.view'
Check ($r.Data.metrics.marketing_pending -eq 2 -and $r.Data.metrics.new_leads -eq $fixtures.expected_new_leads) 'lead-only counts exclude foreign pending marketing and leads'
foreach($key in @('sales_followups_due','departures_today','bookings_at_risk','my_tasks_due')){Check ($null -eq $r.Data.metrics.$key) "lead-only hides $key"}
$r=CallApi 'workspace/sales' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and $r.Data.metrics.new_leads -eq $fixtures.expected_new_leads -and $r.Data.metrics.need_qualification -eq $fixtures.expected_need_qualification) 'lead-only Sales returns full real lead counts'
Check (@($r.Data.queues.new_leads.items).Count -eq 6) 'bounded six-row queue does not truncate aggregate count'
foreach($key in @('followup_today','overdue_followup','draft_quotes','sent_quotes','waiting_client','confirmed_today','lost')){Check ($null -eq $r.Data.metrics.$key -and -not $r.Data.queues.$key.available -and @($r.Data.queues.$key.items).Count -eq 0) "lead-only hides $key queue"}
$r=CallApi 'workspace/sales&queue=new_leads&limit=100&offset=300' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and $r.Data.queues.new_leads.total -eq $fixtures.expected_new_leads -and @($r.Data.queues.new_leads.items).Count -eq ($fixtures.expected_new_leads-300)) 'queue pagination reaches real rows beyond 300'
$r=CallApi 'lead-hub/requests' 'GET' $null $role.Token $role.Session
Check (@($r.Data.items).Count -eq 100 -and $r.Data.total -gt 300 -and @($r.Data.items | Where-Object id -eq $fixtures.old_request_id).Count -eq 0) 'older request lies outside paged list while full total remains visible'
$r=CallApi "lead-hub/requests&request_id=$($fixtures.old_request_id)" 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and @($r.Data.items).Count -eq 1 -and $r.Data.items[0].id -eq $fixtures.old_request_id) 'focused request id resolves an older record'
$r=CallApi "lead-hub/leads&request_id=$($fixtures.old_request_id)" 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and @($r.Data.items).Count -eq 1 -and $r.Data.items[0].id -eq $fixtures.old_lead_id) 'focused request id resolves its linked lead'
foreach($route in @('lead-hub/requests','lead-hub/leads')){$r=CallApi "$route&request_id=$($fixtures.foreign_request_id)" 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 200 -and @($r.Data.items).Count -eq 0) "focused foreign id remains hidden in $route"}
$r=CallApi 'inquiries' 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 403) 'lead.view does not grant sales inquiry read'

$role=$roles['campaign-only'];$r=CallApi 'workspace/home' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and -not $r.Data.access.marketing -and -not $r.Data.access.sales) 'campaign write permission grants no Marketing or Sales read access'
foreach($metric in $r.Data.metrics.PSObject.Properties){Check ($null -eq $metric.Value) "campaign-only hides $($metric.Name)"}
foreach($route in @('workspace/sales','lead-hub/requests')){$r=CallApi $route 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 403) "campaign-only denied $route"}

$role=$roles['task-only'];$r=CallApi 'workspace/home' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and $r.Data.access.tasks -and -not $r.Data.access.sales -and -not $r.Data.access.operations -and $r.Data.metrics.my_tasks_due -eq 2) 'task-only Home counts only own due OPEN and SNOOZED tasks'
$actualTaskIds=@($r.Data.attention.tasks.items | ForEach-Object { [long]$_.id } | Sort-Object)
$expectedTaskIds=@($fixtures.task_due_ids | ForEach-Object { [long]$_ } | Sort-Object)
Check (($actualTaskIds -join ',') -eq ($expectedTaskIds -join ',')) 'task-only attention excludes other owner foreign done future and undated tasks'
$r=CallApi 'workspace/sales' 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 403) 'task-only denied Sales aggregate'
$r=CallApi 'tasks&scope=mine' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and @($r.Data.items | Where-Object owner_user_id -ne $role.User.id).Count -eq 0) 'task-only default task list stays owner scoped'

$role=$roles['booking-only'];$r=CallApi 'workspace/home' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and $r.Data.access.sales -and -not $r.Data.access.operations -and $null -eq $r.Data.metrics.departures_today) 'booking-only opens its Sales queue without operations read access'
$r=CallApi 'workspace/sales' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and $r.Data.metrics.confirmed_today -eq $fixtures.expected_confirmed_today -and $r.Data.queues.confirmed_today.available) 'booking-only count excludes foreign tenant bookings'
foreach($key in @('new_leads','need_qualification','followup_today','overdue_followup','draft_quotes','sent_quotes','waiting_client','lost')){Check ($null -eq $r.Data.metrics.$key -and -not $r.Data.queues.$key.available) "booking-only hides $key"}
$r=CallApi 'bookings' 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 200 -and @($r.Data.items | Where-Object id -eq $fixtures.foreign_booking_id).Count -eq 0) 'booking list remains tenant scoped'

$role=$roles['sales-only'];$r=CallApi 'inquiries' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and @($r.Data.items).Count -eq 300 -and @($r.Data.items | Where-Object id -eq $fixtures.old_inquiry_id).Count -eq 0) 'focused inquiry fixture lies beyond bounded legacy list'
$r=CallApi "inquiries&id=$($fixtures.old_inquiry_id)" 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and @($r.Data.items).Count -eq 1 -and $r.Data.items[0].id -eq $fixtures.old_inquiry_id) 'focused inquiry id resolves older record'
$r=CallApi "inquiries&id=$($fixtures.foreign_inquiry_id)" 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 200 -and @($r.Data.items).Count -eq 0) 'focused inquiry id never returns another tenant record'
$r=CallApi 'workspace/sales' 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 200 -and $null -eq $r.Data.metrics.new_leads -and $null -eq $r.Data.metrics.confirmed_today -and $r.Data.queues.followup_today.available) 'sales-only reads inquiry/quote domains while hiding lead and booking domains'

$role=$roles['admin-deny'];Check ($role.User.role_code -eq 'ADMIN' -and $role.User.permissions -notcontains 'lead.view' -and $role.User.permissions -notcontains 'task.view') 'ADMIN login exposes effective per-user permission denies'
$r=CallApi 'workspace/home' 'GET' $null $role.Token $role.Session
Check ($r.Status -eq 200 -and -not $r.Data.access.marketing -and -not $r.Data.access.sales -and -not $r.Data.access.operations -and -not $r.Data.access.tasks) 'ADMIN role label cannot bypass explicit domain deny'
foreach($metric in $r.Data.metrics.PSObject.Properties){Check ($null -eq $metric.Value) "denied ADMIN hides $($metric.Name)"}
$r=CallApi 'workspace/sales' 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 403) 'explicit ADMIN denies block Sales aggregate'
$month=(Get-Date).ToString('yyyy-MM')
foreach($name in @('report-only','admin-deny')){$role=$roles[$name];$r=CallApi "reports/summary&month=$month" 'GET' $null $role.Token $role.Session;Check ($r.Status -eq 200 -and $null -eq $r.Data.profit_by_currency -and @($r.Data.profit_review_required).Count -eq 0) "$name cannot read profit through Reports"}
$r=CallApi 'reports/summary&month=2026-13';Check ($r.Status -eq 422) 'Reports rejects malformed calendar month over dispatcher'
$allSales=CallApi 'workspace/sales'
foreach($key in @('new_leads','need_qualification','followup_today','overdue_followup','draft_quotes','sent_quotes','waiting_client','confirmed_today','lost')){
 $r=CallApi "workspace/sales&queue=$key&limit=100"
 Check ($r.Status -eq 200 -and $r.Data.queues.$key.total -eq $allSales.Data.metrics.$key -and @($r.Data.queues.PSObject.Properties).Count -eq 1) "filtered $key queue and card share aggregate predicate"
}

# Valid synthetic requests must fail because of the close marker, not unrelated validation/balance errors.
$r=CallApi "bookings/$booking/finance-close" 'POST' @{};Check ($r.Status -eq 200) 'settled operation-completed booking closes finance through dispatcher'
$r=CallApi "bookings/$booking";Check ($r.Data.booking.finance_closed_at -and $r.Data.booking.operations_status -eq 'COMPLETED') 'finance-close persists marker and completed phase'
$closedCases=@(
 @{Route="bookings/$booking/services";Method='POST';Body=@{category='HOTEL';service_name='Closed test';planned_cost='1.00';cost_currency='USD'}},
 @{Route="services/$($svc.id)";Method='PUT';Body=@{notes='Closed service edit'}},
 @{Route="services/$($svc.id)/travel-details";Method='PUT';Body=@{meeting_point='Closed edit'}},
 @{Route="bookings/$booking/generate-orders";Method='POST';Body=@{}},
 @{Route="supplier-orders/$order/send";Method='POST';Body=@{}},
 @{Route="bookings/$booking/invoices";Method='POST';Body=@{subtotal='1.00';invoice_type='PROFORMA'}},
 @{Route="invoices/$($invoiceIds[0])/issue";Method='POST';Body=@{}},
 @{Route="invoices/$($invoiceIds[0])/commercial";Method='POST';Body=@{issue_date='2026-09-21'}},
 @{Route="bookings/$booking/receipts";Method='POST';Body=@{amount='1.00';currency='USD';payment_date='2026-09-21';transaction_reference=('VS0-CLOSED-'+[guid]::NewGuid());idempotency_key=[guid]::NewGuid().ToString()}},
 @{Route="receipts/$receipt/allocate";Method='POST';Body=@{request_key=[guid]::NewGuid().ToString();allocation_date='2026-09-21';items=@(@{invoice_id=$invoiceIds[0];amount='1.00'})}},
 @{Route="payables/$($payables[0].id)/payments";Method='POST';Body=@{amount='1.00';currency='USD';payment_date='2026-09-21';transaction_reference='VS0-CLOSED-SUP';idempotency_key=[guid]::NewGuid().ToString()}},
 @{Route="payables/$($payables[0].id)/reconcile";Method='POST';Body=@{supplier_invoice_no='VS0-CLOSED-SUPINV';invoice_amount='1.00';currency='USD';reason='Closed test'}},
 @{Route="supplier-reconciliations/$reconciliation/approve";Method='POST';Body=@{}},
 @{Route="resource-assignments/$assignmentId/cancel";Method='POST';Body=@{reason='Closed assignment edit'}},
 @{Route="bookings/$booking/complete-operations";Method='POST';Body=@{}}
)
foreach($case in $closedCases){$r=CallApi $case.Route $case.Method $case.Body;Check ($r.Status -eq 409 -and $r.Data.message -match 'finance.*closed') "financial close blocks dispatcher $($case.Route)"}
$r=CallApi "bookings/$booking/finance-reopen" 'POST' @{};Check ($r.Status -eq 422) 'finance reopen requires explicit reason'
$r=CallApi "bookings/$booking/finance-reopen" 'POST' @{reason='VS0 authorized synthetic review'};Check ($r.Status -eq 200) 'authorized reasoned reopen succeeds through dispatcher'
$r=CallApi "services/$($svc.id)" 'PUT' @{notes='Reviewed after authorized reopen'};Check ($r.Status -eq 200) 'authorized reopen restores service editing without cost mutation'

foreach($entry in @(@{Field='sales_owner_id';Ids=@($fixtures.foreign_user,$fixtures.inactive_user)},@{Field='customer_id';Ids=@($fixtures.foreign_customer,$fixtures.inactive_customer)},@{Field='agent_id';Ids=@($fixtures.foreign_agent,$fixtures.inactive_agent)})){
 foreach($id in $entry.Ids){$body=@{lead_contact_name="$($fixtures.invalid_prefix)_$($entry.Field)_$id";adults=1;total_guests=1;paying_pax=1};$body[$entry.Field]=$id;$r=CallApi 'inquiries' 'POST' $body;Check ($r.Status -eq 422 -and $r.Data.error -eq 'VALIDATION') "manual inquiry rejects foreign/inactive $($entry.Field) $id before INSERT"}
}
foreach($id in @($fixtures.foreign_user,$fixtures.inactive_user)){$r=CallApi 'customers' 'POST' @{full_name="$($fixtures.invalid_prefix)_customer_$id";sales_owner_id=$id};Check ($r.Status -eq 422 -and $r.Data.error -eq 'VALIDATION') "manual customer rejects foreign/inactive owner $id before INSERT"}
& $PhpPath "$PSScriptRoot/vs0-fixtures.php" verify
if($LASTEXITCODE -ne 0){throw 'VS0 invariant verification failed.'}
Write-Output 'VS0 HTTP dispatcher regression complete: effective permissions, full counts, focused identities, immutable financial close and CRM preflight.'
