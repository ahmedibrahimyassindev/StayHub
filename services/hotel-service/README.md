# Hotel Service

Owns hotel profile data for StayHub.

## API

Public:

- `GET /api/hotels/health`

Protected through APISIX + Keycloak:

- `GET /api/hotels`
- `POST /api/hotels`
- `GET /api/hotels/{hotel}`
- `PATCH /api/hotels/{hotel}`
- `PUT /api/hotels/{hotel}`
- `DELETE /api/hotels/{hotel}`

List filters:

- `city`
- `country`
- `status`
- `q`
- `per_page`

Example:

```bash
curl http://localhost:9080/api/hotels?city=Cairo \
  -H "Authorization: Bearer <token>"
```

