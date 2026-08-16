# StayHub

Hotel reservation microservices platform built with Laravel services, Apache APISIX, Keycloak, PostgreSQL, Redis, and Kafka.

## Architecture

- APISIX is the public API gateway on `http://localhost:9080`.
- Keycloak issues tokens for the `stayhub` realm on `http://localhost:8080`.
- Each Laravel service owns its own database.
- Services communicate through internal Docker DNS names such as `inventory-service:8000`.
- Kafka and Redis are available for event and cache workflows.

## Services

- `hotel-service`: hotels and rooms
- `inventory-service`: room availability and reservation-safe inventory updates
- `booking-service`: booking workflow, inventory reservation, payment workflow coordination, and notification creation
- `payment-service`: mock payment lifecycle
- `search-service`: available hotel/room search aggregator
- `user-service`: user profiles linked to Keycloak identities
- `notification-service`: notification records and mock delivery lifecycle

## Local Setup

Start the stack:

```powershell
docker compose up -d
```

Register APISIX routes:

```powershell
powershell -ExecutionPolicy Bypass -File .\gateway\apisix\register-routes.ps1
```

Run the full gateway smoke test:

```powershell
powershell -ExecutionPolicy Bypass -File .\gateway\apisix\e2e-smoke.ps1
```

## CI/CD

GitHub Actions workflows live in `.github/workflows`.

- `CI`: runs on pull requests and pushes to `main`. It validates `docker-compose.yml`, installs each Laravel service, lints PHP files, runs migrations against SQLite, and runs the service test suite.
- `Publish Images`: runs on pushes to `main` that change service code, or manually from GitHub Actions. It builds every service Docker image and pushes it to GitHub Container Registry.

Published image names:

```text
ghcr.io/<owner>/stayhub-hotel-service
ghcr.io/<owner>/stayhub-inventory-service
ghcr.io/<owner>/stayhub-booking-service
ghcr.io/<owner>/stayhub-payment-service
ghcr.io/<owner>/stayhub-search-service
ghcr.io/<owner>/stayhub-user-service
ghcr.io/<owner>/stayhub-notification-service
```

Tags:

- `latest` for the default branch
- commit SHA for every published image

## Demo Users

All demo users use password `password`.

- `customer`
- `manager`
- `admin`

## Main APIs

All non-health routes are protected by APISIX OpenID Connect.

```http
GET /api/hotels
GET /api/hotels/{hotel}/rooms
GET /api/inventory
POST /api/bookings
POST /api/bookings/{booking}/confirm-payment
POST /api/bookings/{booking}/fail-payment
GET /api/payments
GET /api/search/hotels
GET /api/users/profiles
GET /api/notifications
```

Health routes are public:

```http
GET /api/hotels/health
GET /api/inventory/health
GET /api/bookings/health
GET /api/payments/health
GET /api/search/health
GET /api/users/health
GET /api/notifications/health
```
