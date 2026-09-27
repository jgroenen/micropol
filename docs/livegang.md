# Livegang: MiniPol op een VPS met FrankenPHP

Alles draait op één VPS, met [FrankenPHP](https://frankenphp.dev): dat is de webserver Caddy met PHP erin. Caddy regelt ook HTTPS: hij haalt zelf certificaten bij Let's Encrypt en vernieuwt ze. Er is geen Apache, php-fpm of certbot nodig.

Elk deel draait op een eigen subdomein:

| Subdomein | Deel |
|---|---|
| `www.<domein>` | de productpagina (het kale `<domein>` stuurt hierheen door) |
| `app.<domein>` | app |
| `admin.<domein>` | admin |
| `api.<domein>` | API |
| `math.<domein>` | math server |
| `cdn.<domein>` | cdn: het design system en de bibliotheken |

De repository staat op de VPS in `/srv/minipol`. Een nieuwe versie uitrollen is `git pull`.

- **De [Caddyfile](../Caddyfile)** in de repository beschrijft alle subdomeinen. Hij geeft elk PHP-deel de URL's van de andere mee.
- **Instellingen:** alleen het domein en een e-mailadres, in `/etc/minipol.env`.
- **De data** (`api/data`, `math/data`) staat niet in git, dus een pull raakt hem nooit.

## Eenmalig

### 1. De VPS

Neem een VPS met **Ubuntu 24.04** (of Debian). 1 GB geheugen is genoeg; het installatiescript zet er een klein swapbestand bij.

Zet de firewall open voor SSH, HTTP en HTTPS, als root:

```sh
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable
```

### 2. DNS

Maak bij je domein A-records (en AAAA voor IPv6) naar het IP-adres van de VPS, voor `@`, `www`, `app`, `admin`, `api`, `math` en `cdn`. Een wildcard-record `*` mag ook, maar `@` moet er apart bij.

Wacht tot de namen naar de VPS wijzen, bijvoorbeeld met `dig +short api.<domein>`. Caddy haalt de certificaten pas als dat zo is.

### 3. De repository op de VPS

De code staat op [github.com/jgroenen/minipol](https://github.com/jgroenen/minipol). Als root op de VPS:

```sh
apt install -y git curl
git clone https://github.com/jgroenen/minipol.git /srv/minipol
```

Een openbare repository haalt de VPS zo op, ook bij elke update, zonder sleutel.

**Een privé-repository**, zoals een eigen kopie, vraagt een *deploy key*: een SSH-sleutel die alleen die repository mag lezen.
1. Maak de sleutel op de VPS: `ssh-keygen -t ed25519 -f /root/minipol-deploy -N ''`.
2. Zet `/root/minipol-deploy.pub` bij de repository op GitHub, onder *Settings → Deploy keys*. Zet *Allow write access* niet aan: de server hoeft alleen te lezen.
3. Clone met: `GIT_SSH_COMMAND='ssh -i /root/minipol-deploy' git clone git@github.com:jgroenen/minipol.git /srv/minipol` (met de naam van je eigen repository).
4. Na het installeren doet de gebruiker `minipol` de pulls. Zet de sleutel daarom ook in `/var/lib/minipol/.ssh/id_ed25519`, van de gebruiker `minipol` en met rechten `600`.

### 4. Installeren

```sh
/srv/minipol/deploy/installeer.sh <domein> <email>
```

[installeer.sh](../deploy/installeer.sh) doet het volgende:
- installeert FrankenPHP in `/usr/local/bin`;
- maakt de gebruiker `minipol`, die de repository bezit en de dienst draait;
- schrijft `/etc/minipol.env`;
- zet swap aan;
- start de dienst `minipol` ([minipol.service](../deploy/minipol.service)).

Bij de eerste requests haalt Caddy de certificaten. Opnieuw draaien kan geen kwaad.

### 5. De eerste superbeheerder

Ga naar `https://admin.<domein>` en log in met **admin/admin**. Je maakt dan meteen je eigen account: de eerste superbeheerder, met gebruikersnaam, e-mailadres en wachtwoord. Daarna werkt admin/admin niet meer.

Doe dit direct na het installeren: tot die tijd kan iedereen die admin/admin probeert, de eerste superbeheerder maken.

### 6. Bestaande data meenemen (optioneel)

Wil je de gesprekken van je laptop meenemen, kopieer dan `api/data` naar de VPS voordat daar iets is aangemaakt, en geef hem aan `minipol`:

```sh
rsync -a api/data/ root@<vps>:/srv/minipol/api/data/
ssh root@<vps> 'chown -R minipol:minipol /srv/minipol/api/data'
```

Met `math/data` gaat het net zo, maar dat hoeft niet: de analysemodellen worden vanzelf opnieuw berekend.

Is de data van een oudere versie, breng hem dan bij met de [migraties](../README.md#migraties). Dat doet `uppen.sh` bij een nieuwe versie vanzelf, maar na zo'n kopie draai je ze één keer zelf:

```sh
sudo -u minipol frankenphp php-cli /srv/minipol/api/bin/migreer.php
```

Hadden de beheerders nog `beheerders.csv`, dan zijn ze daarna superbeheerder, en is stap 5 niet nodig.

## Een nieuwe versie uitrollen

Push de code naar [github.com/jgroenen/minipol](https://github.com/jgroenen/minipol). Kijk bij [Actions](https://github.com/jgroenen/minipol/actions) of de tests slagen, en dan vanaf je laptop:

```sh
ssh root@<vps> /srv/minipol/deploy/uppen.sh
```

[uppen.sh](../deploy/uppen.sh) doet:
1. kijken of er een nieuwe versie is; zo niet, dan stopt het;
2. een **back-up** van `api/data` maken, in `/var/backups/minipol/<datum>-<commit>.tar.gz`. De laatste tien blijven bewaard;
3. `git pull`;
4. de **migraties** draaien: zo komt de data in de vorm die de nieuwe code verwacht (zie [Migraties](../README.md#migraties));
5. Caddy laten herladen, zonder onderbreking, en controleren of de API antwoordt.

Veranderde PHP-bestanden gebruikt FrankenPHP meteen. Tussen de pull en de migraties draait de nieuwe code heel even op de oude data. Dat duurt hooguit een paar seconden.

### Terugzetten

Mislukt een migratie, of werkt de nieuwe versie niet, dan zet [terugzetten.sh](../deploy/terugzetten.sh) de code én de data terug naar een back-up. `uppen.sh` noemt in dat geval het commando al:

```sh
ssh root@<vps> /srv/minipol/deploy/terugzetten.sh /var/backups/minipol/<datum>-<commit>.tar.gz
```

Zonder back-up toont het de back-ups die er zijn. De data van vóór het terugzetten blijft bewaard in `api/data-<datum>`. Wat er sinds de back-up bij kwam, staat daarin. De volgende `uppen.sh` haalt de nieuwste versie weer op, dus los eerst het probleem op.

## Controleren

De tests kunnen ook tegen de echte server draaien. Dan maken ze testdata aan op die server, dus doe dat alleen op een testserver, niet in productie. Zie de bovenkant van [tests/api.php](../tests/api.php) en [tests/browser.mjs](../tests/browser.mjs) voor de variabelen, zoals `TEST_API_URL`.

Voor productie is dit genoeg:

```sh
curl -I https://www.<domein>/ && curl -I https://app.<domein>/ && curl https://api.<domein>/sessie
```

## Op de server

| Wat | Waar |
|---|---|
| De code | `/srv/minipol` (van `minipol`) |
| De data | `/srv/minipol/api/data` en `/srv/minipol/math/data` |
| Instellingen | `/etc/minipol.env` |
| Certificaten | `/var/lib/minipol/caddy/`; vernieuwen gaat vanzelf |
| Logs | `journalctl -u minipol` (de PHP-fouten, zonder argumenten); er is geen access log, zie [Logging in SECURITY.md](../SECURITY.md#logging) |
| Herstarten | `systemctl restart minipol` |

Pas op de server niets aan in `/srv/minipol`: de volgende pull verwacht een schone repository. Pas het aan in git en rol uit.

## Back-ups

Alle data staat in `api/data`: de events van de gesprekken en het beheer (accounts, teams, uitnodigingen), en de logins. De bestanden worden alleen aangevuld, dus een kopie is altijd consistent genoeg.

`uppen.sh` maakt bij elke nieuwe versie een back-up in `/var/backups/minipol/`, maar die staat op dezelfde VPS. Zorg daarom ook voor een kopie elders. Twee opties:

- **Snapshots van de VPS** bij je provider.
- **Zelf ophalen**, bijvoorbeeld dagelijks vanaf een andere machine:

```sh
rsync -a root@<vps>:/srv/minipol/api/data/ backup/api-data/
```

## Wat het script niet doet

- De VPS zelf beveiligen, zoals automatische updates (`unattended-upgrades`), inloggen met alleen een SSH-sleutel, en fail2ban. Die zijn wel aan te raden.
- FrankenPHP bijwerken. `installeer.sh` installeert een vaste versie, en controleert de download met een sha256. Voor een nieuwere versie pas je in `installeer.sh` `FRANKENPHP_VERSIE` en de twee checksums aan (die staan bij de release op GitHub). Rol dat uit, en draai `installeer.sh` daarna nog een keer. Zie [SECURITY.md](../SECURITY.md).
- Het opruimen van verlopen logins in `api/data/sessies.csv`.
- Een proxy of CDN voor de server regelen: zie [Logging in SECURITY.md](../SECURITY.md#logging) voor wat er dan bij het IP-adres verandert.
