#!/bin/bash

echo "🚀 Setting up Laravel project with Docker..."

# Check if Laravel is already installed
if [ -f "artisan" ]; then
    echo "⚠️  Laravel is already installed in this directory!"
    read -p "Do you want to continue anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    echo "📦 Creating new Laravel project..."
    
    # Create Laravel project in a temporary directory
    docker run --rm -v $(pwd):/app -w /app php:8.4-cli bash -c '
        apt-get update -qq &&
        apt-get install -y -qq curl git unzip libzip-dev > /dev/null 2>&1 &&
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet &&
        docker-php-ext-install zip > /dev/null 2>&1 &&
        composer create-project laravel/laravel laravel-temp
    '
    
    # Move Laravel files to current directory
    mv laravel-temp/* laravel-temp/.* . 2>/dev/null || true
    rmdir laravel-temp
    
    echo "✅ Laravel project created!"
fi

# Create necessary directories for Docker configuration
echo "📁 Creating Docker configuration directories..."
mkdir -p docker/nginx docker/supervisor docker/php

# Create .env file configuration for Docker
if [ -f .env ]; then
    echo "🔧 Updating .env for Docker environment..."
    
    # Backup original .env
    cp .env .env.backup
    
    # Update database configuration for Docker
    sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=mysql/' .env
    sed -i 's/# DB_HOST=127.0.0.1/DB_HOST=db/' .env
    sed -i 's/# DB_PORT=3306/DB_PORT=3306/' .env
    sed -i 's/# DB_DATABASE=laravel/DB_DATABASE=laravel/' .env
    sed -i 's/# DB_USERNAME=root/DB_USERNAME=laravel_user/' .env
    sed -i 's/# DB_PASSWORD=/DB_PASSWORD=laravel_password/' .env
    
    # Update Redis configuration
    sed -i 's/REDIS_HOST=127.0.0.1/REDIS_HOST=redis/' .env
    
    echo "✅ .env updated (backup saved as .env.backup)"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✨ Setup Complete!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📋 NEXT STEPS:"
echo ""
echo "1️⃣  Install a starter kit (INTERACTIVE - you'll choose options):"
echo ""
echo "    docker run --rm -it -v \$(pwd):/var/www/html -w /var/www/html php:8.4-cli bash -c '"
echo "      apt-get update -qq && apt-get install -y -qq git libzip-dev nodejs npm > /dev/null 2>&1 &&"
echo "      docker-php-ext-install zip > /dev/null 2>&1 &&"
echo "      curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet &&"
echo "      composer require laravel/breeze --dev &&"
echo "      php artisan breeze:install"
echo "    '"
echo ""
echo "    💡 You'll be prompted to choose:"
echo "       • Stack: Vue, React, Blade, or API"
echo "       • Testing framework: Pest or PHPUnit"
echo "       • Dark mode support"
echo "       • TypeScript support (for Vue/React)"
echo ""
echo "2️⃣  Build and start containers:"
echo "    docker-compose up -d --build"
echo ""
echo "3️⃣  Generate app key:"
echo "    docker-compose exec app php artisan key:generate"
echo ""
echo "4️⃣  Run migrations:"
echo "    docker-compose exec app php artisan migrate"
echo ""
echo "5️⃣  Install npm dependencies and build assets:"
echo "    docker-compose exec app npm install"
echo "    docker-compose exec app npm run build"
echo ""
echo "6️⃣  Access your app at: http://localhost:8080"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
