#!/bin/bash

set -e

# Colors
BLUE='\033[0;34m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}   PrestaBoost Installation Script     ${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Check Docker
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed${NC}"
    exit 1
fi

# Check Docker Compose
if ! command -v docker-compose &> /dev/null; then
    echo -e "${RED}Error: Docker Compose is not installed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Docker and Docker Compose are installed${NC}"
echo ""

# Build containers
echo -e "${BLUE}Building Docker containers...${NC}"
cd infra && docker-compose build
echo -e "${GREEN}✓ Containers built${NC}"
echo ""

# Start containers
echo -e "${BLUE}Starting containers...${NC}"
docker-compose up -d
echo -e "${GREEN}✓ Containers started${NC}"
echo ""

# Wait for PostgreSQL to be ready
echo -e "${BLUE}Waiting for PostgreSQL to be ready...${NC}"
sleep 5
echo -e "${GREEN}✓ PostgreSQL is ready${NC}"
echo ""

# Install Composer dependencies
echo -e "${BLUE}Installing Composer dependencies...${NC}"
docker-compose exec -T php composer install --no-interaction
echo -e "${GREEN}✓ Dependencies installed${NC}"
echo ""

# Create database
echo -e "${BLUE}Creating database...${NC}"
docker-compose exec -T php bin/console doctrine:database:create --if-not-exists
echo -e "${GREEN}✓ Database created${NC}"
echo ""

# Run migrations
echo -e "${BLUE}Running migrations...${NC}"
docker-compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
echo -e "${GREEN}✓ Migrations executed${NC}"
echo ""

# Generate JWT keys
echo -e "${BLUE}Generating JWT keys...${NC}"
mkdir -p ../config/jwt
docker-compose exec -T php sh -c "openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:change_me" 2>/dev/null
docker-compose exec -T php sh -c "openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:change_me" 2>/dev/null
echo -e "${GREEN}✓ JWT keys generated${NC}"
echo ""

# Clear cache
echo -e "${BLUE}Clearing cache...${NC}"
docker-compose exec -T php bin/console cache:clear
echo -e "${GREEN}✓ Cache cleared${NC}"
echo ""

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}   Installation Complete!              ${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo ""
echo -e "1. Create a user:"
echo -e "   ${BLUE}make console CMD='app:create-user --email=admin@example.com --password=secret --first-name=John --last-name=Doe --super-admin'${NC}"
echo ""
echo -e "2. Access the application:"
echo -e "   ${BLUE}http://localhost:8080${NC}"
echo ""
echo -e "3. View the Quick Start guide:"
echo -e "   ${BLUE}cat QUICKSTART.md${NC}"
echo ""
echo -e "4. Check available commands:"
echo -e "   ${BLUE}make help${NC}"
echo ""
