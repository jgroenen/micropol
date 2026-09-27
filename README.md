# MiniPol

[![tests](https://github.com/jgroenen/micropol/actions/workflows/tests.yml/badge.svg)](https://github.com/jgroenen/micropol/actions/workflows/tests.yml)

Een kleine, Polis-achtige tool voor gesprekken. Deelnemers beantwoorden stellingen met *eens*, *neutraal* of *oneens* en kunnen zelf stellingen toevoegen. De analyse deelt deelnemers in groepen in die stellingen op een vergelijkbare manier beantwoorden.

Er is geen database, er zijn geen dependencies en er is geen build-stap: een PHP-backend die alles wat er gebeurt bijhoudt als events in bestanden, en frontends in vanilla JavaScript (ES modules).

Het bestaat uit zes delen die elk op een eigen server (en domein) draaien:

| Deel | Wat | Lokaal |
|---|---|---|
| [www/](www/) | De productpagina: wat MiniPol is, met links naar alle andere delen en hun specs | <http://localhost:8000> |
| [api/](api/) | De JSON-API in PHP, met de data | <http://localhost:8001> ([docs](http://localhost:8001/docs)) |
| [app/](app/) | De frontend voor deelnemers (statische bestanden) | <http://localhost:8005> |
| [admin/](admin/) | De beheeromgeving (statische bestanden) | <http://localhost:8002> |
| [cdn/](cdn/) | Het design system en de JS-bibliotheken die de app en de admin delen (statische bestanden) | <http://localhost:8003> |
| [math/](math/) | De math server: rekent de groepsanalyse uit de export van een API, zie [Math server](#math-server) | <http://localhost:8004> ([docs](http://localhost:8004/docs)) |

```
www: links naar alle delen
app ──▶ api ◀── admin
 │       ▲
 └─▶ math┘  (haalt GET /export)
app en admin laden gedeelde css/js van de cdn
```

## Starten

Vereist alleen PHP 8.1 of hoger. Start de zes servers met [dev/start.sh](dev/start.sh):

```sh
./dev/start.sh
```

Open daarna <http://localhost:8000>: de productpagina, met links naar de app (<http://localhost:8005>), het beheer (<http://localhost:8002>) en de documentatie van API en math server. Ctrl-C stopt alle servers.

## Testen

Start eerst de servers (`./dev/start.sh`), en dan:

```sh
./tests/run.sh
```

| Test | Wat |
|---|---|
| [tests/api.php](tests/api.php) | Elke call van API en math tegen de OpenAPI-spec: status, request en response, inclusief inloggen en uitloggen. Elke operatie in de specs wordt minstens één keer aangeroepen. |
| [tests/schemacontrole.php](tests/schemacontrole.php) | De validator die `api.php` gebruikt ([lib/Schemacontrole.php](tests/lib/Schemacontrole.php)): een eigen, kleine JSON Schema-controle zonder afhankelijkheden, die zelf ook getest wordt |
| [tests/browser.mjs](tests/browser.mjs) | De productpagina, de app en de admin in Chrome (headless) |
| [tests/migraties.sh](tests/migraties.sh) | De [migraties](#migraties) op data in de oudste vorm |

- **Testdata:** de tests beginnen op een lege API. Ze installeren hem (admin/admin maakt de eerste superbeheerder) en maken hun eigen data aan. Daarna zet `run.sh` de data van API en math terug. Gebruik de app niet terwijl de tests draaien.
- **Nodig:** PHP 8.1 of nieuwer, Node 18 of nieuwer, en Chrome. Er hoeft niets geïnstalleerd te worden: geen Composer, npm of pip. Node en Chrome zijn alleen voor de browsertest; MiniPol zelf heeft alleen PHP nodig.
- **Op GitHub** draaien alle tests bij elke push ([.github/workflows/tests.yml](.github/workflows/tests.yml)). De uitkomst staat onder [Actions](https://github.com/jgroenen/micropol/actions), en in het schildje bovenaan deze README.

## Structuur

| Pad | Wat |
|---|---|
| [api/index.php](api/index.php) | JSON-API: `/<resource>[/<id>]` gaat naar `handlers/<Resource>Handler-><METHOD>($id)` |
| [api/openapi.json](api/openapi.json) | De OpenAPI-spec van de API, live op <http://localhost:8001/docs>, zie [API](#api) |
| [api/schema.json](api/schema.json) | De typen van de API als JSON Schema, live op <http://localhost:8001/docs/schema.json>, zie [Typen](#typen) |
| [api/config.php](api/config.php) | Instellingen per omgeving: welke origins de API mogen aanroepen, en hoe lang een login geldig is, zie [Losse servers](#losse-servers) |
| [api/handlers/](api/handlers/) | Eén handler per resource |
| [api/lib/](api/lib/) | Gedeelde code: opslag ([Data](api/lib/Data.php), [Jsonl](api/lib/Jsonl.php), [Csv](api/lib/Csv.php)), HTTP ([Http](api/lib/Http.php), [HttpFout](api/lib/HttpFout.php)), rollen ([Beheer](api/lib/Beheer.php)) en inloggen ([Toegang](api/lib/Toegang.php), [Wachtwoord](api/lib/Wachtwoord.php)) |
| [api/bin/](api/bin/) | [superbeheerder-toevoegen.php](api/bin/superbeheerder-toevoegen.php), zie [Beheer](#beheer); [migreer.php](api/bin/migreer.php) met de [migraties/](api/bin/migraties/), zie [Migraties](#migraties) |
| [api/data/](api/data/) | De data, zie [Opslag](#opslag) |
| [www/](www/) | De productpagina: [index.php](www/index.php) vult de URL's van alle delen in [views/pagina.html](www/views/pagina.html) in; [css/](www/css/) en [instellingen.php](www/instellingen.php). Geen JavaScript |
| [app/](app/) | Frontend: [index.php](app/index.php) serveert de pagina [views/pagina.html](app/views/pagina.html) met de cdn-URL erin; verder [js/](app/js/), [css/](app/css/) en de andere [views/](app/views/). [instellingen.php](app/instellingen.php) zegt waar de API, de math server en de cdn zijn (ook voor de browser, via [js/config.php](app/js/config.php)) |
| [admin/](admin/) | Beheeromgeving, zie [Beheer](#beheer); zelfde opbouw als `app/`: [index.php](admin/index.php) met [views/pagina.html](admin/views/pagina.html), [js/](admin/js/), [css/](admin/css/), [views/](admin/views/) en [instellingen.php](admin/instellingen.php) |
| [cdn/](cdn/) | Gedeeld door app en admin: het design system in [design/](cdn/design/) (het font IBM Plex Sans, reset, tokens, basisstijlen en lay-out; [main.css](cdn/design/main.css) laadt ze), en de bibliotheken in [lib/](cdn/lib/): [views.js](cdn/lib/views.js) (views laden), [verzoek.js](cdn/lib/verzoek.js) (calls naar de servers), [html.js](cdn/lib/html.js) (`escapeHtml`), [tabs.js](cdn/lib/tabs.js) (tabs, met hun stijl in [design/tabs.css](cdn/design/tabs.css)) en [apilog.js](cdn/lib/apilog.js) (de API-popup, met zijn eigen stylesheet) |
| [math/](math/) | De math server, zelfde opbouw als `api/`: [index.php](math/index.php), [config.php](math/config.php), [openapi.json](math/openapi.json), [handlers/](math/handlers/), [lib/](math/lib/) ([Analyse](math/lib/Analyse.php), [AnalyseModel](math/lib/AnalyseModel.php), [Export](math/lib/Export.php)) en [data/](math/data/) (de berekende modellen) |
| [dev/](dev/) | Alleen voor lokaal ontwikkelen: [start.sh](dev/start.sh) en de router voor de cdn ([cdn.php](dev/cdn.php)) |
| [Caddyfile](Caddyfile) | De webserver in productie: alle subdomeinen, met FrankenPHP en automatische HTTPS, zie [docs/livegang.md](docs/livegang.md) |
| [deploy/](deploy/) | Op de VPS: [installeer.sh](deploy/installeer.sh) (eenmalig), [uppen.sh](deploy/uppen.sh) (een nieuwe versie: back-up, `git pull`, migraties), [terugzetten.sh](deploy/terugzetten.sh) (terug naar een back-up) en de dienst [minipol.service](deploy/minipol.service) |
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
- **Viewer:** de servers hebben zelf geen Swagger UI. `/docs` stuurt door naar Swagger UI op [petstore.swagger.io](https://petstore.swagger.io), met de URL van de spec als `?url=` (`DOCS_VIEWER` in `config.php`). Daarvoor mag iedereen `/docs/openapi.json` en `/docs/schema.json` lezen (CORS `*`), en mag de viewer de API aanroepen voor Try it out.
- **Lokaal** blokkeert Chrome dat een https-site als de viewer `http://localhost` leest, tenzij je de vraag om toegang tot je lokale netwerk toestaat. Lukt dat niet, open dan de spec zelf: <http://localhost:8001/docs/openapi.json>.
- **Onderhoud:** pas de spec aan als je een endpoint toevoegt of verandert. `npx @redocly/cli lint api/openapi.json math/openapi.json` controleert of de spec geldig is.

### Typen

De typen staan in een los JSON Schema-bestand (2020-12), zodat ook andere tools ze kunnen gebruiken, bijvoorbeeld om een export te valideren of code te genereren:

| Server | Bestand | Live |
|---|---|---|
| API | [api/schema.json](api/schema.json) | <http://localhost:8001/docs/schema.json> |
| Math server | [math/schema.json](math/schema.json) | <http://localhost:8004/docs/schema.json> |

- **Waar:** elk type staat onder `$defs`, bijvoorbeeld `schema.json#/$defs/Export`.
- **`$id`:** de live versie heeft de eigen URL als `$id`, zodat je er vanuit een ander schema naar kunt verwijzen.
- **De OpenAPI-spec** heeft zelf geen typen meer: hij verwijst naar `schema.json`. Pas een type daarom aan in `schema.json`.

Er is één type per ding: `Gesprek`, `Stelling`, `Antwoord`, `Beoordeling`, `Event` en `Deelnemer`. Wat de server invult, is `readOnly`. Welke velden in een antwoord staan, hangt af van de context. Een stelling in een gesprek heeft bijvoorbeeld alleen `id` en `tekst`, en een eigen stelling heeft alles.

De namen zijn Nederlands, behalve `error` in foutmeldingen: dat is de gangbare naam, en zo zijn API en math server gelijk.

Het overzicht hieronder is de korte versie:

| Methode en pad | Wat |
|---|---|
| `GET /gesprekken` | Alle gesprekken (lokaal <http://localhost:8001/gesprekken>) |
| `POST /gesprekken` | Gesprek aanmaken, alleen voor superbeheerders: `{ titel, omschrijving, moderatie }` (max. 200 en 1000 tekens; omschrijving optioneel; `moderatie` is `achteraf` (standaard) of `vooraf`, zie [Moderatie](#moderatie)) |
| `PUT /gesprekken/<id>` | Gesprek aanpassen, alleen voor de gespreksbeheerders ervan: `{ titel, omschrijving, moderatie }`, zelfde regels als aanmaken; zonder `moderatie` blijft die gelijk |
| `GET /gesprekken/<id>` | Eén gesprek met zijn zichtbare stellingen (`{ id, tekst }`) in willekeurige volgorde |
| `GET /stellingen?gesprek_id=<id>&deelnemer_id=<id>` | De stellingen die één deelnemer heeft toegevoegd, met per stelling `beoordeling` (`goedgekeurd`, `afgekeurd` of `null`), `reden`, `zichtbaar` en `antwoorden: { eens, neutraal, oneens }` (aantal deelnemers) |
| `POST /stellingen` | Stelling toevoegen: `{ gesprek_id, deelnemer_id, tekst }` (max. 500 tekens); geeft de stelling terug met `beoordeling`, `reden` en `zichtbaar` |
| `GET /antwoorden?gesprek_id=<id>&deelnemer_id=<id>` | De antwoorden van één deelnemer: `{ antwoorden: [{ gesprek_id, deelnemer_id, stelling_id, waarde }] }` |
| `GET /antwoorden?gesprek_id=<id>` | Alle antwoorden als matrix: `{ gesprek_id, deelnemers: [{ nummer, antwoorden: { stelling_id: waarde } }] }`, anoniem, zoals in de export |
| `POST /antwoorden` | Antwoord geven: `{ gesprek_id, deelnemer_id, stelling_id, waarde }`, met `waarde` één van `eens`, `oneens`, `neutraal`; alleen op zichtbare stellingen |
| `GET /beoordelingen?gesprek_id=<id>` | Alle stellingen van een gesprek met `beoordeling`, `reden`, `zichtbaar` en `antwoorden`, alleen voor het team van het gesprek |
| `POST /beoordelingen` | Stelling beoordelen, alleen voor het team van het gesprek: `{ gesprek_id, stelling_id, beoordeling, reden }`, met `beoordeling` `goedgekeurd` of `afgekeurd`; bij `afgekeurd` is een `reden` verplicht (max. 500 tekens) |
| `GET /events?gesprek_id=<id>[&voor=<id>][&limiet=<n>]` | Wat er in een gesprek gebeurde, nieuwste eerst: `{ events, meer }`, alleen voor het team van het gesprek, zie [Logboek](#logboek) |
| `GET /export?gesprek_id=<id>` | De standaardexport van een gesprek, voor de math server, zie [Math server](#math-server) |
| `GET /docs` | Deze API-documentatie (Swagger UI, lokaal <http://localhost:8001/docs>); de spec zelf op `GET /docs/openapi.json` |
| `POST /sessie` | Inloggen in de beheeromgeving: `{ gebruikersnaam, wachtwoord }`, geeft `{ account, installatie, token, verloopt }` |
| `GET /sessie` | Wie er is ingelogd: `{ account: { id, gebruikersnaam, email, superbeheerder, rollen }, installatie }`, of `account` null |
| `POST /installatie` | Direct na installatie, ingelogd met admin/admin: de eerste superbeheerder maken, `{ gebruikersnaam, email, wachtwoord }` |
| `POST /uitnodigingen` | Een uitnodigingslink maken: `{ rol, gesprek_id }`, zie [Beheer](#beheer) |
| `GET`, `POST /uitnodigingen/<token>` | Waarvoor een uitnodiging is; aannemen, ingelogd of met een nieuw account |
| `GET`, `POST /team?gesprek_id=<id>` | Het team van een gesprek; een lid opschorten, herstellen of verwijderen |
| `GET`, `POST /superbeheerders` | De superbeheerders; opschorten, herstellen of verwijderen |
| `POST /gespreksstatus` | Een gesprek pauzeren, beëindigen of weer openen, alleen voor superbeheerders |
| `DELETE /sessie` | Uitloggen: het token werkt daarna niet meer |
Wat alleen voor de beheeromgeving is, vraagt het token uit `POST /sessie` in de header `Authorization: Bearer <token>`, anders volgt 401. Mag het account het niet, dan volgt 403. Zie [Beheer](#beheer) en [Inloggen](#inloggen).

In de app en de admin toont de knop **{ } API** de API-calls van de huidige pagina, met request en response. Het wachtwoord en het token staan daar niet in.

## Opbouw van de code

De twee PHP-diensten (api en math) zijn op dezelfde manier opgebouwd:

- **`index.php`** is de router: `/<resource>[/<id>]` gaat naar `handlers/<Resource>Handler-><METHOD>($id)`.
- **Fouten:** een handler gooit een `HttpFout($status, $melding)`, en `index.php` antwoordt dan met `{ "error": "..." }`.
- **Data:** handlers lezen en schrijven alleen via `Data`. Wat er in een gesprek gebeurt, schrijven ze met `voegEventToe()`; `gesprekken()`, `stellingen()`, `matrix()` en de andere lezen de events en geven de stand. Accounts, rollen, teams en uitnodigingen staan in `Beheer`. Voor de logins zijn er `bestand()`, `voegToe()` en `laatste()`. Zie [Opslag](#opslag).
- **Gedeelde bestanden:** `Csv.php`, `Http.php`, `HttpFout.php` en `DocsHandler.php` staan in elk project als gelijke kopie. De projecten zijn los, dus ze delen geen code.

App en admin zijn ook op dezelfde manier opgebouwd: `views/`, één module per view met `koppel()`, een hash-router in `main.js`, en `api.js` voor de calls. Het laden van views en de calls zelf komen van de cdn (`views.js` en `verzoek.js`). Een call die mislukt, gooit een `Error` met `status`; opzoekfuncties geven `null` als er niets is.

### Namen

- **Nederlands:** alle namen die we zelf bedenken: functies, variabelen, klassen, CSS-klassen, ids en velden in de API en de data. Bijvoorbeeld `gesprekBestaat()`, `voegToe()`, `.huidige-stelling`, `deelnemer_id`.
- **Engels, waar een standaard of het platform de naam vastlegt:**
  - HTTP-methodes, zoals de handlermethodes `GET()`/`POST()` en `get`/`post` in `api.js`
  - `error` in foutmeldingen
  - CSS-eigenschappen in design tokens (`--font-size-…`)
  - begrippen uit de DOM en fetch (`response`, `options`)
- **Engels, voor de dunne wrappers rond PHP:** `Csv`, `Jsonl` en `Http`. Hun methodes spiegelen PHP- en HTTP-begrippen (`read`, `append`, `json`, `bearer`, `noCache`).
- **Engels, voor structuurnamen:** mappen (`handlers`, `lib`, `views`, `data`), `…Handler`, en „view”.
- **Taal van teksten:** commentaar is Engels. Teksten voor mensen (schermen, meldingen, het aanmaakscript) zijn Nederlands. Foutmeldingen van de API zijn Engels, want die zijn voor ontwikkelaars.

## Opslag

Alles wat er gebeurt, staat als **event** in [api/data/](api/data/): één JSON-object per regel (jsonl). Er zijn deze stromen:

| Bestand | Events |
|---|---|
| `gesprekken.jsonl` | `gesprek.aangemaakt`, `.aangepast`, `.opgeschort`, `.beeindigd`, `.hersteld` (van alle gesprekken) |
| `gesprekken/<gesprek_id>/stellingen.jsonl` | `stelling.toegevoegd`, `stelling.goedgekeurd`, `stelling.afgekeurd` |
| `gesprekken/<gesprek_id>/antwoorden.jsonl` | `antwoord.gegeven` |
| `gesprekken/<gesprek_id>/team.jsonl` | `lid.toegevoegd`, `.opgeschort`, `.hersteld`, `.verwijderd`: het team van het gesprek |
| `beheer.jsonl` | `account.aangemaakt`, `wachtwoord.ingesteld`, `superbeheerder.benoemd`, `.opgeschort`, `.hersteld`, `.verwijderd`, `uitnodiging.aangemaakt`, `.gebruikt` |

Een event ziet er zo uit:

```json
{"id":"0199d4c2-…","tijdstip":1790345554,"type":"stelling.afgekeurd","door":{"soort":"beheerder","id":"7faf…"},"gesprek_id":"39ee…","stelling_id":"b336…","reden":"Matige stelling."}
```

- **Elk event** heeft `id`, `tijdstip` (unix-tijd), `type`, `door` en `gesprek_id`, plus de velden van zijn type (zie `Event` in [api/schema.json](api/schema.json)).
- **`id`** is een UUID v7. Die begint met de tijd, dus op id sorteren is op tijd sorteren; zo voegt het [logboek](#logboek) de stromen van een gesprek samen.
- **`door`** is `{ soort, id }`: een account van de beheeromgeving (`beheerder`), of een deelnemer met zijn `deelnemer_id`.
- **`gesprek.aangepast`** heeft alleen de velden die veranderden. Verandert er niets, dan komt er geen event.
- **De stand volgt uit de events:** een gesprek, zijn stellingen met hun beoordeling, en de antwoorden krijg je door de events op volgorde te lezen ([Data.php](api/lib/Data.php)). Een wijziging is een nieuw event, en het laatste telt. Beantwoordt iemand een stelling opnieuw, dan telt het laatste antwoord.
- **Snel genoeg:** `GET /gesprekken` leest alleen het kleine `gesprekken.jsonl`, en tellingen, de matrix en de export lezen de antwoorden van één gesprek.

De bestanden worden alleen aangevuld, nooit gewijzigd. Gesprekken worden aangemaakt in de [beheeromgeving](#beheer).

Logins zijn geen events: die staan in `sessies.csv` (`token_hash, account_id, begonnen, verloopt`), zie [Inloggen](#inloggen). Daar telt de laatste regel met dezelfde `token_hash`.

### Migraties

Verandert de vorm van de data, dan zet een **migratie** bestaande data om. [api/bin/migreer.php](api/bin/migreer.php) draait de migraties in [api/bin/migraties/](api/bin/migraties/) die op deze data nog niet gedraaid hebben, op volgorde van hun naam. Welke al gedraaid hebben, staat in `api/data/migraties.jsonl`. Je hoeft ze nooit met de hand te draaien:

- **Op de server** draait [uppen.sh](deploy/uppen.sh) ze bij elke nieuwe versie, na een back-up van de data. [installeer.sh](deploy/installeer.sh) draait ze ook, op de nog lege data.
- **Lokaal** draait [dev/start.sh](dev/start.sh) ze bij het starten.
- **Op nieuwe of lege data** hebben ze niets te doen. Ze worden dan alleen als gedaan genoteerd.

Een nieuwe migratie is een bestand `NNN-naam.php` in `api/bin/migraties/` (het volgende nummer), dat een functie teruggeeft:
- is er niets om te zetten, dan doet de functie niets;
- anders zet hij de data om, en geeft hij in één regel terug wat hij deed;
- klopt de data niet, dan gooit hij een exception. Dan stopt de update, en blijft deze migratie te doen.

Laat oude bestanden niet weg, maar zet ze in een map `csv-voor-…`, zoals de migraties die er zijn. [tests/migraties.sh](tests/migraties.sh) test ze op data in de oudste vorm.

| Migratie | Zet om |
|---|---|
| [001-naar-events](api/bin/migraties/001-naar-events.php) | `gesprekken.csv`, `stellingen.csv`, `antwoorden/` en `beoordelingen/` naar de event-stromen. De oude bestanden gaan naar `csv-voor-events/`. De csv-bestanden bewaarden niet wanneer iets gebeurde, behalve bij beoordelingen: de andere events krijgen `tijdstip` `null`, en wie een gesprek aanmaakte of aanpaste is onbekend (`door` `null`). |
| [002-naar-teams](api/bin/migraties/002-naar-teams.php) | `beheerders.csv` naar accounts. Elke beheerder wordt superbeheerder én gespreksbeheerder van alle gesprekken, met dezelfde gebruikersnaam en hetzelfde wachtwoord; vroeger konden beheerders immers alles. `beheerders.csv` en `sessies.csv` gaan naar `csv-voor-teams/`, dus iedereen logt opnieuw in. |

De data staat niet in git (zie [.gitignore](.gitignore)): elke server heeft zijn eigen data. Een lege `data/`-map werkt; de bestanden ontstaan bij het eerste gebruik. Hetzelfde geldt voor `math/data/`, met de berekende modellen. Op een nieuwe server log je eerst in met admin/admin, zie [Beheer](#beheer).

De data is nooit direct op te vragen: in productie stuurt de [Caddyfile](Caddyfile) elk verzoek aan API en math server naar `index.php`.

De pagina's van app en admin hebben een strikte Content-Security-Policy ([app/index.php](app/index.php), [admin/index.php](admin/index.php)). Scripts, stijlen en fonts komen alleen van de eigen server en de cdn, en calls gaan alleen naar de API (en de math server). Inline scripts en `style`-attributen mogen niet; de import map is toegestaan via zijn hash. Zet een breedte of kleur daarom via JavaScript (`element.style`), niet in de HTML. De browsertest faalt als Chrome iets blokkeert.

## Deelnemers

Een deelnemer is een willekeurige `deelnemer_id` (UUID) die de browser in `localStorage` bewaart ([app/js/deelnemer.js](app/js/deelnemer.js)). Er is geen login. De API neemt alleen ids aan van hooguit 64 letters, cijfers en streepjes, zodat niemand de data kan vullen met lange ids.

## Beheer

De beheeromgeving staat lokaal op <http://localhost:8002>. Wie wat mag, hangt af van de rol van het account ([Beheer.php](api/lib/Beheer.php)):

| Rol | Van | Mag |
|---|---|---|
| **Superbeheerder** | de hele server | gesprekken aanmaken, pauzeren, beëindigen en weer openen; teamleden en andere superbeheerders opschorten, herstellen of verwijderen; altijd met een reden. Leest en modereert zelf geen inhoud. |
| **Gespreksbeheerder** | één gesprek | de gegevens aanpassen, het team beheren (uitnodigen, opschorten, verwijderen) en modereren |
| **Moderator** | één gesprek | stellingen goed- of afkeuren, en het logboek lezen |

Eén account kan meer rollen hebben, in meer gesprekken. Het team van een gesprek is dus per gesprek.

- **Na installatie** log je in met **admin/admin**. Die login kan alleen de eerste superbeheerder maken, met gebruikersnaam, e-mailadres en eigen wachtwoord. Daarna werkt admin/admin niet meer.
- **Nieuwe mensen** komen erbij met een **uitnodigingslink**. Een superbeheerder nodigt uit voor elke rol, een gespreksbeheerder voor het team van zijn gesprek. De link is 48 uur geldig en werkt één keer. Wie hem opent, maakt een account of logt in, en krijgt de rol. Er wordt geen e-mail verstuurd: wie uitnodigt, stuurt de link zelf door.
- **Opschorten** (met een reden) ontzegt iemand tijdelijk de toegang; **herstellen** geeft hem terug. **Verwijderen** is voorgoed, maar de events blijven bewaard. Je eigen status verander je niet, zodat je jezelf niet buitensluit en er altijd een superbeheerder overblijft. Alleen in een team kan een superbeheerder dat wel: zo herstelt hij zichzelf als iemand anders hem heeft opgeschort.
- **Een gesprek pauzeren of beëindigen** doet een superbeheerder (status `opgeschort` of `beeindigd`). Deelnemers zien dan een paginavullende melding: „Dit gesprek is tijdelijk gepauzeerd”, of „Dit gesprek is voorbij”. Er kunnen geen antwoorden of stellingen bij, en het team kan er niet bij. Allebei zijn terug te draaien. Een gesprek wordt nooit verwijderd: de events blijven, en daarmee alle antwoorden.
- **Wachtwoorden** worden bewaard als `wachtwoord.ingesteld` in `beheer.jsonl`, met `wachtwoord_methode` en `versleuteld_wachtwoord`. De methode is nu altijd `password_hash` (PHP's `password_hash()`, met het algoritme en de salt in de hash), zodat er later een andere bij kan.

Kan niemand meer inloggen, dan voeg je op de server een superbeheerder toe met [api/bin/superbeheerder-toevoegen.php](api/bin/superbeheerder-toevoegen.php). Het script vraagt het wachtwoord, zodat het niet in de shell-geschiedenis belandt:

```sh
php api/bin/superbeheerder-toevoegen.php <gebruikersnaam> <email>
```

Een handler in de API controleert de rol met `Toegang::vereisSuperbeheerder()` of `Toegang::vereisRol($gesprekId, [...])`, zie bijvoorbeeld [BeoordelingenHandler.php](api/handlers/BeoordelingenHandler.php).

## Inloggen

Accounts loggen in bij de API ([Toegang.php](api/lib/Toegang.php)), met een eenvoudig token:

- **Inloggen:** `POST /sessie` met gebruikersnaam en wachtwoord geeft een token. Dat is een willekeurige string; de API bewaart alleen de sha256 ervan.
- **Gebruiken:** de admin stuurt het token mee als `Authorization: Bearer <token>`, en bewaart het in `localStorage`, zodat herladen en andere tabbladen ingelogd blijven.
- **Geldigheid:** het token blijft geldig zolang het gebruikt wordt. Het verloopt na een uur zonder gebruik (`SESSIE_IDLE`), en na 12 uur altijd (`SESSIE_MAX`), zie [api/config.php](api/config.php).
- **Verlengen:** gebeurt bij gebruik, met hooguit één nieuwe regel per 5 minuten per login, zodat het bestand niet bij elke call groeit.
- **Uitloggen** (`DELETE /sessie`) beëindigt het token meteen.
- **Een onbekende gebruikersnaam** kost even veel tijd als een fout wachtwoord, zodat je aan de responstijd niet kunt zien welke gebruikersnamen bestaan.

`sessies.csv` (`token_hash, account_id, begonnen, verloopt`) is append-only, zoals de rest: `verloopt` 0 betekent uitgelogd, en de laatste regel telt. Samen vormt het ook het logboek van wie wanneer inlogde. Verlopen regels worden (nog) niet opgeruimd.

## Moderatie

Per gesprek stelt een gespreksbeheerder in hoe stellingen van deelnemers worden getoond (`moderatie` van het gesprek):

| `moderatie` | Stelling is zichtbaar |
|---|---|
| `achteraf` | tenzij afgekeurd (blacklist) |
| `vooraf` | alleen als goedgekeurd (whitelist) |

Een stelling die niet zichtbaar is, verdwijnt uit het gesprek, de matrix en de analyse, en kan niet meer beantwoord worden. De indiener ziet onder **Mijn stellingen** dat een stelling nog niet is goedgekeurd, of dat hij is afgekeurd, met de reden. Wie een stelling heeft ingediend, ziet het team niet.

Het team (gespreksbeheerders en moderators) beoordeelt stellingen op de detailpagina van een gesprek in de beheeromgeving. Een beoordeling is altijd te herzien; de laatste telt.

## Logboek

Op de detailpagina van een gesprek staat in de beheeromgeving een tab **Logboek**, voor het team van het gesprek: wat er in het gesprek gebeurde, nieuwste eerst, met per regel wanneer, wie en wat ([admin/js/logboek.js](admin/js/logboek.js)). Het wordt opnieuw geladen elke keer dat je de tab opent.

Het komt uit `GET /events` ([EventsHandler.php](api/handlers/EventsHandler.php)), dat de stromen van het gesprek samenvoegt, met die van het team. Events over een stelling hebben daar de `tekst` van de stelling, en events van het team de `gebruikersnaam` van het lid.

- **Deelnemers blijven anoniem:** de API geeft ze als `Deelnemer 12`, met het nummer uit de matrix, nooit met hun id. Wie alleen stellingen toevoegde, krijgt een nummer na de deelnemers die antwoordden.
- **Antwoorden** van een deelnemer die na elkaar komen, zijn samen één regel („gaf 8 antwoorden”). Anders zou je de rest niet meer zien.
- **Per 100:** de API geeft de nieuwste 100 events. Met **Oudere laden** haalt de admin de events van vóór de oudste die hij al heeft (`voor=<id>`).

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

De productpagina, de app, de admin, de API, de math server en de cdn draaien elk op een eigen subdomein: `www.`, `app.`, `admin.`, `api.`, `math.` en `cdn.`. In productie staan ze samen op één VPS, met FrankenPHP (Caddy met PHP), zie [docs/livegang.md](docs/livegang.md).

**Instellingen.** Er is geen bouwstap. Elk deel leest zijn instellingen uit omgevingsvariabelen, die de [Caddyfile](Caddyfile) afleidt uit het domein (`MINIPOL_DOMEIN`). Zonder variabelen gelden de waarden voor lokaal ontwikkelen (`localhost`):

| Deel | Instellingen | Variabelen |
|---|---|---|
| `api/` | [config.php](api/config.php): de URL's van app en admin (CORS), en hoe lang een login geldig is | `MINIPOL_APP_URL`, `MINIPOL_ADMIN_URL` |
| `math/` | [config.php](math/config.php): de URL's van app en API | `MINIPOL_APP_URL`, `MINIPOL_API_URL` |
| `www/` | [instellingen.php](www/instellingen.php): de URL's van alle delen, om naar te linken | `MINIPOL_APP_URL`, `MINIPOL_ADMIN_URL`, `MINIPOL_API_URL`, `MINIPOL_MATH_URL`, `MINIPOL_CDN_URL` |
| `app/` | [instellingen.php](app/instellingen.php): de URL's van API, math server en cdn | `MINIPOL_API_URL`, `MINIPOL_MATH_URL`, `MINIPOL_CDN_URL` |
| `admin/` | [instellingen.php](admin/instellingen.php): de URL's van API, app en cdn | `MINIPOL_API_URL`, `MINIPOL_APP_URL`, `MINIPOL_CDN_URL` |

App en admin zijn verder statisch. Alleen `index.php`, die de cdn-URL in `views/pagina.html` invult, en `js/config.php`, die de URL's als JS-module geeft, draaien in PHP.

**Wat de servers nodig hebben** (de Caddyfile regelt het):
- **API en math server:** elk verzoek naar `index.php`, zodat `data/`, `bin/` en `config.php` nooit direct op te vragen zijn. De CORS-preflight (`OPTIONS`) handelt de API zelf af.
- **Math server:** moet de API kunnen bereiken, via zijn URL.
- **CDN:** moet `Access-Control-Allow-Origin` meesturen, anders weigert de browser JS-modules van een ander domein. Lokaal doet [dev/cdn.php](dev/cdn.php) dat.

De cdn gebruiken www, app en admin tegelijk: een wijziging daar raakt alle drie. Ook het font komt van de cdn, zodat ze geen andere servers aanspreken (zoals Google Fonts).

## Licentie en beveiliging

De code staat op [github.com/jgroenen/micropol](https://github.com/jgroenen/micropol). MiniPol valt onder de [MIT-licentie](LICENSE). Het font IBM Plex Sans valt onder de [SIL Open Font License](cdn/design/fonts/LICENSE-OFL.txt).

Een kwetsbaarheid meld je zoals beschreven in [SECURITY.md](SECURITY.md). Daar staat ook waar MiniPol op leunt, en hoe elk risico in die toeleveringsketen is afgedekt.

## Meer

- [docs/analyse.md](docs/analyse.md): hoe de groepsanalyse werkt, welke keuzes erin zitten en waar je iets aanpast.
- [docs/livegang.md](docs/livegang.md): live zetten op een VPS met FrankenPHP, en nieuwe versies uitrollen.
