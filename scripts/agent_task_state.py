#!/usr/bin/env python3
from __future__ import annotations
import json, os, subprocess, sys
from pathlib import Path
REPOSITORY="Vivaliz-site/buscador"
DEFAULT_CONTROLLER=Path("/home/ubuntu/shopvivaliz-deploy/current/scripts/agent_task_state.py")
DEFAULT_RUNTIME_DIR=Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
def controller_path():
    v=os.getenv("SHOPVIVALIZ_CONTINUITY_STATE_CLI","").strip()
    return Path(v).expanduser() if v else DEFAULT_CONTROLLER
def build_controller_env(base=None):
    env=dict(base or os.environ)
    env["SHOPVIVALIZ_TASK_REPOSITORY"]=REPOSITORY
    env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"]=str(Path(env.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR","")).expanduser() if env.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR","").strip() else DEFAULT_RUNTIME_DIR)
    return env
def main():
    if sys.argv[1:]==["--adapter-self-test"]:
        print(json.dumps({"ok":True,"repository":REPOSITORY,"mode":"canonical-controller-adapter"})); return 0
    controller=controller_path()
    try:same=controller.resolve()==Path(__file__).resolve()
    except OSError:same=False
    if same or not controller.is_file():
        print(json.dumps({"ok":False,"error":"global_continuity_controller_unavailable","repository":REPOSITORY},sort_keys=True),file=sys.stderr); return 69
    return int(subprocess.run([sys.executable,str(controller),*sys.argv[1:]],env=build_controller_env(),check=False).returncode)
if __name__=="__main__": raise SystemExit(main())
