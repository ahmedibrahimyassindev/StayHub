$ErrorActionPreference = 'Stop'

$gatewayUrl = 'http://localhost:9080'
$keycloakUrl = 'http://localhost:8080/realms/stayhub/protocol/openid-connect/token'
$clientId = 'stayhub-api'
$clientSecret = 'B6Xz1F7xWF5cn2tbFqBdHlJKE9WzZJn4'

function Get-Token {
    param([string] $Username)

    $response = Invoke-RestMethod `
        -Method Post `
        -Uri $keycloakUrl `
        -ContentType 'application/x-www-form-urlencoded' `
        -Body @{
            grant_type = 'password'
            client_id = $clientId
            client_secret = $clientSecret
            username = $Username
            password = 'password'
            scope = 'openid'
        }

    return $response.access_token
}

function Invoke-Json {
    param(
        [string] $Method,
        [string] $Path,
        [hashtable] $Headers,
        [object] $Body = $null
    )

    $parameters = @{
        Method = $Method
        Uri = "$gatewayUrl$Path"
        Headers = $Headers
    }

    if ($null -ne $Body) {
        $parameters.ContentType = 'application/json'
        $parameters.Body = ($Body | ConvertTo-Json -Depth 10)
    }

    return Invoke-RestMethod @parameters
}

function Assert-Status {
    param(
        [string] $Path,
        [int] $Expected
    )

    $status = curl.exe -s -o NUL -w "%{http_code}" "$gatewayUrl$Path"

    if ([int] $status -ne $Expected) {
        throw "Expected $Expected for $Path, got $status"
    }
}

$token = Get-Token -Username 'customer'
$headers = @{ Authorization = "Bearer $token" }

Write-Output 'Checking public health routes...'
@(
    '/api/hotels/health',
    '/api/inventory/health',
    '/api/bookings/health',
    '/api/payments/health',
    '/api/search/health',
    '/api/users/health',
    '/api/notifications/health'
) | ForEach-Object { Assert-Status -Path $_ -Expected 200 }

Write-Output 'Checking protected route denies missing token...'
Assert-Status -Path '/api/bookings' -Expected 401

$roomId = 9901
$checkIn = '2026-12-10'
$checkOut = '2026-12-11'

Write-Output 'Preparing inventory...'
Invoke-Json -Method Put -Path '/api/inventory' -Headers $headers -Body @{
    room_id = $roomId
    date = $checkIn
    total_rooms = 1
    available_rooms = 1
    reserved_rooms = 0
    price = 300.00
    currency = 'USD'
} | Out-Null

Write-Output 'Creating booking with payment and notification...'
$bookingResponse = Invoke-Json -Method Post -Path '/api/bookings' -Headers $headers -Body @{
    user_id = 1
    hotel_id = 1
    room_id = $roomId
    check_in = $checkIn
    check_out = $checkOut
    quantity = 1
    total_amount = 300.00
    currency = 'USD'
}

if ($bookingResponse.data.booking.status -ne 'pending_payment') {
    throw "Expected pending_payment booking, got $($bookingResponse.data.booking.status)"
}

if ($null -eq $bookingResponse.data.payment.id) {
    throw 'Expected payment id in booking response.'
}

if ($null -eq $bookingResponse.data.notification.id) {
    throw 'Expected notification id in booking response.'
}

$bookingId = $bookingResponse.data.booking.id

Write-Output 'Confirming payment...'
$confirmed = Invoke-Json -Method Post -Path "/api/bookings/$bookingId/confirm-payment" -Headers $headers

if ($confirmed.data.booking.status -ne 'confirmed') {
    throw "Expected confirmed booking, got $($confirmed.data.booking.status)"
}

Write-Output 'Running search aggregator...'
$search = Invoke-Json -Method Get -Path '/api/search/hotels?city=Cairo&check_in=2026-08-17&check_out=2026-08-19&guests=2&max_price=250' -Headers $headers

if ($search.meta.hotels_available -lt 1) {
    throw 'Expected at least one available hotel in search.'
}

Write-Output 'Checking user and notification APIs...'
Invoke-Json -Method Get -Path '/api/users/profiles/keycloak/customer' -Headers $headers | Out-Null
Invoke-Json -Method Get -Path '/api/notifications?recipient_user_id=1&per_page=5' -Headers $headers | Out-Null

Write-Output 'E2E smoke test passed.'
