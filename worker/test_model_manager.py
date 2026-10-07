import threading
import unittest

from model_manager import ModelManager


class FakeClock:
    def __init__(self) -> None:
        self.now = 0.0

    def __call__(self) -> float:
        return self.now


class ModelManagerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.clock = FakeClock()
        self.released = 0
        self.manager = ModelManager(idle_seconds=600, clock=self.clock, release_memory=self.release)
        self.loads: list[str] = []
        self.unloads: list[str] = []

    def release(self) -> None:
        self.released += 1

    def loader(self, name: str):
        def load() -> str:
            self.loads.append(name)
            return f"{name}-model"
        return load

    def unloader(self, model: str) -> None:
        self.unloads.append(model)

    def test_same_model_is_loaded_once_and_reused(self) -> None:
        for _ in range(3):
            with self.manager.use("event_llm", self.loader("event_llm"), self.unloader) as model:
                self.assertEqual(model, "event_llm-model")
        self.assertEqual(self.loads, ["event_llm"])
        self.assertEqual(self.unloads, [])

    def test_requesting_another_model_unloads_the_current_one_first(self) -> None:
        with self.manager.use("whisper", self.loader("whisper"), self.unloader):
            pass
        with self.manager.use("event_llm", self.loader("event_llm"), self.unloader):
            self.assertEqual(self.unloads, ["whisper-model"])
            self.assertEqual(self.manager.loaded, "event_llm")
        self.assertEqual(self.loads, ["whisper", "event_llm"])
        self.assertEqual(self.released, 1)

    def test_failed_load_leaves_no_model_loaded(self) -> None:
        def broken() -> str:
            raise RuntimeError("CUDA out of memory")

        with self.assertRaises(RuntimeError):
            with self.manager.use("event_llm", broken):
                pass
        self.assertIsNone(self.manager.loaded)
        with self.manager.use("event_llm", self.loader("event_llm")) as model:
            self.assertEqual(model, "event_llm-model")

    def test_idle_model_is_unloaded_after_timeout(self) -> None:
        with self.manager.use("whisper", self.loader("whisper"), self.unloader):
            pass
        self.clock.now = 599
        self.assertFalse(self.manager.unload_if_idle())
        self.clock.now = 600
        self.assertTrue(self.manager.unload_if_idle())
        self.assertIsNone(self.manager.loaded)
        self.assertEqual(self.unloads, ["whisper-model"])

    def test_idle_unload_is_disabled_with_zero_seconds(self) -> None:
        manager = ModelManager(idle_seconds=0, clock=self.clock, release_memory=self.release)
        with manager.use("whisper", self.loader("whisper")):
            pass
        self.clock.now = 10_000
        self.assertFalse(manager.unload_if_idle())
        self.assertEqual(manager.loaded, "whisper")

    def test_model_in_use_is_not_unloaded_by_idle_check_or_swapped_away(self) -> None:
        in_use = threading.Event()
        finish = threading.Event()
        order: list[str] = []

        def transcribe() -> None:
            with self.manager.use("whisper", self.loader("whisper"), self.unloader):
                in_use.set()
                finish.wait(5)
                order.append("whisper done")

        def extract() -> None:
            with self.manager.use("event_llm", self.loader("event_llm"), self.unloader):
                order.append("event_llm loaded")

        whisper_thread = threading.Thread(target=transcribe)
        whisper_thread.start()
        in_use.wait(5)
        self.clock.now = 10_000
        self.assertFalse(self.manager.unload_if_idle())
        event_thread = threading.Thread(target=extract)
        event_thread.start()
        event_thread.join(0.2)
        self.assertEqual(order, [])
        finish.set()
        whisper_thread.join(5)
        event_thread.join(5)
        self.assertEqual(order, ["whisper done", "event_llm loaded"])

    def test_two_models_stay_loaded_on_a_large_gpu(self) -> None:
        manager = ModelManager(idle_seconds=600, clock=self.clock, release_memory=self.release, max_loaded=2)
        for name in ["whisper", "event_llm", "whisper", "event_llm"]:
            with manager.use(name, self.loader(name), self.unloader):
                pass
        self.assertEqual(self.loads, ["whisper", "event_llm"])
        self.assertEqual(self.unloads, [])
        self.assertEqual(manager.loaded_models, ["whisper", "event_llm"])

    def test_least_recently_used_model_is_unloaded_when_full(self) -> None:
        manager = ModelManager(clock=self.clock, release_memory=self.release, max_loaded=2)
        for name in ["whisper", "event_llm", "whisper", "third"]:
            with manager.use(name, self.loader(name), self.unloader):
                pass
        self.assertEqual(self.unloads, ["event_llm-model"])
        self.assertEqual(manager.loaded_models, ["whisper", "third"])

    def test_each_idle_model_is_unloaded_on_its_own(self) -> None:
        manager = ModelManager(idle_seconds=600, clock=self.clock, release_memory=self.release, max_loaded=2)
        with manager.use("whisper", self.loader("whisper"), self.unloader):
            pass
        self.clock.now = 400
        with manager.use("event_llm", self.loader("event_llm"), self.unloader):
            pass
        self.clock.now = 700
        self.assertTrue(manager.unload_if_idle())
        self.assertEqual(manager.loaded_models, ["event_llm"])


if __name__ == "__main__":
    unittest.main()
