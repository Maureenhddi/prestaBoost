# Changelog

All notable changes to PrestaBoost will be documented in this file.

## [1.0.0] - 2024-01-XX

### Added
- Initial release of PrestaBoost
- Multi-boutique management system
- Role-based access control (USER, ADMIN, SUPER_ADMIN)
- Automatic PrestaShop data collection via console command
- REST API with JWT authentication
- Docker infrastructure with PHP 8.2, Nginx, PostgreSQL
- Traefik reverse proxy configuration for production
- Stock data collection and historical tracking
- Boutique branding data collection (logo, colors, CSS)
- API endpoints for stocks and boutique management
- User invitation system for boutiques
- Makefile for easy Docker management
- Comprehensive documentation and quick start guide

### Core Features
- **Entities**: User, Boutique, BoutiqueUser, DailyStock
- **Commands**:
  - `app:collect-prestashop-data` - Collect stock data
  - `app:create-user` - Create users
- **API Endpoints**:
  - POST `/api/login` - Authentication
  - GET/POST `/api/boutiques` - Boutique management
  - POST `/api/boutiques/{id}/invite` - User invitations
  - GET `/api/stocks/latest` - Latest stock snapshot
  - GET `/api/stocks/history/{boutiqueId}/{productId}` - Stock history

### Technical Stack
- Symfony 7.0
- PHP 8.2
- PostgreSQL 15
- Docker & Docker Compose
- Nginx
- Traefik (production)
- JWT Authentication
- CORS support

## [Unreleased]

### Planned Features
- User registration endpoint
- Email invitations with notifications
- Dashboard frontend (Angular/React)
- Real-time stock alerts
- Product analytics and reporting
- Multi-language support
- Advanced branding customization
- Webhook support for PrestaShop events
- Automated testing suite
- API documentation with Swagger/OpenAPI
