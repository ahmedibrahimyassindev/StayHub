# StayHub Booking Service

Coordinates booking creation and cancellation.

## Data Model

`bookings`

- `user_id`: external user id
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
GET /api/bookings?user_id=1&status=confirmed
POST /api/bookings
GET /api/bookings/{booking}
POST /api/bookings/{booking}/cancel
POST /api/bookings/{booking}/confirm-payment
POST /api/bookings/{booking}/fail-payment
```

Create booking:

```json
{
  "user_id": 1,
  "hotel_id": 1,
  "room_id": 1,
  "check_in": "2026-09-01",
  "check_out": "2026-09-03",
  "quantity": 1,
  "total_amount": 360.00,
  "currency": "USD"
}
```

Booking creation reserves inventory first by calling `inventory-service`, creates the booking as `pending_payment`, then creates a pending mock payment in `payment-service`. If inventory is unavailable, the API returns `409 Conflict` and no booking row is created.

Payment confirmation marks the mock payment as succeeded, then changes the booking to `confirmed`.

Payment failure marks the mock payment as failed, releases reserved inventory, then changes the booking to `payment_failed`.

Cancellation calls `inventory-service` to release the reserved rooms, then marks the booking as `cancelled`.

## Local Commands

```bash
php artisan migrate
php artisan route:list --path=api/bookings
```
