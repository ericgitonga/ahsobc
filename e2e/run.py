"""Runs every e2e/test_*.py spec's TESTS list and reports pass/fail.

    conda run -n ds python e2e/run.py

Requires a server already running at BASE_URL (see each spec's default). Exits non-zero if any
test fails or errors, for use as a CI gate.
"""

import importlib
import os
import pkgutil
import sys
import traceback
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

SKIP_MODULES = {m for m in os.environ.get("E2E_SKIP_MODULES", "").split(",") if m}


def discover_tests():
    tests = []
    for mod_info in pkgutil.iter_modules([str(Path(__file__).parent)]):
        if not mod_info.name.startswith("test_"):
            continue
        if mod_info.name in SKIP_MODULES:
            print(f"SKIP module {mod_info.name} (E2E_SKIP_MODULES)")
            continue
        module = importlib.import_module(mod_info.name)
        tests.extend(module.TESTS)
    return tests


def main() -> int:
    tests = discover_tests()
    if not tests:
        print("No e2e tests discovered.")
        return 1

    failures = []
    for t in tests:
        label = f"{t.__module__}.{t.__name__}"
        try:
            t()
        except Exception:
            print(f"FAIL {label}")
            traceback.print_exc()
            failures.append(label)
        else:
            print(f"PASS {label}")

    print(f"\n{len(tests) - len(failures)}/{len(tests)} passed.")
    if failures:
        print("Failed:", ", ".join(failures))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
