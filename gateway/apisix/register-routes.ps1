$ErrorActionPreference = 'Stop'

$adminUrl = 'http://localhost:9180/apisix/admin'
$apiKey = 'stayhub-admin-key'
$keycloakClientId = 'stayhub-api'
$keycloakClientSecret = 'B6Xz1F7xWF5cn2tbFqBdHlJKE9WzZJn4'
$keycloakDiscoveryUrl = 'http://keycloak:8080/realms/stayhub/.well-known/openid-configuration'
$keycloakIntrospectionUrl = 'http://keycloak:8080/realms/stayhub/protocol/openid-connect/token/introspect'

$routes = @(
    @{ id = 'hotel-health'; uri = '/api/hotels/health'; node = 'hotel-service:8000'; protected = $false },
    @{ id = 'inventory-health'; uri = '/api/inventory/health'; node = 'inventory-service:8000'; protected = $false },
    @{ id = 'booking-health'; uri = '/api/bookings/health'; node = 'booking-service:8000'; protected = $false },
    @{ id = 'payment-health'; uri = '/api/payments/health'; node = 'payment-service:8000'; protected = $false },
    @{ id = 'search-health'; uri = '/api/search/health'; node = 'search-service:8000'; protected = $false },
    @{ id = 'user-health'; uri = '/api/users/health'; node = 'user-service:8000'; protected = $false },
    @{ id = 'notification-health'; uri = '/api/notifications/health'; node = 'notification-service:8000'; protected = $false },
    @{ id = 'hotel-service-root'; uri = '/api/hotels'; node = 'hotel-service:8000'; protected = $true },
    @{ id = 'inventory-service-root'; uri = '/api/inventory'; node = 'inventory-service:8000'; protected = $true },
    @{ id = 'booking-service-root'; uri = '/api/bookings'; node = 'booking-service:8000'; protected = $true },
    @{ id = 'payment-service-root'; uri = '/api/payments'; node = 'payment-service:8000'; protected = $true },
    @{ id = 'search-service-root'; uri = '/api/search'; node = 'search-service:8000'; protected = $true },
    @{ id = 'user-service-root'; uri = '/api/users'; node = 'user-service:8000'; protected = $true },
    @{ id = 'notification-service-root'; uri = '/api/notifications'; node = 'notification-service:8000'; protected = $true },
    @{ id = 'hotel-service'; uri = '/api/hotels/*'; node = 'hotel-service:8000'; protected = $true },
    @{ id = 'inventory-service'; uri = '/api/inventory/*'; node = 'inventory-service:8000'; protected = $true },
    @{ id = 'booking-service'; uri = '/api/bookings/*'; node = 'booking-service:8000'; protected = $true },
    @{ id = 'payment-service'; uri = '/api/payments/*'; node = 'payment-service:8000'; protected = $true },
    @{ id = 'search-service'; uri = '/api/search/*'; node = 'search-service:8000'; protected = $true },
    @{ id = 'user-service'; uri = '/api/users/*'; node = 'user-service:8000'; protected = $true },
    @{ id = 'notification-service'; uri = '/api/notifications/*'; node = 'notification-service:8000'; protected = $true }
)

foreach ($route in $routes) {
    $body = @{
        uri = $route.uri
        priority = if ($route.protected) { 0 } else { 100 }
        upstream = @{
            type = 'roundrobin'
            nodes = @{
                $route.node = 1
            }
        }
    }

    if ($route.protected) {
        $body.plugins = @{
            'openid-connect' = @{
                client_id = $keycloakClientId
                client_secret = $keycloakClientSecret
                discovery = $keycloakDiscoveryUrl
                introspection_endpoint = $keycloakIntrospectionUrl
                bearer_only = $true
                realm = 'stayhub'
                unauth_action = 'deny'
                ssl_verify = $false
                timeout = 10
            }
            'proxy-rewrite' = @{
                headers = @{
                    remove = @(
                        'X-StayHub-User-Id',
                        'X-StayHub-Roles',
                        'X-User-Id',
                        'X-User-Roles'
                    )
                }
            }
        }
    }

    $jsonBody = $body | ConvertTo-Json -Depth 10

    Invoke-RestMethod `
        -Method Put `
        -Uri "$adminUrl/routes/$($route.id)" `
        -Headers @{ 'X-API-KEY' = $apiKey } `
        -ContentType 'application/json' `
        -Body $jsonBody | Out-Null

    $mode = if ($route.protected) { 'protected' } else { 'public' }
    Write-Output "registered $($mode) $($route.uri) -> $($route.node)"
}
