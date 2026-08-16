# 🏨 StayHub

**StayHub** is a scalable hotel reservation platform built using a **microservices architecture**.

The project demonstrates advanced backend and distributed-system concepts including:

- Microservices Architecture
- API Gateway
- Centralized Authentication
- Event-Driven Architecture
- Saga Pattern
- Transactional Outbox Pattern
- Eventual Consistency
- Kafka Messaging
- Redis Caching
- Distributed Transactions
- Idempotency
- Docker
- Kubernetes
- Observability
- CI/CD

The goal of this project is not only to build a hotel booking application, but to demonstrate how complex business workflows can be implemented reliably across independently deployed services.

---

# 🎯 Project Goals

StayHub allows customers to:

- Search hotels
- Search available rooms
- View hotel details
- Create reservations
- Pay for reservations
- Cancel reservations
- Receive booking confirmations
- View reservation history
- Manage their profile

Hotel administrators can:

- Manage hotels
- Manage rooms
- Manage availability
- Manage prices
- View reservations
- View occupancy information

System administrators can:

- Manage users
- Manage hotels
- Monitor transactions
- Monitor services
- View system reports

---

# 🏗 Architecture

```text
                         ┌───────────────────┐
                         │      Client       │
                         │ Web / Mobile App  │
                         └─────────┬─────────┘
                                   │
                                   ▼
                       ┌─────────────────────┐
                       │    Apache APISIX    │
                       │     API Gateway     │
                       └─────────┬───────────┘
                                 │
                         Authentication
                                 │
                                 ▼
                       ┌─────────────────────┐
                       │      Keycloak       │
                       │ Identity & Access   │
                       │    Management       │
                       └─────────────────────┘

                                 │
              ┌──────────────────┼──────────────────┐
              │                  │                  │
              ▼                  ▼                  ▼

      ┌───────────────┐  ┌───────────────┐  ┌──────────────┐
      │ Hotel Service │  │ Search Service│  │ User Service │
      └───────────────┘  └───────────────┘  └──────────────┘

              │
              ▼

      ┌──────────────────┐
      │ Inventory Service│
      │ Room Availability│
      └──────────────────┘

              │
              ▼

      ┌──────────────────┐
      │ Booking Service  │
      └────────┬─────────┘
               │
               │ Booking Saga
               ▼
      ┌──────────────────┐
      │ Payment Service  │
      └──────────────────┘

               │
               ▼
      ┌──────────────────┐
      │ Notification     │
      │ Service          │
      └──────────────────┘


              Event Communication
                     │
                     ▼

             ┌─────────────────┐
             │      Kafka      │
             │  Event Broker   │
             └─────────────────┘
```

---

# 🧩 Microservices

## 1. API Gateway

Technology:

```text
Apache APISIX
```

Responsibilities:

- Route requests to microservices
- Authentication integration
- Rate limiting
- Request routing
- Load balancing
- API versioning
- Request logging
- CORS
- Traffic control

Example:

```text
/api/hotels/*
        ↓
Hotel Service

/api/bookings/*
        ↓
Booking Service

/api/payments/*
        ↓
Payment Service
```

---

# 🔐 2. Identity & Access Management

Technology:

```text
Keycloak
```

Keycloak handles authentication centrally instead of implementing login logic independently inside each microservice.

Example roles:

```text
CUSTOMER

HOTEL_MANAGER

ADMIN
```

Authentication flow:

```text
User
 │
 ▼
Keycloak
 │
 │ Access Token
 ▼
Client
 │
 ▼
Apache APISIX
 │
 │ Validate authentication
 ▼
Microservice
```

The services can then use claims contained in the authenticated identity to apply authorization rules.

---

# 👤 3. User Service

Responsibilities:

- User profile
- Personal information
- Preferences
- Customer profile management

Example data:

```text
users

id
keycloak_user_id
first_name
last_name
phone
country
created_at
updated_at
```

Authentication credentials are managed by Keycloak rather than stored by this service.

---

# 🏨 4. Hotel Service

Responsibilities:

- Hotel information
- Hotel facilities
- Hotel images
- Hotel policies
- Hotel categories
- Hotel management

Example:

```text
hotels

id
name
description
country
city
address
latitude
longitude
rating
status
created_at
updated_at
```

