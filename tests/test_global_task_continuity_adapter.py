import importlib.util, os, sys, unittest
from pathlib import Path
from unittest import mock
ROOT=Path(__file__).resolve().parents[1]; ADAPTER=ROOT/"scripts"/"agent_task_state.py"
def load():
    spec=importlib.util.spec_from_file_location("adapter",ADAPTER); mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod); return mod
class T(unittest.TestCase):
    def setUp(self): self.m=load()
    def test_repo(self): self.assertEqual(self.m.REPOSITORY,"Vivaliz-site/buscador")
    def test_controller_requires_explicit_injection(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            self.assertEqual(self.m.controller_path(), Path())
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_STATE_CLI": "/tmp/explicit-controller.py"}, clear=True):
            self.assertEqual(self.m.controller_path(), Path("/tmp/explicit-controller.py"))
    def test_no_controller_is_fail_closed(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            with mock.patch.object(sys, "argv", [str(ADAPTER), "show", "--task", "x"]):
                self.assertEqual(self.m.main(), 69)
    def test_runtime_directory_is_not_invented(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            env = self.m.build_controller_env()
            self.assertEqual(env["SHOPVIVALIZ_TASK_REPOSITORY"], "Vivaliz-site/buscador")
            self.assertNotIn("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", env)
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_AGENT_TASK_STATE_DIR": "/tmp/buscador-state"}, clear=True):
            self.assertEqual(self.m.build_controller_env()["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"], "/tmp/buscador-state")
    def test_closed(self):
        with mock.patch.dict(os.environ,{"SHOPVIVALIZ_CONTINUITY_STATE_CLI":"/missing/controller.py"},clear=False):
            with mock.patch.object(sys,"argv",[str(ADAPTER),"show","--task","x"]): self.assertEqual(self.m.main(),69)
    def test_self(self):
        with mock.patch.object(sys,"argv",[str(ADAPTER),"--adapter-self-test"]): self.assertEqual(self.m.main(),0)
if __name__=="__main__": unittest.main()
