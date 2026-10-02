. "$PSScriptRoot/full-http.ps1"
$r=CallApi 'search&q=HTTP' 'GET' $null '' $viewer
Check ($r.Status -eq 200 -and @($r.Data.groups).Count -eq 0) 'search hides domains without permission'
foreach($route in @('v3/control-center','v3/reports/attribution','v3/operations','v3/resources')){
 $r=CallApi $route 'GET' $null '' $viewer;Check ($r.Status -eq 403) "viewer denied $route"
}
$r=CallApi 'v3/control-center';Check ($r.Status -eq 200) 'shared control queues load'
$r=CallApi 'v3/reports/attribution';Check ($r.Status -eq 200 -and @($r.Data.items | Where-Object { $_.campaign_id -eq $campaign -and $_.bookings -eq 1 }).Count -eq 1) 'attribution report follows campaign to booking'
$r=CallApi 'finance/ar';$ar=@($r.Data.items | Where-Object id -eq $booking)[0];Check ([decimal]$ar.balance -eq 0 -and [decimal]$ar.paid -eq 137.50) 'global AR includes allocated receipts'
$r=CallApi "bookings/$booking";$svc=@($r.Data.services | Where-Object category -eq 'TRANSPORT')[0]
$r=CallApi "services/$($svc.id)" 'PUT' @{actual_cost='0.01'};Check ($r.Status -eq 409) 'service update cannot bypass supplier invoice reconciliation'
$r=CallApi "services/$($svc.id)" 'PUT' @{confirmed_cost=$null};Check ($r.Status -eq 409) 'service update cannot erase confirmed cost'
$r=CallApi 'v3/resources' 'POST' @{kind='DRIVER';name='HTTP Driver'};Check ($r.Status -eq 201) 'create operational resource';$resource=$r.Data.id
$assignment=@{resource_id=$resource;starts_at='2027-01-02 08:00:00';ends_at='2027-01-02 18:00:00'}
$r=CallApi "services/$($svc.id)/assign" 'POST' $assignment;Check ($r.Status -eq 201) 'assign driver to service'
$r=CallApi "services/$($svc.id)/assign" 'POST' $assignment;Check ($r.Status -eq 201) 'assignment retry idempotent over HTTP'
$r=CallApi "bookings/$booking/issues" 'POST' @{category='TRANSPORT';severity='HIGH';title='Late pickup';description='Synthetic service recovery'};Check ($r.Status -eq 201) 'open operational issue';$issue=$r.Data.id
$r=CallApi "bookings/$booking/readiness";Check (-not $r.Data.checks.no_critical_issues) 'open high issue blocks readiness'
$r=CallApi "issues/$issue/resolve" 'POST' @{root_cause='Traffic';resolution='Replacement dispatched';lessons_learned='Add departure buffer';financial_impact='0';currency='USD'};Check ($r.Status -eq 200) 'resolve issue with cause and lessons'
$r=CallApi 'v3/operations&date=2027-01-02';Check ($r.Status -eq 200 -and @($r.Data.movement | Where-Object booking_id -eq $booking).Count -eq 4) 'movement date query returns all four booking services'
$r=CallApi "quotes/$quote/new-version" 'POST' @{};Check ($r.Status -eq 201) 'create quote revision after confirmation';$newVersion=$r.Data.version_id
$r=CallApi "quote-versions/$newVersion/options";Check ($r.Status -eq 200 -and $r.Data.items.Count -eq 3) 'revision retains three costed options'
Check (@($r.Data.items | Where-Object { $_.snapshot.adults -eq 10 -and $_.snapshot.children -eq 0 -and $_.snapshot.infants -eq 0 }).Count -eq 3) 'revision preserves reviewed passenger segments in every option'
$r=CallApi "bookings/$booking/profit-v3";Check ($r.Data.actual_profit -eq 12.5) 'new quote revision preserves accepted booking economics'
Write-Output 'Extended Operations, control center, revisions, AR and permission regression passed.'

$r=Invoke-WebRequest -Uri ($base+"quote-versions/$qv/customer-html") -WebSession $session -SkipHttpErrorCheck
Check ($r.StatusCode -eq 200 -and $r.Content -match '3\*' -and $r.Content -notmatch 'cost_total|rate_snapshot|supplier_id') 'issued customer quote HTML uses only public snapshot'
$r=CallApi "services/$($svc.id)/travel-details" 'PUT' @{meeting_point='Arrival gate A';guest_instructions='Look for your name sign';internal_cost='PRIVATE VALUE'}
Check ($r.Status -eq 200 -and -not $r.Data.details.PSObject.Properties['internal_cost']) 'voucher details reject internal field propagation'
$r=CallApi "services/$($svc.id)/travel-details";Check ($r.Data.details.meeting_point -eq 'Arrival gate A') 'voucher details persist'
$r=CallApi "bookings/$booking/readiness";Check (-not $r.Data.checks.current_travel_pack) 'changed voucher details make readiness require a new Travel Pack'
$r=CallApi "services/$($svc.id)/assign" 'POST' $assignment;$assignmentId=$r.Data.id
$r=CallApi "resource-assignments/$assignmentId/cancel" 'POST' @{reason='Synthetic reassignment'};Check ($r.Status -eq 200) 'resource assignment can be cancelled with reason'
$r=CallApi "services/$($svc.id)/assign" 'POST' $assignment;Check ($r.Status -eq 201 -and $r.Data.id -ne $assignmentId) 'cancelled resource interval can be reassigned'

# Travel detail changes above must be followed by a newly reviewed document, with all resources already assigned.
$r=CallApi "bookings/$booking/travel-documents" 'POST' @{kind='TRAVEL_PACK';visibility='HIDE_PRICE'};$refreshedPack=$r.Data.id
Check ($r.Status -eq 201) 'prepare replacement Travel Pack after voucher and assignment changes'
foreach($action in @('review','ready','issue')){$r=CallApi "travel-documents/$refreshedPack/$action" 'POST' @{};Check ($r.Status -eq 200) "replacement Travel Pack $action"}
$r=CallApi "bookings/$booking/readiness";Check ($r.Status -eq 200 -and $r.Data.ready -and $r.Data.readiness_pct -eq 100) 'replacement document restores canonical readiness after changed details'