---

# 🚪 5. Room Service

Responsibilities:

- Room types
- Room details
- Capacity
- Amenities
- Base pricing

Example:

```text
rooms

id
hotel_id
room_type
name
capacity
base_price
currency
status
```

Example room types:

```text
Single Room

Double Room

Suite

Family Room
```

---

# 📦 6. Inventory Service

The Inventory Service is responsible for room availability.

Example:

```text
room_inventory

id
room_id
date
total_rooms
available_rooms
reserved_rooms
price
```

Example:

```text
Room Type: Deluxe

Date: 2026-09-20

Total Rooms: 20

Available Rooms: 7
```

This service is particularly important because concurrent users may attempt to reserve the same room inventory.

---

# 🔎 7. Search Service

Responsibilities:

- Hotel search
- Room search
- Availability search
- Price filtering
- Location filtering

Example:

```http
GET /api/search/hotels
```

Parameters:

```text
city=Cairo

check_in=2026-09-20

check_out=2026-09-23

guests=2
```

Example:

```http
GET /api/search/hotels?city=Cairo&check_in=2026-09-20&check_out=2026-09-23&guests=2
```

The search service can use:

```text
Redis

or

Elasticsearch / OpenSearch
```

for optimized hotel search.

---

# 📅 8. Booking Service

The Booking Service is responsible for managing hotel reservations.

Example reservation:

```text
booking_id

customer_id

hotel_id

room_id

check_in

check_out

guests

total_amount

currency

status
```

Possible statuses:

```text
PENDING

ROOM_RESERVED

PAYMENT_PROCESSING

CONFIRMED

FAILED

CANCELLED

REFUNDED
```

---

# 💳 9. Payment Service

Responsibilities:

- Payment initialization
- Payment processing
- Payment status
- Refunds
- Payment transaction history

Example statuses:

```text
PENDING

PROCESSING

PAID

FAILED

REFUNDED
```

The project can initially use a mock payment provider.

Later integrations can be added behind a common payment-provider interface.

---

# 🔔 10. Notification Service

Responsibilities:

- Booking confirmation
- Booking cancellation
- Payment confirmation
- Payment failure
- Email notifications
- SMS notifications
- Push notifications

Example event:

```text
BookingConfirmed
```

Notification Service consumes the event:

```text
Kafka
   │
   ▼
Notification Service
   │
   ├── Email
   ├── SMS
   └── Push
```

---

# 🔄 Booking Workflow

Creating a hotel reservation requires operations across multiple services.

Example:

```text
Customer
   │
   ▼
Create Booking
   │
   ▼
Booking Service
   │
   ▼
Reserve Room
   │
   ▼
Inventory Service
   │
   ▼
Process Payment
   │
   ▼
Payment Service
   │
   ▼
Confirm Booking
   │
   ▼
Notification Service
```

A normal database transaction cannot span these independent service databases.

StayHub therefore models the workflow using a **Saga**.

---

# 🔁 Saga Pattern

The booking process is implemented as a Saga.

```text
                BOOKING SAGA

Create Booking
      │
      ▼
Reserve Room
      │
      ▼
Process Payment
      │
      ▼
Confirm Booking
      │
      ▼
Send Notification
```

Each service performs its own local transaction.

---

# ✅ Successful Saga

Example:

```text
1. BookingCreated

          ↓

2. RoomReserved

          ↓

3. PaymentCompleted

          ↓

4. BookingConfirmed

          ↓

5. ConfirmationNotificationSent
```

Final status:

```text
Booking = CONFIRMED
```

---

# ❌ Failed Saga

Imagine payment fails.

```text
Create Booking
      │
      ▼
Reserve Room
      │
      ▼
Process Payment
      │
      X
Payment Failed
```

The system must compensate for previous operations.

```text
PaymentFailed
     │
     ▼
ReleaseRoom
     │
     ▼
CancelBooking
```

Result:

```text
Room inventory restored

Booking status = FAILED
```

---

# ↩️ Compensating Transactions

Each Saga step has a compensation operation.

| Action | Compensation |
|---|---|
| Create Booking | Cancel Booking |
| Reserve Room | Release Room |
| Charge Payment | Refund Payment |
| Confirm Booking | Cancel Confirmation |

