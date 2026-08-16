param(
    [int] $Limit = 50,
    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'

$dbContainer = 'stayhub-booking-db'
$kafkaContainer = 'stayhub-kafka'
$dbUser = 'stayhub'
$dbName = 'booking_db'

if ($Limit -lt 1) {
    throw 'Limit must be at least 1.'
}

$safeLimit = [Math]::Min($Limit, 500)
$selectSql = @"
SELECT id, topic, payload::text
FROM outbox_messages
WHERE status = 'pending'
  AND available_at <= now()
ORDER BY id
LIMIT $safeLimit;
"@

$rows = docker exec $dbContainer psql -U $dbUser -d $dbName -At -F "`t" -c $selectSql

if ($LASTEXITCODE -ne 0) {
    throw 'Failed to read pending outbox messages from booking database.'
}

if (-not $rows) {
    Write-Output 'No pending outbox messages.'
    exit 0
}

foreach ($row in $rows) {
    $parts = $row -split "`t", 3

    if ($parts.Count -ne 3) {
        Write-Warning "Skipping malformed outbox row: $row"
        continue
    }

    $id = [int] $parts[0]
    $topic = $parts[1]
    $payload = $parts[2]

    Write-Output "Publishing outbox #$id to $topic"

    if ($DryRun) {
        continue
    }

    $payload | docker exec -i $kafkaContainer kafka-console-producer --bootstrap-server kafka:9092 --topic $topic

    if ($LASTEXITCODE -ne 0) {
        $errorSql = "UPDATE outbox_messages SET attempts = attempts + 1, status = 'failed', last_error = 'kafka-console-producer failed', updated_at = now() WHERE id = $id;"
        docker exec $dbContainer psql -U $dbUser -d $dbName -c $errorSql | Out-Null
        throw "Kafka publish failed for outbox message #$id."
    }

    $updateSql = "UPDATE outbox_messages SET attempts = attempts + 1, status = 'published', published_at = now(), last_error = NULL, updated_at = now() WHERE id = $id;"
    docker exec $dbContainer psql -U $dbUser -d $dbName -c $updateSql | Out-Null
}

Write-Output 'Outbox publish complete.'
