# StayHub User Service

Owns user profile data while Keycloak remains the identity provider.

## Data Model

`user_profiles`

- `keycloak_user_id`: external Keycloak user id or username for local demo data
- `email`
- `first_name`
- `last_name`
- `phone`
- `role`: `CUSTOMER`, `HOTEL_MANAGER`, or `ADMIN`
- `locale`
- `metadata`

## API

Protected through APISIX and Keycloak except for health.

```http
GET /api/users/health
GET /api/users/profiles?role=CUSTOMER&q=demo
POST /api/users/profiles
GET /api/users/profiles/{profile}
GET /api/users/profiles/keycloak/{keycloakUserId}
PUT /api/users/profiles/{profile}
```

Create profile:

```json
{
  "keycloak_user_id": "customer",
  "email": "customer@stayhub.local",
  "first_name": "Demo",
  "last_name": "Customer",
  "phone": "+201000000000",
  "role": "CUSTOMER",
  "locale": "en",
  "metadata": {}
}
```

## Local Commands

```bash
php artisan migrate --seed
php artisan route:list --path=api/users
```
