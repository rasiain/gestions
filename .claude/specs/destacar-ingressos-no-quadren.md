# Destacar ingressos que no quadren a la llista de moviments del lloguer

## Context

A la vista de Lloguers (`Index.vue`), els moviments classificats com a ingrés que no "quadren" han de tenir un fons diferent perquè l'usuari els identifiqui ràpidament i els pugui corregir.

Un ingrés **quadra** quan:
```
net_calculat = base_lloguer - gestoria_import - sum(linies.import)
diferencia = net_calculat - moviment.import
diferencia === 0  →  quadra
diferencia !== 0  →  NO quadra (cal destacar)
```

## Estat actual

- La lògica de càlcul ja existeix al modal de classificació (`ingresDiferencia` computed, línia ~494).
- Les files de la taula de moviments ja apliquen colors condicionals (línia ~1024-1032):
  - **Classificat a un altre lloguer**: `opacity-50 bg-gray-100`
  - **Exclòs**: `opacity-40`
  - **Classificat al lloguer actual**: `bg-green-50` (verd clar)
  - **Pendent de classificar**: `bg-amber-50` (ambre clar)
- Les dades de `moviment.ingres` ja inclouen `base_lloguer`, `gestoria_import` i `linies[]` amb `import` (arriben del backend a `LloguerController::moviments()`).

## Pla d'implementació

### Fitxer a modificar

**`src/resources/js/Pages/Lloguers/Index.vue`**

#### 1. Afegir funció helper `ingresNoQuadra(moviment)` (~línia 455, a prop de `classificacioThisLloguer`)

```typescript
const ingresNoQuadra = (moviment: Moviment): boolean => {
    const cls = classificacioThisLloguer(moviment);
    if (!cls || cls.tipus !== 'ingres') return false;
    const ingres = cls.data as MovimentIngres;
    const base = parseFloat(ingres.base_lloguer?.toString() ?? '0');
    const gestoria = parseFloat(ingres.gestoria_import?.toString() ?? '0');
    const linies = ingres.linies.reduce((s, l) => s + parseFloat(l.import?.toString() ?? '0'), 0);
    const netCalculat = parseFloat((base - gestoria - linies).toFixed(2));
    const importBanc = parseFloat(moviment.import);
    return parseFloat((netCalculat - importBanc).toFixed(2)) !== 0;
};
```

#### 2. Modificar les classes condicionals del `<tr>` de cada moviment (~línia 1024-1032)

Canviar la condició de `classificacioThisLloguer(moviment)` per distingir entre ingressos que quadren i que no quadren:

**Abans:**
```
classificacioThisLloguer(moviment)
    ? 'bg-green-50 dark:bg-green-900/20'
    : 'bg-amber-50 dark:bg-amber-900/10'
```

**Després:**
```
classificacioThisLloguer(moviment)
    ? ingresNoQuadra(moviment)
        ? 'bg-red-50 dark:bg-red-900/20 ring-1 ring-inset ring-red-200 dark:ring-red-800'
        : 'bg-green-50 dark:bg-green-900/20'
    : 'bg-amber-50 dark:bg-amber-900/10'
```

Això fa que els ingressos que no quadren tinguin fons vermell clar amb una vora subtil, clarament diferent del verd dels classificats correctes.

### Resum de canvis

| Acció | Fitxer | Descripció |
|-------|--------|------------|
| Modificar | `src/resources/js/Pages/Lloguers/Index.vue` | Afegir helper `ingresNoQuadra()` i canviar classes del `<tr>` |

### Cap fitxer nou ni canvi al backend

Totes les dades necessàries ja arriben del servidor. El canvi és purament visual al frontend.

### Verificació

1. Classificar un moviment com a ingrés amb dades que quadrin → fila verda
2. Classificar un moviment com a ingrés amb dades que NO quadrin → fila vermella
3. Les despeses classificades segueixen sent verdes
4. Els moviments pendents segueixen sent ambre
5. Compilar amb `docker compose exec app npm run build 2>&1`
