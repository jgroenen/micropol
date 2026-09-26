# MiniPol

Een kleine, Polis-achtige tool voor gesprekken. Deelnemers beantwoorden stellingen met *eens*, *neutraal* of *oneens* en kunnen zelf stellingen toevoegen. De analyse deelt deelnemers in groepen in die stellingen op een vergelijkbare manier beantwoorden.

Er is geen database, er zijn geen dependencies en er is geen build-stap: een PHP-backend die csv-bestanden bijhoudt, en frontends in vanilla JavaScript (ES modules).

Het bestaat uit vijf delen die elk op een eigen server (en domein) draaien:

| Deel | Wat | Lokaal |
|---|---|---|
| `api/` | De JSON-API in PHP, met de data | <http://localhost:8001> |
| `app/` | De frontend voor deelnemers (statische bestanden) | <http://localhost:8000> |
| `admin/` | De beheeromgeving (statische bestanden) | <http://localhost:8002> |
| `cdn/` | CSS en JS die de app en de admin delen (statische bestanden) | <http://localhost:8003> |
| `math/` | De math server: rekent de groepsanalyse uit de export van een API, zie [Math server](#math-server) | <http://localhost:8004> |

```
app ──▶ api ◀── admin
 │       ▲
 └─▶ math┘  (haalt GET /export)
app en admin laden gedeelde css/js van de cdn
```

## Starten

Vereist PHP 8 of hoger. Start de vijf servers met:

```sh
./dev/start.sh
```

Open daarna <http://localhost:8000> (app) of <http://localhost:8002> (beheer). Ctrl-C stopt ze allemaal.

## Structuur

| Pad | Wat |
|---|---|
| `api/index.php` | JSON-API: `/<resource>[/<id>]` gaat naar `handlers/<Resource>Handler-><METHOD>($id)` |
| `api/config.php` | Instellingen per omgeving: welke origins de API mogen aanroepen, zie [Losse servers](#losse-servers) |
| `api/handlers/` | Eén handler per resource |
| `api/lib/` | Gedeelde code: csv-opslag (`Csv`, `Data`), HTTP (`Http`) en inloggen (`Sessie`, `Wachtwoord`) |
| `api/data/` | De data, zie [Opslag](#opslag) |
| `api/bin/` | Scripts voor de command line, zie [Beheer](#beheer) |
| `app/` | Frontend: `index.html`, `js/`, `css/` en `views/`; `js/config.js` zegt waar de API is |
| `admin/` | Beheeromgeving, zie [Beheer](#beheer); zelfde opbouw als `app/`, `js/config.js` zegt waar de API en de app zijn |
| `cdn/` | Gedeeld door app en admin: de basisstijlen (`css/main.css` en wat die importeert), `js/util.js` en de API-popup (`js/apilog.js`, `css/apilog.css`) |
| `math/` | De math server, zelfde opbouw als `api/`: `index.php`, `config.php`, `handlers/`, `lib/` (`Analyse`, `AnalyseModel`, `Export`) en `data/` (de berekende modellen) |
| `dev/` | Alleen voor lokaal ontwikkelen: `start.sh` en de router voor de cdn |
| `docs/` | Achtergronddocumentatie |

## API

Alle requests en responses zijn JSON. Fouten komen terug als `{ "error": "..." }` met een passende statuscode.

| Methode en pad | Wat |
|---|---|
| `GET /gesprekken` | Alle gesprekken |
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
| `GET /sessie` | De beheerder van het token: `{ user: { id, username, email } }`, of `{ user: null }` zonder geldig token |
| `POST /sessie` | Inloggen in de beheeromgeving: `{ username, password }`, geeft `{ user, token, verloopt }` |
| `DELETE /sessie` | Uitloggen: het token werkt daarna niet meer |

„Alleen voor ingelogde beheerders” betekent: met de header `Authorization: Bearer <token>`, anders volgt 401.

In de app en de admin toont de knop **{ } API** de API-calls van de huidige pagina, met request en response. Het wachtwoord van de login staat daar niet in.

## Opslag

Alle data staat als csv in `api/data/`:

| Bestand | Kolommen |
|---|---|
| `gesprekken.csv` | `id, titel, omschrijving, moderatie` |
| `stellingen.csv` | `id, gesprek_id, content, user_id` (leeg bij stellingen van vóór die kolom) |
| `antwoorden/<gesprek_id>.csv` | `user_id, stelling_id, waarde` |
| `beoordelingen/<gesprek_id>.csv` | `stelling_id, beoordeling, reden, user_id, tijdstip` (`user_id` is de beheerder) |
| `users.csv` | `id, username, email, salt, encrypted_password, password_method` (beheerders, zie [Beheer](#beheer)) |
| `sessies.csv` | `token_hash, user_id, verloopt` (logins van beheerders; de sha256 van het token, `verloopt` als unix-tijd) |

De bestanden worden alleen aangevuld, nooit gewijzigd. Beantwoordt iemand een stelling opnieuw, dan telt het laatste antwoord. Zo werkt het ook bij een aangepast gesprek (een nieuwe regel met hetzelfde `id` in `gesprekken.csv`) en bij een nieuwe beoordeling van een stelling: de laatste regel telt. Gesprekken worden aangemaakt in de [beheeromgeving](#beheer).

Onder Apache blokkeert `api/data/.htaccess` directe toegang tot de data. Gebruik je een andere webserver, zorg dan zelf dat `api/data/` niet publiek bereikbaar is.

## Deelnemers

Een deelnemer is een willekeurige `user_id` (UUID) die de browser in `localStorage` bewaart. Er is geen login.

## Beheer

De beheeromgeving staat lokaal op <http://localhost:8002>. Beheerders staan in `api/data/users.csv` en worden toegevoegd met:

```sh
php api/bin/user-toevoegen.php <username> <email>
```

Het script vraagt het wachtwoord (minstens 12 tekens), zodat het niet in de shell-geschiedenis belandt.

`password_method` zegt hoe `encrypted_password` is gemaakt, zodat er later een andere methode bij kan zonder bestaande gebruikers te breken. Nu is dat altijd `password_hash`: PHP's `password_hash()`. Die hash bevat zelf het algoritme en de salt, dus de kolom `salt` blijft leeg.

Inloggen geeft een token. Een cookie kan niet: de admin draait op een ander domein dan de API, en browsers blokkeren dan third-party cookies. De admin bewaart het token in `localStorage` en stuurt het mee als `Authorization: Bearer <token>`.

- **Geldigheid:** een token is 12 uur geldig (`Sessie::GELDIG`).
- **Opslag:** de API bewaart alleen de sha256 van het token.
- **Uitloggen:** voegt voor datzelfde token een regel toe die meteen verloopt.
- **Beheerders-handlers:** een handler die alleen voor beheerders is, begint met `Sessie::vereisUser()`.

## Moderatie

Per gesprek stelt een beheerder in hoe stellingen van deelnemers worden getoond (`moderatie` in `gesprekken.csv`):

| `moderatie` | Stelling is zichtbaar |
|---|---|
| `achteraf` | tenzij afgekeurd (blacklist) |
| `vooraf` | alleen als goedgekeurd (whitelist) |

Een stelling die niet zichtbaar is, verdwijnt uit het gesprek, de matrix en de analyse, en kan niet meer beantwoord worden. De indiener ziet onder **Mijn stellingen** dat een stelling nog niet is goedgekeurd, of dat hij is afgekeurd, met de reden. Wie een stelling heeft ingediend, ziet een beheerder niet.

Beheerders beoordelen stellingen op de detailpagina van een gesprek in de beheeromgeving. Een beoordeling is altijd te herzien; de laatste telt.

## Math server

De groepsanalyse draait op een eigen server, de math server (`math/`). Die heeft zelf geen data: hij haalt de standaardexport van een gesprek op bij een API en rekent daaruit de groepen. De app vraagt de analyse aan de math server en geeft mee om welk gesprek het gaat, en op welke API:

`GET /analyse?api=<url van de api>&gesprek_id=<id>[&herbereken=1]` (op de math server)

De math server haalt alleen exports op bij API's die in zijn `config.php` staan (`TOEGESTANE_APIS`). Anders zou iedereen hem elke url kunnen laten ophalen. Het model wordt per API en gesprek bewaard in `math/data/analyse/<bron>/<gesprek_id>/`, waarbij `<bron>` een korte hash van de API-url is. Het antwoord en de werking van de analyse staan in [docs/analyse.md](docs/analyse.md).

Is de math server niet bereikbaar, dan werkt de rest gewoon: beantwoorden en de matrix blijven werken, alleen de groepen ontbreken.

### Exportformaat

`GET /export?gesprek_id=<id>` op de API geeft:

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
- **Versie:** verandert het formaat op een manier die lezers breekt, dan gaat `versie` omhoog. De math server controleert `formaat` en `versie`.

## Losse servers

De app, de admin, de API, de math server en de cdn draaien elk op een eigen server en domein. Per omgeving pas je aan:

| Deel | Bestand | Wat |
|---|---|---|
| `api/` | `config.php` | `TOEGESTANE_ORIGINS`: de origins van de app en de admin (CORS) |
| `app/` | `js/config.js` | `API_URL` en `MATH_URL` |
| `app/` | `index.html` | De url van de cdn: de stylesheet en de import map (`cdn/`) |
| `admin/` | `js/config.js` | `API_URL` en `APP_URL` (voor de links naar de app) |
| `admin/` | `index.html` | De url van de cdn, zoals bij de app |
| `math/` | `config.php` | `TOEGESTANE_ORIGINS`: de origin van de app (CORS); `TOEGESTANE_APIS`: de API's waarvan hij exports mag ophalen |

Voor de verschillende servers:

- **API:** handelt de CORS-preflight (`OPTIONS`) zelf af. Alle requests moeten naar `api/index.php`, en alleen die: `data/` en `bin/` mogen niet publiek zijn. Onder Apache moet de `Authorization`-header PHP bereiken; zet daarvoor `CGIPassAuth On`. Draait de API in een submap `api/`, dan werken de paden ook met die prefix (`/api/gesprekken`).
- **Math server:** net als de API: alle requests naar `math/index.php`, en `data/` mag niet publiek zijn. Hij moet de API kunnen bereiken en PHP moet urls kunnen openen (`allow_url_fopen`).
- **App en admin:** zijn alleen statische bestanden; een eigen server-router is niet nodig.
- **CDN:** moet `Access-Control-Allow-Origin` meesturen voor de JS-bestanden. Zonder die header weigert de browser modules van een ander domein. Lokaal doet `dev/cdn.php` dat.

De CSS en JS op de cdn gebruiken de app en de admin tegelijk: een wijziging daar raakt beide.

## Meer

- [docs/analyse.md](docs/analyse.md): hoe de groepsanalyse werkt, welke keuzes erin zitten en waar je iets aanpast.
