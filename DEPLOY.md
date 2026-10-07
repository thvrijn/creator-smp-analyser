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

**Reverse proxy**: stuur het domein van de app naar `creatorsmp4:8000`. Dat is de containernaam op het `proxy`-netwerk, net als `uitzet-tracker:80`.

- Zet de maximale uploadgrootte ruim (bijv. 2 GB). Bij Nginx Proxy Manager: `client_max_body_size 2100M;` onder *Advanced*. Anders mislukken video-uploads. VOD-audio haalt de Pi zelf op.
- Het adres moet HTTPS hebben. Anders werkt de ingesloten Twitch-speler niet.
- De workers moeten de app op dat adres kunnen bereiken. Thuis kan dat via het LAN. Onderweg gaat het via Tailscale, of via het domein als dat van buiten bereikbaar is.

**Eerste deploy**: start de workflow (*Actions → CI/CD → Run workflow*), of op de Pi `./scripts/deploy.sh`. De eerste build duurt even; daarna gebruikt Docker zijn cache.

Bestaande data van de laptop meenemen kan eenmalig, direct na de eerste deploy:

1. Op de laptop: `docker compose exec -T postgres pg_dump -U creatorsmp4 creatorsmp4 > dump.sql`
2. Op de Pi: `docker compose -f docker-compose.prod.yml exec -T postgres psql -U creatorsmp4 creatorsmp4 < dump.sql`
3. Kopieer `storage/app/private` naar `STORAGE_PATH/app/private`.

## 3. Dagelijks gebruik

- **Deployen**: pushen naar master. De voortgang staat in het tabblad *Actions*.
- **Handmatig deployen**: *Actions → CI/CD → Run workflow*, of `./scripts/deploy.sh` op de Pi.
- **Terugdraaien**: `./scripts/deploy.sh <oudere commit>`. Migraties blijven staan: ze voegen alleen toe.
- **Loopt er een job?** De deploy wacht maximaal een uur (`DEPLOY_WAIT_MINUTES`). `FORCE=1` wacht niet, maar breekt de job af. Die stream staat dan op "Lijkt vastgelopen" en kan opnieuw gestart worden.
- **Logs**: `docker compose -f docker-compose.prod.yml logs -f app queue`
- **Backups**: `backups/db-<datum>-<commit>.sql.gz`, gemaakt vóór elke deploy. Zet ze ook ergens anders neer (ze staan op dezelfde schijf).
- **Nooit** `docker compose ... down -v`: dat verwijdert de database.
