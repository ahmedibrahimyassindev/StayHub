# Local Docker Environment

Start the foundation stack:

```bash
docker compose up -d
```

Local endpoints:

- APISIX gateway: `http://localhost:9080`
- APISIX admin API: `http://localhost:9180`
- Keycloak: `http://localhost:8080`
- Kafka external listener: `localhost:9094`
- Redis: `localhost:6379`
- Hotel Service: `http://localhost:8001`
- Inventory Service: `http://localhost:8002`
- Booking Service: `http://localhost:8003`
- Payment Service: `http://localhost:8004`
- Search Service: `http://localhost:8005`
- User Service: `http://localhost:8006`
- Notification Service: `http://localhost:8007`

Keycloak admin:

- Username: `admin`
- Password: `admin`

Imported realm:

- Realm: `stayhub`
- Client: `stayhub-api`
- Demo users: `customer`, `manager`, `admin`
- Demo user password: `password`

Register APISIX routes:

```powershell
powershell -ExecutionPolicy Bypass -File .\gateway\apisix\register-routes.ps1
```

Test APISIX + Keycloak authentication:

```powershell
powershell -ExecutionPolicy Bypass -File .\gateway\apisix\auth-test.ps1
```

Expected result:

- Public health routes return `200` without a token.
- Protected service routes return `401` without a token.
- Protected service routes accept a valid Keycloak token and reach the downstream service.