Example:

```text
ReserveRoom

Compensation:

ReleaseRoom
```

---

# 🎼 Saga Orchestration

The recommended implementation uses **Saga Orchestration**.

```text
                    ┌─────────────────────┐
                    │ Booking Orchestrator│
                    └──────────┬──────────┘
                               │
                   ReserveRoom Command
                               │
                               ▼
                    Inventory Service
                               │
                   RoomReserved Event
                               │
                               ▼
                    Booking Orchestrator
                               │
                   ProcessPayment Command
                               │
                               ▼
                     Payment Service
                               │
                  PaymentCompleted Event
                               │
                               ▼
                    Booking Orchestrator
                               │
                       Confirm Booking
                               │
                               ▼
                         COMPLETED
```

The orchestrator tracks the current state of the Saga.

Example:

```text
booking_sagas

id
booking_id
current_step
status
started_at
completed_at
```

---

# 📤 Transactional Outbox Pattern

Publishing events introduces another problem.

Consider:

```text
Database Update
      +
Kafka Publish
```

These are two different operations.

Example:

```text
Booking Service

1. INSERT booking

2. Publish BookingCreated
```

If the database insert succeeds but Kafka publishing fails:

```text
Booking exists

but

other services never receive the event
```

To avoid this dual-write problem, StayHub uses the **Transactional Outbox Pattern**.

---

# 📦 Outbox Architecture

```text
             Booking Service

                  │

         Database Transaction

                  │
         ┌────────┴─────────┐
         │                  │
         ▼                  ▼

   Insert Booking      Insert Outbox Event

         │                  │
         └────────┬─────────┘
                  │
               COMMIT
                  │
                  ▼

             Outbox Table
                  │
                  ▼
           Outbox Publisher
                  │
                  ▼
                Kafka
```

Both the business record and the event are written within the same local database transaction.

---

# 🗃 Outbox Table

Example:

```text
outbox_messages

id
aggregate_id
aggregate_type
event_type
payload
status
created_at
published_at
```

Example:

```json
{
    "id": "evt_123",
    "aggregate_id": "booking_9001",
    "aggregate_type": "booking",
    "event_type": "BookingCreated",
    "payload": {
        "booking_id": "booking_9001",
        "hotel_id": 100,
        "room_id": 250,
        "customer_id": 501
    }
}
```

---

# 🔄 Outbox Publisher

A background worker reads unpublished events.

```text
Outbox Table
      │
      ▼
Outbox Worker
      │
      ▼
Kafka
      │
      ▼
Mark Event Published
```

Example event lifecycle:

```text
PENDING

↓

PUBLISHED
```

Failed publishing attempts can be retried.

---

# 📨 Kafka Topics

Possible topics:

```text
booking-events

inventory-events

payment-events

notification-events
```

Example events:

```text
BookingCreated

RoomReservationRequested

RoomReserved

RoomReservationFailed

PaymentRequested

PaymentCompleted

PaymentFailed

BookingConfirmed

BookingCancelled

RoomReleased

RefundRequested

RefundCompleted
```

---

# 🔁 Event Flow

```text
Booking Service
      │
      │ BookingCreated
      ▼
Kafka
      │
      ▼
Saga Orchestrator
      │
      │ ReserveRoom
      ▼
Inventory Service
      │
      │ RoomReserved
      ▼
Kafka
      │
      ▼
Saga Orchestrator
      │
      │ ProcessPayment
      ▼
Payment Service
      │
      │ PaymentCompleted
      ▼
Kafka
      │
      ▼
Saga Orchestrator
      │
      ▼
Booking Service
      │
      │ BookingConfirmed
      ▼
Kafka
      │
      ▼
Notification Service
```

---

# 🛡 Idempotency

Events may be delivered more than once.

Consumers must therefore handle duplicate messages safely.

Example:

```text
PaymentCompleted

received once

↓

Process

received again

↓

Ignore
```

Each event has a unique identifier.

```json
{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "event_type": "PaymentCompleted"
}
```

Consumers maintain processed event IDs.

```text
processed_messages

event_id
consumer
processed_at
```

Before processing:

