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

$checkIn = '2026-12-10'
$checkOut = '2026-12-11'
$smokeSlug = "stayhub-smoke-$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())"

Write-Output 'Preparing hotel catalog...'
$hotelResponse = Invoke-Json -Method Post -Path '/api/hotels' -Headers $headers -Body @{
    name = 'StayHub Smoke Hotel'
    slug = $smokeSlug
    description = 'Temporary hotel for the APISIX smoke flow.'
    country = 'Egypt'
    city = 'Cairo'
    address = 'Smoke Test Street'
    rating = 4.5
    status = 'active'
}

$hotelId = $hotelResponse.data.id

$roomResponse = Invoke-Json -Method Post -Path "/api/hotels/$hotelId/rooms" -Headers $headers -Body @{
    room_type = 'double'
    name = 'Smoke Double Room'
    description = 'Temporary room for the APISIX smoke flow.'
    capacity = 2
    base_price = 300.00
    currency = 'USD'
    amenities = @('wifi')
    status = 'active'
}

$roomId = $roomResponse.data.id

Write-Output 'Preparing inventory...'
Invoke-Json -Method Put -Path '/api/inventory' -Headers $headers -Body @{
    room_id = $roomId
    date = $checkIn
    total_rooms = 2
    available_rooms = 2
    reserved_rooms = 0
    price = 300.00
    currency = 'USD'
} | Out-Null

Write-Output 'Creating booking with payment and notification...'
$bookingResponse = Invoke-Json -Method Post -Path '/api/bookings' -Headers $headers -Body @{
    hotel_id = $hotelId
    room_id = $roomId
    check_in = $checkIn
    check_out = $checkOut
    quantity = 1
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
$search = Invoke-Json -Method Get -Path "/api/search/hotels?city=Cairo&check_in=$checkIn&check_out=$checkOut&guests=2&max_price=350" -Headers $headers

if ($search.meta.hotels_available -lt 1) {
    throw 'Expected at least one available hotel in search.'
}

Write-Output 'Checking user and notification APIs...'
try {
    Invoke-Json -Method Get -Path '/api/users/profiles/keycloak/customer' -Headers $headers | Out-Null
}
catch {
    if ($_.Exception.Response.StatusCode.value__ -ne 404) {
        throw
    }

    Invoke-Json -Method Post -Path '/api/users/profiles' -Headers $headers -Body @{
        keycloak_user_id = 'customer'
        email = 'customer@stayhub.local'
        first_name = 'Demo'
        last_name = 'Customer'
        role = 'CUSTOMER'
        locale = 'en'
    } | Out-Null
}

Invoke-Json -Method Get -Path '/api/notifications?recipient_user_id=1&per_page=5' -Headers $headers | Out-Null

Write-Output 'E2E smoke test passed.'
