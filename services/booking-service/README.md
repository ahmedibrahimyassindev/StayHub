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

Booking creation derives `user_id` from trusted gateway identity, reserves inventory first by calling `inventory-service`, calculates `total_amount` and `currency` from inventory pricing, creates the booking as `pending_payment`, creates a pending mock payment in `payment-service`, then creates a pending-payment notification in `notification-service`. If inventory is unavailable, the API returns `409 Conflict` and no booking row is created.

Send `Idempotency-Key` on booking creation to make client retries safe. A repeated key for the same authenticated user returns the original booking with `meta.idempotent_replay=true` instead of reserving inventory or creating another payment again.

Payment confirmation marks the mock payment as succeeded, changes the booking to `confirmed`, and creates a confirmation notification.

Payment failure marks the mock payment as failed, releases reserved inventory, changes the booking to `payment_failed`, and creates a failure notification.

Cancellation calls `inventory-service` to release the reserved rooms, marks the booking as `cancelled`, and creates a cancellation notification.

## Local Commands

```bash
php artisan migrate
php artisan route:list --path=api/bookings
```
