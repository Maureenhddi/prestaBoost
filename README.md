# PrestaBoost

PrestaBoost is a multi-boutique PrestaShop management platform built with Symfony 7, allowing users to manage multiple PrestaShop stores, collect stock data automatically, and apply dynamic branding per boutique.

## Features

### Multi-Boutique Management
- Each user can create their own boutique (PrestaShop domain + API key)
- Boutique creator automatically becomes admin
- Support for multiple boutiques per user

### Role-Based Access Control
- **USER**: Basic access to assigned boutiques
- **ADMIN**: Full control over specific boutiques, can invite other users
- **SUPER_ADMIN**: Global access to all boutiques and users

### Automatic Data Collection
- Daily automated collection via Symfony Scheduler or cron
- Fetches products (`/api/products`) and stock data (`/api/stock_availables`)
- Merges data by `id_product` to create complete stock snapshots
- Historical tracking in `daily_stock` table

### Dynamic Branding
- Automatic collection of logo, favicon, and theme colors
- CSS parsing for theme customization
- Dynamic application of boutique branding on dashboard

### REST API
- JWT authentication
- CORS enabled for frontend integration
- Endpoints for stock data, boutique management, and user invitations

## Tech Stack

- **PHP 8.2**
- **Symfony 7**
- **PostgreSQL 15**
- **Docker & Docker Compose**
- **Nginx**
- **Traefik** (production reverse proxy with HTTPS)

## Prerequisites

- Docker
- Docker Compose
- Make (optional, for convenience commands)

## Installation

### 1. Clone and Build

```bash
# Clone the repository
cd PrestaBoost

# Build and start containers
make install
```

This will:
- Build Docker images
- Start containers (PHP, Nginx, PostgreSQL)
- Install Composer dependencies
- Create database
- Run migrations

### 2. Generate JWT Keys

```bash
make jwt-keys
```

When prompted, use the passphrase configured in `.env` (default: `change_me`)

### 3. Access the Application

- Application: http://localhost:8080
- Database: localhost:5432 (user: `prestaboost`, password: `prestaboost_password`)

## Manual Setup (without Make)

```bash
# Build images
cd infra && docker-compose build

# Start containers
docker-compose up -d

# Install dependencies
docker-compose exec php composer install

# Create database
docker-compose exec php bin/console doctrine:database:create

# Run migrations
docker-compose exec php bin/console doctrine:migrations:migrate

# Generate JWT keys
mkdir -p ../config/jwt
docker-compose exec php openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:change_me
docker-compose exec php openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:change_me
```

## Usage

### Creating Users

Create users via Symfony console or register via API:

```bash
# Access container
make shell

# Create a user (you'll need to create a command for this or use fixtures)
bin/console app:create-user --email=admin@example.com --password=secret --first-name=John --last-name=Doe --super-admin
```

### Creating a Boutique

Once authenticated, create a boutique via API:

```bash
POST /api/boutiques
Content-Type: application/json
Authorization: Bearer YOUR_JWT_TOKEN

{
  "name": "My PrestaShop",
  "domain": "https://myshop.com",
  "api_key": "YOUR_PRESTASHOP_API_KEY"
}
```

The user creating the boutique automatically becomes its admin.

### Inviting Users to a Boutique

Boutique admins can invite other users:

```bash
POST /api/boutiques/{id}/invite
Content-Type: application/json
Authorization: Bearer YOUR_JWT_TOKEN

{
  "email": "user@example.com",
  "role": "USER"  # or "ADMIN"
}
```

### Collecting Stock Data

#### Manual Collection

Collect data for all boutiques:
```bash
make collect-data
```

Collect data for a specific boutique:
```bash
make collect-boutique ID=1
```

Or with branding data:
```bash
make console CMD='app:collect-prestashop-data --all --branding'
```

#### Automated Collection with Cron

Add to crontab:
```bash
# Collect data daily at 2 AM
0 2 * * * cd /path/to/PrestaBoost/infra && docker-compose exec -T php bin/console app:collect-prestashop-data --all
```

#### Using Symfony Scheduler

Configure in `config/packages/scheduler.yaml`:
```yaml
framework:
    scheduler:
        default_transport: async
```

Then create a scheduled message handler.

### API Endpoints

#### Authentication
```bash
POST /api/login
{
  "email": "user@example.com",
  "password": "secret"
}
```

#### Boutiques
- `GET /api/boutiques` - List accessible boutiques
- `POST /api/boutiques` - Create boutique
- `GET /api/boutiques/{id}` - Get boutique details
- `POST /api/boutiques/{id}/invite` - Invite user to boutique

#### Stocks
- `GET /api/stocks/latest?boutique_id={id}` - Get latest stock snapshot
- `GET /api/stocks/history/{boutiqueId}/{productId}?days=30` - Get product stock history

