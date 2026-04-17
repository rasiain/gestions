# Agent d'Importació Automàtica de Moviments Bancaris

## Objectiu

Crear un agent Claude que, donat un fitxer exportat d'un banc (XML, XLS, CSV...), sigui capaç de:

1. Llegir i interpretar el contingut del fitxer
2. Identificar automàticament a quin compte corrent de l'aplicació correspon
3. Importar els moviments sense intervenció manual
4. Opcionalment, revisar i completar la classificació de categories

---

## Visió general de l'arquitectura

```
Fitxer bancari
     │
     ▼
┌─────────────────────┐
│  Agent Importador   │  ← Claude amb Tool Use
│                     │
│  1. Llegir fitxer   │
│  2. Identificar     │
│     compte          │
│  3. Cridar API      │
│     parse + import  │
└─────────────────────┘
          │
          │  API REST (Sanctum token)
          ▼
┌─────────────────────┐
│   Laravel App       │
│  (sistema actual)   │
└─────────────────────┘
          │
          ▼
┌─────────────────────┐       ┌──────────────────────────┐
│  Agent Classificador│  ←→   │  Agent Classificador     │
│  (opcional, separat)│       │  (mateix o diferent run) │
└─────────────────────┘       └──────────────────────────┘
```

La proposta és tenir **dos agents independents** amb responsabilitats separades:

| Agent | Responsabilitat | Quan s'executa |
|---|---|---|
| **Importador** | Llegir fitxer → identificar compte → importar | Cada vegada que hi ha un fitxer nou |
| **Classificador** | Revisar categories → crear-ne de noves → classificar | Sota demanda, després d'importar |

Aquesta separació permet invocar-los independentment i facilita que en el futur l'agent classificador operi sobre moviments ja importats (no necessàriament recents).

---

## Eines (Tools) que necessita cada agent

### Agent Importador

```
list_accounts()
  GET /api/comptes-corrents
  → id, nom, entitat, iban, bank_type, saldo_actual

parse_file(file_path, compte_id, bank_type, mode)
  POST /api/import/parse
  → moviments, estadístiques, errors de saldo

import_movements(compte_id, bank_type, mode, moviments_editats?)
  POST /api/import/store
  → { created, skipped, errors }
```

**Flux de raonament de l'agent:**

1. Llegeix el fitxer (raw o parsejat)
2. Extreu senyals d'identitat: IBAN, nom entitat, BIC, capçaleres
3. Crida `list_accounts()` i compara els senyals amb els comptes existents
4. Si hi ha coincidència clara → procedeix
5. Si hi ha ambigüitat → demana confirmació a l'usuari
6. Crida `parse_file()` i verifica que no hi ha errors de saldo
7. Crida `import_movements()` i informa del resultat

### Agent Classificador

```
list_movements(compte_id, sense_categoria?, limit?)
  GET /api/moviments?compte_id=X&sense_categoria=1
  → llista de moviments no classificats

list_categories(compte_id?)
  GET /api/categories
  → arbre de categories existent

create_category(nom, categoria_pare_id?)
  POST /api/categories
  → categoria creada

classify_movement(moviment_id, categoria_id)
  PATCH /api/moviments/{id}/categoria
  → ok

bulk_classify(moviment_ids[], categoria_id)
  POST /api/moviments/classifica-multiple
  → { updated }
```

**Flux de raonament de l'agent:**

1. Obté moviments sense categoria del compte indicat
2. Obté l'arbre de categories existent
3. Per cada moviment (o grup de moviments similars), raona quina categoria és la més adequada basant-se en el concepte, l'import i el signe
4. Si cap categoria existent encaixa, proposa crear-ne una de nova i la crea
5. Classifica en bloc els moviments similars
6. Reporta el resum de l'operació

---

## API REST necessària

### Autenticació

Utilitzar **Laravel Sanctum** (ja instal·lat) amb tokens d'API de llarga durada:

```
POST /api/tokens
Body: { name: "agent-importacio" }
Response: { token: "..." }
```

Totes les crides de l'agent inclouen la capçalera:
```
Authorization: Bearer {token}
```

### Endpoints nous a crear

#### Comptes corrents

```
GET /api/comptes-corrents
Response: [
  {
    id, nom, entitat, iban, bank_type,
    saldo_actual, compte_corrent
  }
]
```

El camp `compte_corrent` (24 caràcters) del model **ja és l'IBAN** — els IBANs espanyols tenen exactament 24 caràcters. No cal migració. L'endpoint ha d'exposar-lo com a `iban` per claredat semàntica.

