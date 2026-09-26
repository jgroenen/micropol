# Livegang: MiniPol op een VPS met FrankenPHP

Alles draait op één VPS, met [FrankenPHP](https://frankenphp.dev): dat is de webserver Caddy met PHP erin. Caddy regelt ook HTTPS: hij haalt zelf certificaten bij Let's Encrypt en vernieuwt ze. Er is geen Apache, php-fpm of certbot nodig.

Elk deel draait op een eigen subdomein:

| Subdomein | Deel |
|---|---|
| `www.<domein>` | app (het kale `<domein>` stuurt hierheen door) |
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

Maak bij je domein A-records (en AAAA voor IPv6) naar het IP-adres van de VPS, voor `@`, `www`, `admin`, `api`, `math` en `cdn`. Een wildcard-record `*` mag ook, maar `@` moet er apart bij.

Wacht tot de namen naar de VPS wijzen, bijvoorbeeld met `dig +short api.<domein>`. Caddy haalt de certificaten pas als dat zo is.

### 3. De repository op de VPS

De repository moet ergens staan waar de VPS hem kan ophalen, bijvoorbeeld op GitHub. Als root op de VPS:

```sh
apt install -y git curl
git clone <url van de repository> /srv/minipol
```

**Is de repository privé**, gebruik dan een *deploy key*: een SSH-sleutel die alleen deze repository mag lezen.
1. Maak de sleutel op de VPS: `ssh-keygen -t ed25519 -f /root/minipol-deploy -N ''`.
2. Zet `/root/minipol-deploy.pub` bij GitHub onder *Settings → Deploy keys*.
3. Clone met: `GIT_SSH_COMMAND='ssh -i /root/minipol-deploy' git clone git@github.com:<jij>/<repo>.git /srv/minipol`.
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

### 5. De eerste beheerder

```sh
sudo -u minipol frankenphp php-cli /srv/minipol/api/bin/beheerder-toevoegen.php <gebruikersnaam> <email>
```

Het script vraagt het wachtwoord. Log daarna in op `https://admin.<domein>`.

### 6. Bestaande data meenemen (optioneel)

Wil je de gesprekken van je laptop meenemen, kopieer dan `api/data` naar de VPS voordat daar iets is aangemaakt, en geef hem aan `minipol`:

```sh
rsync -a api/data/ root@<vps>:/srv/minipol/api/data/
ssh root@<vps> 'chown -R minipol:minipol /srv/minipol/api/data'
```

Met `math/data` gaat het net zo, maar dat hoeft niet: de analysemodellen worden vanzelf opnieuw berekend.

Is de data van vóór de events (met `gesprekken.csv` in plaats van `gesprekken.jsonl`), zet hem dan eenmalig om. Zie [Opslag](../README.md#opslag) in de README.

```sh
sudo -u minipol frankenphp php-cli /srv/minipol/api/bin/naar-events.php
```

## Een nieuwe versie uitrollen

Push de code naar de repository, en dan op de VPS:

```sh
/srv/minipol/deploy/uppen.sh
```

[uppen.sh](../deploy/uppen.sh) doet `git pull` en laat Caddy de `Caddyfile` opnieuw laden, zonder onderbreking. Veranderde PHP-bestanden gebruikt FrankenPHP meteen. Aan het eind controleert het script of de API antwoordt.

Vanaf je laptop kan het in één keer: `ssh root@<vps> /srv/minipol/deploy/uppen.sh`.

## Controleren

De tests kunnen ook tegen de echte server draaien. Dan maken ze testdata aan op die server, dus doe dat alleen op een testserver, niet in productie. Zie de bovenkant van [tests/api.py](../tests/api.py) en [tests/browser.mjs](../tests/browser.mjs) voor de variabelen, zoals `TEST_API_URL`.

Voor productie is dit genoeg:

```sh
curl -I https://www.<domein>/ && curl https://api.<domein>/sessie
```

## Op de server

| Wat | Waar |
|---|---|
| De code | `/srv/minipol` (van `minipol`) |
| De data | `/srv/minipol/api/data` en `/srv/minipol/math/data` |
| Instellingen | `/etc/minipol.env` |
| Certificaten | `/var/lib/minipol/caddy/`; vernieuwen gaat vanzelf |
| Logs | `journalctl -u minipol` (ook de PHP-fouten) |
| Herstarten | `systemctl restart minipol` |

Pas op de server niets aan in `/srv/minipol`: de volgende pull verwacht een schone repository. Pas het aan in git en rol uit.

## Back-ups

Alle data staat in `api/data`: de events van de gesprekken, en de beheerders en logins. De bestanden worden alleen aangevuld, dus een kopie is altijd consistent genoeg. Twee opties:

- **Snapshots van de VPS** bij je provider.
- **Zelf ophalen**, bijvoorbeeld dagelijks vanaf een andere machine:

```sh
rsync -a root@<vps>:/srv/minipol/api/data/ backup/api-data/
```

## Wat het script niet doet

- De VPS zelf beveiligen, zoals automatische updates (`unattended-upgrades`), inloggen met alleen een SSH-sleutel, en fail2ban. Die zijn wel aan te raden.
- FrankenPHP bijwerken. Haal daarvoor een nieuwe versie naar `/usr/local/bin/frankenphp` en herstart de dienst.
- Een beperking op het aantal inlogpogingen, en het opruimen van verlopen logins in `api/data/sessies.csv`.
