# PrestaBoost Architecture

## Overview

PrestaBoost is a multi-tenant SaaS platform built with Symfony 7 that enables users to manage multiple PrestaShop stores from a single dashboard.

## Architecture Layers

### 1. Presentation Layer (API)

**Controllers** (`src/Controller/Api/`)
- `BoutiqueController`: Boutique CRUD and user invitations
- `StockController`: Stock data queries and history

**Authentication**
- JWT-based authentication via LexikJWTAuthenticationBundle
- Token endpoint: `/api/login`
- Bearer token for all API requests

### 2. Application Layer

**Commands** (`src/Command/`)
- `CollectPrestashopDataCommand`: Manual or automated data collection
- `CreateUserCommand`: User creation via CLI

**Services** (`src/Service/`)
- `PrestaShopCollector`: Core logic for API calls to PrestaShop
  - Fetches products and stocks
  - Merges data by product ID
  - Saves to database
  - Collects branding information
- `BoutiqueAuthorizationService`: Access control logic
  - Boutique-level permissions
  - Role verification

### 3. Domain Layer

**Entities** (`src/Entity/`)

```
User (users table)
├── id
├── email (unique)
├── password (hashed)
├── firstName
├── lastName
├── roles (array)
└── boutiqueUsers (OneToMany)

Boutique (boutiques table)
├── id
├── name
├── domain
├── apiKey (encrypted)
├── logoUrl
├── faviconUrl
├── themeColor
├── fontFamily
├── customCss
├── createdAt
├── updatedAt
├── boutiqueUsers (OneToMany)
└── dailyStocks (OneToMany)

BoutiqueUser (boutique_users table) [Join Entity]
├── id
├── boutique (ManyToOne)
├── user (ManyToOne)
├── role (USER|ADMIN)
└── createdAt

DailyStock (daily_stocks table)
├── id
├── boutique (ManyToOne)
├── productId
├── reference
├── name
├── quantity
└── collectedAt
```

**Repositories** (`src/Repository/`)
- Custom queries for stock history and latest snapshots
- User management queries

### 4. Infrastructure Layer

**Docker Services**
```
┌─────────────────────────────────────┐
│           Traefik (Production)      │
│    Reverse Proxy + HTTPS (Let's    │
│           Encrypt)                  │
└─────────┬───────────────────────────┘
          │
┌─────────▼───────────────────────────┐
│             Nginx                    │
│      (Web Server, Port 80)          │
└─────────┬───────────────────────────┘
          │
┌─────────▼───────────────────────────┐
│           PHP-FPM 8.2               │
│    (Symfony Application)            │
│      - Composer                     │
│      - Doctrine ORM                 │
│      - JWT Auth                     │
└─────────┬───────────────────────────┘
          │
┌─────────▼───────────────────────────┐
│        PostgreSQL 15                │
│     (Persistent Storage)            │
└─────────────────────────────────────┘
```

**Database Schema**

```sql
users
├── PK: id
└── UK: email

boutiques
└── PK: id

boutique_users
├── PK: id
├── FK: boutique_id → boutiques.id
├── FK: user_id → users.id
└── UK: (boutique_id, user_id)

daily_stocks
├── PK: id
├── FK: boutique_id → boutiques.id
├── IDX: (boutique_id, collected_at)
└── IDX: product_id
```

## Security Model

### Authentication
- JWT tokens with RSA keys (4096 bits)
- Token TTL: 3600 seconds (1 hour)
- Refresh token: Not implemented yet

### Authorization

**Role Hierarchy**
```
ROLE_SUPER_ADMIN
  └── ROLE_ADMIN
        └── ROLE_USER
```

**Access Control**
- **ROLE_USER**: Base role for all users
- **ROLE_ADMIN**: Per-boutique admin role (managed in BoutiqueUser)
- **ROLE_SUPER_ADMIN**: Global admin role (can access all boutiques)