```text
Has event_id already been processed?

YES
    ↓
Ignore

NO
    ↓
Process
    ↓
Store event_id
```

---

# 📥 Inbox Pattern

An optional **Transactional Inbox Pattern** can complement the Outbox pattern.

```text
Kafka
  │
  ▼
Consumer
  │
  ▼
Inbox Table
  │
  ▼
Business Logic
```

Example:

```text
inbox_messages

event_id
event_type
payload
processed_at
```

This helps consumers implement reliable idempotent event processing.

---

# 🔒 Preventing Double Booking

One of the most important challenges in the project is preventing two customers from reserving the final available room simultaneously.

Example:

```text
Available Rooms = 1

Customer A ─────┐
                ├── Reserve
Customer B ─────┘
```

Only one reservation must succeed.

Possible strategies include:

```text
Database row locking

SELECT ... FOR UPDATE

Optimistic locking

Atomic database updates

Redis distributed locks
```

A database-level atomic operation is preferred for the inventory source of truth.

Example concept:

```sql
UPDATE room_inventory
SET available_rooms = available_rooms - 1
WHERE room_id = ?
AND date = ?
AND available_rooms > 0;
```

If no row is updated, the reservation fails because inventory is no longer available.

---

# ⚡ Redis

Redis can be used for:

- Search caching
- Hotel details cache
- Availability cache
- Rate limiting
- Distributed locks where appropriate
- Temporary Saga state
- Session-independent application caching

Example:

```text
hotel:100

room:250

availability:250:2026-09-20
```

---

# 🚪 Apache APISIX

Apache APISIX acts as the entry point for external API requests.

```text
Internet
   │
   ▼
APISIX
   │
   ├── Authentication
   ├── Rate Limiting
   ├── Routing
   ├── Logging
   └── Load Balancing
```

Example routes:

```text
/api/hotels/*
        ↓
hotel-service

/api/search/*
        ↓
search-service

/api/bookings/*
        ↓
booking-service

/api/payments/*
        ↓
payment-service
```

Internal Kafka communication does not need to pass through the API Gateway.

---

# 🔐 Keycloak Roles

Example realm:

```text
stayhub
```

Roles:

```text
CUSTOMER

HOTEL_MANAGER

ADMIN
```

Example permissions:

### Customer

```text
Search Hotels

Create Booking

Cancel Own Booking

View Own Bookings
```

### Hotel Manager

```text
Manage Own Hotels

Manage Rooms

Manage Availability

View Hotel Reservations
```

### Admin

```text
Manage Users

Manage Hotels

View All Reservations

View Reports
```

---

# 🗄 Database Per Service

Each microservice owns its data.

```text
Hotel Service
      ↓
hotel_db


Inventory Service
      ↓
inventory_db


Booking Service
      ↓
booking_db


Payment Service
      ↓
payment_db


User Service
      ↓
user_db
```

Services must not directly query another service's database.

Communication happens through:

```text
REST

or

Kafka Events
```

---

# 🌐 Synchronous vs Asynchronous Communication

## REST

Use synchronous communication when the caller needs an immediate response.

Examples:

```text
Get Hotel

Get Booking

Search Hotels
```

---

## Kafka

Use asynchronous communication for business events and long-running workflows.

Examples:

```text
BookingCreated

RoomReserved

PaymentCompleted

BookingConfirmed
```

---

# 🐳 Docker

Local development environment:

```text
Docker Compose

├── Apache APISIX
├── etcd
├── Keycloak
├── Kafka
├── Redis
├── Hotel Service
├── Inventory Service
├── Booking Service
├── Payment Service
├── Search Service
├── Notification Service
└── Databases
```

Start:

```bash
docker compose up -d
```

---

# ☸️ Kubernetes

An advanced deployment can run on Kubernetes.

```text
Kubernetes

├── APISIX Ingress / Gateway
├── Hotel Service
├── Inventory Service
├── Booking Service
├── Payment Service
├── Notification Service
├── Redis
└── Kafka
```

Each service can be independently scaled.

Example:

```text
booking-service

replicas: 3
```

---

# 📊 Observability

A distributed architecture needs centralized observability.

Recommended stack:

```text
Prometheus

Grafana

OpenTelemetry

Jaeger

ELK / Loki
```

