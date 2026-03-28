# Exportació CSV del llibre de despeses d'un lloguer

## Objectiu

Afegir un botó que exporti les dades classificades d'un lloguer en format CSV,
com un llibre de despeses, compatible amb Excel i Pages.

## Fitxers a modificar

### 1. `src/routes/web.php`
- Ruta GET: `/lloguers/{lloguer}/exportar` → `LloguerController::exportar`

### 2. `src/app/Http/Controllers/LloguerController.php`
- Nou mètode `exportar(Lloguer $lloguer, Request $request)`
- Paràmetre opcional `any` (query string)
- Genera CSV amb `StreamedResponse`
- Inclou ingressos (base, gestoria, notes) i despeses (categoria, proveïdor, import, notes)
- Inclou despeses dins d'ingressos (línies) a la secció de despeses
- Secció de resum final

### 3. `src/resources/js/Pages/Lloguers/Index.vue`
- Botó "Exportar CSV" a la barra de filtres
- Obre `/lloguers/{id}/exportar?any={any}` amb `window.open()`

## Format CSV

- Separador: `;`
- Codificació: UTF-8 amb BOM
- Nom fitxer: `lloguer-{acronim}-{any}.csv`

## Estructura

```
Lloguer: {nom}
Any: {any o "Tots"}
Immoble: {adreça}

=== INGRESSOS ===
Data;Concepte;Base lloguer;Gestoria;Notes
...
Total ingressos:;;{total_base};{total_gestoria};

=== DESPESES ===
Data;Categoria;Concepte;Proveïdor;Import;Notes
...
Total despeses:;;;;;{total};

=== RESUM ===
Total ingressos bruts;{x}
Total despeses gestoria;{x}
Total despeses;{x}
Resultat net;{x}
```
