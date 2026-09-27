# Beveiliging

## Een kwetsbaarheid melden

Vind je een zwakke plek in MiniPol, meld die dan **niet** in een openbaar issue. Gebruik [**Report a vulnerability**](https://github.com/jgroenen/minipol/security/advisories/new) onder het tabblad **Security** van [github.com/jgroenen/minipol](https://github.com/jgroenen/minipol) ([private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)). Alleen de beheerder van de repository ziet je melding.

Beschrijf wat je vond, hoe je het kunt nadoen, en wat iemand ermee zou kunnen. Je krijgt binnen een week antwoord. Een oplossing komt zo snel als het kan, en daarna een openbare melding, met jouw naam als je dat wilt.

Alleen de nieuwste versie van `main` krijgt oplossingen: een server die bijblijft met [deploy/uppen.sh](deploy/uppen.sh), heeft die vanzelf.

## Hoe MiniPol beveiligd is

- **Inloggen:** een willekeurig token van 256 bits, waarvan de API alleen de sha256 bewaart; wachtwoorden met `password_hash`, van minstens 12 tekens. Na 10 mislukte pogingen per uur van één IP-adres, of 20 voor één gebruikersnaam, volgt 429. Zie [Inloggen](README.md#inloggen).
- **Geen IP-adressen op de server:** de limiet op inloggen bewaart alleen een HMAC van het IP-adres, met een sleutel die elk uur nieuw is en daarna weg. Verzoeken worden niet gelogd (zie [Logging](#logging)).
- **Rollen:** elke call van de beheeromgeving controleert de rol van het account in het gesprek, zie [Beheer](README.md#beheer). Direct na installatie werkt admin/admin, alleen om de eerste superbeheerder te maken: doe dat meteen.
- **Deelnemers blijven anoniem:** hun id komt nooit terug uit de API; in de export, de matrix en het logboek zijn ze een nummer.
- **Panelleden ook:** MiniPol bewaart bij een antwoord via een panellink alleen het panel, nooit de link. Per link bewaart het alleen aantallen, zonder volgorde of tijd, en die lopen een dag achter. Wie de export én alle data heeft, kan dus nog steeds niet zien wat een panellid antwoordde. Zie [Panels](README.md#panels).
- **Geen paden van buitenaf:** alleen een gesprek-id komt in een bestandspad, en dat mag alleen letters, cijfers en `-` bevatten. Die controle zit in de functie die het pad maakt ([Data.php](api/lib/Data.php)), niet alleen in de handlers: een ongeldig id geeft een fout, nooit een pad buiten de datamap.
- **Geen id's of tokens in URL's:** de `deelnemer_id` werkt als een token en gaat daarom in `Authorization: Bearer`, net als het token van een beheerder; een kanaaltoken alleen in de body van een POST. Foutmeldingen van PHP bevatten geen argumenten (`zend.exception_ignore_args`).

## Wat hier niet nodig is

Een algemene security-checklist noemt vaak twee dingen die voor MiniPol niet gelden:

- **CSRF-tokens.** CSRF werkt alleen als de browser een inloggegeven vanzelf meestuurt, zoals een cookie: een andere site laat dan jouw browser iets doen met jouw sessie. MiniPol gebruikt geen cookies. Het token van een beheerder en de `deelnemer_id` staan in `Authorization`, en die header zet een browser nooit vanzelf. Een andere site kan ze ook niet lezen: ze staan in de `localStorage` van de admin of de app, op een ander domein. **Komen er ooit cookies bij, dan zijn CSRF-tokens wél nodig.**
- **CORS beperken tot eigen domeinen.** De API is bewust open voor elke site, als basis voor open innovatie. CORS beschermt de server niet (een aanvaller gebruikt gewoon `curl`), maar de bezoeker tegen misbruik van zijn inloggegevens door andere sites, en ook dat speelt zonder cookies niet. Wat een open API wel mogelijk maakt, en wat [kanalen](README.md#kanalen) tegengaan, staat bij [Bekende grenzen](#bekende-grenzen). Beperken kan met `TOEGESTANE_ORIGINS` in [api/config.php](api/config.php).

## Logging

MiniPol logt zelf geen verzoeken, en de [Caddyfile](Caddyfile) zet geen access log aan. Zo blijft het:
- **Een access log** (van Caddy, een proxy of een CDN) mag alleen methode, pad, status en tijd bevatten. Log nooit de header `Authorization` of de inhoud van verzoeken: daar staan de `deelnemer_id` en het kanaaltoken, en samen koppelen die een panellid aan zijn antwoorden. Kort IP-adressen in.
- **Een proxy of CDN ervoor** (zoals Cloudflare)? Dan ziet PHP het IP-adres van de proxy, en telt de limiet op inloggen alle bezoekers samen. Neem dan het IP-adres uit de header van de proxy, alleen als het verzoek van die proxy komt, en laat de proxy zelf ook geen IP-adressen bewaren.
- **Blijft er dan nog iets over?** Met IP-adres en tijd uit een log, en de tijden in de data, is ruwweg te zien wanneer iemand meedeed. Maar niet via welke persoonlijke link, want die staat nergens bij een tijd.
- **In de browser:** een strikte Content-Security-Policy op elke pagina, en alle tekst van gebruikers ge-escaped.
- **De data:** nooit direct op te vragen; alles gaat via `index.php`.
- **Een open API:** elke website mag de API en de math server vanuit de browser aanroepen (CORS `*`). Voor het beheer is dat veilig: zonder cookies stuurt een pagina alleen een token mee dat hij zelf heeft.

## Bekende grenzen

- **Panels vertrouwen de server:** dat een panellid los staat van zijn link, komt doordat de code het niet vastlegt, en die code is open. Wie de server beheert en de code aanpast, kan het wel vastleggen. Een harde, wiskundige garantie geven *blinde handtekeningen* (RFC 9474, zoals in Privacy Pass): de server geeft per antwoord een munt die hij bij het inwisselen niet meer herkent. Dat is een mogelijke volgende stap.
- **Nep-antwoorden:** meedoen is anoniem, dus iemand kan met veel verschillende `deelnemer_id`s veel „deelnemers” maken en zo de groepen beïnvloeden. Doordat de API open is voor elke site, kan dat ook via de browsers van de bezoekers van een andere site. Er is nog geen limiet per IP-adres of per tijd. Wat wel kan: meedoen alleen via [kanalen](README.md#kanalen) toestaan, en een kanaal dat misbruikt wordt intrekken, en de antwoorden erdoor laten wegvallen.

## De toeleveringsketen

MiniPol leunt op zo min mogelijk anderen. Dit is alles, met hoe elk risico is afgedekt:

| Wat | Waarvoor | Afgedekt |
|---|---|---|
| PHP 8 | draaien | Van de server zelf, of in FrankenPHP. |
| [FrankenPHP](https://frankenphp.dev) | de webserver in productie (Caddy met PHP, HTTPS) | [installeer.sh](deploy/installeer.sh) installeert een **vaste versie** en controleert de download met een **sha256** vóór installatie. Een nieuwere versie is een bewuste stap: versie en checksums aanpassen, en installeer.sh opnieuw draaien. |
| [github.com/jgroenen/minipol](https://github.com/jgroenen/minipol) | [uppen.sh](deploy/uppen.sh) pullt `main` en draait die code | Tweestapsverificatie op het GitHub-account, branch protection op `main`, en een deploy key van de server die alleen mag lezen. |
| IBM Plex Sans | het font | Meegeleverd in [cdn/design/fonts/](cdn/design/fonts/): vaste bestanden, geen externe server. |
| [petstore.swagger.io](https://petstore.swagger.io) | de viewer van `/docs` | Leest alleen de openbare spec; op de eigen domeinen draait hij niet. Een token gebruikt hij alleen als iemand dat daar zelf invult. |
| Node en Chrome | alleen de browsertest | Draaien alleen bij het testen, nooit op de server. |
| [actions/checkout](https://github.com/actions/checkout) | de tests op GitHub | Vastgezet op een commit, niet op een tag die kan verschuiven. |

Er zijn geen pakketten van Composer, npm of pip: tijdens het draaien niet, en bij het testen ook niet.

**Een nieuwe afhankelijkheid** voeg je alleen toe als het echt niet anders kan. Zet hem dan vast op een versie en een checksum (of een commit), en zet hem in deze tabel.