Important metrics:

```text
Request Latency

Error Rate

Requests Per Second

Kafka Consumer Lag

Saga Failures

Payment Failures

Outbox Pending Events

Booking Success Rate
```

---

# 🔍 Distributed Tracing

Every incoming request should have a correlation identifier.

Example:

```text
X-Correlation-ID:
550e8400-e29b-41d4-a716-446655440000
```

The same ID can propagate across:

```text
APISIX

↓

Booking Service

↓

Kafka

↓

Inventory Service

↓

Payment Service
```

This makes debugging distributed workflows easier.

---

# 🧪 Testing

The project should contain several types of tests.

## Unit Tests

```text
Booking domain

Pricing logic

Saga transitions

Payment logic
```

## Integration Tests

```text
Database

Redis

Kafka

Outbox Worker
```

## Contract Tests

Verify communication between services.

```text
Booking Service

↔

Inventory Service
```

## End-to-End Tests

Test the complete booking Saga.

Example:

```text
Create Booking

↓

Reserve Room

↓

Pay

↓

Confirm

↓

Notification
```

---

# 💥 Failure Scenarios

The project should intentionally test distributed-system failures.

### Payment Failure

```text
Room Reserved

↓

Payment Failed

↓

Release Room

↓

Cancel Booking
```

---

### Inventory Failure

```text
Create Booking

↓

Room Not Available

↓

Cancel Booking
```

---

### Kafka Temporarily Unavailable

```text
Booking saved

↓

Outbox event saved

↓

Kafka unavailable

↓

Outbox worker retries

↓

Kafka restored

↓

Event published
```

---

### Duplicate Kafka Event

```text
PaymentCompleted

↓

Consumer processes

↓

Same event arrives again

↓

Idempotency check

↓

Ignored
```

---

### Notification Failure

Notification failure should not cancel a successful hotel reservation.

```text
Booking Confirmed

↓

Notification Failed

↓

Retry Notification
```

---

# 📁 Repository Structure

One possible monorepo structure:

```text
stayhub/

├── gateway/
│   └── apisix/
│
├── infrastructure/
│   ├── docker/
│   ├── kafka/
│   ├── keycloak/
│   ├── kubernetes/
│   ├── monitoring/
│   └── redis/
│
├── services/
│
│   ├── hotel-service/
│   │
│   ├── inventory-service/
│   │
│   ├── booking-service/
│   │
│   ├── payment-service/
│   │
│   ├── search-service/
│   │
│   ├── user-service/
│   │
│   └── notification-service/
│
├── docker-compose.yml
│
└── README.md
```

---

# 🛠 Suggested Technology Stack

### Backend

```text
PHP 8.4+

Laravel 12
```

### Authentication

```text
Keycloak

OpenID Connect

OAuth 2.0
```

### API Gateway

```text
Apache APISIX
```

### Messaging

```text
Apache Kafka
```

### Cache

```text
Redis
```

### Databases

```text
PostgreSQL

or

MySQL
```

### Containers

```text
Docker

Docker Compose

Kubernetes
```

### Observability

```text
OpenTelemetry

Prometheus

Grafana

Jaeger
```

### Testing

```text
Pest

or

PHPUnit
```

### CI/CD

```text
GitHub Actions
```

---

# 🚀 Development Roadmap

## Phase 1 — Foundation

- [ ] Create Docker environment
- [ ] Configure Apache APISIX
- [ ] Configure Keycloak
- [ ] Create Keycloak realm
- [ ] Create CUSTOMER role
- [ ] Create HOTEL_MANAGER role
- [ ] Create ADMIN role
- [ ] Configure APISIX authentication
- [ ] Create basic microservices

---

## Phase 2 — Hotel Domain

- [ ] Hotel Service
- [ ] Room management
- [ ] Inventory Service
- [ ] Availability management
- [ ] Search Service
- [ ] Redis caching

---

## Phase 3 — Booking

- [ ] Booking Service
- [ ] Booking states
- [ ] Inventory reservation
- [ ] Prevent double booking
- [ ] Booking expiration

---

## Phase 4 — Event-Driven Architecture

- [ ] Kafka
- [ ] Domain events
- [ ] Event schemas
- [ ] Event consumers
- [ ] Event producers

