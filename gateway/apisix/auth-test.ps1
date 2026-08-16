$ErrorActionPreference = 'Stop'

. "$PSScriptRoot\stayhub-env.ps1"

$clientId = Get-StayHubEnvValue -Name 'KEYCLOAK_CLIENT_ID' -Default 'stayhub-api'
$clientSecret = Get-StayHubEnvValue -Name 'KEYCLOAK_CLIENT_SECRET' -Required

$tokenResponse = Invoke-RestMethod `
    -Method Post `
    -Uri 'http://localhost:8080/realms/stayhub/protocol/openid-connect/token' `
    -ContentType 'application/x-www-form-urlencoded' `
    -Body @{
        grant_type = 'password'
        client_id = $clientId
        client_secret = $clientSecret
        username = 'customer'
        password = 'password'
        scope = 'openid'
    }

$accessToken = $tokenResponse.access_token

function Assert-Status {
    param(
        [string] $Label,
        [string] $Url,
        [int] $Expected,
        [hashtable] $Headers = @{}
    )

    $curlHeaders = @()

    foreach ($header in $Headers.GetEnumerator()) {
        $curlHeaders += @('-H', "$($header.Key): $($header.Value)")
    }

    $status = curl.exe -s -o NUL -w "%{http_code}" @curlHeaders $Url

    if ([int] $status -ne $Expected) {
        throw "$Label expected HTTP $Expected, got $status"
    }

    Write-Output "$Label`: HTTP $status"
}

Assert-Status `
    -Label 'Unauthenticated protected request' `
    -Url 'http://localhost:9080/api/bookings' `
    -Expected 401

Assert-Status `
    -Label 'Authenticated protected request' `
    -Url 'http://localhost:9080/api/bookings' `
    -Expected 200 `
    -Headers @{ Authorization = "Bearer $accessToken" }

Write-Output 'APISIX + Keycloak authentication test passed.'
