#!/usr/bin/env sh
# Verifies that the whole CreatorSMP4 stack works. Used by `make check` and `make start`.
set -u

COMPOSE="docker compose"
failed=0

ok() { printf '  \033[32mok\033[0m    %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; failed=1; }

echo "CreatorSMP4 health check:"

$COMPOSE exec -T postgres pg_isready -q -U creatorsmp4 -d creatorsmp4 && ok "postgres" || fail "postgres not ready"

[ "$($COMPOSE exec -T postgres psql -U creatorsmp4 -d creatorsmp4 -tAc "SELECT 1 FROM pg_database WHERE datname='creatorsmp4_test'" 2>/dev/null)" = "1" ] \
    && ok "test database creatorsmp4_test" || fail "test database creatorsmp4_test missing (run: make test-db)"

[ "$($COMPOSE exec -T redis redis-cli ping 2>/dev/null | tr -d '\r')" = "PONG" ] && ok "redis" || fail "redis did not answer PONG"

pending=$($COMPOSE exec -T app php artisan migrate:status 2>/dev/null | grep -c Pending)
[ "$pending" = "0" ] && ok "migrations up to date" || fail "$pending pending migration(s) (run: make migrate)"

app_ok=0
for _ in $(seq 1 30); do
    if curl -fsS -o /dev/null http://localhost:8000/dashboard; then app_ok=1; break; fi
    sleep 2
done
[ "$app_ok" = "1" ] && ok "app http://localhost:8000" || fail "app not reachable on http://localhost:8000 (see: make logs-app)"

$COMPOSE ps --status running --services 2>/dev/null | grep -qx queue && ok "queue worker running" || fail "queue container not running (see: make logs-queue)"

health=$($COMPOSE exec -T app curl -fsS http://worker:8001/health 2>/dev/null)
[ -n "$health" ] && ok "python worker $health" || fail "python worker not reachable on http://worker:8001 (see: make logs-worker)"

gpu=$($COMPOSE exec -T worker python3 -W ignore -c "
import torch, ctranslate2
assert torch.cuda.is_available(), 'torch: CUDA not available'
x = torch.ones(1024, device='cuda'); assert (x * 2).sum().item() == 2048
assert ctranslate2.get_cuda_device_count() > 0, 'ctranslate2: no CUDA device'
free, total = torch.cuda.mem_get_info()
print(f'{torch.cuda.get_device_name(0)} (torch {torch.__version__}, {free / 2**30:.1f}/{total / 2**30:.1f} GB VRAM free)')
" 2>&1 | tail -1)
case "$gpu" in
    *GB\ VRAM\ free*) ok "GPU $gpu" ;;
    *) fail "GPU/CUDA check failed: $gpu" ;;
esac

if [ "$failed" = "0" ]; then
    echo "All checks passed. Open http://localhost:8000"
else
    echo "Some checks failed."
fi
exit "$failed"
