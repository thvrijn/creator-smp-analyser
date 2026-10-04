# CreatorSMP4

Stap 1 bevat alleen de developmentomgeving: Laravel 12, Inertia.js, Vue 3, TypeScript, Vite, PostgreSQL, Redis en een minimale Python-worker.

## Starten

```bash
make start
```

Dit bouwt wat gewijzigd is, start alle services, maakt de testdatabase aan, downloadt de modellen en controleert alles (inclusief GPU). Open daarna http://localhost:8000. Vite draait op http://localhost:5173 en ondersteunt hot reload via de Laravel-container.

## Controleren

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f worker
docker compose exec app php artisan about
docker compose exec redis redis-cli ping
docker compose exec postgres pg_isready -U creatorsmp4 -d creatorsmp4
```

Stoppen:

```bash
docker compose down
```

De PostgreSQL- en Redis-data blijven in Docker-volumes staan. Gebruik `docker compose down -v` alleen als die lokale developmentdata verwijderd mag worden.

## Databehoud

Wis of reset de database niet automatisch. Gebruik geen `migrate:fresh`, `db:wipe`, `docker compose down -v` of vergelijkbare destructieve acties zonder expliciete toestemming. Bestaande players, streams, transcripties en uploads moeten behouden blijven. Gebruik migrations veilig en controleer eerst de bestaande data voordat records, tabellen of volumes worden verwijderd.

## Technische keuzes

- Frontend en backend zijn één Laravel-project; Vue/TypeScript staat onder `resources/js`.
- De `app`-service bevat PHP én Node/Vite om development hot reload eenvoudig te houden.
- Redis is geconfigureerd als Laravel cache- en queue-backend, maar er draaien nog geen AI-jobs.
- De Python worker gebruikt standaard NVIDIA CUDA/cuDNN met faster-whisper op GPU (`WHISPER_DEVICE=cuda`, `WHISPER_COMPUTE_TYPE=float16`). Hiervoor zijn een NVIDIA-driver en NVIDIA Container Toolkit op de host nodig. CPU-fallback blijft mogelijk via `WHISPER_DEVICE=cpu` en `WHISPER_COMPUTE_TYPE=int8`.
- Whisper en het event-model (`unsloth/Qwen3-8B-bnb-4bit`, Qwen3-8B voorgekwantiseerd in 4-bit) delen de GPU. Met 8 GB VRAM passen ze niet tegelijk, dus de worker laadt per taak het benodigde model en laadt het andere eerst uit (`worker/model_manager.py`). Een model blijft geladen zolang het gebruikt wordt en wordt na `WORKER_MODEL_IDLE_SECONDS` (standaard 600) zonder gebruik uitgeladen. Gemeten op de RTX 5060 Laptop: Whisper ~0,6 GB VRAM en 1-2 s laden; Qwen ~6,1 GB VRAM, 11-18 s laden en 5-8 s per transcript-chunk. Tijdens het laden van Qwen gebruikt de worker tot ~4,3 GB RAM. Geef WSL daarom bij voorkeur meer dan de standaard 50% geheugen (`.wslconfig`: `memory=12GB`).
- Modellen worden gedownload door `make start` (`make models`) en staan in `worker/.cache` (Whisper) en het Docker-volume `event_model_cache` (Qwen). Ze zitten niet in de image of in Git.
- PostgreSQL bevat de CreatorSMP4-domeintabellen voor players, streams en transcriptsegmenten.
- `.env` bevat uitsluitend lokale developmentwaarden. Deel dit bestand niet en commit geen echte secrets.
# creator-smp-analyser
