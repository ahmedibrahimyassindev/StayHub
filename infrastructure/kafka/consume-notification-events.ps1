param(
    [int] $MaxMessages = 10,
    [string] $NotificationUrl = 'http://localhost:8007/api/notifications'
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false

if ($MaxMessages -lt 1) {
    throw 'MaxMessages must be at least 1.'
}

$safeMaxMessages = [Math]::Min($MaxMessages, 500)
$kafkaContainer = 'stayhub-kafka'
$consumerGroup = 'stayhub-notification-service'

$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$messages = & docker exec $kafkaContainer kafka-console-consumer `
    --bootstrap-server kafka:9092 `
    --topic notification-events `
    --group $consumerGroup `
    --from-beginning `
    --timeout-ms 5000 `
    --max-messages $safeMaxMessages 2>&1
$ErrorActionPreference = $previousErrorActionPreference

if (-not $messages) {
    Write-Output 'No notification events consumed.'
    exit 0
}

foreach ($message in $messages) {
    $line = ([string] $message).Trim().TrimStart([char] 0xFEFF)

    if (-not $line.StartsWith('{')) {
        continue
    }

    $event = $line | ConvertFrom-Json

    if ($event.type -ne 'notification.requested') {
        Write-Output "Skipping unsupported event type $($event.type)."
        continue
    }

    $notification = $event.payload
    $body = @{
        source_event_id = $event.event_id
        recipient_user_id = $notification.recipient_user_id
        channel = $notification.channel
        type = $notification.type
        subject = $notification.subject
        body = $notification.body
        payload = $notification.payload
    } | ConvertTo-Json -Depth 10

    $response = Invoke-RestMethod `
        -Method Post `
        -Uri $NotificationUrl `
        -ContentType 'application/json' `
        -Body $body

    $mode = if ($response.meta.idempotent_replay) { 'replayed' } else { 'created' }
    Write-Output "Notification $mode from event $($event.event_id)."
}
