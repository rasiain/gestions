# Filtres de moviments dins la vista de lloguers

## Objectiu

Afegir tres filtres a la llista de moviments dins de la vista de Lloguers:

1. **Selector d'any natural** — Un `<select>` per triar un any (2023, 2024, 2025, 2026...). Per defecte, sense selecció (mostra tots els anys).
2. **Checkbox "Només classificats"** — Mostra només els moviments que tenen una despesa o ingrés associat al lloguer seleccionat (`despesa.lloguer_id` o `ingres.lloguer_id` coincideix amb el lloguer actual).
3. **Checkbox "Pendents de classificar"** — Mostra només els moviments que NO tenen classificació (ni despesa ni ingrés per cap lloguer) i que NO estan exclosos (`exclou_lloguer = false`).

Els checkboxes 2 i 3 son mútuament excloents (activar un desactiva l'altre). Si cap checkbox esta actiu i no hi ha any seleccionat, es mostren tots els moviments (comportament actual).

## Fitxers a modificar

### 1. Backend: `src/app/Http/Controllers/LloguerController.php`

**Mètode `moviments()`** (línia 110):

- Acceptar nous paràmetres de query string:
  - `any` (integer, opcional): any natural per filtrar per `data_moviment`
  - `classificats` (boolean, opcional): si `true`, filtrar moviments que tenen una despesa o ingrés amb `lloguer_id` = lloguer actual
  - `pendents` (boolean, opcional): si `true`, filtrar moviments que NO tenen cap despesa ni ingrés associat I `exclou_lloguer = false`

- Afegir condicions al query builder:
  ```php
  // Filtre per any
  if ($any = $request->integer('any')) {
      $query->whereYear('data_moviment', $any);
  }

  // Filtre classificats (per AQUEST lloguer)
  if ($request->boolean('classificats')) {
      $query->where(function ($q) use ($lloguer) {
          $q->whereHas('despesa', fn($q2) => $q2->where('lloguer_id', $lloguer->id))
            ->orWhereHas('ingres', fn($q2) => $q2->where('lloguer_id', $lloguer->id));
      });
  }

  // Filtre pendents de classificar
  if ($request->boolean('pendents')) {
      $query->whereDoesntHave('despesa')
            ->whereDoesntHave('ingres')
            ->where('exclou_lloguer', false);
  }
  ```

- Retornar també la llista d'anys disponibles per al compte corrent (per omplir el selector):
  ```php
  $anys = MovimentCompteCorrent::where('compte_corrent_id', $lloguer->compte_corrent_id)
      ->selectRaw('DISTINCT YEAR(data_moviment) as any')
      ->orderBy('any', 'desc')
      ->pluck('any');
  ```
  Afegir `'anys' => $anys` a la resposta JSON (només a la primera pàgina, `$page === 1`).

### 2. Frontend: `src/resources/js/Pages/Lloguers/Index.vue`

**Secció `<script setup>`:**

- Afegir noves refs:
  ```ts
  const movimentsFilterAny = ref<number | null>(null);
  const movimentsFilterClassificats = ref(false);
  const movimentsFilterPendents = ref(false);
  const movimentsAnys = ref<number[]>([]);
  ```

- Modificar `fetchMoviments()` per passar els filtres com a query params:
  ```ts
  const params = new URLSearchParams({ page: String(page) });
  if (movimentsFilterAny.value) params.set('any', String(movimentsFilterAny.value));
  if (movimentsFilterClassificats.value) params.set('classificats', '1');
  if (movimentsFilterPendents.value) params.set('pendents', '1');

  const res = await fetch(`/lloguers/${lloguer.id}/moviments?${params}`, { ... });
  ```

- Desar `json.anys` (si present) a `movimentsAnys`:
  ```ts
  if (json.anys) movimentsAnys.value = json.anys;
  ```

- Afegir exclusió mútua entre checkboxes:
  ```ts
  watch(movimentsFilterClassificats, (val) => {
      if (val) movimentsFilterPendents.value = false;
  });
  watch(movimentsFilterPendents, (val) => {
      if (val) movimentsFilterClassificats.value = false;
  });
  ```

- Afegir un watcher que recarregui els moviments quan canvia qualsevol filtre:
  ```ts
  watch([movimentsFilterAny, movimentsFilterClassificats, movimentsFilterPendents], () => {
      if (selectedLloguerId.value) {
          const lloguer = props.lloguers.find(l => l.id === selectedLloguerId.value);
          if (lloguer) fetchMoviments(lloguer, 1);
      }
  });
  ```

- Resetejar filtres quan es canvia de lloguer (al watcher de `selectedLloguerId`, línia 623):
  ```ts
  movimentsFilterAny.value = null;
  movimentsFilterClassificats.value = false;
  movimentsFilterPendents.value = false;
  ```

**Secció `<template>` — entre el títol "Moviments" i la taula (línia ~943):**

Afegir una barra de filtres:

```html
<div class="mb-4 flex flex-wrap items-center gap-4">
    <!-- Selector d'any -->
    <div class="flex items-center gap-2">
        <label class="text-sm text-gray-600 dark:text-gray-400">Any:</label>
        <select
            v-model="movimentsFilterAny"
            class="rounded-md border-gray-300 text-sm shadow-sm
                   focus:border-amber-500 focus:ring-amber-500
                   dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
        >
            <option :value="null">Tots</option>
            <option v-for="a in movimentsAnys" :key="a" :value="a">{{ a }}</option>
        </select>
    </div>

    <!-- Checkbox classificats -->
    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
        <input
            type="checkbox"
            v-model="movimentsFilterClassificats"
            class="rounded border-gray-300 text-amber-500 focus:ring-amber-400"
        />
        Només classificats
    </label>

    <!-- Checkbox pendents -->
    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
        <input
            type="checkbox"
            v-model="movimentsFilterPendents"
            class="rounded border-gray-300 text-amber-500 focus:ring-amber-400"
        />
        Pendents de classificar
    </label>
</div>
```

## Ordre d'implementació

1. Backend: modificar `LloguerController::moviments()` (filtres + anys)
2. Frontend: afegir refs, watchers i lògica de filtres
3. Frontend: afegir la barra de filtres al template

## Punts de verificació

- [ ] Seleccionar un any filtra correctament els moviments
- [ ] "Només classificats" mostra els moviments amb despesa/ingrés d'aquest lloguer
- [ ] "Pendents de classificar" mostra els moviments sense classificació i no exclosos
- [ ] Activar un checkbox desactiva l'altre
- [ ] Canviar de lloguer reseteja tots els filtres
- [ ] El selector d'anys mostra els anys disponibles per al compte corrent
- [ ] La paginació ("Mostrar-ne més") respecta els filtres actius
- [ ] El comptador "X moviments en total" reflecteix el total filtrat
