import gc
import time
from collections import OrderedDict
from contextlib import contextmanager
from threading import RLock
from typing import Any, Callable, Iterator


def release_gpu_memory() -> None:
    gc.collect()
    try:
        import torch

        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except ImportError:
        pass
    try:
        import mlx.core as mx  # macOS only

        mx.clear_cache()
    except ImportError:
        pass


class ModelManager:
    """Keeps at most `max_loaded` heavy models loaded and swaps on demand.

    An 8 GB GPU (the RTX 5060 Laptop) cannot hold Whisper and the event LLM at
    the same time, so there a request for a different model unloads the current
    one first (max_loaded=1, the default). A 16 GB GPU (RTX 4080) holds both
    (WORKER_MAX_LOADED_MODELS=2), so nothing is reloaded between a transcription
    and an analysis. A model stays loaded between requests, so the many chunk
    requests of one event extraction run do not reload it. The lock is held while
    a model is in use: one task runs at a time, and a request waits for it.
    """

    def __init__(
        self,
        idle_seconds: float = 0,
        clock: Callable[[], float] = time.monotonic,
        release_memory: Callable[[], None] = release_gpu_memory,
        max_loaded: int = 1,
    ) -> None:
        self.idle_seconds = idle_seconds
        self.max_loaded = max(1, max_loaded)
        self._clock = clock
        self._release_memory = release_memory
        self._lock = RLock()
        # name -> (model, unloader), least recently used first
        self._models: OrderedDict[str, tuple[Any, Callable[[Any], None] | None]] = OrderedDict()
        self._last_used: dict[str, float] = {}

    @property
    def loaded(self) -> str | None:
        """The most recently used loaded model."""
        return next(reversed(self._models), None)

    @property
    def loaded_models(self) -> list[str]:
        return list(self._models)

    @contextmanager
    def use(self, name: str, loader: Callable[[], Any], unloader: Callable[[Any], None] | None = None) -> Iterator[Any]:
        with self._lock:
            if name not in self._models:
                while len(self._models) >= self.max_loaded:
                    self._unload(next(iter(self._models)))
                started = self._clock()
                print(f"model manager: loading {name}", flush=True)
                self._models[name] = (loader(), unloader)
                print(f"model manager: loaded {name} in {self._clock() - started:.1f}s", flush=True)
            self._models.move_to_end(name)
            try:
                yield self._models[name][0]
            finally:
                self._last_used[name] = self._clock()

    def unload(self) -> None:
        with self._lock:
            for name in list(self._models):
                self._unload(name)

    def unload_if_idle(self) -> bool:
        if self.idle_seconds <= 0 or not self._lock.acquire(blocking=False):
            return False
        try:
            idle = [name for name in self._models if self._clock() - self._last_used.get(name, 0.0) >= self.idle_seconds]
            for name in idle:
                print(f"model manager: {name} idle for {self.idle_seconds:.0f}s", flush=True)
                self._unload(name)
            return bool(idle)
        finally:
            self._lock.release()

    def _unload(self, name: str) -> None:
        model, unloader = self._models.pop(name)
        self._last_used.pop(name, None)
        if unloader is not None:
            unloader(model)
        del model
        self._release_memory()
        print(f"model manager: unloaded {name}", flush=True)
