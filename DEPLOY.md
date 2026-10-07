# Deployen naar de Raspberry Pi

Dit werkt op dezelfde manier als `uitzet-tracker-new`. Een self-hosted GitHub-runner op de Pi werkt de checkout in `/opt/docker/apps/` bij en bouwt de containers op de Pi zelf. De app hangt aan het Docker-netwerk `proxy` van je reverse proxy en publiceert zelf geen poort.

De app (website, queue, database, Redis) draait op de Pi. Het zware werk doen de workers op de laptop, de pc en de Mac. Die melden zich bij de app aan (zie `worker/.env.example`).

```
push naar master
  └─ GitHub Actions (.github/workflows/ci.yml)
       ├─ Laravel-tests + worker-tests op GitHub   (ook bij elke pull request)
       └─ runner op de Pi: /opt/docker/apps/creator-smp-analyser/scripts/deploy.sh <commit>
            ├─ haalt master op en zet de commit klaar
            ├─ bouwt de image op de Pi (de oude versie draait door)
            ├─ wacht tot er geen transcriptie/analyse/download loopt (max. 60 min)
            ├─ maakt een backup van de database (backups/, laatste 14)
            ├─ draait de migraties
            └─ start de nieuwe versie en wacht tot /up antwoordt
```

## 1. Een runner voor deze repo op de Pi

Je GitHub-account is een persoonlijk account. Daar hoort een self-hosted runner bij **één** repo. De runner van `uitzet-tracker-new` pakt de jobs van deze repo dus niet op. Zet ernaast een tweede runner, in een eigen map:

1. Ga in GitHub naar deze repo: *Settings → Actions → Runners → New self-hosted runner* en kies Linux, ARM64.
2. Voer de getoonde commando's uit op de Pi, in een nieuwe map (bijv. `~/actions-runner-creatorsmp4`, naast die van de uitzet-tracker). Doe dat als dezelfde gebruiker als de bestaande runner. Die gebruiker heeft al Docker-rechten en schrijfrechten op `/opt/docker/apps`. De standaardlabels `self-hosted, linux, ARM64` zijn precies wat de workflow vraagt.
3. Installeer de runner als service, zodat hij na een herstart weer draait:

   ```bash
   sudo ./svc.sh install && sudo ./svc.sh start
   ```

   Typ dit op één regel. Als het over twee regels wordt geplakt, draait `sudo` zonder commando en faalt `./svc.sh start` met "Must run as sudo".

Op de Pi staat deze runner nu als `creatorsmp4-pi` in `~/actions-runner-creatorsmp4`, met de service `actions.runner.thvrijn-creator-smp-analyser.creatorsmp4-pi`. Logs: `journalctl -u 'actions.runner.thvrijn-creator-smp-analyser.*' -f`.

De deploy-job haalt de code op met het token van de run en heeft dus geen GitHub-sleutel op de Pi nodig. Wil je vanaf de Pi pushen, dan staat er een wachtwoord op `~/.ssh/id_ed25519`. Ontgrendel die dan eerst in je eigen terminal met `ssh-add ~/.ssh/id_ed25519`. Dat blijft gelden tot de Pi herstart.

De repo is publiek. Daarom draait alleen een push naar `master` op de Pi. Pull requests (ook van anderen) gebruiken alleen de runners van GitHub. Laat bij *Settings → Actions → General* de goedkeuring voor workflows van externe bijdragers aan staan.

## 2. Eenmalig: de checkout en `.env`

```bash
git clone https://github.com/thvrijn/creator-smp-analyser.git /opt/docker/apps/creator-smp-analyser
cd /opt/docker/apps/creator-smp-analyser
cp .env.production.example .env
nano .env
```

Vul in `.env` in:

