# TODO

Vink af met `[x]`. Afgeronde punten mogen naar **Klaar** onderaan.

## Nu

- [ ] Players en streams opnieuw aanmaken en video's opnieuw uploaden (database en uploads zijn leeg)
- [ ] Transcriptie en event-extractie opnieuw draaien voor de nieuwe streams

## Event Extraction-basis (plan stap afronden)

- [ ] Event-extractie testen op een echte SMP-stream met ontmoetingen. Stream 41 is een tips-video, en de huidige prompt is getest op zelfgemaakte SMP-fragmenten.
- [ ] Later, voor cross-stream matching: genoemde spelers als apart veld opslaan (nu alleen in titel en beschrijving)

## Features

- [ ] Sprekerherkenning op echte streams beoordelen: kloppen de sprekers en is speaker 0 echt de streamer? Daarvoor eerst een Hugging Face-token (`HF_TOKEN`) in `.env` en in de `worker/.env` van elke worker, met de voorwaarden van pyannote/speaker-diarization-3.1 en pyannote/segmentation-3.0 geaccepteerd.
- [ ] Drempel voor stemherkenning meten zodra meerdere spelers streams met sprekerherkenning hebben: `php artisan voices:evaluate`, daarna `VOICE_MATCH_THRESHOLD` bijstellen (nu 0,6, nog niet gemeten).
- [ ] Clips in 1080p ophalen (kiezen, bekijken en bijstellen is klaar): alleen dat stuk downloaden. Let op: ffmpeg-seek in de HLS-playlist van Twitch blijft hangen, dus zelf de benodigde `.ts`-segmenten uit de m3u8 halen en lokaal knippen. Moet binnen de bewaartermijn: Partners 60 dagen, 2 spelers maar 7 dagen.
- [ ] Downloads op een eigen queue-worker zetten, zodat ze transcripties niet ophouden
- [ ] Het praten met de chat vóór de gameplay overslaan bij transcriptie of analyse. Daarvoor moet bekend zijn wanneer de speler zelf joinde, en creatorsmp.nl geeft alleen wanneer iemand vertrok en hoe lang hij speelde. Optie: OCR van "<speler> joined the game" in de eigen POV, of zelf een startpunt per stream zetten.
- [ ] Knop om alle VOD's van een speler in één keer audio te laten ophalen
- [ ] Deaths van creatorsmp.nl ophalen voor de tijdlijn (`/api/events/{id}/deaths`: exacte tijd en bericht, geen OCR nodig)
- [ ] Tijdlijn-pagina bouwen (nu placeholder), zoals in een video-editor (DaVinci Resolve): streams kiezen, elke stream is een spoor onder elkaar, precies uitgelijnd op servertijd (`started_at` + `video_offset_seconds` + filetijd, zoals `SharedMoments`). Eén afspeelkop over alle sporen, zodat je op elk moment ziet wat er in elke stream gebeurt.
  - Per spoor: waar de stream live was, waar het SMP-deel/transcript is (`transcription_ranges`), events als blokjes, clips, "Zelfde moment"-koppelingen als lijnen tussen sporen, en wie er praat (sprekers als gekleurde stukjes, herkende spelers met naam).
  - Inzoomen van een hele dag naar seconden; klikken op een event springt de afspeelkop erheen.
  - Audio: we hebben de audio van elk spoor, dus afspelen kan meteen; dempen/solo per spoor (zoals in een editor) om naar één POV te luisteren.
  - Video zonder iets te downloaden: per gekozen spoor de ingesloten Twitch-player (`TwitchPlayer.vue`, staat al bij Clips) op VOD-tijd = afspeelkop − `started_at`, en die laten meelopen met de afspeelkop (seek bij scrubben, af en toe bijsturen bij drift). Bijv. een raster van 2-4 POV's tegelijk, één met geluid. Werkt alleen zolang de VOD bestaat (Partners 60 dagen, anderen korter).
  - Later, voor echt beeld zonder Twitch: alleen de gekozen stukken in lage resolutie of 1080p ophalen (zie het clip-downloadpunt), of per stream een thumbnail-strip (één frame per minuut) als filmstrip in het spoor.
  - Export van de selectie als FCPXML/EDL voor DaVinci Resolve, met de sporen al op tijd uitgelijnd (sluit aan op het videobouwer-punt).
