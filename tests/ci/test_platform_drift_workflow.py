#!/usr/bin/env python3

from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "platform-drift.yml"


def require(pattern: str, message: str) -> None:
    source = WORKFLOW.read_text(encoding="utf-8")

    if re.search(pattern, source, flags=re.MULTILINE) is None:
        raise AssertionError(message)


def main() -> int:
    require(
        r"^\s{2}repository_dispatch:\n\s{4}types:\n\s{6}- belimbing-platform-main-ci-succeeded$",
        "receiver must subscribe only to the agreed platform-success event",
    )
    require(
        r"^\s{10}test \"\$PLATFORM_REPOSITORY\" = 'BelimbingApp/belimbing'$",
        "receiver must reject dispatches claiming another platform repository",
    )
    require(
        r"^\s{10}test \"\$PLATFORM_REF\" = 'refs/heads/main'$",
        "receiver must reject dispatches for a non-main platform ref",
    )
    require(
        r"^\s{10}\[\[ \"\$PLATFORM_SHA\" =~ \^\[0-9a-f\]\{40\}\$ \]\]$",
        "receiver must require an exact lowercase platform commit SHA",
    )
    require(
        r"^\s{6}platform-ref: \$\{\{ needs\.contract\.outputs\.platform_sha \}\}$",
        "domain CI must compose the exact dispatched platform revision",
    )
    require(
        r"^\s{2}ci:\n\s{4}if: github\.event_name == 'repository_dispatch'$",
        "the expensive composed run must not execute for contract-only pull requests",
    )

    print("platform drift workflow contract: ok")

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except AssertionError as error:
        print(f"platform drift workflow contract: {error}", file=sys.stderr)
        raise SystemExit(1)
