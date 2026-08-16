# StayHub Search Service

Read aggregator for hotel and room availability search.

## API

Protected through APISIX and Keycloak except for health.

```http
GET /api/search/health
GET /api/search/hotels
```

Hotel search filters:

- `city`
- `country`
- `check_in`
- `check_out`
- `guests`
- `max_price`
- `per_page`

Example:

```http
GET /api/search/hotels?city=Cairo&check_in=2026-08-17&check_out=2026-08-19&guests=2&max_price=250
```

## Behavior

Search calls:

- `hotel-service` for active hotels and rooms
- `inventory-service` for room availability by date

When dates are supplied, a room is returned only if every requested night has at least one available room. `check_out` is treated as the checkout date and is not counted as a booked night.

## Local Commands

```bash
php artisan route:list --path=api/search
```