- [ ] Video builder verder bouwen: clips staan er al op servertijd. Volgende stappen: downloaden en exporteren naar een montageprogramma (FCPXML voor DaVinci Resolve/Premiere).
- [ ] Settings-pagina bouwen (nu placeholder)
- [ ] Knop op de Settings-pagina: "Ongebruikte video's opruimen"
  - Start een queue-job die alle video's in `storage/app/private/streams/` verwijdert waar geen stream in de database meer naar verwijst (`streams.video_path`)
  - Laat eerst zien hoeveel bestanden en hoeveel GB het gaat om, en vraag om bevestiging (verwijderen is onomkeerbaar)
  - Ruim daarna ook de lege `streams/<id>/`-mappen op
  - Toon na afloop hoeveel er is verwijderd
- [ ] Events-overzicht uitwerken (`resources/js/Pages/Events/Index.vue` is nog minimaal)
- [ ] Overview-pagina vullen met echte statistieken (nu nog statische placeholder)
- [ ] Player-pagina uitbreiden (bijv. events en tijdlijn per player)

## Technisch

- [ ] De Pi inrichten volgens `DEPLOY.md`: een tweede self-hosted runner voor deze repo (naast die van uitzet-tracker-new), de checkout in `/opt/docker/apps/creator-smp-analyser` met `.env`, het domein in de reverse proxy naar `creatorsmp4:8000` (met een ruime uploadlimiet). Daarna de dev-data eenmalig overzetten.
- [ ] Backups van de Pi ook buiten de Pi bewaren (`backups/` staat op dezelfde schijf)
- [ ] Workers op de laptop, de pc (RTX 4080: `WORKER_MAX_LOADED_MODELS=2`) en de Mac installeren met `make remote-worker` (Tailscale + `worker/.env`)
- [ ] Mac-worker automatisch laten starten (launchd-agent); de Docker-workers doen dat al (`restart: unless-stopped`)
- [ ] Transcriptie en analyse tegelijk op één worker met genoeg VRAM (nu één taak per worker)

- [ ] WSL meer geheugen geven (`.wslconfig`: `memory=12GB`, daarna `wsl --shutdown`). Nu 7,4 GB; het laden van Qwen gebruikt tot ~4,3 GB en Docker Desktop crashte eerder bij het uitpakken van de image.
- [ ] Uitzoeken waarom `make start` de worker-image eenmalig helemaal opnieuw bouwde (build-cache kwijt?)

- [ ] Backup van de dev-database regelen
- [ ] Event-types op één plek definiëren (nu dubbel in `worker/event_extractor.py` en `ExtractStreamEventsJob`)

## Ideeën

