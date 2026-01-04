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
- **[Guia d'ús d'Xdebug](docs/XDEBUG_GUIDE.md)** - Debugging pas a pas amb VSCode

### Funcionalitats
- **[Gestió de Persones](docs/gestio-persones.md)** - Persones amb diferents rols (titulars de comptes, propietaris, etc.)
- **[Gestió de Comptes Corrents](docs/gestio-comptes-corrents.md)** - Comptes bancaris amb titulars
- **[Gestió de Categories](docs/gestio-categories.md)** - Categories jeràrquiques per compte corrent
- **[Importació de Categories des de KMyMoney](docs/importacio-categories.md)** - Importar categories des de fitxers QIF
- **[Importació de Moviments](docs/importacio-moviments.md)** - Importar moviments bancaris des de fitxers Excel/CSV

## Convencions de Codi

- **Commits**: Conventional Commits en català
- **Noms de taules**: Prefix `g_` (gestions)
- **Models**: Singular en català (Persona, CompteCorrent, Categoria)
- **Controladors**: Resource controllers amb Laravel conventions
- **Validació**: Form Request classes dedicades