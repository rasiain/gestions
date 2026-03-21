# Conventional Commit

Ajuda a redactar i publicar un missatge de commit seguint la convenció Conventional Commits.

## Allowed types

| Tipus      | Quan usar-lo                                      |
|------------|---------------------------------------------------|
| `feat`     | Nova funcionalitat                                |
| `fix`      | Correcció d'un error                              |
| `chore`    | Tasques de manteniment sense afectar el codi font |
| `docs`     | Canvis en documentació                            |
| `refactor` | Refactorització sense canvi de comportament       |
| `test`     | Afegir o modificar tests                          |
| `build`    | Canvis en el sistema de build o dependències      |
| `ci`       | Canvis en la configuració de CI/CD                |
| `perf`     | Millores de rendiment                             |

## Format

```
<tipus>(<àmbit>): <descripció curta en minúscules>

<cos opcional: explica el "per què", no el "què">
```

## Exemple

```
feat(lloguers): edició de moviments i categoria des de la vista de lloguers

Afegida columna de categoria bancària i botó d'edició a la taula de
moviments d'un lloguer. L'edició obre un modal idèntic al de Gestió de
moviments (data, concepte, notes, import, saldo, categoria).
```

---

## Instruccions

1. Executa `git diff --stat HEAD` i `git log --oneline -5` per veure els canvis pendents i l'estil dels commits anteriors.
2. Proposa un text de commit seguint el format i els tipus anteriors.
3. Espera la confirmació de l'usuari.
4. Si l'usuari accepta (o demana ajustos menors i els accepta), invoca l'agent **publicador** per fer el commit i el push.

Si l'usuari proporciona un text de commit a $ARGUMENTS, utilitza'l directament com a punt de partida i proposa'l per a confirmació.
