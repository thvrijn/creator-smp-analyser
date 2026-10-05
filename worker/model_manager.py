import gc
import time
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
    """Keeps at most one heavy model loaded and swaps on demand.

    The GPU (8 GB on the RTX 5060 Laptop) cannot hold Whisper and the event LLM
    at the same time, so a request for a different model unloads the current one
    first. A model stays loaded between requests, so the many chunk requests of
    one event extraction run do not reload it. The lock is held while a model is
    in use: a request for another model waits until the current one finishes.
    """

    def __init__(
        self,
        idle_seconds: float = 0,
        clock: Callable[[], float] = time.monotonic,
        release_memory: Callable[[], None] = release_gpu_memory,
    ) -> None:
        self.idle_seconds = idle_seconds
        self._clock = clock
        self._release_memory = release_memory
        self._lock = RLock()
        self._name: str | None = None
        self._model: Any = None
        self._unloader: Callable[[Any], None] | None = None
        self._last_used = 0.0

    @property
    def loaded(self) -> str | None:
        return self._name

    @contextmanager
    def use(self, name: str, loader: Callable[[], Any], unloader: Callable[[Any], None] | None = None) -> Iterator[Any]:
        with self._lock:
            if self._name != name:
                self._unload()
                started = self._clock()
                print(f"model manager: loading {name}", flush=True)
                self._model = loader()
                self._name = name
                self._unloader = unloader
                print(f"model manager: loaded {name} in {self._clock() - started:.1f}s", flush=True)
            try:
                yield self._model
            finally:
                self._last_used = self._clock()

    def unload(self) -> None:
        with self._lock:
            self._unload()

    def unload_if_idle(self) -> bool:
        if self.idle_seconds <= 0 or not self._lock.acquire(blocking=False):
            return False
        try:
            if self._name is None or self._clock() - self._last_used < self.idle_seconds:
                return False
            print(f"model manager: {self._name} idle for {self.idle_seconds:.0f}s", flush=True)
            self._unload()
            return True
        finally:
            self._lock.release()

    def _unload(self) -> None:
        if self._name is None:
            return
        name, model, unloader = self._name, self._model, self._unloader
        self._name, self._model, self._unloader = None, None, None
        if unloader is not None:
            unloader(model)
        del model
        self._release_memory()
        print(f"model manager: unloaded {name}", flush=True)
