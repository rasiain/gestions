# Laravel per realitzar gestions

Aplicació Laravel per a la gestió financera personal i de lloguers, amb Vue 3, Inertia.js i Tailwind CSS.

## Tecnologies

- **Backend**: Laravel 12, PHP 8.4
- **Frontend**: Vue 3 (Composition API) + TypeScript
- **Bridge**: Inertia.js
- **Estils**: Tailwind CSS amb dark mode
- **Base de dades**: SQLite (desenvolupament)
- **Infraestructura**: Docker (PHP-FPM + Nginx + Supervisor + Redis)

## Mòduls

L'aplicació s'organitza en dues grans àrees:

### Gestions Bancàries
Gestió de comptes corrents i moviments bancaris.

### Lloguers
Gestió d'immobles en règim de lloguer: propietats, contractes, llogaters i seguiment de moviments bancaris associats.

## Documentació

### Configuració inicial
- **[Configurar un Laravel de zero que contingui Vue i Inertia](docs/laravel-vue-inertia-setup.md)**
- **[Crear usuaris](docs/crear-usuaris.md)** - Com i quan crear usuaris a l'aplicació
- **[Guia d'ús d'Xdebug](docs/XDEBUG_GUIDE.md)** - Debugging pas a pas amb VSCode

### Gestions Bancàries
- **[Gestió de Persones](docs/gestio-persones.md)** - Persones amb diferents rols (titulars de comptes, propietaris, etc.)
- **[Gestió de Comptes Corrents](docs/gestio-comptes-corrents.md)** - Comptes bancaris amb titulars
- **[Gestió de Categories](docs/gestio-categories.md)** - Categories jeràrquiques per compte corrent
- **[Importació de Categories des de KMyMoney](docs/importacio-categories.md)** - Importar categories des de fitxers QIF
- **[Importació de Moviments](docs/importacio-moviments.md)** - Importar moviments bancaris des de fitxers Excel/CSV

### Lloguers
- **[Gestió de Lloguers](docs/gestio-lloguers.md)** - Lloguers, contractes, llogaters i moviments associats
- **[Gestió d'Immobles](docs/gestio-immobles.md)** - Immobles disponibles per assignar a lloguers
- **[Gestió de Proveïdors](docs/gestio-proveidors.md)** - Proveïdors de serveis associats als immobles

## Flux de treball de desenvolupament

```bash
# Construir i arrancar els contenidors
dcb          # docker compose build (aprofita caché)
dc up -d     # docker compose up en segon pla

# Dins el contenidor
docker exec -it laravel-app bash
php artisan migrate          # aplicar migracions pendents
npm run build                # compilar assets frontend (Vite)
```

> **Nota**: Vite NO s'executa via Supervisor. Cal compilar manualment amb `npm run build` cada cop que es modifiquen fitxers Vue/TS/CSS.

## Convencions de Codi

- **Commits**: Conventional Commits en català
- **Noms de taules**: Prefix `g_` (gestions)
- **Models**: Singular en català (Persona, CompteCorrent, Lloguer)
- **Controladors**: Resource controllers amb Laravel conventions
- **Validació**: Form Request classes dedicades
- **Colors UI**: Cada mòdul té un color Tailwind propi (blau → comptes, ambre → lloguers)