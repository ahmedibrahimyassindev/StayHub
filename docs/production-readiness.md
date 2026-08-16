# Production Readiness

StayHub is now feature-complete as a local backend portfolio platform. Before using it as a real production system, configure and validate these items.

## Required Secrets

- Replace all local Keycloak, APISIX, PostgreSQL, and application secrets.
- Store production secrets in GitHub Environments or the deployment platform secret manager.
- Do not commit `.env` files with real credentials.

## Payment Provider

The current payment service uses a mock provider. A production provider integration should add:

- provider customer/payment intent identifiers
- webhook signature verification
- idempotent webhook handling
- refund and dispute handling
- provider-specific failure reason mapping

## Service-to-Service Security

APISIX validates public requests through Keycloak. Internal service calls should also use one of:

- service account JWTs
- mTLS between services
- private network policies plus signed internal headers

## Kafka Operations

Current local scripts publish booking outbox rows and consume notification events through Kafka CLI tools. Production should run long-lived workers for:

- booking outbox publishing
- notification event consumption
- failed Saga compensation retries

## Deployment

The manual GitHub Actions deploy workflow expects:

- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_SSH_KEY`
- `DEPLOY_PATH`

The target host must have Docker and Docker Compose installed and authenticated to GHCR if images are private.

## Observability

Booking Service propagates `X-Correlation-ID`. Extend the same middleware pattern to all services, then add:

- JSON application logs
- Prometheus metrics
- centralized log aggregation
- tracing with OpenTelemetry/Jaeger

## Data Protection

- Enable database backups and restore testing.
- Configure TLS at the edge.
- Restrict APISIX Admin API access.
- Rotate secrets and image tags through release processes.
