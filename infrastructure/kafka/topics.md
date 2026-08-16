# Kafka Topics

Initial StayHub topics:

- `booking-events`
- `inventory-events`
- `payment-events`
- `notification-events`

Kafka is configured with topic auto-creation enabled for local development.

Booking Service writes Kafka-ready messages to its transactional `outbox_messages` table before publishing. Event envelopes include:

- `event_id`
- `type`
- `version`
- `correlation_id`
- `aggregate_id`
- `occurred_at`
- `payload`

Current booking workflow events:

- `booking.created` on `booking-events`
- `payment.pending` on `booking-events`
- `booking.confirmed` on `booking-events`
- `booking.cancelled` on `booking-events`
- `booking.payment_failed` on `booking-events`
- `notification.requested` on `notification-events`

Publish pending booking outbox messages to real Kafka locally:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\kafka\publish-booking-outbox.ps1 -Limit 50
```

The script reads pending rows from `stayhub-booking-db`, sends each payload through `kafka-console-producer` in `stayhub-kafka`, then marks the row `published` only after the producer exits successfully.

Consume `notification.requested` events into Notification Service:

```powershell
powershell -ExecutionPolicy Bypass -File .\infrastructure\kafka\consume-notification-events.ps1 -MaxMessages 10
```

The consumer uses group `stayhub-notification-service` and writes `source_event_id` to notification records so duplicate event delivery is safe.
