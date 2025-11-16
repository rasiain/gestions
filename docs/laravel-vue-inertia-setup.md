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
docker compose up -d --build (or dcupdb)
```

## Step 6: Generate Application Key

Check if APP_KEY is empty:
```bash
cat .env | grep APP_KEY
```

If empty, generate it:
```bash
docker compose exec app php artisan key:generate (or dce app php artisan key:generate )
```

## Step 7: Run Database Migrations

```bash
docker compose exec app php artisan migrate (or dce app php artisan migrate)
```

## Step 8: Install NPM Dependencies

```bash
docker compose exec app npm install --legacy-peer-deps (or dce app npm install)
```

## Step 9: Build Frontend Assets

```bash
docker compose exec app npm run build (or dce app npm run build)
```

## Step 10: Access Your Application

Open: **http://localhost:8080**

---

# Docker Commands

## Container Management

```bash
# Start containers
dcupd

# Start and rebuild
dcupdb

# Stop containers
dcdn

# Restart containers
dcrestart

# View status
dps
```

## Logs

```bash
# View all logs
dcl

# View specific service
dclf app

# Last 50 lines
dcl --tail=50 app
```

## Access Container Shell

```bash
dce app bash
dce db bash
```

## Laravel Commands

```bash
dce app php artisan migrate
dce app php artisan make:model Product
dce app php artisan make:controller ProductController
dce app php artisan route:list
dce app php artisan cache:clear
```

## Composer Commands

```bash
dce app composer install
dce app composer require package/name
dce app composer update
```

## NPM Commands

```bash
dce app npm install --legacy-peer-deps
dce app npm run build
dce app npm run dev
```

## Database Commands

```bash
# MySQL CLI
dce db mysql -u laravel_user -p

# Dump database
dce db mysqldump -u laravel_user -plaravel_password laravel > backup.sql

# Restore database
dce -T db mysql -u laravel_user -plaravel_password laravel < backup.sql
```

## Testing

```bash
dce app php artisan test
dce app php artisan test --coverage
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
dcrestart app
```

---

# Quick Reference

## Daily Workflow

```bash
# Start
dcupd

# Make changes
# Files are mounted, changes reflect immediately

# Build assets after JS/Vue changes
dce app npm run build

# End
dcdn
```

## After Pulling Code

```bash
dce app composer install
dce app npm install --legacy-peer-deps
dce app php artisan migrate
dce app npm run build
dce app php artisan optimize:clear
```

