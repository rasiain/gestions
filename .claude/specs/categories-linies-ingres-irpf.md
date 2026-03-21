# Categoritzar línies d'ingrés i millorar report IRPF

## Resum

Afegir categories a les línies d'ingrés dels lloguers (iguals que les de despeses + gestoria),
tractar la gestoria com a despesa categoritzada al report IRPF, i usar `base_lloguer` com a ingrés
en lloc de `moviment.import`.

---

## Canvis detallats

### 1. Migració: afegir camp `categoria` a la taula de línies (opcional)

**Fitxer nou:** `src/database/migrations/XXXX_add_categoria_to_ingres_linia_table.php`

- **Decisió clau:** El camp `tipus` (varchar 20) ja existeix i s'usa com a text lliure. Hi ha dues opcions:
  - **(A) Reutilitzar `tipus`** com a camp de categoria (ja és varchar 20, coincideix amb les categories). No cal migració. Només cal canviar la validació i la UI.
  - **(B) Afegir un camp `categoria`** nou i mantenir `tipus` per compatibilitat.

- **Recomanació: Opció A** (reutilitzar `tipus`). Les dades existents al camp `tipus` són text lliure, però com que el projecte és recent, es pot fer una migració que normalitzi els valors existents. No cal nou camp.

- **Migració de dades:** Script que mapegi els valors existents de `tipus` a les categories estàndard. Valors no reconeguts es posen a `altres`.

### 2. Backend: Validació al MovimentClassificacioController

**Fitxer:** `src/app/Http/Controllers/MovimentClassificacioController.php`

- Canviar la regla de validació de `linies.*.tipus`:
  - De: `['required', 'string', 'max:20']`
  - A: `['required', 'in:comunitat,taxes,assegurança,compres,reparacions,gestoria,altres']`

### 3. Frontend: Canviar input text a selector a Lloguers/Index.vue

**Fitxer:** `src/resources/js/Pages/Lloguers/Index.vue`

- **Definir categories de línies d'ingrés** (reutilitzar `categoriesDespesa` afegint-hi `gestoria`):
  ```
  const categoriesIngresLinia = [
      ...categoriesDespesa,
      { value: 'gestoria', label: 'Gestoria' },
  ];
  ```

- **Canviar l'input text** (linia 1428-1435) per un `<select>`:
  - De: `<input v-model="linia.tipus" type="text" placeholder="Tipus" ...>`
  - A: `<select v-model="linia.tipus" ...> <option v-for="cat in categoriesIngresLinia" ...> </select>`

### 4. Backend: Canviar ImpostosIrpfController per usar base_lloguer i desglossar despeses

**Fitxer:** `src/app/Http/Controllers/ImpostosIrpfController.php`

Canvis principals al `foreach ($moviments as $moviment)`:

- **Categories:** Afegir `'gestoria'` a la llista de categories (linia 20):
  ```php
  $categories = ['comunitat', 'taxes', 'assegurança', 'compres', 'reparacions', 'gestoria', 'altres'];
  ```

- **Quan el moviment és un ingrés:**
  - L'ingrés passa a ser `base_lloguer` (no `moviment->import`):
    ```php
    $totalIngressos += (float) $moviment->ingres->base_lloguer;
    ```
  - La `gestoria_import` es comptabilitza com a despesa a la categoria `gestoria`:
    ```php
    if ($moviment->ingres->gestoria_import) {
        $gestoria = (float) $moviment->ingres->gestoria_import;
        $despesesPerCategoria['gestoria'] += $gestoria;
        $totalDespeses -= $gestoria; // negatiu perquè les despeses son negatives al sistema
    }
    ```
    **Nota:** Cal verificar el signe. Actualment les despeses (`moviment->import`) són negatives. La `gestoria_import` és positiva (es resta de la base). Cal adaptar el signe per consistència. Probablement: `$totalDespeses += -$gestoria;`
  - Les **línies d'ingrés** es comptabilitzen com a despeses a la seva categoria:
    ```php
    foreach ($moviment->ingres->linies as $linia) {
        $cat = $linia->tipus; // ara és una categoria vàlida
        $despesesPerCategoria[$cat] += -(float) $linia->import; // negatiu
        $totalDespeses += -(float) $linia->import;
    }
    ```
  - Cal carregar les línies: afegir `'ingres.linies'` al `with()` (linia 33):
    ```php
    ->with(['ingres.linies', 'despesa'])
    ```

- **Moviments detall:** Afegir els moviments de gestoria i línies als arrays `$movimentsDespeses` per categoria, perquè apareguin al modal de detall.

### 5. Frontend: Canviar "Ingressos" per "Import base" a Irpf.vue

**Fitxer:** `src/resources/js/Pages/Impostos/Irpf.vue`

- **Capçalera taula** (linia 116): Canviar "Ingressos" per "Import base"
- **Afegir `gestoria`** a la llista de categories (linia 48-49):
  ```typescript
  interface DespesesPerCategoria {
      comunitat: number;
      taxes: number;
      assegurança: number;
      compres: number;
      reparacions: number;
      gestoria: number;
      altres: number;
  }
  ```
- **Afegir `gestoria`** a l'array de categories (linia 48):
  ```typescript
  const categories: (keyof DespesesPerCategoria)[] = [
      'comunitat', 'taxes', 'assegurança', 'compres', 'reparacions', 'gestoria', 'altres',
  ];
  ```
- **Canviar etiqueta** al `@click` del modal d'ingressos (linia 144): "Import base" en lloc d'"Ingressos"

---

## Ordre d'implementació recomanat

1. **Migració de dades** del camp `tipus` (normalitzar valors existents)
2. **Validació backend** (`MovimentClassificacioController`) - afegir `in:` rule
3. **UI selector** (`Lloguers/Index.vue`) - canviar input text per select
4. **ImpostosIrpfController** - refactoritzar lògica d'ingressos/despeses
5. **Irpf.vue** - afegir gestoria, renomenar columna
6. **Compilar frontend** amb `docker compose exec app npm run build`

---

## Punts de verificació

- [ ] Les línies d'ingrés existents tenen els seus valors `tipus` normalitzats
- [ ] Al formulari de classificació d'ingrés, el camp `tipus` de cada línia és un selector amb les categories
- [ ] Al report IRPF, la columna d'ingressos mostra "Import base" i el valor és `base_lloguer`
- [ ] Al report IRPF, la gestoria apareix com a despesa a la columna "gestoria"
- [ ] Al report IRPF, les línies d'ingrés apareixen com a despeses a la seva categoria corresponent
- [ ] El "Total Despeses" inclou despeses de moviments despesa + gestoria + línies d'ingrés
- [ ] El "Resultat Net" = Import base - Total Despeses (correcte)
- [ ] Els modals de detall mostren correctament els moviments de cada categoria

---

## Fitxers afectats (resum)

| Fitxer | Acció |
|--------|-------|
| `src/database/migrations/XXXX_normalize_ingres_linia_tipus.php` | **Nou** - Normalitzar valors existents |
| `src/app/Http/Controllers/MovimentClassificacioController.php` | **Modificar** - Validació `in:` per `linies.*.tipus` |
| `src/resources/js/Pages/Lloguers/Index.vue` | **Modificar** - Select en lloc d'input per tipus de línia |
| `src/app/Http/Controllers/ImpostosIrpfController.php` | **Modificar** - Lògica IRPF amb base_lloguer, gestoria i línies |
| `src/resources/js/Pages/Impostos/Irpf.vue` | **Modificar** - Afegir gestoria, renomenar "Import base" |
