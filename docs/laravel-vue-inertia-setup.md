# Laravel + Vue + Inertia Docker Setup - Complete Documentation

This is the complete documentation in a single file. You can split it into separate files in the `docs/` folder as needed.

---

# Table of Contents

1. [Getting Started](#getting-started)
2. [File Structure](#file-structure)
3. [Installation Steps](#installation-steps)
4. [Docker Commands](#docker-commands)
5. [Troubleshooting](#troubleshooting)
6. [Configuration](#configuration)

---

# Getting Started

## Prerequisites

Before you begin, ensure you have the following installed on your system:

### Required Software

- **Docker Desktop** (or Docker Engine + Docker Compose)
  - Download: https://www.docker.com/products/docker-desktop
  - Minimum version: Docker 20.10+, Docker Compose 2.0+

- **Git** (optional, but recommended)
  - Download: https://git-scm.com/downloads

### System Requirements

- **Operating System**: Linux, macOS, or Windows 10/11 with WSL2
- **RAM**: Minimum 4GB, recommended 8GB+
- **Disk Space**: At least 5GB free space
- **Ports**: Make sure ports 8080, 3306, and 6379 are available

### Verify Installation

```bash
docker --version
docker-compose --version
```

## What's Included

- **PHP 8.4** (Latest stable - November 2024)
- **Node.js 22.x LTS** "Jod" (October 2024)
- **MySQL 9.1** (Innovation release)
- **Redis 7.4**
- **Nginx** (Latest)
- **Laravel 12.x** with **Vue 3** and **Inertia.js**
- **Pest** testing framework

---

# File Structure

You need to create 6 configuration files:

1. Dockerfile

2. docker-compose.yml

3. docker/nginx/default.conf

4. docker/supervisor/supervisord.conf

5. docker/php/local.ini

6. setup.sh


---

# Installation Steps

## Step 1: Create Project Directory

```bash
mkdir my-laravel-app
cd my-laravel-app
```

## Step 2: Create Configuration Files

Create all 6 files listed in the File Structure section above.

## Step 3: Run Setup Script

```bash
chmod +x setup.sh
./setup.sh
```

## Step 4: Install Vue + Inertia (Interactive)

```bash
docker run --rm -it -v $(pwd):/var/www/html -w /var/www/html php:8.4-cli bash -c '
  apt-get update -qq && apt-get install -y -qq git libzip-dev > /dev/null 2>&1 &&
  docker-php-ext-install zip > /dev/null 2>&1 &&
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet &&
  composer require laravel/breeze --dev &&
  php artisan breeze:install
'
```

**Choose:**
- Stack: **vue**
- Testing: **pest**
- Dark mode: your preference
- TypeScript: **no** (or yes)

**Note**: The `npm: not found` error at the end is expected and harmless.

## Step 5: Start Docker Containers

```bash
docker-compose up -d --build
```

## Step 6: Generate Application Key

Check if APP_KEY is empty:
```bash
cat .env | grep APP_KEY
```

If empty, generate it:
```bash
docker-compose exec app php artisan key:generate
```

## Step 7: Run Database Migrations

```bash
docker-compose exec app php artisan migrate
```

## Step 8: Install NPM Dependencies

```bash
docker-compose exec app npm install --legacy-peer-deps
```

## Step 9: Build Frontend Assets

```bash
docker-compose exec app npm run build
```

## Step 10: Access Your Application

Open: **http://localhost:8080**

---

# Docker Commands

## Container Management

```bash
# Start containers
docker-compose up -d

# Start and rebuild
docker-compose up -d --build

# Stop containers
docker-compose down

# Restart containers
docker-compose restart

# View status
docker-compose ps
```

## Logs

```bash
# View all logs
docker-compose logs -f

# View specific service
docker-compose logs -f app

# Last 50 lines
docker-compose logs --tail=50 app
```

## Access Container Shell

```bash
docker-compose exec app bash
docker-compose exec db bash
```

## Laravel Commands

```bash
docker-compose exec app php artisan migrate
docker-compose exec app php artisan make:model Product
docker-compose exec app php artisan make:controller ProductController
docker-compose exec app php artisan route:list
docker-compose exec app php artisan cache:clear
```

## Composer Commands

```bash
docker-compose exec app composer install
docker-compose exec app composer require package/name
docker-compose exec app composer update
```

## NPM Commands

```bash
docker-compose exec app npm install --legacy-peer-deps
docker-compose exec app npm run build
docker-compose exec app npm run dev
```

## Database Commands

```bash
# MySQL CLI
docker-compose exec db mysql -u laravel_user -p

# Dump database
docker-compose exec db mysqldump -u laravel_user -plaravel_password laravel > backup.sql

# Restore database
docker-compose exec -T db mysql -u laravel_user -plaravel_password laravel < backup.sql
```

## Testing

```bash
docker-compose exec app php artisan test
docker-compose exec app php artisan test --coverage
```

---

# Troubleshooting

## Port Already in Use

**Error**: `bind: address already in use`

**Solution**: Change port in `docker-compose.yml`:
```yaml
ports:
  - "8081:80"  # Change 8080 to 8081
```

## NPM Dependency Conflicts

**Error**: `ERESOLVE unable to resolve dependency tree`

**Solution**:
```bash
docker-compose exec app npm install --legacy-peer-deps
```

## Permission Denied

**Error**: `Permission denied: storage/logs/laravel.log`

**Solution**:
```bash
docker-compose exec -u root app chown -R www-data:www-data /var/www/html
docker-compose exec -u root app chmod -R 755 storage bootstrap/cache
```

## Database Connection Failed

**Error**: `Connection refused`

**Check `.env`**:
```bash
DB_HOST=db  # Must be "db", not "localhost"
```

**Restart containers**:
```bash
docker-compose restart
```

## Application Key Missing

**Solution**:
```bash
docker-compose exec app php artisan key:generate
```

## Assets Not Loading

**Solution**:
```bash
docker-compose exec app npm run build
docker-compose exec app php artisan optimize:clear
```

## Complete Reset

```bash
docker-compose down -v
rm -rf vendor node_modules
./setup.sh
# Follow installation steps again
```

---

# Configuration

## Environment Variables

Edit `.env`:

```bash
APP_NAME="My Laravel App"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel_user
DB_PASSWORD=laravel_password

REDIS_HOST=redis
REDIS_PORT=6379
```

## PHP Configuration

Edit `docker/php/local.ini`:

```ini
upload_max_filesize=100M
post_max_size=100M
memory_limit=512M
max_execution_time=600
```

Restart:
```bash
docker-compose restart app
```

## MySQL 8.4 LTS Alternative

Edit `docker-compose.yml`:
```yaml
db:
  image: mysql:8.4  # Instead of mysql:9.1
```

## Add Queue Worker

Add to `docker-compose.yml`:

```yaml
queue:
  build:
    context: .
    dockerfile: Dockerfile
  container_name: laravel-queue
  restart: unless-stopped
  command: php artisan queue:work --tries=3
  volumes:
    - .:/var/www/html
  networks:
    - laravel-network
  depends_on:
    - app
    - redis
```

## Mailpit (Development Email)

Add to `docker-compose.yml`:

```yaml
mailpit:
  image: axllent/mailpit
  container_name: laravel-mailpit
  ports:
    - "8025:8025"
    - "1025:1025"
  networks:
    - laravel-network
```

Update `.env`:
```bash
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

Access: http://localhost:8025

## Hot Module Replacement (HMR)

Edit `vite.config.js`:
```js
server: {
    host: '0.0.0.0',
    port: 5173,
    hmr: {
        host: 'localhost',
    },
}
```

Add to `docker-compose.yml`:
```yaml
ports:
  - "8080:80"
  - "5173:5173"
```

Run:
```bash
docker-compose exec app npm run dev
```

---

# Quick Reference

## Daily Workflow

```bash
# Start
docker-compose up -d

# Make changes
# Files are mounted, changes reflect immediately

# Build assets after JS/Vue changes
docker-compose exec app npm run build

# End
docker-compose down
```

## After Pulling Code

```bash
docker-compose exec app composer install
docker-compose exec app npm install --legacy-peer-deps
docker-compose exec app php artisan migrate
docker-compose exec app npm run build
docker-compose exec app php artisan optimize:clear
```

## Useful Aliases

Add to `~/.bashrc` or `~/.zshrc`:

```bash
alias dce='docker-compose exec app'
alias dcr='docker-compose restart'
alias dcl='docker-compose logs -f'
```

Then use:
```bash
dce php artisan migrate
dce composer install
dce npm run build
```

---

## 🎉 You're All Set!

Your Laravel + Vue + Inertia application with Docker is ready to use!
