# StayHub Booking Service

Coordinates booking creation and cancellation.

## Data Model

`bookings`

- `user_id`: authenticated user id derived from APISIX/Keycloak identity
- `hotel_id`: external hotel id from `hotel-service`
- `room_id`: external room id from `hotel-service`
- `check_in`: first booked night
- `check_out`: checkout date, not booked as a night
- `quantity`: number of rooms reserved
- `status`: `pending_payment`, `confirmed`, `cancelled`, or `payment_failed`
- `total_amount`: booking total
- `currency`: ISO currency code
- `payment_id`: external payment id from `payment-service`
- `cancelled_at`: cancellation timestamp

## API

Protected through APISIX and Keycloak except for health.

```http
GET /api/bookings/health
GET /api/bookings?status=confirmed
POST /api/bookings
GET /api/bookings/{booking}
POST /api/bookings/{booking}/cancel
POST /api/bookings/{booking}/confirm-payment
POST /api/bookings/{booking}/fail-payment
```

Create booking:

```json
{
  "hotel_id": 1,
  "room_id": 1,
  "check_in": "2026-09-01",
  "check_out": "2026-09-03",
  "quantity": 1
}
```

Booking creation derives `user_id` from trusted gateway identity, reserves inventory first by calling `inventory-service`, calculates `total_amount` and `currency` from inventory pricing, creates the booking as `pending_payment`, creates a pending mock payment in `payment-service`, and records notification work in the transactional outbox. If inventory is unavailable, the API returns `409 Conflict` and no booking row is created.

Send `Idempotency-Key` on booking creation to make client retries safe. A repeated key for the same authenticated user and identical payload returns the original booking with `meta.idempotent_replay=true` instead of reserving inventory or creating another payment again. Reusing the same key with a different payload returns `409`.

Payment confirmation marks the mock payment as succeeded, changes the booking to `confirmed`, marks the booking Saga completed, and records a confirmation notification event.

Payment failure marks the mock payment as failed, releases reserved inventory, changes the booking to `payment_failed`, marks the booking Saga compensated, and records a failure notification event.

Cancellation calls `inventory-service` to release the reserved rooms, marks the booking as `cancelled`, marks the booking Saga compensated, and records a cancellation notification event.

Transactional outbox events are stored in `outbox_messages` with a versioned envelope containing `event_id`, `correlation_id`, `aggregate_id`, `occurred_at`, and `payload`. Publish pending events with:

```bash
php artisan outbox:publish --limit=50
```

## Local Commands

```bash
php artisan migrate
php artisan route:list --path=api/bookings
```
