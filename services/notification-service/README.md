# StayHub Notification Service

Owns notification records and a mock delivery lifecycle.

## Data Model

`notifications`

- `recipient_user_id`: external user profile id
- `channel`: `email`, `sms`, or `in_app`
- `type`: event or notification type
- `subject`
- `body`
- `payload`: structured context
- `status`: `queued`, `sent`, or `failed`
- `failure_reason`
- `sent_at`
- `read_at`

## API

Protected through APISIX and Keycloak except for health.

```http
GET /api/notifications/health
GET /api/notifications?recipient_user_id=1&unread=1
POST /api/notifications
GET /api/notifications/{notification}
POST /api/notifications/{notification}/send
POST /api/notifications/{notification}/fail
POST /api/notifications/{notification}/read
```

Create notification:

```json
{
  "recipient_user_id": 1,
  "channel": "email",
  "type": "booking.created",
  "subject": "Your booking is pending payment",
  "body": "Complete payment to confirm your booking.",
  "payload": {
    "booking_id": 1
  }
}
```

Only queued notifications can be sent or failed. Read status is tracked separately with `read_at`.

## Local Commands

```bash
php artisan migrate --seed
php artisan route:list --path=api/notifications
```
