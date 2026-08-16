# StayHub Inventory Service

Owns room availability by room and date.

## Data Model

`room_inventory`

- `room_id`: external room id from `hotel-service`
- `date`: inventory date
- `total_rooms`: sellable room count for the date
- `available_rooms`: rooms still available to reserve
- `reserved_rooms`: rooms already reserved
- `price`: nightly price
- `currency`: ISO currency code

The table has a unique index on `room_id` and `date`.

## API

Protected through APISIX and Keycloak except for health.

```http
GET /api/inventory/health
GET /api/inventory?room_id=1&date_from=2026-09-01&date_to=2026-09-30
PUT /api/inventory
POST /api/inventory/reservations
POST /api/inventory/releases
```

Upsert inventory:

```json
{
  "room_id": 1,
  "date": "2026-09-01",
  "total_rooms": 10,
  "available_rooms": 10,
  "reserved_rooms": 0,
  "price": 180.00,
  "currency": "USD"
}
```

Reserve availability:

```json
{
  "room_id": 1,
  "check_in": "2026-09-01",
  "check_out": "2026-09-03",
  "quantity": 1
}
```

Release availability:

```json
{
  "room_id": 1,
  "check_in": "2026-09-01",
  "check_out": "2026-09-03",
  "quantity": 1
}
```

Reservation updates run inside a transaction and use an atomic guarded update per night:

- reserve only when `available_rooms >= quantity`
- release only when `reserved_rooms >= quantity`
- failed reservations return `409 Conflict`

## Local Commands

```bash
php artisan migrate --seed
php artisan route:list --path=api/inventory
```
