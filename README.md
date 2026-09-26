# MiniPol

Een kleine, Polis-achtige tool voor gesprekken. Deelnemers beantwoorden stellingen met *eens*, *neutraal* of *oneens* en kunnen zelf stellingen toevoegen. De analyse deelt deelnemers in groepen in die stellingen op een vergelijkbare manier beantwoorden.

Er is geen database, er zijn geen dependencies en er is geen build-stap: een PHP-backend die csv-bestanden bijhoudt, en frontends in vanilla JavaScript (ES modules).

Het bestaat uit vijf delen die elk op een eigen server (en domein) draaien:

| Deel | Wat | Lokaal |
|---|---|---|
| [api/](api/) | De JSON-API in PHP, met de data | <http://localhost:8001> ([docs](http://localhost:8001/docs)) |
| [app/](app/) | De frontend voor deelnemers (statische bestanden) | <http://localhost:8000> |
| [admin/](admin/) | De beheeromgeving (statische bestanden) | <http://localhost:8002> |
| [cdn/](cdn/) | CSS en JS die de app en de admin delen (statische bestanden) | <http://localhost:8003> |
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

## Structuur

| Pad | Wat |
|---|---|
| [api/index.php](api/index.php) | JSON-API: `/<resource>[/<id>]` gaat naar `handlers/<Resource>Handler-><METHOD>($id)` |
| [api/openapi.json](api/openapi.json) | De OpenAPI-spec van de API, live op <http://localhost:8001/docs>, zie [API](#api) |
| [api/config.php](api/config.php) | Instellingen per omgeving: welke origins de API mogen aanroepen, zie [Losse servers](#losse-servers) |
| [api/handlers/](api/handlers/) | Eén handler per resource |
| [api/lib/](api/lib/) | Gedeelde code: csv-opslag ([Csv](api/lib/Csv.php), [Data](api/lib/Data.php)), HTTP ([Http](api/lib/Http.php)) en inloggen ([Sessie](api/lib/Sessie.php), [Wachtwoord](api/lib/Wachtwoord.php)) |
| [api/data/](api/data/) | De data, zie [Opslag](#opslag) |
| [api/bin/](api/bin/) | Scripts voor de command line, zie [Beheer](#beheer) |
| [app/](app/) | Frontend: [index.html](app/index.html), [js/](app/js/), [css/](app/css/) en [views/](app/views/); [js/config.js](app/js/config.js) zegt waar de API en de math server zijn |
| [admin/](admin/) | Beheeromgeving, zie [Beheer](#beheer); zelfde opbouw als `app/`: [index.html](admin/index.html), [js/](admin/js/), [css/](admin/css/) en [views/](admin/views/); [js/config.js](admin/js/config.js) zegt waar de API en de app zijn |
| [cdn/](cdn/) | Gedeeld door app en admin: de basisstijlen ([css/main.css](cdn/css/main.css) en wat die importeert), [js/util.js](cdn/js/util.js) en de API-popup ([js/apilog.js](cdn/js/apilog.js), [css/apilog.css](cdn/css/apilog.css)) |
| [math/](math/) | De math server, zelfde opbouw als `api/`: [index.php](math/index.php), [config.php](math/config.php), [openapi.json](math/openapi.json), [handlers/](math/handlers/), [lib/](math/lib/) ([Analyse](math/lib/Analyse.php), [AnalyseModel](math/lib/AnalyseModel.php), [Export](math/lib/Export.php)) en [data/](math/data/) (de berekende modellen) |
| [dev/](dev/) | Alleen voor lokaal ontwikkelen: [start.sh](dev/start.sh) en de router voor de cdn ([cdn.php](dev/cdn.php)) |
| [docs/](docs/) | Achtergronddocumentatie, zoals [analyse.md](docs/analyse.md) |

## API

Alle requests en responses zijn JSON. Fouten komen terug als `{ "error": "..." }` met een passende statuscode.

De volledige beschrijving staat in een OpenAPI 3.1-spec, per server:

| Server | Spec | Live |
|---|---|---|
| API | [api/openapi.json](api/openapi.json) | <http://localhost:8001/docs> (Swagger UI) en <http://localhost:8001/docs/openapi.json> |
| Math server | [math/openapi.json](math/openapi.json) | <http://localhost:8004/docs> en <http://localhost:8004/docs/openapi.json> |

- **Try it out:** op `/docs` kun je elke call uitproberen. De spec krijgt daar de server zelf als `servers`-url, dus dat werkt in elke omgeving. Voor de beheer-endpoints log je eerst in met `POST /sessie`, en vul je het token in via **Authorize**.
- **Swagger UI:** komt van cdn.jsdelivr.net (`swagger-ui-dist@5`). Alleen `/docs` gebruikt het; de API zelf niet.
- **Onderhoud:** pas de spec aan als je een endpoint toevoegt of verandert. `npx @redocly/cli lint api/openapi.json math/openapi.json` controleert of de spec geldig is.

Het overzicht hieronder is de korte versie:

| Methode en pad | Wat |
|---|---|
| `GET /gesprekken` | Alle gesprekken (lokaal <http://localhost:8001/gesprekken>) |
| `POST /gesprekken` | Gesprek aanmaken, alleen voor ingelogde beheerders: `{ titel, omschrijving, moderatie }` (max. 200 en 1000 tekens; omschrijving optioneel; `moderatie` is `achteraf` (standaard) of `vooraf`, zie [Moderatie](#moderatie)) |
| `PUT /gesprekken/<id>` | Gesprek aanpassen, alleen voor ingelogde beheerders: `{ titel, omschrijving, moderatie }`, zelfde regels als aanmaken; zonder `moderatie` blijft die gelijk |
| `GET /gesprekken/<id>` | Eén gesprek met zijn zichtbare stellingen (`{ id, content }`) in willekeurige volgorde |
| `GET /stellingen?gesprek_id=<id>&user_id=<id>` | De stellingen die één deelnemer heeft toegevoegd, met per stelling `beoordeling` (`goedgekeurd`, `afgekeurd` of `null`), `reden`, `zichtbaar` en `antwoorden: { eens, neutraal, oneens }` (aantal deelnemers) |
| `POST /stellingen` | Stelling toevoegen: `{ gesprek_id, user_id, content }` (max. 500 tekens); geeft de stelling terug met `beoordeling`, `reden` en `zichtbaar` |
| `GET /antwoorden?gesprek_id=<id>&user_id=<id>` | De antwoorden van één deelnemer |
| `GET /antwoorden?gesprek_id=<id>` | Alle antwoorden als matrix: per deelnemer `{ stelling_id: waarde }`, zonder `user_id` |
| `POST /antwoorden` | Antwoord geven: `{ gesprek_id, user_id, stelling_id, waarde }`, met `waarde` één van `eens`, `oneens`, `neutraal`; alleen op zichtbare stellingen |
| `GET /beoordelingen?gesprek_id=<id>` | Alle stellingen van een gesprek met `beoordeling`, `reden`, `zichtbaar` en `antwoorden`, alleen voor ingelogde beheerders |
| `POST /beoordelingen` | Stelling beoordelen, alleen voor ingelogde beheerders: `{ gesprek_id, stelling_id, beoordeling, reden }`, met `beoordeling` `goedgekeurd` of `afgekeurd`; bij `afgekeurd` is een `reden` verplicht (max. 500 tekens) |
| `GET /export?gesprek_id=<id>` | De standaardexport van een gesprek, voor de math server, zie [Math server](#math-server) |
| `GET /docs` | Deze API-documentatie (Swagger UI, lokaal <http://localhost:8001/docs>); de spec zelf op `GET /docs/openapi.json` |
| `GET /sessie` | De beheerder van het token: `{ user: { id, username, email } }`, of `{ user: null }` zonder geldig token |
| `POST /sessie` | Inloggen in de beheeromgeving: `{ username, password }`, geeft `{ user, token, verloopt }` |
| `DELETE /sessie` | Uitloggen: het token werkt daarna niet meer |

„Alleen voor ingelogde beheerders” betekent: met de header `Authorization: Bearer <token>`, anders volgt 401.

In de app en de admin toont de knop **{ } API** de API-calls van de huidige pagina, met request en response. Het wachtwoord van de login staat daar niet in.

## Opslag

Alle data staat als csv in [api/data/](api/data/):

| Bestand | Kolommen |
|---|---|
| [gesprekken.csv](api/data/gesprekken.csv) | `id, titel, omschrijving, moderatie` |
| [stellingen.csv](api/data/stellingen.csv) | `id, gesprek_id, content, user_id` (leeg bij stellingen van vóór die kolom) |
| [antwoorden/](api/data/antwoorden/)`<gesprek_id>.csv` | `user_id, stelling_id, waarde` |
| `beoordelingen/<gesprek_id>.csv` | `stelling_id, beoordeling, reden, user_id, tijdstip` (`user_id` is de beheerder) |
| [users.csv](api/data/users.csv) | `id, username, email, salt, encrypted_password, password_method` (beheerders, zie [Beheer](#beheer)) |
| `sessies.csv` | `token_hash, user_id, verloopt` (logins van beheerders; de sha256 van het token, `verloopt` als unix-tijd) |

De bestanden worden alleen aangevuld, nooit gewijzigd. Beantwoordt iemand een stelling opnieuw, dan telt het laatste antwoord. Zo werkt het ook bij een aangepast gesprek (een nieuwe regel met hetzelfde `id` in `gesprekken.csv`) en bij een nieuwe beoordeling van een stelling: de laatste regel telt. Gesprekken worden aangemaakt in de [beheeromgeving](#beheer).

De data staat niet in git (zie [.gitignore](.gitignore)): elke server heeft zijn eigen data. Een lege `data/`-map werkt; de bestanden ontstaan bij het eerste gebruik. Maak op een nieuwe server eerst een beheerder aan, zie [Beheer](#beheer). Hetzelfde geldt voor `math/data/`, met de berekende modellen.

Onder Apache blokkeert [api/data/.htaccess](api/data/.htaccess) directe toegang tot de data. Gebruik je een andere webserver, zorg dan zelf dat `api/data/` niet publiek bereikbaar is.

## Deelnemers

Een deelnemer is een willekeurige `user_id` (UUID) die de browser in `localStorage` bewaart. Er is geen login.

## Beheer

De beheeromgeving staat lokaal op <http://localhost:8002>. Beheerders staan in [api/data/users.csv](api/data/users.csv) en worden toegevoegd met [api/bin/user-toevoegen.php](api/bin/user-toevoegen.php):

```sh
php api/bin/user-toevoegen.php <username> <email>
```

Het script vraagt het wachtwoord (minstens 12 tekens), zodat het niet in de shell-geschiedenis belandt.

`password_method` zegt hoe `encrypted_password` is gemaakt, zodat er later een andere methode bij kan zonder bestaande gebruikers te breken. Nu is dat altijd `password_hash`: PHP's `password_hash()`. Die hash bevat zelf het algoritme en de salt, dus de kolom `salt` blijft leeg.

Inloggen geeft een token. Een cookie kan niet: de admin draait op een ander domein dan de API, en browsers blokkeren dan third-party cookies. De admin bewaart het token in `localStorage` en stuurt het mee als `Authorization: Bearer <token>`.

- **Geldigheid:** een token is 12 uur geldig (`GELDIG` in [Sessie.php](api/lib/Sessie.php)).
- **Opslag:** de API bewaart alleen de sha256 van het token.
- **Uitloggen:** voegt voor datzelfde token een regel toe die meteen verloopt.
- **Beheerders-handlers:** een handler die alleen voor beheerders is, begint met `Sessie::vereisUser()`, zie bijvoorbeeld [BeoordelingenHandler.php](api/handlers/BeoordelingenHandler.php).

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
  "stellingen": [{ "id": "…", "content": "…" }],
  "deelnemers": [{ "nummer": 1, "antwoorden": { "<stelling_id>": "eens" } }]
}
```

- **Stellingen:** alleen de zichtbare, zie [Moderatie](#moderatie).
- **Antwoorden:** alleen op die stellingen. Per deelnemer telt het laatste antwoord. `waarde` is `eens`, `neutraal` of `oneens`.
- **Deelnemers:** anoniem, genummerd op volgorde van hun eerste antwoord. Dat zijn dezelfde nummers als in de matrix van `GET /antwoorden`. Wie alleen onzichtbare stellingen beantwoordde, staat erin met lege `antwoorden`, zodat de nummers gelijk blijven.
- **Versie:** verandert het formaat op een manier die lezers breekt, dan gaat `versie` omhoog. De math server controleert `formaat` en `versie` ([Export.php](math/lib/Export.php)).

## Losse servers

De app, de admin, de API, de math server en de cdn draaien elk op een eigen server en domein. Per omgeving pas je aan:

| Deel | Bestand | Wat |
|---|---|---|
| `api/` | [config.php](api/config.php) | `TOEGESTANE_ORIGINS`: de origins van de app en de admin (CORS) |
| `app/` | [js/config.js](app/js/config.js) | `API_URL` en `MATH_URL` |
| `app/` | [index.html](app/index.html) | De url van de cdn: de stylesheet en de import map (`cdn/`) |
| `admin/` | [js/config.js](admin/js/config.js) | `API_URL` en `APP_URL` (voor de links naar de app) |
| `admin/` | [index.html](admin/index.html) | De url van de cdn, zoals bij de app |
| `math/` | [config.php](math/config.php) | `TOEGESTANE_ORIGINS`: de origin van de app (CORS); `TOEGESTANE_APIS`: de API's waarvan hij exports mag ophalen |

Voor de verschillende servers:

- **API:** handelt de CORS-preflight (`OPTIONS`) zelf af. Alle requests moeten naar [api/index.php](api/index.php), en alleen die: `data/` en `bin/` mogen niet publiek zijn. Onder Apache moet de `Authorization`-header PHP bereiken; zet daarvoor `CGIPassAuth On`. Draait de API in een submap `api/`, dan werken de paden ook met die prefix (`/api/gesprekken`).
- **Math server:** net als de API: alle requests naar [math/index.php](math/index.php), en `data/` mag niet publiek zijn. Hij moet de API kunnen bereiken en PHP moet urls kunnen openen (`allow_url_fopen`).
- **App en admin:** zijn alleen statische bestanden; een eigen server-router is niet nodig.
- **CDN:** moet `Access-Control-Allow-Origin` meesturen voor de JS-bestanden. Zonder die header weigert de browser modules van een ander domein. Lokaal doet [dev/cdn.php](dev/cdn.php) dat.

De CSS en JS op de cdn gebruiken de app en de admin tegelijk: een wijziging daar raakt beide.

## Meer

- [docs/analyse.md](docs/analyse.md): hoe de groepsanalyse werkt, welke keuzes erin zitten en waar je iets aanpast.
