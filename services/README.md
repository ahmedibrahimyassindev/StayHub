# Services

Planned Laravel services:

- `hotel-service`
- `inventory-service`
- `booking-service`
- `payment-service`
- `search-service`
- `user-service`
- `notification-service`

Each service owns its database and communicates with other services through REST or Kafka events.

Current status:

- All planned services have been scaffolded as Laravel services.
- Service containers run in Docker on `localhost:8001` through `localhost:8007`.
- APISIX routes public `/api/*` paths to the matching internal service.

Gateway health checks:

```bash
curl http://localhost:9080/api/hotels/health
curl http://localhost:9080/api/inventory/health
curl http://localhost:9080/api/bookings/health
curl http://localhost:9080/api/payments/health
curl http://localhost:9080/api/search/health
curl http://localhost:9080/api/users/health
curl http://localhost:9080/api/notifications/health
```

Register APISIX routes after clearing etcd data:

```powershell
powershell -ExecutionPolicy Bypass -File .\gateway\apisix\register-routes.ps1
```

Authentication status:

- `/api/*/health` routes are public for local readiness checks.
- All other `/api/*` service routes are protected by APISIX `openid-connect`.
- Tokens are issued by Keycloak realm `stayhub`.

Smoke test:

```powershell
powershell -ExecutionPolicy Bypass -File .\gateway\apisix\auth-test.ps1
```

Implemented domains:

- `hotel-service`: hotels and rooms tables, models, factories, seed data, and CRUD/list APIs.
- `inventory-service`: room availability table, seed data, list/upsert APIs, and reservation-safe reserve/release APIs.
- `booking-service`: bookings table, create/list/show/cancel APIs, and inventory reservation/release coordination.
