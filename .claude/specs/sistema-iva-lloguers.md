# Sistema IVA Lloguers

## Context

Els lloguers no-habitatge (locals comercials) estan subjectes a IVA. Cal una nova pagina
sota la seccio "Impostos" que permeti visualitzar la liquidacio trimestral d'IVA:
IVA repercutit (cobrat via factures) menys IVA suportat (pagat en despeses), amb resultat per trimestre.

Actualment les despeses (`g_moviment_lloguer_despesa`) no tenen camps d'IVA, i cal afegir-los
tant a la BD com al formulari de classificacio. Les factures (`g_factures`) ja tenen `iva_import`.

## Canvis

### Fitxers a modificar

- `src/database/migrations/` — nova migracio per afegir `base_imposable` i `iva_import` a `g_moviment_lloguer_despesa`
- `src/app/Models/MovimentLloguerDespesa.php` — afegir `base_imposable` i `iva_import` als `$fillable` i `$casts`
- `src/app/Http/Controllers/MovimentClassificacioController.php` — afegir `base_imposable` i `iva_import` a les regles de validacio, al `saveClassificacio` (creacio de despesa) i al `movimentResponse` (resposta JSON)
- `src/resources/js/Pages/Lloguers/Index.vue` — afegir camps `base_imposable` i `iva_import` al formulari de classificacio de despeses (nomes visible quan el lloguer seleccionat NO es habitatge), al ref `classificacioDespesa`, a la interficie `MovimentDespesa`, i al body del fetch
- `src/resources/js/Pages/Dashboard.vue` — afegir link "IVA Lloguers" sota "IRPF Lloguers" dins la seccio Impostos (linia ~197)
- `src/routes/web.php` — afegir ruta `impostos.iva`

### Fitxers nous

- `src/database/migrations/2026_03_30_100000_add_iva_fields_to_g_moviment_lloguer_despesa.php` — migracio per afegir `base_imposable DECIMAL(10,2) NULL` i `iva_import DECIMAL(10,2) NULL` a la taula `g_moviment_lloguer_despesa`
- `src/app/Http/Controllers/ImpostosIvaController.php` — controller seguint el patro d'`ImpostosIrpfController`. Filtra nomes lloguers amb `es_habitatge = false`. Per cada lloguer i trimestre (1T=gen-mar, 2T=abr-jun, 3T=jul-set, 4T=oct-des), calcula:
  - **IVA repercutit**: suma de `iva_import` de les factures (`g_factures`) del lloguer per al trimestre
  - **IVA suportat**: suma de `iva_import` de les despeses (`g_moviment_lloguer_despesa`) del lloguer per al trimestre (via la relacio moviment -> data_moviment)
  - **Resultat**: IVA repercutit - IVA suportat
  - **Base imposable**: suma de `base` de les factures del trimestre
  - Passa a Inertia: `any`, `lloguers` (array amb dades trimestrals), `totals`
- `src/resources/js/Pages/Impostos/Iva.vue` — component Vue seguint el patro d'`Irpf.vue`. Taula amb:
  - Files: un lloguer per fila
  - Columnes: Lloguer | Immoble | 1T Base | 1T IVA rep. | 1T IVA sup. | 1T Resultat | ... (x4 trimestres) | Total anual
  - Selector d'any (identic a IRPF)
  - Fila de totals al tfoot
  - Modal de detall al clicar sobre una cel.la (mostra moviments/factures individuals)

## Ordre d'implementacio

1. Migracio: afegir camps IVA a `g_moviment_lloguer_despesa`
2. Model `MovimentLloguerDespesa`: actualitzar `$fillable` i afegir `$casts`
3. Controller `MovimentClassificacioController`: validacio, creacio i resposta JSON amb nous camps
4. Frontend `Lloguers/Index.vue`: camps IVA al formulari de classificacio de despeses (condicional: nomes si el lloguer no es habitatge)
5. Controller `ImpostosIvaController`: logica de calcul trimestral
6. Ruta `impostos.iva` a `web.php`
7. Component `Impostos/Iva.vue`
8. Link al `Dashboard.vue`
9. Compilar frontend: `docker compose exec app npm run build`

## Detalls d'implementacio

### Migracio
```
$table->decimal('base_imposable', 10, 2)->nullable();
$table->decimal('iva_import', 10, 2)->nullable();
```

### Controller ImpostosIvaController
- Filtrar lloguers: `Lloguer::where('es_habitatge', false)->with(['immoble'])`
- Per cada lloguer, obtenir factures de l'any agrupades per trimestre:
  ```php
  $factures = Factura::where('lloguer_id', $lloguer->id)
      ->where('any', $any)
      ->get()
      ->groupBy(fn($f) => ceil($f->mes / 3));
  ```
- Per cada lloguer, obtenir despeses amb IVA de l'any agrupades per trimestre:
  ```php
  $despeses = MovimentLloguerDespesa::where('lloguer_id', $lloguer->id)
      ->whereNotNull('iva_import')
      ->whereHas('moviment', fn($q) => $q->whereYear('data_moviment', $any))
      ->with('moviment')
      ->get()
      ->groupBy(fn($d) => ceil($d->moviment->data_moviment->month / 3));
  ```

### Formulari classificacio despeses (Index.vue)
- Afegir al ref `classificacioDespesa`: `base_imposable: null, iva_import: null`
- Afegir a la interficie `MovimentDespesa`: `base_imposable: number | null; iva_import: number | null`
- Al template, despres del camp "Notes", afegir condicionalment (si el lloguer seleccionat te `es_habitatge === false`) dos inputs numerics per base_imposable i iva_import
- Cal que la prop `lloguers` passi `es_habitatge` al frontend (verificar que ja ho fa)
- Afegir al body del fetch els nous camps
- Afegir al `saveClassificacio` del controller els nous camps
- Afegir a la resposta JSON del controller els nous camps

## Verificacio

- Executar migracio: `docker compose exec app php artisan migrate`
- Classificar una despesa d'un lloguer no-habitatge amb base_imposable i iva_import informats
- Verificar que els camps es guarden correctament a la BD
- Accedir a `/impostos/iva` i verificar que es mostra la taula amb dades trimestrals
- Verificar que el link al Dashboard funciona
- Verificar que per lloguers habitatge, els camps IVA NO apareixen al formulari de classificacio
- Compilar frontend sense errors: `docker compose exec app npm run build`
