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
- `GET /api/hotels/{hotel}/rooms`
- `POST /api/hotels/{hotel}/rooms`
- `GET /api/hotels/{hotel}/rooms/{room}`
- `PATCH /api/hotels/{hotel}/rooms/{room}`
- `PUT /api/hotels/{hotel}/rooms/{room}`
- `DELETE /api/hotels/{hotel}/rooms/{room}`

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

Room list filters:

- `room_type`
- `status`
- `min_capacity`
- `max_price`
- `per_page`

Example:

```bash
curl http://localhost:9080/api/hotels/1/rooms?min_capacity=2 \
  -H "Authorization: Bearer <token>"
```