- Spelernamen in het verhaal en de events koppelen aan de spelerlijst: het model schrijft soms "Egbertlive" naast "Egbert" of "Jeremy Frieser" naast "Jeremy".
- Verhalen van meerdere streams van dezelfde dag samenvoegen tot één verhaallijn van de server (alle POV's samen, met "Zelfde moment"-koppelingen).

## Klaar

- [x] Whisper op de Mac via de GPU (mlx-whisper) in plaats van de CPU: 10 min van stream 2 in 21 s i.p.v. 157 s (29× realtime), een stream van ~6 uur zou zo ~12 minuten duren (binnen het uur van een job). Zelfde stiltefilter en tijden als op de CPU; een herhalingslus bij muziek (15× "Oh.") wordt samengevoegd. `WHISPER_DEVICE=cpu` zet hem terug op de CPU.
- [x] Eventherkenning draait om het verhaal van de server: alleen in-game verhaal-events (ontmoetingen, samenwerken, ruzie, verraad, gevechten, ruilen, plot), geen chat-praat of routine (bomen hakken, kapotte pickaxe). Stukken van 5 minuten in plaats van 90 seconden, het model krijgt wie er praat, wie de streamer is en de spelerlijst (verkeerd gespelde namen worden goed), en een event loopt van begin tot eind (minimaal 2 zinnen). Nieuwe types: samenwerking, conflict, ruil, verhaal. Prompt-eval: 6/6 verhaalmomenten, 0 ruis.
- [x] Verhaal per stream (tab "Verhaal"): wat de speler in de game deed, wie hij tegenkwam en wat er gebeurde, per uur een alinea, plus een samenvatting per deel van 5 minuten met ▶ en een link naar het transcript. Verzonnen plekken (zoals de Nether), chat-praat en herhalingen worden eruit gehaald.
- [x] "Spreker 6" in events en verhaal is klikbaar om te zeggen wie het is; het transcript filtert op een spreker; zinnen zijn met de hand te verbeteren.
- [x] Afspelen vanaf een transcriptregel klopte niet bij Twitch' HLS-audio (stream 66): het bestand wordt na het ophalen een gewone MP4 (`streams:normalize-audio` voor oude bestanden).
- [x] Sprekers herkennen aan wat ze zeggen: zegt een spreker op hetzelfde moment dezelfde zinnen als de streamer van een andere stream, dan is het die speler. Gaat vóór stemherkenning, werkt ook als de stem via Discord anders klinkt, en zo herkende stemmen verbeteren het stemprofiel. Draait vanzelf na elke transcriptie; voor oude streams `php artisan speakers:match-text`. Nog te testen op een echt paar (47 + 51).
- [x] Dashboard toont bovenaan "Nu bezig": welke streams nu audio ophalen, transcriberen of analyseren (of in de wachtrij staan), met voortgang, resterende tijd, worker en annuleren. Werkt zichzelf elke 3 seconden bij.
- [x] Whisper-model van `small` naar `large-v3-turbo`: op 10 min van stream 47 sneller (37 s i.p.v. 84 s), 13% meer woorden, geen verzonnen herhalingen, en kortere zinnen per spreekbeurt (beter voor sprekerherkenning).
- [x] Hetzelfde moment in andere streams: onder een event staat "Zelfde moment bij" met de spelers die erbij waren (hun stem in het event, bij naam genoemd, of andersom). De link opent hun event op dat moment, of hun transcript op die tijd. Nog te beoordelen op echte streams met ontmoetingen.
- [x] "Bekijk op Twitch" opent bij een live stream het kanaal in plaats van de VOD.

- [x] Sprekerherkenning stap 2: een spreker in een andere stream wordt aan zijn stem herkend als speler (stemprofiel per speler uit hun eigen streams en sprekers die je benoemde). Herkend staat er met een vraagteken; "✓ Klopt" bevestigt het en maakt het profiel beter.

- [x] Sprekers corrigeren op de streampagina: een spreker een speler of naam geven, twee sprekers samenvoegen, en één zin aan een andere spreker geven.

- [x] Jobs annuleren: ✕ Annuleren bij transcriptie, analyse en audio ophalen (in de wachtrij meteen, een lopende job binnen enkele seconden; de worker stopt ook). Een vorig transcript of vorige events blijven staan.
- [x] Activiteitenlog voor de admin (Systeem → Activiteit): inloggen, mislukte pogingen, uitloggen en alles wat iemand verandert, met resultaat en IP.

- [x] Inloggen verplicht: zonder account kom je alleen op `/login`. Accounts maak je met `make user` (op de Pi: `php artisan user:create`, zie `DEPLOY.md`). Inloggen met een gebruikersnaam (hoofdletters maken niet uit). Uitloggen en je wachtwoord wijzigen via het profielmenu rechtsboven. Alleen de admin (thvrijn2002@gmail.com) maakt en verwijdert accounts op de pagina Gebruikers; zelf een account aanmaken kan niet.

- [x] Audio afspelen op de streampagina: speler boven de tabs, ▶ bij elk transcriptsegment en event, het segment dat speelt licht op en het transcript volgt mee. Audio ophalen kan nu ook op de streampagina.
- [x] Knop "Opnieuw transcriberen" (↻ in de streamtabel, knop op de streampagina) met bevestiging; geweigerd zolang een analyse loopt. Verwijderen in de streamtabel is nu een prullenbak-icoon. Analyseren toont een voortgangsbalk met stuk X/Y en resterende tijd.
- [x] Sprekerherkenning stap 1 (diarization met pyannote): elk transcriptsegment krijgt een spreker, en de spreker die het meest praat (meestal de streamer) staat met de spelernaam in het transcript. Stemprofielen per spreker worden al bewaard voor stap 2.

- [x] Automatisch deployen naar de Pi, zoals bij uitzet-tracker-new: GitHub Actions test elke push, en bij master draait de self-hosted runner op de Pi `scripts/deploy.sh` (bouwen op de Pi, wachten op lopende jobs, backup, migraties, healthcheck). Productie-image op FrankenPHP, `docker-compose.prod.yml` op het `proxy`-netwerk, `DEPLOY.md`.

- [x] Workers melden zich aan bij de app (heartbeat) en de app kiest per job de beste vrije worker. Zonder worker wacht een job ("Wacht op worker"). Een worker zonder de opslag van de app haalt de audio op via een tijdelijke ondertekende URL. Workers staan bij Instellingen (uitschakelen, vergeten) en in de header. `make remote-worker` start alleen een worker op een andere machine.

- [x] Clips kiezen (stap 1): "＋ Clip" bij events, "✂ Clip" in het transcript of de hele stream, bekijken in de ingesloten Twitch-speler, ±5 s bijstellen, en een overzicht op servertijd in de Videobouwer.
- [x] VOD-lijst syncen per speler en voor iedereen, en per VOD de audio ophalen (met voortgangsbalk). Alleen de stukken in de categorie CreatorSMP tussen 14:00 en 00:00 worden getranscribeerd, dus ook een ander spel halverwege valt weg. Een live VOD wordt pas na afloop opgehaald.
- [x] Twitch-kanaal per speler (`twitch_login`): de seeder vult het in, het staat in het spelerformulier en er staat een link op de spelerpagina.
- [x] Seeder met alle 90 spelers van creatorsmp.nl/spelers, inclusief profielfoto's (`CreatorSmpPlayerSeeder`). Spelers staan nu op naam gesorteerd zonder op hoofdletters te letten.
- [x] Spelers kunnen een foto krijgen (toevoegen/bewerken op de Spelers-pagina). Die staat overal waar een avatar staat. Een rij op de Spelers-pagina opent de spelerpagina.
- [x] Alles in de UI in het Nederlands, ook meldingen, fouten en de event-titels van het model. Code blijft Engels, game-termen mogen Engels blijven.
- [x] Draait ook op een Mac: `make` ziet macOS en draait de worker dan buiten Docker (Whisper op CPU, Qwen via MLX op Metal). `make start` installeert ffmpeg, uv en de venv zelf.
- [x] CLAUDE.md / AGENTS.md aangemaakt
- [x] Aparte testdatabase (`creatorsmp4_test`) zodat tests de dev-data niet meer wissen
- [x] Ongebruikte video's opgeruimd
- [x] Worker op torch `cu128`: Qwen draait nu op de RTX 5060
- [x] GPU-modelwissel: Whisper en Qwen worden on demand geladen en uitgeladen (`worker/model_manager.py`), gemeten en gedocumenteerd
- [x] Whisper op de GPU gehouden (beslissing)
- [x] Voorgekwantiseerd model `unsloth/Qwen3-8B-bnb-4bit` (6 GB i.p.v. 16 GB)
- [x] `worker/.dockerignore`, modellen niet meer in de image
- [x] `make start` doet alles: bouwen, starten, testdatabase, modellen downloaden, health check (inclusief GPU)
- [x] Python-tests uitgebreid (19 tests), `make worker-test` draait alle testbestanden
- [x] Git-repository opgezet. Video's, modelcaches en gegenereerde bestanden staan in `.gitignore`.
- [x] Event-kwaliteit verbeterd. Nieuwe prompt voor ~90 SMP-streamers: type optioneel, spelers bij naam, filler genegeerd. Max 12 segmenten per event, en dedupe op overlappende segmenten. Prompt-eval: 12/12 momenten gevonden (oude prompt 7/12), 0 events op filler. `make prompt-eval` om toekomstige promptwijzigingen te meten.
- [x] Stream-pagina (`/streams/{id}`): klik op een stream-rij voor events en transcript naast elkaar. Klik op een event om naar de segmenten te springen. De Events-pagina linkt hier ook naartoe.
- [x] Dashboard met player-cards (5 per rij) en player-pagina met hun streams. Oude dashboard heet nu Overview. Streamtabel en Add Stream zijn gedeelde componenten.
- [x] HTTP/0.9-fout opgelost: worker-fouten zijn nu altijd een nette HTTP-response en worden gelogd
- [x] Event-validatie per event: tijden komen uit de segmenten. Een onbruikbare chunk wordt overgeslagen, een nieuwe run vervangt de oude events.
- [x] Bug opgelost: een tweede run of retry bleef hangen op "processing" (beide jobs)
- [x] Echte E2E-test event-extractie op stream 41: 10 events in ~105 s, allemaal gekoppeld aan segmenten
- [x] Laravel-tests voor event-extractie: segment-isolatie, index buiten de chunk, duplicaten bij overlap, worker-fout, onbereikbare worker, queued → processing → completed (13 tests)
- [x] Status "Queued" zichtbaar in de UI. Event-status wordt live bijgewerkt, en je kunt niet twee keer in de wachtrij zetten.
