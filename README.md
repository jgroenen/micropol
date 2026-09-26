# MiniPol

Een kleine, Polis-achtige tool voor gesprekken. Deelnemers beantwoorden stellingen met *eens*, *neutraal* of *oneens* en kunnen zelf stellingen toevoegen. De analyse deelt deelnemers in groepen in die stellingen op een vergelijkbare manier beantwoorden.

Er is geen database, er zijn geen dependencies en er is geen build-stap: een PHP-backend die csv-bestanden bijhoudt, en frontends in vanilla JavaScript (ES modules).

Het bestaat uit zes delen die elk op een eigen server (en domein) draaien:

| Deel | Wat | Lokaal |
|---|---|---|
| [api/](api/) | De JSON-API in PHP, met de data | <http://localhost:8001> ([docs](http://localhost:8001/docs)) |
| [app/](app/) | De frontend voor deelnemers (statische bestanden) | <http://localhost:8000> |
| [admin/](admin/) | De beheeromgeving (statische bestanden) | <http://localhost:8002> |
| [cdn/](cdn/) | CSS en JS die de app en de admin delen (statische bestanden) | <http://localhost:8003> |
| [math/](math/) | De math server: rekent de groepsanalyse uit de export van een API, zie [Math server](#math-server) | <http://localhost:8004> ([docs](http://localhost:8004/docs)) |
| [auth/](auth/) | De auth service: het inloggen van beheerders (OAuth 2), zie [Inloggen](#inloggen) | <http://localhost:8005> ([docs](http://localhost:8005/docs)) |

```
app ──▶ api ◀── admin ──▶ auth
 │       ▲ │               ▲
 └─▶ math┘ └───────────────┘  math haalt GET /export; api controleert tokens bij auth
app, admin en de inlogpagina laden gedeelde css/js van de cdn
```

## Starten

Vereist PHP 8 of hoger. Start de zes servers met [dev/start.sh](dev/start.sh):

```sh
./dev/start.sh
```

Open daarna <http://localhost:8000> (app) of <http://localhost:8002> (beheer). De API-documentatie staat op <http://localhost:8001/docs>, <http://localhost:8004/docs> en <http://localhost:8005/docs>. Ctrl-C stopt alle servers.

## Structuur

| Pad | Wat |
|---|---|
| [api/index.php](api/index.php) | JSON-API: `/<resource>[/<id>]` gaat naar `handlers/<Resource>Handler-><METHOD>($id)` |
| [api/openapi.json](api/openapi.json) | De OpenAPI-spec van de API, live op <http://localhost:8001/docs>, zie [API](#api) |
| [api/config.php](api/config.php) | Instellingen per omgeving: welke origins de API mogen aanroepen, en waar de auth service is, zie [Losse servers](#losse-servers) |
| [api/handlers/](api/handlers/) | Eén handler per resource |
| [api/lib/](api/lib/) | Gedeelde code: csv-opslag ([Csv](api/lib/Csv.php), [Data](api/lib/Data.php)), HTTP ([Http](api/lib/Http.php)) en wie er ingelogd is ([Sessie](api/lib/Sessie.php)) |
| [api/data/](api/data/) | De data, zie [Opslag](#opslag) |
| [app/](app/) | Frontend: [index.html](app/index.html), [js/](app/js/), [css/](app/css/) en [views/](app/views/); [js/config.js](app/js/config.js) zegt waar de API en de math server zijn |
| [admin/](admin/) | Beheeromgeving, zie [Beheer](#beheer); zelfde opbouw als `app/`: [index.html](admin/index.html), [js/](admin/js/), [css/](admin/css/) en [views/](admin/views/); [js/config.js](admin/js/config.js) zegt waar de API, de app en de auth service zijn; [js/auth.js](admin/js/auth.js) doet het inloggen |
| [cdn/](cdn/) | Gedeeld door app en admin: de basisstijlen ([css/main.css](cdn/css/main.css) en wat die importeert), [js/util.js](cdn/js/util.js) en de API-popup ([js/apilog.js](cdn/js/apilog.js), [css/apilog.css](cdn/css/apilog.css)) |
| [math/](math/) | De math server, zelfde opbouw als `api/`: [index.php](math/index.php), [config.php](math/config.php), [openapi.json](math/openapi.json), [handlers/](math/handlers/), [lib/](math/lib/) ([Analyse](math/lib/Analyse.php), [AnalyseModel](math/lib/AnalyseModel.php), [Export](math/lib/Export.php)) en [data/](math/data/) (de berekende modellen) |
| [auth/](auth/) | De auth service, zelfde opbouw als `api/`: [index.php](auth/index.php), [config.php](auth/config.php), [openapi.json](auth/openapi.json), [handlers/](auth/handlers/), [lib/](auth/lib/) (de flows in [Tokens](auth/lib/Tokens.php)), [bin/](auth/bin/) en [data/](auth/data/) (gebruikers en tokens) |
| [dev/](dev/) | Alleen voor lokaal ontwikkelen: [start.sh](dev/start.sh) en de router voor de cdn ([cdn.php](dev/cdn.php)) |
| [deploy/](deploy/) | Uitrollen naar een VPS met Apache: [deploy.sh](deploy/deploy.sh), de Apache-sjablonen en [productie.env.voorbeeld](deploy/productie.env.voorbeeld), zie [docs/livegang.md](docs/livegang.md) |
| [docs/](docs/) | Achtergronddocumentatie, zoals [analyse.md](docs/analyse.md) |

## API

Alle requests en responses zijn JSON. Fouten komen terug als `{ "error": "..." }` met een passende statuscode.

De volledige beschrijving staat in een OpenAPI 3.1-spec, per server:

| Server | Spec | Live |
|---|---|---|
| API | [api/openapi.json](api/openapi.json) | <http://localhost:8001/docs> (Swagger UI) en <http://localhost:8001/docs/openapi.json> |
| Auth service | [auth/openapi.json](auth/openapi.json) | <http://localhost:8005/docs> en <http://localhost:8005/docs/openapi.json> |
| Math server | [math/openapi.json](math/openapi.json) | <http://localhost:8004/docs> en <http://localhost:8004/docs/openapi.json> |

- **Try it out:** op `/docs` kun je elke call uitproberen. De spec krijgt daar de server zelf als `servers`-url, dus dat werkt in elke omgeving. Voor de beheer-endpoints vul je via **Authorize** een access token in: log in in de admin en kopieer `access_token` uit `minipol_admin_tokens` in localStorage (15 minuten geldig).
- **Swagger UI:** komt van cdn.jsdelivr.net (`swagger-ui-dist@5`). Alleen `/docs` gebruikt het; de API zelf niet.
- **Onderhoud:** pas de spec aan als je een endpoint toevoegt of verandert. `npx @redocly/cli lint api/openapi.json math/openapi.json auth/openapi.json` controleert of de spec geldig is.

De spec heeft één schema per ding: `Gesprek`, `Stelling`, `Antwoord`, `Beoordeling` en `Deelnemer`. Wat de server invult, is `readOnly`. Welke velden in een antwoord staan, hangt af van de context. Een stelling in een gesprek heeft bijvoorbeeld alleen `id` en `tekst`, en een eigen stelling heeft alles.

De namen zijn Nederlands, behalve waar een standaard de naam vastlegt (OAuth en OpenID Connect bij de auth service: `username`, `sub`, `client_id` en dergelijke) en `error` in foutmeldingen, zodat de drie diensten gelijk blijven.

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
„Alleen voor ingelogde beheerders” betekent: met een access token van de auth service in de header `Authorization: Bearer <token>`, anders volgt 401. Zie [Inloggen](#inloggen).

In de app en de admin toont de knop **{ } API** de API-calls van de huidige pagina, met request en response. Tokens, codes en het wachtwoord staan daar niet in.

## Opslag

Alle data staat als csv in [api/data/](api/data/):

| Bestand | Kolommen |
|---|---|
| [gesprekken.csv](api/data/gesprekken.csv) | `id, titel, omschrijving, moderatie` |
| [stellingen.csv](api/data/stellingen.csv) | `id, gesprek_id, tekst, deelnemer_id` (`deelnemer_id` leeg bij stellingen van vóór die kolom) |
| [antwoorden/](api/data/antwoorden/)`<gesprek_id>.csv` | `deelnemer_id, stelling_id, waarde` |
| `beoordelingen/<gesprek_id>.csv` | `stelling_id, beoordeling, reden, beheerder_id, tijdstip` |

De bestanden worden alleen aangevuld, nooit gewijzigd. Beantwoordt iemand een stelling opnieuw, dan telt het laatste antwoord. Zo werkt het ook bij een aangepast gesprek (een nieuwe regel met hetzelfde `id` in `gesprekken.csv`) en bij een nieuwe beoordeling van een stelling: de laatste regel telt. Gesprekken worden aangemaakt in de [beheeromgeving](#beheer).

De data staat niet in git (zie [.gitignore](.gitignore)): elke server heeft zijn eigen data. Een lege `data/`-map werkt; de bestanden ontstaan bij het eerste gebruik. Hetzelfde geldt voor `math/data/` (de berekende modellen) en `auth/data/` (gebruikers en tokens, zie [Inloggen](#inloggen)). Maak op een nieuwe server eerst een beheerder aan, zie [Beheer](#beheer).

Onder Apache blokkeert [api/data/.htaccess](api/data/.htaccess) directe toegang tot de data. Gebruik je een andere webserver, zorg dan zelf dat `api/data/` niet publiek bereikbaar is.

## Deelnemers

Een deelnemer is een willekeurige `deelnemer_id` (UUID) die de browser in `localStorage` bewaart ([app/js/deelnemer.js](app/js/deelnemer.js)). Er is geen login.

## Beheer

De beheeromgeving staat lokaal op <http://localhost:8002>. Beheerders staan in de auth service, in [auth/data/gebruikers.csv](auth/data/gebruikers.csv), en worden toegevoegd met [auth/bin/gebruiker-toevoegen.php](auth/bin/gebruiker-toevoegen.php):

```sh
php auth/bin/gebruiker-toevoegen.php <gebruikersnaam> <email>
```

Het script vraagt het wachtwoord (minstens 12 tekens), zodat het niet in de shell-geschiedenis belandt.

`wachtwoord_methode` zegt hoe `versleuteld_wachtwoord` is gemaakt, zodat er later een andere methode bij kan zonder bestaande gebruikers te breken. Nu is dat altijd `password_hash`: PHP's `password_hash()`. Die hash bevat zelf het algoritme en de salt, dus de kolom `salt` blijft leeg.

Een handler in de API die alleen voor beheerders is, begint met `Sessie::vereisBeheerder()`, zie bijvoorbeeld [BeoordelingenHandler.php](api/handlers/BeoordelingenHandler.php).

## Inloggen

Beheerders loggen in bij de auth service ([auth/](auth/)): een kleine OAuth 2-server, zo opgezet dat je hem later kunt vervangen door bijvoorbeeld Keycloak.

```
admin ──(1) naar /authorize (inlogpagina van auth)──▶ auth
admin ◀─(2) terug met ?code=…&state=… ─────────────── auth
admin ──(3) POST /token: code + PKCE-verifier ───────▶ auth   → access token + refresh token
admin ──(4) Authorization: Bearer <access token> ───▶ api ──(5) POST /introspect ──▶ auth
```

- **Het wachtwoord** gaat alleen naar de inlogpagina van de auth service, nooit naar de admin of de API.
- **PKCE (S256):** de code is alleen in te wisselen door wie de login begon.
- **Access token:** 15 minuten geldig. De admin vernieuwt het op de achtergrond met het refresh token.
- **Refresh token:** werkt één keer. Elke refresh geeft een nieuw paar (*rotation*). Wordt een oud refresh token toch nog eens gebruikt, dan is het waarschijnlijk gestolen, en eindigt de hele login.
- **Idle timeout:** zonder vernieuwing verloopt het refresh token na 30 minuten. Een login duurt hooguit 12 uur.
- **Uitloggen** (`/revoke`) beëindigt de login: alle tokens ervan werken meteen niet meer.
- **Tokens** zijn willekeurige strings zonder betekenis buiten de auth service. Die bewaart alleen hun sha256.
- **De API** vraagt bij de auth service of een token geldig is (*introspection*), met een eigen id en geheim. Het antwoord bewaart hij 10 seconden (`INTROSPECTIE_CACHE`), dus uitloggen bereikt de API binnen 10 seconden.
- **De admin** bewaart de tokens in `localStorage`, zodat herladen en andere tabbladen ingelogd blijven. Vernieuwen gebeurt in één tabblad tegelijk (Web Locks), zodat tabbladen elkaars refresh token niet dubbel gebruiken.

De auth service slaat alles append-only op in [auth/data/](auth/data/):

| Bestand | Kolommen |
|---|---|
| [gebruikers.csv](auth/data/gebruikers.csv) | `id, gebruikersnaam, email, salt, versleuteld_wachtwoord, wachtwoord_methode` |
| `codes.csv` | `code_hash, client_id, redirect_uri, code_challenge, gebruiker_id, verloopt` (`verloopt` 0: ingewisseld) |
| `sessies.csv` | `id, gebruiker_id, client_id, begonnen, verloopt` (`verloopt` 0: uitgelogd of ingetrokken) |
| `tokens.csv` | `token_hash, soort, sessie_id, verloopt` (`soort` is `access` of `refresh`; `verloopt` 0: refresh token gebruikt) |

Samen vormen ze ook het logboek van wie wanneer inlogde. De bestanden groeien met elke login en elke vernieuwing; verlopen regels worden (nog) niet opgeruimd.

**Naar Keycloak of een andere OAuth-server:**
- **Admin:** zet de URL van de server (bij Keycloak die van de realm) als `AUTH_URL`, en de client-id als `CLIENT_ID`, in [admin/js/config.js](admin/js/config.js). De admin leest de endpoints uit `/.well-known/openid-configuration`.
- **API:** zet de introspection-URL en het id en geheim van de API (in Keycloak een *confidential client*) in [api/config.php](api/config.php).
- **In Keycloak:** maak een public client met PKCE (S256) en `http://…/` van de admin als redirect-URI.
- **Wat de API gebruikt:** uit de introspection alleen `active`, `sub`, `username` (of `preferred_username`) en `email`. Rollen gebruikt hij nog niet: iedereen die kan inloggen, is beheerder.

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

De app, de admin, de API, de math server, de auth service en de cdn draaien elk op een eigen server en domein. Hoe je ze live zet op een VPS met Apache, met elk deel op een subdomein, staat in [docs/livegang.md](docs/livegang.md). [deploy/deploy.sh](deploy/deploy.sh) regelt de instellingen per omgeving; met de hand hoef je niets aan te passen.

Waar de instellingen zitten:

| Deel | Instellingen | Hoe per omgeving |
|---|---|---|
| `api/` | [config.php](api/config.php): de URL's van app, admin en auth, en het geheim bij de auth service | Omgevingsvariabelen `MINIPOL_APP_URL`, `MINIPOL_ADMIN_URL`, `MINIPOL_AUTH_URL`, `MINIPOL_API_SECRET` (in Apache via `SetEnv`) |
| `math/` | [config.php](math/config.php): de URL's van app en API | `MINIPOL_APP_URL`, `MINIPOL_API_URL` |
| `auth/` | [config.php](auth/config.php): de eigen URL, die van admin en cdn, en het geheim; ook de geldigheidsduur van tokens | `MINIPOL_AUTH_URL`, `MINIPOL_ADMIN_URL`, `MINIPOL_CDN_URL`, `MINIPOL_API_SECRET` |
| `app/` | [js/config.js](app/js/config.js) en de cdn-URL in [index.html](app/index.html) | Geschreven door `deploy/deploy.sh` (een browser kent geen omgevingsvariabelen) |
| `admin/` | [js/config.js](admin/js/config.js) en de cdn-URL in [index.html](admin/index.html) | Geschreven door `deploy/deploy.sh` |

Zonder omgevingsvariabelen gelden de waarden voor lokaal ontwikkelen (`localhost`), zoals in de repository.

Voor de verschillende servers:

- **API:** handelt de CORS-preflight (`OPTIONS`) zelf af. Alle requests moeten naar [api/index.php](api/index.php), en alleen die: `data/` mag niet publiek zijn. Onder Apache moet de `Authorization`-header PHP bereiken: `CGIPassAuth On` (php-fpm) of `SetEnvIf Authorization` (mod_php); de sjablonen in `deploy/` doen beide. Draait de API in een submap `api/`, dan werken de paden ook met die prefix (`/api/gesprekken`).
- **Auth service:** net als de API: alle requests naar [auth/index.php](auth/index.php), en `data/` en `bin/` mogen niet publiek zijn. Draai hem in productie alleen over HTTPS. De API moet hem kunnen bereiken voor de introspection.
- **Math server:** net als de API: alle requests naar [math/index.php](math/index.php), en `data/` mag niet publiek zijn. Hij moet de API kunnen bereiken en PHP moet urls kunnen openen (`allow_url_fopen`).
- **App en admin:** zijn alleen statische bestanden; een eigen server-router is niet nodig.
- **CDN:** moet `Access-Control-Allow-Origin` meesturen voor de JS-bestanden. Zonder die header weigert de browser modules van een ander domein. Lokaal doet [dev/cdn.php](dev/cdn.php) dat.

De CSS en JS op de cdn gebruiken de app en de admin tegelijk: een wijziging daar raakt beide.

## Meer

- [docs/analyse.md](docs/analyse.md): hoe de groepsanalyse werkt, welke keuzes erin zitten en waar je iets aanpast.
- [docs/livegang.md](docs/livegang.md): live zetten op een VPS met Apache, en nieuwe versies uitrollen.
