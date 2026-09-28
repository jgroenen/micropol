# CLAUDE.md

Werkinstructies voor deze codebase. De [README](README.md) is de volledige documentatie (API, opslag, rollen, kanalen, panels); dit bestand zegt hoe je hier werkt en waar je op let.

## In het kort

MiniPol is een kleine, Polis-achtige tool. Deelnemers **beantwoorden** stellingen (`eens`, `neutraal`, `oneens`) en voegen zelf stellingen toe; een math server deelt ze in groepen in.

- **Geen database, geen dependencies, geen build-stap.** PHP 8.1+ en vanilla JavaScript (ES modules). Voeg geen Composer, npm-packages, bundler of framework toe.
- **Terminologie:** het zijn *antwoorden*, nooit *stemmen* of *votes*, in code, teksten, commits en documentatie.
- **Werk direct op `main`**, geen feature branches. Commitberichten zijn Nederlands, één regel die zegt wat er voor de gebruiker verandert (zie `git log`).

## Zes losse diensten

Elk deel is een eigen server op een eigen (sub)domein, en staat op zichzelf:

| Deel | Wat | Lokaal | Soort |
|---|---|---|---|
| `www/` | Productpagina, links naar alle delen | :8000 | PHP vult URL's in een HTML-pagina; geen JS |
| `api/` | JSON-API met alle data | :8001 | PHP, elk verzoek via `index.php` |
| `admin/` | Beheeromgeving | :8002 | statisch + `index.php` en `js/config.php` |
| `cdn/` | Design system en gedeelde JS-bibliotheken | :8003 | alleen statische bestanden |
| `math/` | Groepsanalyse (PCA + K-means) uit de export van een API | :8004 | PHP, elk verzoek via `index.php` |
| `app/` | Frontend voor deelnemers | :8005 | statisch + `index.php` en `js/config.php` |

```
www: links naar alle delen
app ──▶ api ◀── admin
 │       ▲
 └─▶ math┘  (haalt GET /export)
app, admin en www laden css/js/fonts van de cdn
```

Regels die daaruit volgen:

- **Diensten delen geen code op de server.** `api/` en `math/` zijn allebei zelfstandig. `Http.php`, `HttpFout.php` (in `lib/`) en `DocsHandler.php` (in `handlers/`) staan in allebei als **gelijke kopie**: pas je er één aan, pas dan de andere precies zo aan (`cmp api/lib/Http.php math/lib/Http.php`). `Csv.php` en `Jsonl.php` zitten alleen in `api/`.
- **Frontends delen code alleen via de cdn.** Wat app én admin nodig hebben, gaat naar `cdn/lib/` (JS) of `cdn/design/` (CSS). Ze importeren het als `import … from 'cdn/<bestand>.js'` via de import map in `views/pagina.html`. Een wijziging in `cdn/` raakt www, app en admin tegelijk.
- **Diensten praten alleen via HTTP.** De math server heeft geen eigen invoerdata: hij haalt `GET /export` op bij een API uit `TOEGESTANE_APIS` ([math/config.php](math/config.php)). Verandert het exportformaat op een brekende manier, verhoog dan `versie` en pas [math/lib/Export.php](math/lib/Export.php) aan.
- **De app moet werken zonder math server:** de analyse is extra, fouten daarvan worden opgevangen.
- **URL's van andere delen staan nooit hard in de code.** Elk deel leest ze uit omgevingsvariabelen (`MINIPOL_*_URL`) met localhost als standaard:
  - `api/config.php`, `math/config.php`: `define()`/`const`
  - `app/instellingen.php`, `admin/instellingen.php`, `www/instellingen.php`: geven een array terug
  - `app/js/config.php`, `admin/js/config.php`: maken daar een JS-module van (`import { API_URL } from './config.php'`)
  - `index.php` van app/admin/www vult `{{CDN_URL}}` e.d. in `views/pagina.html` in

  Heeft een deel een nieuwe URL of instelling nodig: voeg hem toe in de `instellingen.php`/`config.php` van dat deel, in de `(urls)`-snippet van de [Caddyfile](Caddyfile) als het een URL is, en in de tabel „Losse servers” in de README.
- **In productie** draait alles op één VPS met FrankenPHP; de [Caddyfile](Caddyfile) leidt de subdomeinen af uit `MINIPOL_DOMEIN`. Zie [docs/livegang.md](docs/livegang.md) en [deploy/](deploy/).

## Starten en testen

```sh
./dev/start.sh     # alle zes servers, draait eerst de migraties; Ctrl-C stopt ze
./tests/run.sh     # in een tweede terminal, terwijl start.sh draait
```

- `run.sh` bewaart `api/data` en `math/data`, test op een lege API en zet de data daarna terug.
- De tests: [tests/api.php](tests/api.php) roept elke operatie uit beide OpenAPI-specs aan en valideert request en response tegen `schema.json`; [tests/browser.mjs](tests/browser.mjs) test www, app en admin in headless Chrome (faalt ook als de CSP iets blokkeert). Verder losse tests voor migraties, paneltellingen, inlogpogingen en paden.
- Een **nieuw endpoint** zonder aanroep in `tests/api.php` laat de test falen. Voeg bij nieuw gedrag in de frontend een stap toe aan `browser.mjs`.
- GitHub Actions draait `run.sh` bij elke push.

