# Livegang: MiniPol op een VPS met Apache

Alle delen draaien op één VPS, elk op een eigen subdomein:

| Subdomein | Deel |
|---|---|
| `www.<domein>` | app (het kale `<domein>` stuurt hierheen door) |
| `admin.<domein>` | admin |
| `api.<domein>` | API |
| `auth.<domein>` | auth service |
| `cdn.<domein>` | cdn |
| `math.<domein>` | math server |

Het uitrollen gaat met [deploy/deploy.sh](../deploy/deploy.sh). Dat script leest je instellingen uit `deploy/productie.env` en regelt de rest:

- **Configs:** de PHP-delen krijgen hun URL's en het geheim via `SetEnv` in Apache. In de app en de admin schrijft het script `js/config.js`, en de URL van de cdn in `index.html`.
- **Apache:** de vhosts voor alle subdomeinen, uit [deploy/apache-http.conf.sjabloon](../deploy/apache-http.conf.sjabloon) en [deploy/apache-https.conf.sjabloon](../deploy/apache-https.conf.sjabloon).
- **HTTPS:** één certificaat van Let's Encrypt voor alle subdomeinen. Het wordt vanzelf vernieuwd.
- **De code:** gaat met rsync naar de server. De data op de server (`api/data`, `math/data`, `auth/data`) wordt nooit overschreven.
- **Controle:** na elke uitrol kijkt het script of alles bereikbaar is.

## Eenmalig

### 1. De VPS

Neem bij TransIP een VPS met **Ubuntu 24.04**. Het script installeert zelf Apache, PHP (mod_php) en certbot.

Maak een gebruiker om mee uit te rollen, met sudo en je SSH-sleutel. Log daarvoor als root in op de VPS:

```sh
adduser deploy
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
```

Zet de firewall open voor SSH, HTTP en HTTPS:

```sh
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable
```

### 2. DNS

Maak bij je domein A-records (en AAAA voor IPv6) naar het IP-adres van de VPS, voor `@`, `www`, `admin`, `api`, `auth`, `cdn` en `math`. Een wildcard-record `*` mag ook, maar `@` moet er apart bij.

Wacht tot de namen naar de VPS wijzen, bijvoorbeeld met `dig +short api.<domein>`. Let's Encrypt controleert elke naam via de VPS.

### 3. De instellingen

```sh
cp deploy/productie.env.voorbeeld deploy/productie.env
openssl rand -hex 32    # het geheim van de API bij de auth service
```

Vul in `deploy/productie.env` het domein, de server (`deploy@<ip of naam>`), het e-mailadres voor Let's Encrypt en het geheim in. Dit bestand staat niet in git.

### 4. Installeren en uitrollen

```sh
./deploy/deploy.sh installeer
```

Dit installeert Apache met PHP, maakt de datamappen aan, haalt het certificaat, zet de vhosts neer en rolt de code uit. Sudo vraagt daarbij soms om het wachtwoord van `deploy`.

### 5. De eerste beheerder

```sh
./deploy/deploy.sh beheerder <naam> <email>
```

Het script vraagt het wachtwoord op de server. Log daarna in op `https://admin.<domein>`.

### 6. Bestaande data meenemen (optioneel)

Wil je de gesprekken van je laptop meenemen, kopieer dan de data vóórdat er op de server iets is aangemaakt, en geef hem aan `www-data`:

```sh
rsync -a api/data/ deploy@<server>:/tmp/api-data/
ssh -t deploy@<server> 'sudo rsync -a /tmp/api-data/ /var/www/minipol/api/data/ && sudo chown -R www-data:www-data /var/www/minipol/api/data && rm -rf /tmp/api-data'
```

Met `auth/data` (de beheerders) en `math/data` (de analysemodellen) gaat het op dezelfde manier. De analysemodellen worden ook vanzelf opnieuw berekend.

## Daarna: een nieuwe versie uitrollen

```sh
./deploy/deploy.sh uitrollen
```

Andere commando's:

| Commando | Wat |
|---|---|
| `./deploy/deploy.sh bouw` | Alleen lokaal bouwen, in `deploy/build/productie/`, om te bekijken wat er naar de server gaat |
| `./deploy/deploy.sh controleer` | Kijken of alle delen bereikbaar zijn |
| `OMGEVING=test ./deploy/deploy.sh uitrollen` | Een andere omgeving, met instellingen uit `deploy/test.env` |

## Op de server

| Wat | Waar |
|---|---|
| De code | `/var/www/minipol/<deel>/` (van `deploy`) |
| De data | `/var/www/minipol/{api,math,auth}/data/` (van `www-data`) |
| Apache-config | `/etc/apache2/sites-available/minipol-http.conf` en `minipol-https.conf`; het tweede bevat het geheim en is alleen voor root leesbaar |
| Certificaat | `/etc/letsencrypt/live/<domein>/`; vernieuwen gaat vanzelf (`systemctl list-timers certbot`) |
| Logs | `/var/log/apache2/error.log` |

**Pas de Apache-config niet op de server aan.** De volgende uitrol overschrijft hem; pas de sjablonen in `deploy/` aan.

## Back-ups

Alle data staat in de drie `data`-mappen. De bestanden worden alleen aangevuld, dus een kopie is altijd consistent genoeg. Twee opties:

- **Snapshots van de VPS** bij TransIP.
- **Zelf ophalen**, bijvoorbeeld dagelijks vanaf een andere machine:

```sh
rsync -a --rsync-path="sudo rsync" deploy@<server>:/var/www/minipol/api/data/ backup/api-data/
```

## Wat het script niet doet

- De VPS zelf beveiligen, zoals automatische updates (`unattended-upgrades`), inloggen met alleen een SSH-sleutel, en fail2ban. Die zijn wel aan te raden.
- Een beperking op het aantal inlogpogingen bij de auth service.
- Opruimen van verlopen tokens in `auth/data`.