---

## Phase 5 — Transactional Outbox

- [ ] Outbox table
- [ ] Outbox publisher
- [ ] Retry mechanism
- [ ] Idempotent consumers
- [ ] Inbox pattern

---

## Phase 6 — Saga

- [ ] Booking Saga
- [ ] Saga orchestrator
- [ ] Reserve room step
- [ ] Payment step
- [ ] Confirmation step
- [ ] Compensation transactions
- [ ] Saga failure recovery

---

## Phase 7 — Payments

- [ ] Payment Service
- [ ] Mock payment provider
- [ ] Idempotency keys
- [ ] Refund flow
- [ ] Payment events

---

## Phase 8 — Notifications

- [ ] Notification Service
- [ ] Email
- [ ] SMS abstraction
- [ ] Booking notifications
- [ ] Retry mechanism

---

## Phase 9 — Observability

- [ ] Centralized logging
- [ ] Correlation IDs
- [ ] Distributed tracing
- [ ] Prometheus
- [ ] Grafana
- [ ] Kafka monitoring

---

## Phase 10 — DevOps

- [ ] Automated tests
- [ ] GitHub Actions
- [ ] Docker images
- [ ] Kubernetes manifests
- [ ] Health checks
- [ ] Readiness probes
- [ ] Liveness probes

---

# 🎯 Concepts Demonstrated

This project is intended to demonstrate practical experience with:

```text
Microservices Architecture

Domain Boundaries

Database Per Service

API Gateway Pattern

Identity & Access Management

OpenID Connect

OAuth 2.0

Event-Driven Architecture

Apache Kafka

Saga Pattern

Compensating Transactions

Transactional Outbox Pattern

Inbox Pattern

Eventual Consistency

Idempotency

Distributed Transactions

Concurrency Control

Distributed Tracing

Redis Caching

Docker

Kubernetes

Observability

CI/CD
```

---

# 💡 Main Engineering Challenge

The most important workflow in StayHub is:

```text
Hotel Booking
```

because a single customer action affects multiple independent services:

```text
Booking

Inventory

Payment

Notification
```

Instead of trying to create one ACID transaction across all databases, StayHub treats each service operation as an independent local transaction and coordinates the overall workflow through events, Saga state, compensation operations, and reliable event publishing.

That makes the reservation workflow the central demonstration of distributed-system design in this repository.

---

# 📖 Example Complete Booking Scenario

```text
Customer
   │
   │ POST /bookings
   ▼
APISIX
   │
   │ Authentication
   ▼
Keycloak
   │
   ▼
Booking Service
   │
   ├── Create PENDING booking
   │
   └── Save BookingCreated to Outbox
              │
              ▼
       Outbox Publisher
              │
              ▼
             Kafka
              │
              ▼
       Saga Orchestrator
              │
              │ ReserveRoom
              ▼
      Inventory Service
              │
              ├── Reserve inventory
              └── RoomReserved
                      │
                      ▼
                     Kafka
                      │
                      ▼
               Saga Orchestrator
                      │
                      │ ProcessPayment
                      ▼
               Payment Service
                      │
                      ├── Charge customer
                      └── PaymentCompleted
                               │
                               ▼
                              Kafka
                               │
                               ▼
                       Saga Orchestrator
                               │
                               ▼
                        Booking Service
                               │
                               ├── CONFIRMED
                               └── BookingConfirmed
                                      │
                                      ▼
                                     Kafka
                                      │
                                      ▼
                            Notification Service
                                      │
                                      ▼
                           Confirmation Email
```

---

# 🏆 Portfolio Goal

StayHub is designed as a portfolio project for demonstrating **senior backend engineering skills** rather than only CRUD development.

The project focuses on:

- Service boundaries
- Distributed transactions
- Reliability
- Failure handling
- Scalability
- Security
- Asynchronous processing
- Infrastructure
- Observability
- Production-oriented architecture

---

# 📄 License

This project is licensed under the **MIT License**.

---

## 👨‍💻 Author

**Ahmed Ibrahim Yassin**

Backend / Full Stack Developer

PHP • Laravel • Microservices • Distributed Systems • Kafka • Redis • Docker