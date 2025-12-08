# Laravel per realitzar gestions

Aplicació Laravel per a la gestió financera personal amb Vue 3, Inertia.js i Tailwind CSS.

## Tecnologies

- **Backend**: Laravel 12
- **Frontend**: Vue 3 (Composition API) + TypeScript
- **Bridge**: Inertia.js
- **Estils**: Tailwind CSS amb dark mode
- **Base de dades**: SQLite

## Documentació

### Configuració inicial
- **[Configurar un Laravel de zero que contingui Vue i Inertia](docs/laravel-vue-inertia-setup.md)**
- **[Crear usuaris](docs/crear-usuaris.md)** - Com i quan crear usuaris a l'aplicació

### Funcionalitats
- **[Gestió de Titulars](docs/gestio-titulars.md)** - Persones associades als comptes corrents
- **[Gestió de Comptes Corrents](docs/gestio-comptes-corrents.md)** - Comptes bancaris amb titulars
- **[Gestió de Categories](docs/gestio-categories.md)** - Categories jeràrquiques per compte corrent
- **[Importació de Categories des de KMyMoney](docs/importacio-categories.md)** - Importar categories des de fitxers QIF
- **[Processament de fitxers de dades](docs/processament-dades.md)** - Importar i processar fitxers Excel/CSV

## Convencions de Codi

- **Commits**: Conventional Commits en català
- **Noms de taules**: Prefix `g_` (gestions)
- **Models**: Singular en català (Titular, CompteCorrent, Categoria)
- **Controladors**: Resource controllers amb Laravel conventions
- **Validació**: Form Request classes dedicades