#### Importació (wrappers de la lògica existent)

```
POST /api/import/parse
Body: {
  file: <base64 o multipart>,
  compte_corrent_id: int,
  bank_type: string,
  import_mode: "from_beginning" | "from_last_db"
}
Response: { movements, stats, errors, balance_validation_failed }

POST /api/import/store
Body: {
  compte_corrent_id: int,
  bank_type: string,
  import_mode: string,
  edited_movements?: [...]
}
Response: { created, skipped, errors }
```

Aquests endpoints poden reutilitzar directament `MovementImportController` adaptant-lo per acceptar autenticació per token i retornar JSON pur (sense Inertia).

#### Moviments

```
GET /api/moviments?compte_id=X&sense_categoria=1&limit=100
Response: [{ id, data_moviment, concepte, import, saldo_posterior, categoria_id }]

PATCH /api/moviments/{id}/categoria
Body: { categoria_id: int }
Response: { ok }

POST /api/moviments/classifica-multiple
Body: { moviment_ids: [int], categoria_id: int }
Response: { updated: int }
```

#### Categories

```
GET /api/categories
Response: arbre jeràrquic o llista plana amb categoria_pare_id

POST /api/categories
Body: { nom: string, categoria_pare_id?: int, compte_corrent_id?: int }
Response: { id, nom, ... }
```

---

## Identificació automàtica del compte

Aquesta és la peça central de l'agent. Les estratègies possibles, ordenades de major a menor fiabilitat:

| Prioritat | Senyal | Font al fitxer | Font al sistema |
|---|---|---|---|
| 1 | **IBAN** | Capçalera del fitxer XML/OFX | Camp `iban` del compte (cal afegir si no hi és) |
| 2 | **BIC / SWIFT** | Capçalera | Camp `entitat` (deduir) |
| 3 | **Nom de l'entitat** | Capçalera o nom fitxer | Accessor `bank_type` |
| 4 | **Rang de saldos** | Primer/últim saldo del fitxer | Últim `saldo_posterior` a la BD |
| 5 | **Patró de conceptes** | Mostra dels primers moviments | Últims moviments del compte |

Si l'agent no pot identificar el compte amb confiança suficient, ha de preguntar a l'usuari en lloc de fer una suposició.

---

## Suport de formats

El sistema actual suporta: **XLS** (Caixa Enginyers, CaixaBank), **QIF** (KMyMoney), **XLSX** (BBVA).

