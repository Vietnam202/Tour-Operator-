param([string]$PhpPath=$env:VTA_TEST_PHP)
$ErrorActionPreference='Stop'
$vtaDocBase='http://127.0.0.1:8873/api/index.php?route='
function DocCheck($condition,$name){if(-not $condition){throw "FAIL $name"};Write-Output "PASS $name"}
function DocApi($route,$method,$body,$web,$token){
 $docParams=@{Uri=$vtaDocBase+$route;Method=$method;WebSession=$web;SkipHttpErrorCheck=$true;ContentType='application/json';Headers=@{'X-VTA-CSRF'=$token}}
 if($null -ne $body){$docParams.Body=ConvertTo-Json $body -Depth 8}
 $r=Invoke-WebRequest @docParams;return @{Status=$r.StatusCode;Data=$r.Content|ConvertFrom-Json}
}
$adminWeb=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r=DocApi 'auth/login' 'POST' @{email='admin@example.invalid';password='Local-Http-Test-2026'} $adminWeb '';DocCheck ($r.Status -eq 200) 'document test administrator login';$adminToken=$r.Data.user.csrf
$r=DocApi 'suppliers' 'GET' $null $adminWeb $adminToken;$supplier=$r.Data.items[0].id;DocCheck ($supplier -gt 0) 'use an existing same-company supplier'
$inputFile=Join-Path ([IO.Path]::GetTempPath()) ('vta-doc-review-'+[guid]::NewGuid().ToString('N')+'.csv')
[IO.File]::WriteAllText($inputFile,"category,product_name,amount,currency,rate_basis`nTRANSPORT,QA Transfer,100,USD,PER_TRANSFER`n",[Text.UTF8Encoding]::new($false))
try{
 $r=Invoke-WebRequest -Uri ($vtaDocBase+'documents/upload') -Method POST -WebSession $adminWeb -SkipHttpErrorCheck -Headers @{'X-VTA-CSRF'=$adminToken} -Form @{supplier_id=[string]$supplier;document_type='RATE_SHEET';file=Get-Item -LiteralPath $inputFile}
 if($r.StatusCode -ne 201){throw ('Multipart upload failed: HTTP '+$r.StatusCode+' '+$r.Content)}
 DocCheck ($r.StatusCode -eq 201) 'real multipart document upload succeeds';$document=($r.Content|ConvertFrom-Json).id
 $fixture=& $PhpPath "$PSScriptRoot/document-review-fixture.php" | ConvertFrom-Json;if($LASTEXITCODE -ne 0){throw 'Document reviewer fixture failed'}
 $reviewWeb=New-Object Microsoft.PowerShell.Commands.WebRequestSession
 $r=DocApi 'auth/login' 'POST' @{email=$fixture.email;password='Local-Http-Test-2026'} $reviewWeb '';DocCheck ($r.Status -eq 200) 'document-only reviewer login';$reviewToken=$r.Data.user.csrf
 $r=DocApi 'suppliers' 'GET' $null $reviewWeb $reviewToken;DocCheck ($r.Status -eq 403) 'document review permission does not grant supplier list access'
 $r=DocApi 'documents' 'GET' $null $reviewWeb $reviewToken;$original=@($r.Data.items | Where-Object id -eq $document)[0];DocCheck ($r.Status -eq 200 -and $original.supplier_id -eq $supplier) 'document-only reader sees the existing association'
 $r=DocApi "documents/$document/review" 'PUT' @{notes='Reviewed without changing supplier';review_status='REJECTED'} $reviewWeb $reviewToken;DocCheck ($r.Status -eq 200) 'scoped reviewer saves status and notes without supplier field'
 $r=DocApi 'documents' 'GET' $null $reviewWeb $reviewToken;$saved=@($r.Data.items | Where-Object id -eq $document)[0];DocCheck ($saved.supplier_id -eq $supplier -and $saved.review_status -eq 'REJECTED' -and $saved.notes -eq 'Reviewed without changing supplier') 'native review preserves supplier and selected status after save'
}finally{Remove-Item -LiteralPath $inputFile -ErrorAction SilentlyContinue}
