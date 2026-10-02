$ErrorActionPreference='Stop'
$base='http://127.0.0.1:8873/api/index.php?route='
$session=New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Check($condition,$name){if(-not $condition){throw "FAIL $name"};Write-Output "PASS $name"}
function CallApi($route,$method='GET',$body=$null,$csrf=$script:token,$web=$session){
 $params=@{Uri=($base+$route);Method=$method;WebSession=$web;SkipHttpErrorCheck=$true;Headers=@{'X-VTA-CSRF'=$csrf};ContentType='application/json'}
 if($null -ne $body){$params.Body=ConvertTo-Json $body -Depth 15 -Compress}
 $r=Invoke-WebRequest @params
 return @{Status=[int]$r.StatusCode;Data=($r.Content|ConvertFrom-Json)}
}
$script:token=''
$r=CallApi 'lead-hub/requests';Check ($r.Status -eq 401) 'unauthenticated Lead Hub blocked'
$r=CallApi 'auth/login' 'POST' @{email='admin@example.invalid';password='Local-Http-Test-2026'}
Check ($r.Status -eq 200) 'admin login';$script:token=$r.Data.user.csrf
$r=CallApi 'lead-hub/campaigns' 'POST' @{name='Test';source='WEBSITE'} 'wrong'
Check ($r.Status -eq 419) 'mutation without CSRF rejected'
$r=CallApi 'lead-hub/campaigns' 'POST' @{name='HTTP Campaign';source='FACEBOOK'}
Check ($r.Status -eq 201) 'campaign API create';$campaign=$r.Data.id
$r=CallApi 'lead-hub/forms' 'POST' @{name='HTTP Form';campaign_id=$campaign}
Check ($r.Status -eq 201) 'form API create';$form=$r.Data.public_token
$payload=@{submission_key=('http-smoke-'+[guid]::NewGuid().ToString());contact_name='HTTP Guest';phone='+84123456789';total_guests=11;paying_pax=10;foc=1;utm_campaign='HTTP Campaign'}|ConvertTo-Json
$r=Invoke-WebRequest -Uri "http://127.0.0.1:8873/api/lead-submit.php?form=$form" -Method Post -ContentType 'application/json' -Body $payload -SkipHttpErrorCheck
Check ([int]$r.StatusCode -eq 202) 'public form submission over HTTP'
$r=CallApi 'lead-hub/requests';$request=$r.Data.items[0].id
Check ($r.Data.items[0].contact_name -eq 'HTTP Guest') 'request visible in Lead Hub'
$r=CallApi "lead-hub/requests/$request/qualify" 'POST' @{qualification_note='Ready to quote';expected_version=1;action_key='http-qualify-00001';next_action_due='2027-01-01 09:00:00'};$lead=$r.Data.id
Check ($r.Status -eq 200) 'qualify over HTTP'
$r=CallApi "lead-hub/leads/$lead/accept" 'POST' @{expected_version=$r.Data.handover_version;action_key='http-accept-000001';sales_owner_user_id=1;next_action_due='2027-01-01 09:00:00'}
Check ($r.Status -eq 200) 'Sales acceptance before conversion over HTTP'
$acceptedVersion=$r.Data.handover_version
$r=CallApi "lead-hub/leads/$lead/customer-candidates"
Check ($r.Status -eq 200) 'review customer candidates over HTTP'
$r=CallApi "lead-hub/leads/$lead/convert" 'POST' @{expected_version=$acceptedVersion;action_key='http-convert-00001';identity_mode='CREATE_NEW';identity_reviewed=$true;identity_review_key=$r.Data.identity_review_key;new_customer=@{full_name='HTTP Guest';email='http-guest@example.invalid';whatsapp=''}};$inquiry=$r.Data.inquiry_id
Check ($r.Status -eq 200) 'convert to inquiry over HTTP'
$r=CallApi "inquiries/$inquiry/create-quote" 'POST' @{};$quote=$r.Data.id
Check ($r.Status -eq 201) 'existing v2.4 inquiry creates quote'
$r=CallApi "quotes/$quote";$qv=$r.Data.version.id
Check ($r.Data.version.total_guests -eq 11 -and $r.Data.version.paying_pax -eq 10 -and $r.Data.version.foc -eq 1) 'guest counts survive through existing quote API'
$r=CallApi 'inventory/programs' 'POST' @{code=('HTTP-'+[guid]::NewGuid().ToString());name='HTTP Hanoi'};$program=$r.Data.id
Check ($r.Status -eq 201) 'tour program create'
$r=CallApi 'inventory/components' 'POST' @{name='Airport transfer';category='TRANSPORT';destination='Hanoi';customer_description='Meet driver'};$component=$r.Data.id
Check ($r.Status -eq 201) 'component create'
$r=CallApi "inventory/programs/$program/versions" 'POST' @{title='Hanoi arrival';days=@(@{title='Arrival';itinerary='Meet the driver and transfer to your hotel.';services=@(@{component_id=$component;pax_basis='ONE';quantity=1})});variants=@(@{name='Comfort';hotel_level='4*'})};$version=$r.Data.id
Check ($r.Status -eq 201) 'day itinerary and blueprint create'
$r=CallApi "inventory/versions/$version/publish" 'POST' @{}
Check ($r.Status -eq 200) 'publish tour version'
$r=CallApi "inventory/versions/$version/use" 'POST' @{quote_version_id=$qv}
Check ($r.Status -eq 200) 'copy published program into draft quote'
$r=CallApi "inventory/versions/$version/health&travel_date=2027-01-02&pax=10"
Check ($r.Status -eq 200 -and $r.Data.items[0].status -eq 'MISSING_RATE') 'Product Health reports missing rate'
$r=CallApi 'auth/logout' 'POST' @{}
$r=CallApi 'auth/login' 'POST' @{email='admin@example.invalid';password='Local-Http-Test-2026'};$script:token=$r.Data.user.csrf
$r=CallApi "quotes/$quote"
Check ($r.Data.version.schedule_json -match 'Meet the driver') 'quote itinerary persists after logout and login'
$r=CallApi 'suppliers' 'POST' @{name='Local test transport';supplier_type='TRANSPORT';currency='USD'};$supplier=$r.Data.id
Check ($r.Status -eq 201) 'test supplier created'
$r=CallApi "quote-versions/$qv" 'PUT' @{start_date='2027-01-02';end_date='2027-01-02';adults=10;children=0;infants=0;foc=1;total_guests=11;paying_pax=10}
Check ($r.Status -eq 200) 'review explicit passenger segments and quote travel dates'
$optionIds=@{}
foreach($level in @('3*','4*','5*')){
 $hotelCost=@{'3*'=60;'4*'=70;'5*'=90}[$level]
 $lines=@(
  @{category='HOTEL';service_name="Hanoi hotel $level";supplier_id=$supplier;service_date='2027-01-02';pax=1;qty=1;unit_price=$hotelCost;currency='USD';reason='Synthetic supplier quote for smoke test'},
  @{category='TRANSPORT';service_name='Airport transfer';supplier_id=$supplier;service_date='2027-01-02';pax=1;qty=1;unit_price=20;currency='USD';reason='Synthetic supplier quote for smoke test'},
  @{category='ATTRACTION';service_name='City activity';supplier_id=$supplier;service_date='2027-01-02';pax=1;qty=1;unit_price=10;currency='USD';reason='Synthetic supplier quote for smoke test'},
  @{category='CRUISE';service_name='Cruise excursion';supplier_id=$supplier;service_date='2027-01-02';pax=1;qty=1;unit_price=10;currency='USD';reason='Synthetic supplier quote for smoke test'}
 )
 $r=CallApi "quote-versions/$qv/options" 'POST' @{label="Option $level";hotel_level=$level;pricing_mode='TARGET_MARGIN';pricing_value=20;rounding_step=0;lines=$lines}
 Check ($r.Status -eq 201) "save $level cost and price snapshot";$optionIds[$level]=$r.Data.id
}
$r=CallApi "quotes/$quote/approve" 'POST' @{reason='Reviewed all synthetic manual costs and margins'}
Check ($r.Status -eq 200) 'approve multi-option bundle'
$r=CallApi "quotes/$quote/send" 'POST' @{};$snapshot=$r.Data.customer_safe_snapshot|ConvertTo-Json -Depth 15 -Compress
Check ($r.Status -eq 200 -and $r.Data.customer_safe_snapshot.options.Count -eq 3) 'issue three-option customer snapshot'
Check ($snapshot -notmatch 'unit_price|supplier_id|cost_total|margin_pct') 'issued quote omits internal pricing'
$r=CallApi "quotes/$quote/approve" 'POST' @{reason='Reopen'}
Check ($r.Status -eq 409) 'sent bundle cannot be reopened'
$r=CallApi "quotes/$quote/confirm" 'POST' @{option_id=$optionIds['4*']}
Check ($r.Status -eq 200) 'confirm 4-star option'
$r=CallApi "quotes/$quote/create-booking" 'POST' @{};$booking=$r.Data.id
Check ($r.Status -eq 201) 'booking from exact accepted option'
$r=CallApi "bookings/$booking";$selling=[decimal]$r.Data.booking.confirmed_selling
Check ($r.Data.services.Count -eq 4 -and $selling -eq [decimal]137.50) 'booking contains accepted services and selling value'
Check ($r.Data.booking.adults -eq 10 -and $r.Data.booking.children -eq 0 -and $r.Data.booking.infants -eq 0 -and $r.Data.booking.foc -eq 1) 'booking preserves accepted passenger segmentation'
$r=CallApi "bookings/$booking/generate-orders" 'POST' @{};$order=$r.Data.items[0].id
$r=CallApi "supplier-orders/$order";$services=$r.Data.services
Check ($r.Data.order.status -eq 'DRAFT') 'supplier order starts DRAFT'
$r=CallApi "supplier-orders/$order/confirm" 'POST' @{status='CONFIRMED'}
Check ($r.Status -eq 409) 'supplier confirmation cannot bypass send'
$r=CallApi "supplier-orders/$order/send" 'POST' @{}
Check ($r.Status -eq 200) 'explicit order send'
$costs=@{};$confirmed=0
foreach($service in $services){$amount=[decimal]$service.planned_cost;if($service.category -eq 'HOTEL'){$amount+=10};$costs[[string]$service.id]=$amount;$confirmed+=$amount}
$r=CallApi "supplier-orders/$order/confirm" 'POST' @{status='CONFIRMED';confirmation_no='FULL-LOCAL-TEST';confirmation_source='EMAIL';currency='USD';confirmed_total=$confirmed;service_costs=$costs}
Check ($r.Status -eq 200) 'confirm each order service with explicit cost'
# Readiness uses the passenger list and active resource intervals, not typed contact fields.
for($guestNo=1;$guestNo -le 11;$guestNo++){
 $kind=if($guestNo -eq 11){'FOC'}else{'ADULT'}
 $r=CallApi "bookings/$booking/guests" 'POST' @{guest_type=$kind;full_name="Synthetic passenger $guestNo"}
 Check ($r.Status -eq 201) "record reviewed passenger $guestNo"
}
$r=CallApi "bookings/$booking";$transfer=@($r.Data.services | Where-Object category -eq 'TRANSPORT')[0]
$r=CallApi 'v3/resources' 'POST' @{kind='DRIVER';name='Full HTTP Driver';phone='+840001'};$fullDriver=$r.Data.id
Check ($r.Status -eq 201) 'create active readiness driver'
$r=CallApi 'v3/resources' 'POST' @{kind='VEHICLE';name='Full HTTP 16-seat vehicle';capacity=16};$fullVehicle=$r.Data.id
Check ($r.Status -eq 201) 'create vehicle with capacity covering all guests including FOC'
foreach($resourceId in @($fullDriver,$fullVehicle)){
 $r=CallApi "services/$($transfer.id)/assign" 'POST' @{resource_id=$resourceId;starts_at='2027-01-02 00:00:00';ends_at='2027-01-02 23:59:59'}
 Check ($r.Status -eq 201) 'assign active resource covering full service interval'
}
$r=CallApi "bookings/$booking/readiness"
Check ($r.Status -eq 200 -and $r.Data.checks.guest_list -and $r.Data.checks.resource_assignment -and -not $r.Data.checks.current_travel_pack -and -not $r.Data.ready) 'missing current Travel Pack still blocks readiness after guest and resource setup'
$documentIds=@()
foreach($kind in @('FULL_ITINERARY','HOTEL_VOUCHER','TRANSFER_VOUCHER','ACTIVITY_VOUCHER','CRUISE_VOUCHER','TRAVEL_PACK')){
 $r=CallApi "bookings/$booking/travel-documents" 'POST' @{kind=$kind;visibility='HIDE_PRICE'};$document=$r.Data.id
 Check ($r.Status -eq 201) "prepare $kind"
 foreach($action in @('review','ready','issue')){$r=CallApi "travel-documents/$document/$action" 'POST' @{};Check ($r.Status -eq 200) "$kind $action"}
 $render=Invoke-WebRequest -Uri ($base+"travel-documents/$document/html") -WebSession $session -SkipHttpErrorCheck
 Check ([int]$render.StatusCode -eq 200 -and $render.Content -notmatch 'unit_price|supplier_id|margin_pct|planned_cost|confirmed_cost') "$kind customer-safe HTML"
 $documentIds+=$document
}
$r=CallApi "bookings/$booking/readiness"
Check ($r.Status -eq 200 -and $r.Data.ready -and $r.Data.readiness_pct -eq 100) 'fresh Travel Pack after passenger and resource assignments satisfies all five readiness checks'
$invoiceIds=@();$amounts=@('50.00',(($selling-50).ToString('0.00',[Globalization.CultureInfo]::InvariantCulture)))
foreach($amount in $amounts){
 $r=CallApi "bookings/$booking/invoices" 'POST' @{invoice_type='PROFORMA';subtotal=$amount;issue_date='2026-09-21';due_date='2026-09-21'};$invoice=$r.Data.id
 Check ($r.Status -eq 201) 'create proforma instalment'
 $r=CallApi "invoices/$invoice/issue" 'POST' @{};Check ($r.Status -eq 200) 'issue immutable invoice'
 $render=Invoke-WebRequest -Uri ($base+"invoices/$invoice/html") -WebSession $session -SkipHttpErrorCheck
 Check ($render.Content -match 'not a Vietnamese VAT e-invoice') 'invoice disclaimer rendered'
 $invoiceIds+=$invoice
}
$r=CallApi "bookings/$booking/receipts" 'POST' @{amount=$selling.ToString('0.00',[Globalization.CultureInfo]::InvariantCulture);currency='USD';payment_date='2026-09-21';transaction_reference='FULL-BANK-TEST';idempotency_key=[guid]::NewGuid().ToString()};$receipt=$r.Data.id
Check ($r.Status -eq 201) 'record one bank receipt'
$r=CallApi "receipts/$receipt/allocate" 'POST' @{request_key=[guid]::NewGuid().ToString();allocation_date='2026-09-21';items=@(@{invoice_id=$invoiceIds[0];amount=$amounts[0]},@{invoice_id=$invoiceIds[1];amount=$amounts[1]})}
Check ($r.Status -eq 200) 'allocate one receipt across two invoices'
$r=CallApi "bookings/$booking/statement-v3&as_of=2026-09-21"
Check ($r.Status -eq 200 -and [decimal]$r.Data.currencies.USD.closing -eq 0) 'partner statement closing reconciles to zero'
$r=CallApi "bookings/$booking/finance";$payables=$r.Data.payables
foreach($payable in $payables){
 $actual=[decimal]$payable.total_amount;if($payable.label -match 'hotel'){$actual+=5}
 $r=CallApi "payables/$($payable.id)/reconcile" 'POST' @{supplier_invoice_no="FULL-SUPINV-$($payable.id)";invoice_amount=$actual.ToString('0.00',[Globalization.CultureInfo]::InvariantCulture);currency='USD';reason='Reviewed synthetic supplier invoice variance'};$reconciliation=$r.Data.id
 Check ($r.Status -eq 201) 'supplier invoice submitted for reconciliation'
 $r=CallApi "supplier-reconciliations/$reconciliation/approve" 'POST' @{};Check ($r.Status -eq 200) 'supplier reconciliation approved'
 $r=CallApi "payables/$($payable.id)/payments" 'POST' @{amount=$actual.ToString('0.00',[Globalization.CultureInfo]::InvariantCulture);currency='USD';payment_date='2026-09-21';transaction_reference="SUP-BANK-$($payable.id)";idempotency_key=[guid]::NewGuid().ToString()}
 Check ($r.Status -eq 201) 'supplier payment recorded against reconciled AP'
}
foreach($invoice in $invoiceIds){
 $r=CallApi "invoices/$invoice/commercial" 'POST' @{issue_date='2026-09-21'};Check ($r.Status -eq 201) 'issue commercial document for existing proforma receivable'
 $render=Invoke-WebRequest -Uri ($base+"invoices/$invoice/commercial-html") -WebSession $session -SkipHttpErrorCheck
 Check ($render.StatusCode -eq 200 -and $render.Content -match 'does not create an additional amount due' -and $render.Content -match 'not a Vietnamese VAT e-invoice') 'commercial document renders linked obligation and disclaimer'
}
$r=CallApi "bookings/$booking/finance";Check ($r.Data.invoices.Count -eq 2) 'commercial issuance does not duplicate invoice ledger'
$r=CallApi "bookings/$booking/statement-v3&as_of=2026-09-21";Check ([decimal]$r.Data.currencies.USD.closing -eq 0) 'commercial issuance preserves zero partner balance'
$r=CallApi "bookings/$booking/profit-v3"
Check ($r.Data.expected_profit -eq 27.5 -and $r.Data.forecast_profit -eq 17.5 -and $r.Data.actual_profit -eq 12.5 -and -not $r.Data.actual_final) 'profit separates planned confirmed actual costs and remains provisional'
$r=CallApi "bookings/$booking/complete-operations" 'POST' @{};Check ($r.Status -eq 200) 'complete synthetic booking operations'
$r=CallApi "bookings/$booking/profit-v3";Check ($r.Data.actual_final -eq $true) 'final profit requires completed operations and settlement'
$r=CallApi 'auth/logout' 'POST' @{}
$r=CallApi 'auth/login' 'POST' @{email='admin@example.invalid';password='Local-Http-Test-2026'};$script:token=$r.Data.user.csrf
$r=CallApi "bookings/$booking/profit-v3";Check ($r.Data.actual_final -eq $true -and $r.Data.actual_profit -eq 12.5) 'financial result persists after re-login'
Write-Output "Full local HTTP chain passed for booking $booking. This is synthetic local verification, not a remote staging deployment."

$viewer=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r=CallApi 'auth/login' 'POST' @{email='viewer@example.invalid';password='Local-Http-Test-2026'} '' $viewer
$r=CallApi 'lead-hub/requests' 'GET' $null $r.Data.user.csrf $viewer
Check ($r.Status -eq 403) 'backend RBAC denies viewer access'
Write-Output 'Full local HTTP regression run complete.'
