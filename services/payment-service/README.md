# StayHub Payment Service

Owns payment records and a mock payment lifecycle for local development.

## Data Model

`payments`

- `booking_id`: external booking id from `booking-service`
- `user_id`: external user id
- `amount`: payment amount
- `currency`: ISO currency code
- `status`: `pending`, `succeeded`, `failed`, or `refunded`
- `provider`: payment provider name, currently `mock`
- `provider_reference`: unique provider transaction reference
- `failure_reason`: failed payment detail
- `paid_at`: success timestamp
- `refunded_at`: refund timestamp

## API

Protected through APISIX and Keycloak except for health.

Send `Idempotency-Key` on payment creation to make retries safe. A repeated key for the same user returns the original payment with `meta.idempotent_replay=true`.

```http
GET /api/payments/health
GET /api/payments?booking_id=1&status=pending
POST /api/payments
GET /api/payments/{payment}
POST /api/payments/{payment}/succeed
POST /api/payments/{payment}/fail
POST /api/payments/{payment}/refund
```

Create payment:

```json
{
  "booking_id": 1,
  "user_id": 1,
  "amount": 210.00,
  "currency": "USD",
  "provider": "mock"
}
```

Mark failed:

```json
{
  "failure_reason": "Card declined"
}
```

Only pending payments can be marked succeeded or failed. Only succeeded payments can be refunded.

## Local Commands

```bash
php artisan migrate --seed
php artisan route:list --path=api/payments
```