## Backend (api en math)

- **Router:** `/<resource>[/<id>]` → `handlers/<Resource>Handler-><METHOD>($id)`. Een nieuwe resource: een handlerklasse in `handlers/` én een regel in `$handlers` in `index.php`. Klassen worden automatisch geladen uit `handlers/` en `lib/` (één klasse per bestand).
- **Fouten:** gooi `new HttpFout($status, 'Engelse melding.')`; `index.php` maakt er `{ "error": "..." }` van. Onverwachte fouten worden 500 en gaan naar de log.
- **Invoer en uitvoer via `Http`:** `Http::body()`, `Http::field()`, `Http::line()`, `Http::json()`.
- **Rechten:** `Toegang::vereisAccount()`, `Toegang::vereisSuperbeheerder()`, `Toegang::vereisRol($gesprekId, Beheer::TEAMROLLEN)`. Deelnemers identificeren zich met `Authorization: Bearer <deelnemer_id>`.
- **Data alleen via `Data`** (gesprekken, stellingen, antwoorden, kanalen) en `Beheer` (accounts, rollen, teams, uitnodigingen). Handlers raken nooit zelf bestanden aan.
- **Event sourcing, append-only.** Een wijziging is een nieuw event via `Data::voegEventToe()`; de stand volgt uit het lezen van de events, het laatste telt. Wijzig of verwijder nooit bestaande regels (enige uitzondering: `paneltellingen.json`). Nieuw eventtype: ook toevoegen aan `Event` in `api/schema.json` en, als het in het logboek hoort, aan `admin/js/logboek.js`.
- **Verandert de vorm van bestaande data**, schrijf dan een migratie `api/bin/migraties/NNN-naam.php` (volgend nummer) en breid [tests/migraties.sh](tests/migraties.sh) uit. Zie „Migraties” in de README.
- **Spec bijwerken bij elke API-wijziging:** `openapi.json` beschrijft de endpoints, `schema.json` de typen (de spec verwijst ernaar). Pas typen alleen in `schema.json` aan. Controle: `npx @redocly/cli lint api/openapi.json math/openapi.json`.
- `api/data/` en `math/data/` staan niet in git; mappen en bestanden ontstaan bij het eerste gebruik.

## Frontend (app en admin)

App en admin hebben dezelfde opbouw:

- `views/pagina.html`: de schil, met de import map voor `cdn/`
- `views/<id>.html`: één `<section id="<id>" class="view">` per view, geladen bij het eerste tonen (`cdn/lib/views.js`)
- `js/main.js`: een hash-router (`#/gesprekken/<id>/…`)
- één module per view, met een `koppel()` die de elementen één keer opzoekt, en een `toon…()` die de view vult
- `js/api.js`: alle calls, via `jsonVerzoek`/`ofNull` uit `cdn/lib/verzoek.js`. Een mislukte call gooit een `Error` met `status`; opzoekfuncties geven `null` bij 404. Elke call komt in de **{ } API**-popup; geef velden met wachtwoorden of tokens mee in `verberg`.
- `css/`: per view een stylesheet; kleuren, fonts en maten komen uit de tokens in `cdn/design/tokens.css`.

Let op:

- **Strikte Content-Security-Policy** (in `index.php`): geen inline scripts, geen `style`-attributen, geen externe bronnen behalve de cdn, en calls alleen naar API en math. Zet een dynamische breedte of kleur via `element.style` in JS. Een nieuwe externe bron of dienst vraagt om een aanpassing van de CSP.
- **Escape alle data** die in HTML gaat met `escapeHtml` uit `cdn/html.js`.
- **Geen id's of tokens in URL's**, niet in de query en niet in het pad als het een geheim is: `deelnemer_id` en beheertokens gaan in `Authorization`, een kanaaltoken in de body van een POST.
- Er is geen build: bestanden worden geladen zoals ze in de repo staan (`Cache-Control: no-cache`).

## Naamgeving en stijl

- **Nederlands** voor alles wat we zelf bedenken: functies, variabelen, klassen, CSS-klassen, ids, API- en datavelden (`gesprekBestaat()`, `.huidige-stelling`, `deelnemer_id`).
- **Engels** waar een standaard de naam vastlegt: HTTP-methodes (`GET()`, `post`), `error` in foutmeldingen, DOM/fetch-begrippen, de wrappers `Http`/`Csv`/`Jsonl` en hun methodes, en structuurnamen (`handlers`, `lib`, `views`, `…Handler`).
- **Commentaar is Engels**, kort, en zegt *waarom*. Teksten voor gebruikers zijn Nederlands; API-foutmeldingen Engels.
- 4 spaties inspringen, 2 voor JSON/MD/YAML ([.editorconfig](.editorconfig)).
- **Documentatie hoort bij de wijziging:** pas de README (en zo nodig SECURITY.md of `docs/`) aan in dezelfde commit.
