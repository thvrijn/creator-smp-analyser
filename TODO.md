# TODO

Vink af met `[x]`. Afgeronde punten mogen naar **Klaar** onderaan.

## Nu

- [ ] Players en streams opnieuw aanmaken en video's opnieuw uploaden (database en uploads zijn leeg)
- [ ] Transcriptie en event-extractie opnieuw draaien voor de nieuwe streams

## Event Extraction-basis (plan stap afronden)

- [ ] Event-extractie testen op een echte SMP-stream met ontmoetingen. Stream 41 is een tips-video, en de huidige prompt is getest op zelfgemaakte SMP-fragmenten.
- [ ] Later, voor cross-stream matching: genoemde spelers als apart veld opslaan (nu alleen in titel en beschrijving)

## Features

- [ ] Timeline-pagina bouwen (nu placeholder)
- [ ] Video builder bouwen (nu placeholder)
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

- [ ] WSL meer geheugen geven (`.wslconfig`: `memory=12GB`, daarna `wsl --shutdown`). Nu 7,4 GB; het laden van Qwen gebruikt tot ~4,3 GB en Docker Desktop crashte eerder bij het uitpakken van de image.
- [ ] Uitzoeken waarom `make start` de worker-image eenmalig helemaal opnieuw bouwde (build-cache kwijt?)

- [ ] Backup van de dev-database regelen
- [ ] Event-types op één plek definiëren (nu dubbel in `worker/event_extractor.py` en `ExtractStreamEventsJob`)

## Ideeën

-

## Klaar

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
