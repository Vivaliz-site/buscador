import importlib.util, os, sys, unittest
from pathlib import Path
from unittest import mock
ROOT=Path(__file__).resolve().parents[1]; ADAPTER=ROOT/"scripts"/"agent_task_state.py"
def load():
    spec=importlib.util.spec_from_file_location("adapter",ADAPTER); mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod); return mod
class T(unittest.TestCase):
    def setUp(self): self.m=load()
    def test_repo(self): self.assertEqual(self.m.REPOSITORY,"Vivaliz-site/buscador")
    def test_target(self): self.assertEqual(str(self.m.DEFAULT_CONTROLLER),"/home/ubuntu/shopvivaliz-deploy/current/scripts/agent_task_state.py")
    def test_closed(self):
        with mock.patch.dict(os.environ,{"SHOPVIVALIZ_CONTINUITY_STATE_CLI":"/missing/controller.py"},clear=False):
            with mock.patch.object(sys,"argv",[str(ADAPTER),"show","--task","x"]): self.assertEqual(self.m.main(),69)
    def test_self(self):
        with mock.patch.object(sys,"argv",[str(ADAPTER),"--adapter-self-test"]): self.assertEqual(self.m.main(),0)
if __name__=="__main__": unittest.main()