Per a fitxers **XML** (OFX/MT940/camt.053, que és l'estàndard SEPA), cal crear:

```
app/Http/Services/ImportFiles/XmlParserService.php
```

seguint el mateix patró que `AbstractMovementParserService`. Els formats XML bancaris més habituals a Espanya:

- **OFX/QFX**: Format Quicken, alguns bancs espanyols
- **camt.053** (ISO 20022): Estàndard SEPA, cada cop més comú
- **MT940**: Format SWIFT, usat per banca corporativa

L'agent pot detectar el format llegint les primeres línies del fitxer i escollir el parser adequat.

---

## Un agent o dos: discussió

### Opció A — Un sol agent que fa tot

**Avantatge**: flux continu, context compartit entre importació i classificació.

**Inconvenient**: el prompt és molt llarg, el cost per execució és alt, i la classificació pot trigar molt si hi ha molts moviments. Si falla a mig camí, cal reiniciar tot.

### Opció B — Dos agents separats (recomanada)

**Agent 1 — Importador**: Responsabilitat única. Ràpid. Pot executar-se en segon pla automàticament cada cop que aparegui un fitxer nou.

**Agent 2 — Classificador**: S'executa sota demanda. Pot processar moviments de qualsevol data, no només els acabats d'importar. Té tot el context de categories i pot raonal sobre patrons globals.

La separació respecta el principi de responsabilitat única i fa cada agent més fàcil de provar i mantenir.

---

## Flux complet d'ús (Opció B)

```
Usuari: "Importa el fitxer abril_2026.xml"
    │
    ▼
Agent Importador
  ├── Llegeix el fitxer
  ├── Extreu IBAN → identifica compte BBVA (id: 3)
  ├── Crida parse: 47 moviments nous, 0 errors
  ├── Crida import: 47 creats, 2 duplicats saltats
  └── Informa: "Importats 47 moviments al compte BBVA ****1234"

[Més tard, o immediatament si l'usuari ho demana]

Usuari: "Revisa les categories dels moviments nous"
    │
    ▼
Agent Classificador
  ├── Obté 31 moviments sense categoria del compte BBVA
  ├── Obté l'arbre de categories existent
  ├── Agrupa per patró de concepte:
  │   ├── "ENDESA" × 3 → Despeses > Subministraments > Electricitat ✓
  │   ├── "MERCADONA" × 8 → Despeses > Alimentació ✓
  │   ├── "CLINICA DENTAL XYZ" × 1 → no hi ha categoria → proposa crear Despeses > Salut > Dental
  │   └── ...
  ├── Crea categoria nova si l'usuari confirma
  ├── Classifica en bloc
  └── Informa: "31 moviments classificats, 1 categoria nova creada"
```

---

## Decisions resoltes

1. **IBAN als comptes**: ✅ El camp `compte_corrent` (24 cars) del model ja és l'IBAN espanyol. No cal migració. L'endpoint `list_accounts()` l'ha d'exposar com a `iban`.

2. **Formats suportats**: ✅ Tots els ja suportats pel sistema (XLS, XLSX, QIF) més CSV. L'agent detecta el format i el banc automàticament a partir del contingut del fitxer. Suport XML (OFX/camt.053) queda per a una fase futura.

3. **Transport del fitxer**: ✅ L'usuari passa el **path absolut** del fitxer com a argument.

4. **Mode d'execució**: ✅ Via **Claude Code CLI** (agent/skill invocable des del terminal).

5. **Confirmació abans d'importar**: ✅ L'agent **sempre** mostra el resum del `parse` (nombre de moviments nous, errors de saldo) i espera confirmació explícita de l'usuari abans de cridar `store`. El `store` és segur de reintentar perquè el sistema dedueix duplicats per hash SHA-256.

6. **Creació de categories**: ✅ L'Agent Classificador pot crear categories **autònomament**, però ha de **notificar clarament** al resum final totes les categories noves creades.

7. **Autenticació de l'agent**: ✅ Token fix de llarga durada configurat via variable d'entorn `AGENT_API_TOKEN`.

### Regles addicionals

- **Conciliat**: Els moviments importats queden sempre amb `conciliat = false` (pendents de revisió manual).
- **Dubtes de classificació**: Si l'agent classificador no té prou confiança per classificar un moviment, **no fa res** i ho notifica al resum final com a "moviment no classificat — motiu del dubte".

---

## Estat actual del sistema (referència)

El sistema d'importació actual funciona via Inertia.js (web, interactiu). Les rutes web existents **no** retornen JSON pur i no accepten autenticació per token:

```
POST /maintenance/movements/import/parse   ← Inertia, auth session
POST /maintenance/movements/import         ← Inertia, auth session
```

Cal crear rutes paral·leles a `routes/api.php` que reutilitzin la mateixa lògica de servei (`MovementImportService`, parsers) però autenticades amb Sanctum token i retornant JSON pur.

La lògica de negoci (6 passos d'importació) **no cal tocar-la**; és a la capa de servei i és reutilitzable.

---

## Ordre d'implementació recomanat

1. **Endpoints API** (`routes/api.php` + controllers API) — desbloqueja els dos agents
2. **Token Sanctum** (`TokenController`) — necessari per provar des de l'agent
3. **Agent Importador** (sense suport XML de moment) — valor immediat amb els formats existents
4. **Agent Classificador** — valor afegit posterior, independent de l'importador
5. **XmlParserService** — si i quan es necessiti un nou format

---

## Fitxers a crear / modificar

| Fitxer | Acció | Motiu |
|---|---|---|
| `routes/api.php` | Modificar | Afegir endpoints REST per a l'agent |
| `app/Http/Controllers/Api/ImportController.php` | Crear | Controller API per a importació |
| `app/Http/Controllers/Api/MovimentController.php` | Crear | Controller API per a moviments |
| `app/Http/Controllers/Api/CategoriaController.php` | Crear | Controller API per a categories |
| `app/Http/Controllers/Api/TokenController.php` | Crear | Generació de tokens Sanctum |
| `app/Http/Services/ImportFiles/XmlParserService.php` | Crear | Parser per a fitxers XML bancaris |
| `database/migrations/XXXX_add_iban_to_comptes_corrents.php` | ~~Crear~~ **No cal** | El camp `compte_corrent` ja és l'IBAN espanyol (24 cars) |
| `docs/agent-importacio-automatica.md` | Aquest fitxer | Especificació de referència |
