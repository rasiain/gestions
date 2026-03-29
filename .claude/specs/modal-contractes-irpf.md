# Modal de contractes i llogaters a la vista IRPF

## Objectiu

A la vista IRPF (`Impostos/Irpf`), afegir un botó per cada lloguer que obri un modal mostrant els contractes i llogaters associats amb les seves dates.

## Backend

### 1. Modificar `ImpostosIrpfController.php`

**Fitxer:** `src/app/Http/Controllers/ImpostosIrpfController.php`

- Canviar l'eager loading (línia 16) per incloure `contractes.llogaters`:
  ```php
  $lloguers = Lloguer::with(['immoble', 'contractes.llogaters'])
  ```

- Dins el `map()` (al return, línia ~88), afegir el camp `contractes`:
  ```php
  'contractes' => $lloguer->contractes->map(fn ($c) => [
      'id' => $c->id,
      'data_inici' => $c->data_inici->toDateString(),
      'data_fi' => $c->data_fi?->toDateString(),
      'llogaters' => $c->llogaters->map(fn ($l) => [
          'nom' => $l->nom . ' ' . $l->cognoms,
          'identificador' => $l->identificador,
      ])->values(),
  ])->values(),
  ```

## Frontend

### 2. Modificar `Irpf.vue`

**Fitxer:** `src/resources/js/Pages/Impostos/Irpf.vue`

#### a) Interfícies TypeScript

Afegir noves interfícies:

```typescript
interface LlogaterInfo {
    nom: string;
    identificador: string | null;
}

interface ContracteInfo {
    id: number;
    data_inici: string;
    data_fi: string | null;
    llogaters: LlogaterInfo[];
}
```

Afegir `contractes: ContracteInfo[]` a la interfície `LloguerIrpf`.

#### b) Estat reactiu

```typescript
const showContractes = ref(false);
const contractesTitol = ref('');
const contractesLlista = ref<ContracteInfo[]>([]);

function obreContractes(lloguer: LloguerIrpf) {
    contractesTitol.value = lloguer.nom;
    contractesLlista.value = lloguer.contractes;
    showContractes.value = true;
}

function tancaContractes() {
    showContractes.value = false;
}
```

#### c) Columna a la taula

- Al `thead`: afegir `<th>` amb text "Contractes" (després de la columna "Immoble")
- Al `tbody`: afegir `<td>` amb un botó/icona que cridi `obreContractes(lloguer)`
- Al `tfoot`: afegir `<td>` buit per mantenir l'alineació

#### d) Modal de contractes

Afegir després del modal existent. Utilitzar el component `Modal` ja existent (importat al fitxer).

```html
<Modal :show="showContractes" max-width="lg" @close="tancaContractes">
    <div class="p-6">
        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            Contractes — {{ contractesTitol }}
        </h3>
        <div v-for="contracte in contractesLlista" :key="contracte.id"
             class="mb-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div class="mb-2 flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200">
                <span>{{ contracte.data_inici }}</span>
                <span>—</span>
                <span v-if="contracte.data_fi">{{ contracte.data_fi }}</span>
                <span v-else class="rounded bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-300">Vigent</span>
            </div>
            <ul class="ml-4 list-disc text-sm text-gray-600 dark:text-gray-400">
                <li v-for="llogater in contracte.llogaters" :key="llogater.nom">
                    {{ llogater.nom }}
                    <span v-if="llogater.identificador" class="text-gray-400">({{ llogater.identificador }})</span>
                </li>
            </ul>
        </div>
        <div v-if="contractesLlista.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
            No hi ha contractes registrats.
        </div>
        <div class="mt-4 flex justify-end">
            <button @click="tancaContractes"
                    class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                Tancar
            </button>
        </div>
    </div>
</Modal>
```

## Notes
- El component `Modal` ja està importat a `Irpf.vue`
- Les relacions ja existeixen: `Lloguer hasMany Contracte`, `Contracte belongsToMany Llogater` (pivot `g_contracte_llogater`)
- `Contracte` té `data_inici` (date), `data_fi` (date, nullable)
- `Llogater` té `nom`, `cognoms`, `identificador`
- Compilar frontend: `docker compose exec app npm run build 2>&1`
