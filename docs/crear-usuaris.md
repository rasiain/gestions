# Creació d'usuaris

## Requisit previ: Executar les migracions

**Abans de crear usuaris**, has d'executar les migracions de Laravel per crear la taula `users` i altres taules necessàries a la base de dades:

```bash
docker exec laravel-app php artisan migrate
```

Això crearà les taules següents:
- `users` - Per emmagatzemar els usuaris
- `password_reset_tokens` - Per gestionar el restabliment de contrasenyes
- `sessions` - Per gestionar les sessions d'usuari
- `cache` i `jobs` - Per a funcionalitats del sistema

**Important**: Només cal executar les migracions una vegada. Si ja les has executat, no cal tornar-les a executar.

## Quan cal crear un usuari?

Cal crear un usuari en les següents situacions:

1. **Primera instal·lació**: Després d'executar les migracions per primer cop
2. **Desenvolupament**: Per provar funcionalitats que requereixin autenticació
3. **Producció**: Per crear comptes d'administrador o usuaris inicials

## Mètodes per crear usuaris

### Mètode 1: Registre des de la interfície web (recomanat)

1. Accedeix a `http://localhost:8080` (o la URL de la teva aplicació)
2. Fes clic a "Register" o "Registrar-se"
3. Omple el formulari amb les dades del nou usuari
4. L'usuari serà creat i validat automàticament

### Mètode 2: Via Artisan Tinker

Per crear un usuari des de la línia de comandes:

```bash
docker exec laravel-app php artisan tinker
```

Dins de Tinker, executa:

```php
App\Models\User::create([
    'name' => 'Nom de l\'usuari',
    'email' => 'usuari@example.com',
    'password' => bcrypt('contrasenya'),
    'email_verified_at' => now()
]);
```

O en una sola línia:

```bash
docker exec laravel-app php artisan tinker --execute="App\Models\User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'), 'email_verified_at' => now()]);"
```

### Mètode 3: Crear un Seeder (per a desenvolupament)

Si necessites crear múltiples usuaris de prova, pots crear un seeder:

```bash
docker exec laravel-app php artisan make:seeder UserSeeder
```

Edita `src/database/seeders/UserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
    }
}
```

Executa el seeder:

```bash
docker exec laravel-app php artisan db:seed --class=UserSeeder
```

## Modificar usuaris existents

### Canviar la contrasenya d'un usuari

Si necessites canviar la contrasenya d'un usuari (per exemple, per a tasques d'administració):

**Opció A: En una sola línia (recomanat)**

```bash
docker exec laravel-app php artisan tinker --execute="\$user = App\Models\User::where('email', 'admin@example.com')->first(); \$user->password = bcrypt('nova_contrasenya'); \$user->save();"
```

**Opció B: Mode interactiu**

```bash
docker exec -it laravel-app php artisan tinker
```

Dins de Tinker (veuràs el prompt `>`), executa:

```php
$user = App\Models\User::where('email', 'admin@example.com')->first();
$user->password = bcrypt('nova_contrasenya');
$user->save();
exit
```

**Important**: Utilitza `-it` (no només `-i`) per mantenir la sessió interactiva oberta.

### Modificar altres dades d'un usuari

Per canviar el nom, email o altres dades:

```php
$user = App\Models\User::where('email', 'admin@example.com')->first();
$user->name = 'Nou Nom';
$user->email = 'nou_email@example.com';
$user->save();
```

### Verificar un usuari manualment

Si un usuari no ha verificat el seu email i vols fer-ho manualment:

```bash
docker exec laravel-app php artisan tinker --execute="\$user = App\Models\User::where('email', 'usuari@example.com')->first(); \$user->email_verified_at = now(); \$user->save();"
```

### Eliminar un usuari

Per eliminar un usuari de la base de dades:

```bash
docker exec laravel-app php artisan tinker --execute="App\Models\User::where('email', 'usuari@example.com')->delete();"
```

## Notes importants

- Sempre utilitza `bcrypt()` o `Hash::make()` per encriptar les contrasenyes
- Estableix `email_verified_at` a `now()` si vols que l'usuari estigui verificat automàticament
- Per a producció, mai utilitzis contrasenyes dèbils com "password"
- Tingues precaució quan eliminis usuaris, ja que aquesta acció és irreversible
