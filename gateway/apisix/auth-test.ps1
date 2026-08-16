$ErrorActionPreference = 'Stop'

$tokenResponse = Invoke-RestMethod `
    -Method Post `
    -Uri 'http://localhost:8080/realms/stayhub/protocol/openid-connect/token' `
    -ContentType 'application/x-www-form-urlencoded' `
    -Body @{
        grant_type = 'password'
        client_id = 'stayhub-api'
        client_secret = 'B6Xz1F7xWF5cn2tbFqBdHlJKE9WzZJn4'
        username = 'customer'
        password = 'password'
        scope = 'openid'
    }

$accessToken = $tokenResponse.access_token

Write-Output 'Unauthenticated protected request:'
curl.exe -s -o NUL -w "HTTP %{http_code}`n" http://localhost:9080/api/bookings/example

Write-Output 'Authenticated protected request:'
curl.exe -s -H "Authorization: Bearer $accessToken" -o NUL -w "HTTP %{http_code}`n" http://localhost:9080/api/bookings/example

Write-Output 'Authenticated token can reach service; 404 is expected until real booking endpoints are implemented.'

