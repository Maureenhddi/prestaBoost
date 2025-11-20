# PrestaBoost - Quick Start Guide

Get PrestaBoost up and running in 5 minutes!

## 1. Install and Start

```bash
cd PrestaBoost
make install
make jwt-keys
```

When prompted for JWT passphrase, use: `change_me` (or change in `.env`)

## 2. Create Your First User

```bash
make console CMD='app:create-user \
  --email=admin@example.com \
  --password=secret \
  --first-name=John \
  --last-name=Doe \
  --super-admin'
```

## 3. Get JWT Token

```bash
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"secret"}'
```

Save the returned `token` value.

## 4. Create a Boutique

```bash
curl -X POST http://localhost:8080/api/boutiques \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -d '{
    "name": "My PrestaShop",
    "domain": "https://myshop.com",
    "api_key": "YOUR_PRESTASHOP_API_KEY"
  }'
```

Save the returned boutique `id`.

## 5. Collect Stock Data

```bash
# Replace 1 with your boutique ID
make collect-boutique ID=1
```

Or collect for all boutiques:
```bash
make collect-data
```

## 6. Query Stock Data

```bash
curl http://localhost:8080/api/stocks/latest?boutique_id=1 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## Next Steps

- **Invite users**: `POST /api/boutiques/{id}/invite` with `{"email": "...", "role": "USER"}`
- **Setup cron**: Add to crontab for daily collection
- **View history**: `GET /api/stocks/history/{boutiqueId}/{productId}`
- **Customize branding**: Use `--branding` flag when collecting data

## Useful Commands

```bash
make help              # Show all available commands
make logs              # View logs
make shell             # Access PHP container
make db-reset          # Reset database (careful!)
make cache-clear       # Clear Symfony cache
```

## Troubleshooting

**Port already in use?**
Edit `infra/docker-compose.yml` and change port `8080` to another port.

**Can't connect to database?**
Run `make status` to check if all containers are running.

**JWT token invalid?**
Ensure JWT keys are generated: `make jwt-keys`

**PrestaShop API errors?**
- Verify API key is correct
- Check PrestaShop webservice is enabled
- Ensure domain is accessible

## Production Deployment

```bash
# Configure
cp infra/.env.prod.example infra/.env.prod
# Edit .env.prod with your domain and credentials

# Deploy
make prod-up
```

Your app will be available at `https://your-domain.com` with automatic HTTPS!

## Need Help?

Check the full [README.md](README.md) for detailed documentation.