### Database Operations

```bash
# Create database
make db-create

# Run migrations
make db-migrate

# Generate new migration from entities
make db-diff

# Reset database (WARNING: deletes all data)
make db-reset
```

### Viewing Logs

```bash
# All logs
make logs

# PHP logs only
make logs-php

# Nginx logs only
make logs-nginx
```

### Container Access

```bash
# PHP container
make shell

# PostgreSQL container
make shell-postgres
```

## Production Deployment with Traefik

### 1. Configure Environment

Copy and edit the production environment file:
```bash
cp infra/.env.prod.example infra/.env.prod
```

Edit `.env.prod`:
```env
DOMAIN_NAME=prestaboost.yourdomain.com
ACME_EMAIL=admin@yourdomain.com

DB_NAME=prestaboost_db
DB_USER=prestaboost
DB_PASSWORD=your_secure_password_here
```

### 2. Start Production Stack

```bash
make prod-up
```

This will:
- Start Traefik reverse proxy
- Configure automatic HTTPS with Let's Encrypt
- Route traffic to your application
- Handle SSL certificate renewal

### 3. Production Configuration

Traefik will automatically:
- Redirect HTTP to HTTPS
- Obtain and renew SSL certificates via Let's Encrypt
- Route requests to the appropriate containers

Your application will be available at: `https://prestaboost.yourdomain.com`

### 4. Monitor Production

```bash
# View logs
make prod-logs

# Check status
make status

# Stop production stack
make prod-down
```

## Project Structure

```
PrestaBoost/
├── config/              # Symfony configuration
│   ├── packages/       # Bundle configurations
│   ├── routes/         # Route definitions
│   └── services.yaml   # Service container
├── infra/              # Docker infrastructure
│   ├── docker/         # Dockerfiles
│   │   ├── nginx/     # Nginx configuration
│   │   └── php/       # PHP configuration
│   ├── docker-compose.yml       # Development compose
│   └── docker-compose.prod.yml  # Production compose
├── public/             # Web root
│   └── index.php      # Front controller
├── src/
│   ├── Command/       # Console commands
│   ├── Controller/    # API controllers
│   │   └── Api/      # REST API endpoints
│   ├── Entity/        # Doctrine entities
│   ├── Repository/    # Database repositories
│   └── Service/       # Business logic services
├── var/               # Cache and logs
├── .env              # Environment configuration
├── composer.json     # PHP dependencies
├── Makefile         # Convenience commands
└── README.md        # This file
```

## Entity Model

### User
- Email, password (hashed)
- First name, last name
- Roles: USER, ADMIN, SUPER_ADMIN
- Relationships with boutiques via BoutiqueUser

### Boutique
- Name, domain, API key
- Branding: logo URL, favicon URL, theme color, font family, custom CSS
- Relationships with users and daily stocks

### BoutiqueUser (Join Table)
- Links User and Boutique
- Role: USER or ADMIN (per boutique)

### DailyStock
- Historical stock data
- Product ID, reference, name, quantity
- Collection timestamp
- Belongs to a Boutique

## Security Considerations

- API keys are stored encrypted in database
- JWT tokens for API authentication
- Role-based access control at boutique level
- SUPER_ADMIN role for platform administration
- HTTPS enforced in production via Traefik
- Password hashing with Symfony's auto algorithm

## Troubleshooting

### Port Conflicts
If port 8080 is already in use, edit `infra/docker-compose.yml`:
```yaml
nginx:
  ports:
    - "8081:80"  # Change 8080 to 8081
```

### Database Connection Issues
Check PostgreSQL is running:
```bash
make status
```

Verify database credentials in `.env`

### JWT Token Issues
Ensure JWT keys are generated:
```bash
make jwt-keys
```

Verify passphrase matches `.env` configuration.

### PrestaShop API Errors
- Verify API key is correct
- Ensure PrestaShop API is enabled
- Check PrestaShop domain is accessible
- Review PHP logs: `make logs-php`

## Development

### Running Tests
```bash
make console CMD='bin/phpunit'
```

### Clearing Cache
```bash
make cache-clear
```

### Database Schema Updates
After modifying entities:
```bash
make db-diff     # Generate migration
make db-migrate  # Apply migration
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## License

Proprietary - All rights reserved

## Support

For issues and questions, please open an issue on the repository.

## Roadmap

- [ ] User registration endpoint
- [ ] Email invitations for new users
- [ ] Dashboard frontend (Angular/React)
- [ ] Real-time stock alerts
- [ ] Product analytics and reporting
- [ ] Multi-language support
- [ ] Advanced branding customization
- [ ] Webhook support for PrestaShop events