- `APP_KEY`: `docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
- `DB_PASSWORD`: `openssl rand -hex 24`
- `WORKER_TOKEN`: `openssl rand -hex 32`. Zet hetzelfde token in de `worker/.env` van elke worker.
- `APP_URL` en `WORKER_APP_URL`: het adres dat je reverse proxy aan de app geeft.
- `TWITCH_CLIENT_ID` en `TWITCH_CLIENT_SECRET`
- `STORAGE_PATH` (optioneel): een map op de SSD voor uploads, audio en spelersfoto's. Standaard is dat `./storage` in de checkout.

Kopieer **nooit** de `.env` van je laptop naar de Pi. Daarin staan dev-instellingen (`APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000`) en een ander `DB_PASSWORD` dan waarmee Postgres op de Pi is aangemaakt. Na de volgende herstart kan de app dan niet meer bij de database, en foutpagina's tonen interne details aan iedereen. Zet alleen losse waarden over, zoals de Twitch-sleutels of het `WORKER_TOKEN` dat je workers al gebruiken. De containers lezen `.env` alleen bij het starten. Na een wijziging: `docker compose -f docker-compose.prod.yml up -d --wait app queue`.

**Reverse proxy**: stuur het domein van de app naar `creatorsmp4:8000`. Dat is de containernaam op het `proxy`-netwerk, net als `uitzet-tracker:80`.

- Zet de maximale uploadgrootte ruim (bijv. 2 GB), met `client_max_body_size 2100M;`. Anders mislukken video-uploads. VOD-audio haalt de Pi zelf op.
- Het adres moet HTTPS hebben. Anders werkt de ingesloten Twitch-speler niet.
- De workers moeten de app op dat adres kunnen bereiken. Thuis kan dat via het LAN. Onderweg gaat het via Tailscale, of via het domein als dat van buiten bereikbaar is.

Op de Pi is Nginx Proxy Manager **niet** via zijn webinterface ingesteld. Alle hosts staan met de hand in `/opt/docker/reverse-proxy/http_top.conf`, dat in de container als `/data/nginx/custom/http_top.conf` is gemount. Dat bestand is van root. Bewerk het daarom via de container en maak eerst een backup. Een domein dat daar niet in staat, komt bij het eerste HTTPS-blok uit, en dat is Jellyfin. Er is één Let's Encrypt-certificaat (`jellyfin.thomasvrijn.nl`) voor alle domeinen. Een nieuw domein voeg je zo toe:

1. Zet een blok op poort 80 voor het domein in `http_top.conf`, met `include /etc/nginx/conf.d/include/letsencrypt-acme-challenge.conf;` en een redirect naar HTTPS. Herlaad daarna: `docker exec nginx-proxy-manager sh -c 'nginx -t && nginx -s reload'`.
2. Breid het certificaat uit. Noem alle bestaande domeinen weer, anders vallen ze eruit:

   ```bash
   docker exec nginx-proxy-manager certbot certonly --webroot -w /data/letsencrypt-acme-challenge \
     --cert-name jellyfin.thomasvrijn.nl --expand \
     -d jellyfin.thomasvrijn.nl -d tracker.thomasvrijn.nl -d creatorsmp4.thomasvrijn.nl
   ```

3. Zet het blok op poort 443 erbij (zie het blok `creatorsmp4.thomasvrijn.nl` in het bestand) en herlaad opnieuw. De nachtelijke `certbot renew` in de container vernieuwt het certificaat daarna vanzelf.

De Tailscale-DNS op de Pi kan voor `*.thomasvrijn.nl` een ander adres geven dan de publieke DNS. Kijk bij twijfel met `nslookup creatorsmp4.thomasvrijn.nl 1.1.1.1`.

**Eerste deploy**: start de workflow (*Actions → CI/CD → Run workflow*), of op de Pi `./scripts/deploy.sh`. De eerste build duurt even; daarna gebruikt Docker zijn cache.

Bestaande data van de laptop meenemen kan eenmalig, direct na de eerste deploy:

1. Op de laptop: `docker compose exec -T postgres pg_dump -U creatorsmp4 creatorsmp4 > dump.sql`
2. Op de Pi: `docker compose -f docker-compose.prod.yml exec -T postgres psql -U creatorsmp4 creatorsmp4 < dump.sql`
3. Kopieer `storage/app/private` naar `STORAGE_PATH/app/private`.

## 3. Dagelijks gebruik

- **Deployen**: pushen naar master. De voortgang staat in het tabblad *Actions*.
- **Handmatig deployen**: *Actions → CI/CD → Run workflow*, of `./scripts/deploy.sh` op de Pi. Lukt `git fetch` via SSH niet (de sleutel is op slot), dan haalt `DEPLOY_GIT_URL=https://github.com/thvrijn/creator-smp-analyser.git ./scripts/deploy.sh` de code via HTTPS op.
- **`package-lock.json`**: de Pi bouwt de assets op arm64. Zitten in de lockfile alleen de native pakketten van één platform (bijv. alleen `@rollup/rollup-linux-x64-gnu`), dan faalt `npm run build` in de image met "Cannot find module @rollup/rollup-linux-arm64-gnu". Maak hem dan opnieuw aan zonder bestaande `node_modules`: `rm -rf node_modules package-lock.json && npm install`.
- **Terugdraaien**: `./scripts/deploy.sh <oudere commit>`. Migraties blijven staan: ze voegen alleen toe.
- **Loopt er een job?** De deploy wacht maximaal een uur (`DEPLOY_WAIT_MINUTES`). `FORCE=1` wacht niet, maar breekt de job af. Die stream staat dan op "Lijkt vastgelopen" en kan opnieuw gestart worden.
- **Logs**: `docker compose -f docker-compose.prod.yml logs -f app queue`
- **Backups**: `backups/db-<datum>-<commit>.sql.gz`, gemaakt vóór elke deploy. Zet ze ook ergens anders neer (ze staan op dezelfde schijf).
- **Nooit** `docker compose ... down -v`: dat verwijdert de database.
