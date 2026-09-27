# Beveiliging

## Een kwetsbaarheid melden

Vind je een zwakke plek in MiniPol, meld die dan **niet** in een openbaar issue. Gebruik [**Report a vulnerability**](https://github.com/jgroenen/micropol/security/advisories/new) onder het tabblad **Security** van [github.com/jgroenen/micropol](https://github.com/jgroenen/micropol) ([private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)). Alleen de beheerder van de repository ziet je melding.

Beschrijf wat je vond, hoe je het kunt nadoen, en wat iemand ermee zou kunnen. Je krijgt binnen een week antwoord. Een oplossing komt zo snel als het kan, en daarna een openbare melding, met jouw naam als je dat wilt.

Alleen de nieuwste versie van `main` krijgt oplossingen: een server die bijblijft met [deploy/uppen.sh](deploy/uppen.sh), heeft die vanzelf.

## Hoe MiniPol beveiligd is

- **Inloggen:** een willekeurig token van 256 bits, waarvan de API alleen de sha256 bewaart; wachtwoorden met `password_hash`. Zie [Inloggen](README.md#inloggen).
- **Rollen:** elke call van de beheeromgeving controleert de rol van het account in het gesprek, zie [Beheer](README.md#beheer). Direct na installatie werkt admin/admin, alleen om de eerste superbeheerder te maken: doe dat meteen.
- **Deelnemers blijven anoniem:** hun id komt nooit terug uit de API; in de export, de matrix en het logboek zijn ze een nummer.
- **Panelleden ook:** MiniPol bewaart bij een antwoord via een panellink alleen het panel, nooit de link. Per link bewaart het alleen aantallen, zonder volgorde of tijd, en die lopen een dag achter. Wie de export én alle data heeft, kan dus nog steeds niet zien wat een panellid antwoordde. Zie [Panels](README.md#panels).
- **Geen id's of tokens in URL's:** de `deelnemer_id` werkt als een token en gaat daarom in `Authorization: Bearer`, net als het token van een beheerder; een kanaaltoken alleen in de body van een POST. Foutmeldingen van PHP bevatten geen argumenten (`zend.exception_ignore_args`).

## Logging

MiniPol logt zelf geen verzoeken, en de [Caddyfile](Caddyfile) zet geen access log aan. Zo blijft het:
- **Een access log** (van Caddy, een proxy of een CDN) mag alleen methode, pad, status en tijd bevatten. Log nooit de header `Authorization` of de inhoud van verzoeken: daar staan de `deelnemer_id` en het kanaaltoken, en samen koppelen die een panellid aan zijn antwoorden. Kort IP-adressen in.
- **Blijft er dan nog iets over?** Met IP-adres en tijd uit een log, en de tijden in de data, is ruwweg te zien wanneer iemand meedeed. Maar niet via welke persoonlijke link, want die staat nergens bij een tijd.
- **In de browser:** een strikte Content-Security-Policy op elke pagina, en alle tekst van gebruikers ge-escaped.
- **De data:** nooit direct op te vragen; alles gaat via `index.php`.
- **Een open API:** elke website mag de API en de math server vanuit de browser aanroepen (CORS `*`). Voor het beheer is dat veilig: zonder cookies stuurt een pagina alleen een token mee dat hij zelf heeft.

## Bekende grenzen

- **Panels vertrouwen de server:** dat een panellid los staat van zijn link, komt doordat de code het niet vastlegt, en die code is open. Wie de server beheert en de code aanpast, kan het wel vastleggen. Een harde, wiskundige garantie geven *blinde handtekeningen* (RFC 9474, zoals in Privacy Pass): de server geeft per antwoord een munt die hij bij het inwisselen niet meer herkent. Dat is een mogelijke volgende stap.
- **Nep-antwoorden:** meedoen is anoniem, dus iemand kan met veel verschillende `deelnemer_id`s veel „deelnemers” maken en zo de groepen beïnvloeden. Doordat de API open is voor elke site, kan dat ook via de browsers van de bezoekers van een andere site. Er is nog geen limiet per IP-adres of per tijd. Wat wel kan: meedoen alleen via [kanalen](README.md#kanalen) toestaan, en een kanaal dat misbruikt wordt intrekken, en de antwoorden erdoor laten wegvallen.
- **Inloggen:** er is nog geen limiet op het aantal inlogpogingen. Wachtwoorden zijn minstens 12 tekens.

## De toeleveringsketen

MiniPol leunt op zo min mogelijk anderen. Dit is alles, met hoe elk risico is afgedekt:

| Wat | Waarvoor | Afgedekt |
|---|---|---|
| PHP 8 | draaien | Van de server zelf, of in FrankenPHP. |
| [FrankenPHP](https://frankenphp.dev) | de webserver in productie (Caddy met PHP, HTTPS) | [installeer.sh](deploy/installeer.sh) installeert een **vaste versie** en controleert de download met een **sha256** vóór installatie. Een nieuwere versie is een bewuste stap: versie en checksums aanpassen, en installeer.sh opnieuw draaien. |
| [github.com/jgroenen/micropol](https://github.com/jgroenen/micropol) | [uppen.sh](deploy/uppen.sh) pullt `main` en draait die code | Tweestapsverificatie op het GitHub-account, branch protection op `main`, en een deploy key van de server die alleen mag lezen. |
| IBM Plex Sans | het font | Meegeleverd in [cdn/design/fonts/](cdn/design/fonts/): vaste bestanden, geen externe server. |
| [petstore.swagger.io](https://petstore.swagger.io) | de viewer van `/docs` | Leest alleen de openbare spec; op de eigen domeinen draait hij niet. Een token gebruikt hij alleen als iemand dat daar zelf invult. |
| Node en Chrome | alleen de browsertest | Draaien alleen bij het testen, nooit op de server. |
| [actions/checkout](https://github.com/actions/checkout) | de tests op GitHub | Vastgezet op een commit, niet op een tag die kan verschuiven. |

Er zijn geen pakketten van Composer, npm of pip: tijdens het draaien niet, en bij het testen ook niet.

**Een nieuwe afhankelijkheid** voeg je alleen toe als het echt niet anders kan. Zet hem dan vast op een versie en een checksum (of een commit), en zet hem in deze tabel.
