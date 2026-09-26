# MiniPol

Een kleine, Polis-achtige tool voor gesprekken. Deelnemers beantwoorden stellingen met *eens*, *neutraal* of *oneens* en kunnen zelf stellingen toevoegen. De analyse deelt deelnemers in groepen in die stellingen op een vergelijkbare manier beantwoorden.

Er is geen database, er zijn geen dependencies en er is geen build-stap: een PHP-backend die csv-bestanden bijhoudt, en frontends in vanilla JavaScript (ES modules).

Het bestaat uit vijf delen die elk op een eigen server (en domein) draaien:

| Deel | Wat | Lokaal |
|---|---|---|
| [api/](api/) | De JSON-API in PHP, met de data | <http://localhost:8001> ([docs](http://localhost:8001/docs)) |
| [app/](app/) | De frontend voor deelnemers (statische bestanden) | <http://localhost:8000> |
| [admin/](admin/) | De beheeromgeving (statische bestanden) | <http://localhost:8002> |
| [cdn/](cdn/) | Het design system en de JS-bibliotheken die de app en de admin delen (statische bestanden) | <http://localhost:8003> |
| [math/](math/) | De math server: rekent de groepsanalyse uit de export van een API, zie [Math server](#math-server) | <http://localhost:8004> ([docs](http://localhost:8004/docs)) |

```
app ──▶ api ◀── admin
 │       ▲
 └─▶ math┘  (haalt GET /export)
app en admin laden gedeelde css/js van de cdn
```

## Starten

Vereist PHP 8 of hoger. Start de vijf servers met [dev/start.sh](dev/start.sh):

```sh
./dev/start.sh
```

Open daarna <http://localhost:8000> (app) of <http://localhost:8002> (beheer). De API-documentatie staat op <http://localhost:8001/docs> en <http://localhost:8004/docs>. Ctrl-C stopt alle servers.

## Testen

Start eerst de servers (`./dev/start.sh`), en dan:

```sh
./tests/run.sh
```

| Test | Wat |
|---|---|
| [tests/api.py](tests/api.py) | Elke call van API en math tegen de OpenAPI-spec: status, request en response, inclusief inloggen en uitloggen. Elke operatie in de specs wordt minstens één keer aangeroepen. |
| [tests/browser.mjs](tests/browser.mjs) | De app en de admin in Chrome (headless) |

- **Testdata:** de tests maken hun eigen data aan, met een eigen testbeheerder. Daarna zet `run.sh` de data van API en math terug. Gebruik de app niet terwijl de tests draaien.
- **Nodig:** python3, node (18 of nieuwer) en Chrome. `jsonschema` komt bij de eerste run in `tests/.venv`.

## Structuur

| Pad | Wat |
|---|---|
| [api/index.php](api/index.php) | JSON-API: `/<resource>[/<id>]` gaat naar `handlers/<Resource>Handler-><METHOD>($id)` |
| [api/openapi.json](api/openapi.json) | De OpenAPI-spec van de API, live op <http://localhost:8001/docs>, zie [API](#api) |
| [api/config.php](api/config.php) | Instellingen per omgeving: welke origins de API mogen aanroepen, en hoe lang een login geldig is, zie [Losse servers](#losse-servers) |
| [api/handlers/](api/handlers/) | Eén handler per resource |
| [api/lib/](api/lib/) | Gedeelde code: csv-opslag ([Csv](api/lib/Csv.php), [Data](api/lib/Data.php)), HTTP ([Http](api/lib/Http.php), [HttpFout](api/lib/HttpFout.php)) en inloggen ([Toegang](api/lib/Toegang.php), [Wachtwoord](api/lib/Wachtwoord.php)) |
| [api/bin/](api/bin/) | [beheerder-toevoegen.php](api/bin/beheerder-toevoegen.php), zie [Beheer](#beheer) |
| [api/data/](api/data/) | De data, zie [Opslag](#opslag) |
| [app/](app/) | Frontend: [index.php](app/index.php), [js/](app/js/), [css/](app/css/) en [views/](app/views/); [instellingen.php](app/instellingen.php) zegt waar de API, de math server en de cdn zijn (ook voor de browser, via [js/config.php](app/js/config.php)) |
| [admin/](admin/) | Beheeromgeving, zie [Beheer](#beheer); zelfde opbouw als `app/`: [index.php](admin/index.php), [js/](admin/js/), [css/](admin/css/), [views/](admin/views/) en [instellingen.php](admin/instellingen.php) |
| [cdn/](cdn/) | Gedeeld door app en admin: het design system in [design/](cdn/design/) (reset, tokens, basisstijlen en lay-out; [main.css](cdn/design/main.css) laadt ze), en de bibliotheken in [lib/](cdn/lib/): [views.js](cdn/lib/views.js) (views laden), [verzoek.js](cdn/lib/verzoek.js) (calls naar de servers), [html.js](cdn/lib/html.js) (`escapeHtml`) en [apilog.js](cdn/lib/apilog.js) (de API-popup, met zijn eigen stylesheet) |
| [math/](math/) | De math server, zelfde opbouw als `api/`: [index.php](math/index.php), [config.php](math/config.php), [openapi.json](math/openapi.json), [handlers/](math/handlers/), [lib/](math/lib/) ([Analyse](math/lib/Analyse.php), [AnalyseModel](math/lib/AnalyseModel.php), [Export](math/lib/Export.php)) en [data/](math/data/) (de berekende modellen) |
| [dev/](dev/) | Alleen voor lokaal ontwikkelen: [start.sh](dev/start.sh) en de router voor de cdn ([cdn.php](dev/cdn.php)) |
| [Caddyfile](Caddyfile) | De webserver in productie: alle subdomeinen, met FrankenPHP en automatische HTTPS, zie [docs/livegang.md](docs/livegang.md) |
| [deploy/](deploy/) | Op de VPS: [installeer.sh](deploy/installeer.sh) (eenmalig), [uppen.sh](deploy/uppen.sh) (nieuwe versie: `git pull`) en de dienst [minipol.service](deploy/minipol.service) |
| [docs/](docs/) | Achtergronddocumentatie, zoals [analyse.md](docs/analyse.md) |
| [tests/](tests/) | De tests, zie [Testen](#testen) |

## API

Alle requests en responses zijn JSON. Fouten komen terug als `{ "error": "..." }` met een passende statuscode.

De volledige beschrijving staat in een OpenAPI 3.1-spec, per server:

| Server | Spec | Live |
|---|---|---|
| API | [api/openapi.json](api/openapi.json) | <http://localhost:8001/docs> (Swagger UI) en <http://localhost:8001/docs/openapi.json> |
| Math server | [math/openapi.json](math/openapi.json) | <http://localhost:8004/docs> en <http://localhost:8004/docs/openapi.json> |

- **Try it out:** op `/docs` kun je elke call uitproberen. De spec krijgt daar de server zelf als `servers`-url, dus dat werkt in elke omgeving. Voor de beheer-endpoints log je in met `POST /sessie` (ook via Try it out), en vul je het token in via **Authorize**.
- **Swagger UI:** komt van cdn.jsdelivr.net (`swagger-ui-dist@5`). Alleen `/docs` gebruikt het; de API zelf niet.
- **Onderhoud:** pas de spec aan als je een endpoint toevoegt of verandert. `npx @redocly/cli lint api/openapi.json math/openapi.json` controleert of de spec geldig is.

De spec heeft één schema per ding: `Gesprek`, `Stelling`, `Antwoord`, `Beoordeling` en `Deelnemer`. Wat de server invult, is `readOnly`. Welke velden in een antwoord staan, hangt af van de context. Een stelling in een gesprek heeft bijvoorbeeld alleen `id` en `tekst`, en een eigen stelling heeft alles.

De namen zijn Nederlands, behalve `error` in foutmeldingen: dat is de gangbare naam, en zo zijn API en math server gelijk.

Het overzicht hieronder is de korte versie:

| Methode en pad | Wat |
|---|---|
| `GET /gesprekken` | Alle gesprekken (lokaal <http://localhost:8001/gesprekken>) |
| `POST /gesprekken` | Gesprek aanmaken, alleen voor ingelogde beheerders: `{ titel, omschrijving, moderatie }` (max. 200 en 1000 tekens; omschrijving optioneel; `moderatie` is `achteraf` (standaard) of `vooraf`, zie [Moderatie](#moderatie)) |
| `PUT /gesprekken/<id>` | Gesprek aanpassen, alleen voor ingelogde beheerders: `{ titel, omschrijving, moderatie }`, zelfde regels als aanmaken; zonder `moderatie` blijft die gelijk |
| `GET /gesprekken/<id>` | Eén gesprek met zijn zichtbare stellingen (`{ id, tekst }`) in willekeurige volgorde |
| `GET /stellingen?gesprek_id=<id>&deelnemer_id=<id>` | De stellingen die één deelnemer heeft toegevoegd, met per stelling `beoordeling` (`goedgekeurd`, `afgekeurd` of `null`), `reden`, `zichtbaar` en `antwoorden: { eens, neutraal, oneens }` (aantal deelnemers) |
| `POST /stellingen` | Stelling toevoegen: `{ gesprek_id, deelnemer_id, tekst }` (max. 500 tekens); geeft de stelling terug met `beoordeling`, `reden` en `zichtbaar` |
| `GET /antwoorden?gesprek_id=<id>&deelnemer_id=<id>` | De antwoorden van één deelnemer: `{ antwoorden: [{ gesprek_id, deelnemer_id, stelling_id, waarde }] }` |
| `GET /antwoorden?gesprek_id=<id>` | Alle antwoorden als matrix: `{ gesprek_id, deelnemers: [{ nummer, antwoorden: { stelling_id: waarde } }] }`, anoniem, zoals in de export |
| `POST /antwoorden` | Antwoord geven: `{ gesprek_id, deelnemer_id, stelling_id, waarde }`, met `waarde` één van `eens`, `oneens`, `neutraal`; alleen op zichtbare stellingen |
| `GET /beoordelingen?gesprek_id=<id>` | Alle stellingen van een gesprek met `beoordeling`, `reden`, `zichtbaar` en `antwoorden`, alleen voor ingelogde beheerders |
| `POST /beoordelingen` | Stelling beoordelen, alleen voor ingelogde beheerders: `{ gesprek_id, stelling_id, beoordeling, reden }`, met `beoordeling` `goedgekeurd` of `afgekeurd`; bij `afgekeurd` is een `reden` verplicht (max. 500 tekens) |
| `GET /export?gesprek_id=<id>` | De standaardexport van een gesprek, voor de math server, zie [Math server](#math-server) |
| `GET /docs` | Deze API-documentatie (Swagger UI, lokaal <http://localhost:8001/docs>); de spec zelf op `GET /docs/openapi.json` |
| `POST /sessie` | Inloggen in de beheeromgeving: `{ gebruikersnaam, wachtwoord }`, geeft `{ beheerder, token, verloopt }` |
| `GET /sessie` | De beheerder van het token: `{ beheerder: { id, gebruikersnaam, email } }`, of `{ beheerder: null }` |
| `DELETE /sessie` | Uitloggen: het token werkt daarna niet meer |
„Alleen voor ingelogde beheerders” betekent: met het token uit `POST /sessie` in de header `Authorization: Bearer <token>`, anders volgt 401. Zie [Inloggen](#inloggen).

In de app en de admin toont de knop **{ } API** de API-calls van de huidige pagina, met request en response. Het wachtwoord en het token staan daar niet in.

## Opbouw van de code

De twee PHP-diensten (api en math) zijn op dezelfde manier opgebouwd:

- **`index.php`** is de router: `/<resource>[/<id>]` gaat naar `handlers/<Resource>Handler-><METHOD>($id)`.
- **Fouten:** een handler gooit een `HttpFout($status, $melding)`, en `index.php` antwoordt dan met `{ "error": "..." }`.
- **Data:** handlers lezen en schrijven alleen via `Data`: `bestand()`, `voegToe()` en `laatste()`. De bestanden worden alleen aangevuld; „de laatste regel telt” zit in `Csv::lastPer()`.
- **Gedeelde bestanden:** `Csv.php`, `Http.php`, `HttpFout.php` en `DocsHandler.php` staan in elk project als gelijke kopie. De projecten zijn los, dus ze delen geen code.

App en admin zijn ook op dezelfde manier opgebouwd: `views/`, één module per view met `koppel()`, een hash-router in `main.js`, en `api.js` voor de calls. Het laden van views en de calls zelf komen van de cdn (`views.js` en `verzoek.js`). Een call die mislukt, gooit een `Error` met `status`; opzoekfuncties geven `null` als er niets is.

### Namen

- **Nederlands:** alle namen die we zelf bedenken: functies, variabelen, klassen, CSS-klassen, ids en velden in de API en de data. Bijvoorbeeld `gesprekBestaat()`, `voegToe()`, `.huidige-stelling`, `deelnemer_id`.
- **Engels, waar een standaard of het platform de naam vastlegt:**
  - HTTP-methodes, zoals de handlermethodes `GET()`/`POST()` en `get`/`post` in `api.js`
  - `error` in foutmeldingen
  - CSS-eigenschappen in design tokens (`--font-size-…`)
  - begrippen uit de DOM en fetch (`response`, `options`)
- **Engels, voor de dunne wrappers rond PHP:** `Csv` en `Http`. Hun methodes spiegelen PHP- en HTTP-begrippen (`read`, `append`, `json`, `bearer`, `noCache`).
- **Engels, voor structuurnamen:** mappen (`handlers`, `lib`, `views`, `data`), `…Handler`, en „view”.
- **Taal van teksten:** commentaar is Engels. Teksten voor mensen (schermen, meldingen, het aanmaakscript) zijn Nederlands. Foutmeldingen van de API zijn Engels, want die zijn voor ontwikkelaars.

## Opslag

Alle data staat als csv in [api/data/](api/data/):

| Bestand | Kolommen |
|---|---|
| [gesprekken.csv](api/data/gesprekken.csv) | `id, titel, omschrijving, moderatie` |
| [stellingen.csv](api/data/stellingen.csv) | `id, gesprek_id, tekst, deelnemer_id` (`deelnemer_id` leeg bij stellingen van vóór die kolom) |
| [antwoorden/](api/data/antwoorden/)`<gesprek_id>.csv` | `deelnemer_id, stelling_id, waarde` |
| [beheerders.csv](api/data/beheerders.csv) | `id, gebruikersnaam, email, salt, versleuteld_wachtwoord, wachtwoord_methode`, zie [Beheer](#beheer) |
| `sessies.csv` | `token_hash, beheerder_id, begonnen, verloopt`, zie [Inloggen](#inloggen) |
| `beoordelingen/<gesprek_id>.csv` | `stelling_id, beoordeling, reden, beheerder_id, tijdstip` (`tijdstip` als unix-tijd, zoals alle tijden in de data) |

De bestanden worden alleen aangevuld, nooit gewijzigd. Beantwoordt iemand een stelling opnieuw, dan telt het laatste antwoord. Zo werkt het ook bij een aangepast gesprek (een nieuwe regel met hetzelfde `id` in `gesprekken.csv`) en bij een nieuwe beoordeling van een stelling: de laatste regel telt. Gesprekken worden aangemaakt in de [beheeromgeving](#beheer).

De data staat niet in git (zie [.gitignore](.gitignore)): elke server heeft zijn eigen data. Een lege `data/`-map werkt; de bestanden ontstaan bij het eerste gebruik. Hetzelfde geldt voor `math/data/`, met de berekende modellen. Maak op een nieuwe server eerst een beheerder aan, zie [Beheer](#beheer).

De data is nooit direct op te vragen: in productie stuurt de [Caddyfile](Caddyfile) elk verzoek aan API en math server naar `index.php`.

## Deelnemers

Een deelnemer is een willekeurige `deelnemer_id` (UUID) die de browser in `localStorage` bewaart ([app/js/deelnemer.js](app/js/deelnemer.js)). Er is geen login.

## Beheer

De beheeromgeving staat lokaal op <http://localhost:8002>. Beheerders staan in [api/data/beheerders.csv](api/data/beheerders.csv), en worden toegevoegd met [api/bin/beheerder-toevoegen.php](api/bin/beheerder-toevoegen.php):

```sh
php api/bin/beheerder-toevoegen.php <gebruikersnaam> <email>
```

Het script vraagt het wachtwoord (minstens 12 tekens), zodat het niet in de shell-geschiedenis belandt.

`wachtwoord_methode` zegt hoe `versleuteld_wachtwoord` is gemaakt, zodat er later een andere methode bij kan zonder bestaande beheerders te breken. Nu is dat altijd `password_hash`: PHP's `password_hash()`. Die hash bevat zelf het algoritme en de salt, dus de kolom `salt` blijft leeg.

Een handler in de API die alleen voor beheerders is, begint met `Toegang::vereisBeheerder()`, zie bijvoorbeeld [BeoordelingenHandler.php](api/handlers/BeoordelingenHandler.php).

## Inloggen

Beheerders loggen in bij de API ([Toegang.php](api/lib/Toegang.php)), met een eenvoudig token:

- **Inloggen:** `POST /sessie` met gebruikersnaam en wachtwoord geeft een token. Dat is een willekeurige string; de API bewaart alleen de sha256 ervan.
- **Gebruiken:** de admin stuurt het token mee als `Authorization: Bearer <token>`, en bewaart het in `localStorage`, zodat herladen en andere tabbladen ingelogd blijven.
- **Geldigheid:** het token blijft geldig zolang het gebruikt wordt. Het verloopt na een uur zonder gebruik (`SESSIE_IDLE`), en na 12 uur altijd (`SESSIE_MAX`), zie [api/config.php](api/config.php).
- **Verlengen:** gebeurt bij gebruik, met hooguit één nieuwe regel per 5 minuten per login, zodat het bestand niet bij elke call groeit.
- **Uitloggen** (`DELETE /sessie`) beëindigt het token meteen.
- **Een onbekende gebruikersnaam** kost even veel tijd als een fout wachtwoord, zodat je aan de responstijd niet kunt zien welke gebruikersnamen bestaan.

`sessies.csv` (`token_hash, beheerder_id, begonnen, verloopt`) is append-only, zoals de rest: `verloopt` 0 betekent uitgelogd, en de laatste regel telt. Samen vormt het ook het logboek van wie wanneer inlogde. Verlopen regels worden (nog) niet opgeruimd.

## Moderatie

Per gesprek stelt een beheerder in hoe stellingen van deelnemers worden getoond (`moderatie` in `gesprekken.csv`):

| `moderatie` | Stelling is zichtbaar |
|---|---|
| `achteraf` | tenzij afgekeurd (blacklist) |
| `vooraf` | alleen als goedgekeurd (whitelist) |

Een stelling die niet zichtbaar is, verdwijnt uit het gesprek, de matrix en de analyse, en kan niet meer beantwoord worden. De indiener ziet onder **Mijn stellingen** dat een stelling nog niet is goedgekeurd, of dat hij is afgekeurd, met de reden. Wie een stelling heeft ingediend, ziet een beheerder niet.

Beheerders beoordelen stellingen op de detailpagina van een gesprek in de beheeromgeving. Een beoordeling is altijd te herzien; de laatste telt.

## Math server

De groepsanalyse draait op een eigen server, de math server ([math/](math/)). Die heeft zelf geen data: hij haalt de standaardexport van een gesprek op bij een API en rekent daaruit de groepen. De app vraagt de analyse aan de math server en geeft mee om welk gesprek het gaat, en op welke API:

`GET /analyse?api=<url van de api>&gesprek_id=<id>[&herbereken=1]` (op de math server)

De math server haalt alleen exports op bij API's die in zijn [config.php](math/config.php) staan (`TOEGESTANE_APIS`). Anders zou iedereen hem elke url kunnen laten ophalen. Het model wordt per API en gesprek bewaard in [math/data/analyse/](math/data/analyse/)`<bron>/<gesprek_id>/`, waarbij `<bron>` een korte hash van de API-url is. Het antwoord en de werking van de analyse staan in [docs/analyse.md](docs/analyse.md).

Is de math server niet bereikbaar, dan werkt de rest gewoon: beantwoorden en de matrix blijven werken, alleen de groepen ontbreken.

### Exportformaat

`GET /export?gesprek_id=<id>` op de API ([ExportHandler.php](api/handlers/ExportHandler.php)) geeft:

```json
{
  "formaat": "minipol-export",
  "versie": 1,
  "gegenereerd": "2026-09-25T16:40:00+02:00",
  "gesprek": { "id": "…", "titel": "…" },
  "stellingen": [{ "id": "…", "tekst": "…" }],
  "deelnemers": [{ "nummer": 1, "antwoorden": { "<stelling_id>": "eens" } }]
}
```

- **Stellingen:** alleen de zichtbare, zie [Moderatie](#moderatie).
- **Antwoorden:** alleen op die stellingen. Per deelnemer telt het laatste antwoord. `waarde` is `eens`, `neutraal` of `oneens`.
- **Deelnemers:** anoniem, genummerd op volgorde van hun eerste antwoord. Dat zijn dezelfde nummers als in de matrix van `GET /antwoorden`. Wie alleen onzichtbare stellingen beantwoordde, staat erin met lege `antwoorden`, zodat de nummers gelijk blijven.
- **Versie:** verandert het formaat op een manier die lezers breekt, dan gaat `versie` omhoog. De math server controleert `formaat` en `versie` ([Export.php](math/lib/Export.php)).

## Losse servers

De app, de admin, de API, de math server en de cdn draaien elk op een eigen subdomein. In productie staan ze samen op één VPS, met FrankenPHP (Caddy met PHP), zie [docs/livegang.md](docs/livegang.md).

**Instellingen.** Er is geen bouwstap. Elk deel leest zijn instellingen uit omgevingsvariabelen, die de [Caddyfile](Caddyfile) afleidt uit het domein (`MINIPOL_DOMEIN`). Zonder variabelen gelden de waarden voor lokaal ontwikkelen (`localhost`):

| Deel | Instellingen | Variabelen |
|---|---|---|
| `api/` | [config.php](api/config.php): de URL's van app en admin (CORS), en hoe lang een login geldig is | `MINIPOL_APP_URL`, `MINIPOL_ADMIN_URL` |
| `math/` | [config.php](math/config.php): de URL's van app en API | `MINIPOL_APP_URL`, `MINIPOL_API_URL` |
| `app/` | [instellingen.php](app/instellingen.php): de URL's van API, math server en cdn | `MINIPOL_API_URL`, `MINIPOL_MATH_URL`, `MINIPOL_CDN_URL` |
| `admin/` | [instellingen.php](admin/instellingen.php): de URL's van API, app en cdn | `MINIPOL_API_URL`, `MINIPOL_APP_URL`, `MINIPOL_CDN_URL` |

App en admin zijn verder statisch. Alleen `index.php`, die de cdn-URL invult, en `js/config.php`, die de URL's als JS-module geeft, draaien in PHP.

**Wat de servers nodig hebben** (de Caddyfile regelt het):
- **API en math server:** elk verzoek naar `index.php`, zodat `data/`, `bin/` en `config.php` nooit direct op te vragen zijn. De CORS-preflight (`OPTIONS`) handelt de API zelf af.
- **Math server:** moet de API kunnen bereiken, via zijn URL.
- **CDN:** moet `Access-Control-Allow-Origin` meesturen, anders weigert de browser JS-modules van een ander domein. Lokaal doet [dev/cdn.php](dev/cdn.php) dat.

De cdn gebruiken app en admin tegelijk: een wijziging daar raakt beide.

## Meer

- [docs/analyse.md](docs/analyse.md): hoe de groepsanalyse werkt, welke keuzes erin zitten en waar je iets aanpast.
- [docs/livegang.md](docs/livegang.md): live zetten op een VPS met FrankenPHP, en nieuwe versies uitrollen.