**Boutique Access Rules**
1. SUPER_ADMIN: Access to all boutiques
2. Boutique ADMIN: Full access to specific boutique
3. Boutique USER: Read access to specific boutique

## Data Flow

### Stock Collection Flow

```
┌──────────────┐
│  Cron/Manual │
│   Trigger    │
└──────┬───────┘
       │
       ▼
┌──────────────────────────────┐
│ CollectPrestashopDataCommand │
└──────┬───────────────────────┘
       │
       ▼
┌──────────────────────┐
│ PrestaShopCollector  │
│                      │
│ 1. Fetch Products    │◄──────┐
│    GET /api/products │       │
│                      │       │
│ 2. Fetch Stocks      │       │ PrestaShop
│    GET /api/stock_   │       │    API
│    availables        │       │
│                      │       │
│ 3. Merge by ID       │       │
│                      │       │
│ 4. Optional: Branding│       │
│    GET /api/shops    │◄──────┘
└──────┬───────────────┘
       │
       ▼
┌──────────────────┐
│   DailyStock     │
│   Entity (ORM)   │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│   PostgreSQL     │
│  daily_stocks    │
└──────────────────┘
```

### API Request Flow

```
┌──────────┐
│  Client  │
│ (Angular)│
└────┬─────┘
     │ POST /api/login
     ▼
┌──────────────┐
│ JWT Auth     │
│ (Lexik)      │
└────┬─────────┘
     │ Returns JWT Token
     │
     ▼
┌────────────────┐
│  Subsequent    │
│  API Calls     │
│  + Bearer      │
└────┬───────────┘
     │
     ▼
┌─────────────────────┐
│  Security Firewall  │
│  (JWT Validation)   │
└────┬────────────────┘
     │
     ▼
┌─────────────────────┐
│  Controller         │
│  (Authorization     │
│   Check)            │
└────┬────────────────┘
     │
     ▼
┌─────────────────────┐
│  Service Layer      │
│  (Business Logic)   │
└────┬────────────────┘
     │
     ▼
┌─────────────────────┐
│  Repository         │
│  (Data Access)      │
└────┬────────────────┘
     │
     ▼
┌─────────────────────┐
│  PostgreSQL         │
└─────────────────────┘
```

## Design Patterns

### Repository Pattern
- Encapsulates data access logic
- Custom queries for complex operations
- Example: `DailyStockRepository::findLatestSnapshot()`

### Service Layer
- Business logic separated from controllers
- Reusable across commands and controllers
- Example: `PrestaShopCollector` used by both command and potential API endpoint

### Dependency Injection
- Symfony's autowiring and autoconfiguration
- Type-hinted constructor injection
- Services defined in `config/services.yaml`

## Scalability Considerations

### Current Limitations
- Single database (vertical scaling only)
- Synchronous data collection
- No caching layer

### Future Improvements
- Redis cache for stock data
- Message queue (Symfony Messenger) for async collection
- Database read replicas
- CDN for static assets (logos, favicons)
- Elasticsearch for product search
- Horizontal scaling with load balancer

## Deployment Strategies

### Development
```
Docker Compose → PHP-FPM + Nginx + PostgreSQL
Port 8080 → localhost
```

### Production
```
Docker Compose + Traefik
├── Automatic HTTPS (Let's Encrypt)
├── Domain routing
└── Certificate renewal
```

### CI/CD (Proposed)
```
Git Push → GitHub Actions
  ├── Run tests
  ├── Build Docker images
  ├── Push to registry
  └── Deploy to production
```

## Monitoring & Logging

### Current Setup
- Docker logs: `docker-compose logs`
- Symfony logs: `var/log/`
- PSR-3 logger for collector service

### Recommended Additions
- Sentry for error tracking
- Prometheus + Grafana for metrics
- ELK stack for centralized logging
- Uptime monitoring (UptimeRobot, etc.)

## API Documentation

To add Swagger/OpenAPI documentation:
```bash
composer require nelmio/api-doc-bundle
```

Then annotate controllers with OpenAPI attributes.
