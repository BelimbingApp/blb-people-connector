#!/usr/bin/env python3

from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_WORKFLOW = ROOT / ".github" / "workflows" / "platform-drift.yml"


def require(source: str, pattern: str, message: str) -> None:
    if re.search(pattern, source, flags=re.MULTILINE) is None:
        raise AssertionError(message)


def require_line(source: str, line: str, message: str) -> None:
    if line not in source.splitlines():
        raise AssertionError(message)


def main(workflow: Path) -> int:
    source = workflow.read_text(encoding="utf-8")

    require(
        source,
        r"^\s{2}repository_dispatch:\n\s{4}types:\n\s{6}- belimbing-platform-main-ci-succeeded$",
        "receiver must subscribe only to the agreed platform-success event",
    )
    require(
        source,
        r"^\s{10}test \"\$PLATFORM_REPOSITORY\" = 'BelimbingApp/belimbing'$",
        "receiver must reject dispatches claiming another platform repository",
    )
    require(
        source,
        r"^\s{10}test \"\$PLATFORM_REF\" = 'refs/heads/main'$",
        "receiver must reject dispatches for a non-main platform ref",
    )
    require(
        source,
        r"^\s{10}\[\[ \"\$PLATFORM_SHA\" =~ \^\[0-9a-f\]\{40\}\$ \]\]$",
        "receiver must require an exact lowercase platform commit SHA",
    )

    payload_mappings = {
        "PLATFORM_REPOSITORY": "platform_repository",
        "PLATFORM_REF": "platform_ref",
        "PLATFORM_SHA": "platform_sha",
        "PLATFORM_RUN_URL": "platform_run_url",
    }
    for environment_name, payload_name in payload_mappings.items():
        require_line(
            source,
            f"          {environment_name}: "
            f"${{{{ github.event.client_payload.{payload_name} }}}}",
            f"receiver must map client_payload.{payload_name} to {environment_name}",
        )

    require_line(
        source,
        '          [[ "$PLATFORM_RUN_URL" =~ '
        '^https://github\\.com/BelimbingApp/belimbing/actions/runs/[0-9]+$ ]]',
        "receiver must reject a non-canonical platform run URL",
    )

    require(
        source,
        r"^\s{6}platform-ref: \$\{\{ needs\.contract\.outputs\.platform_sha \}\}$",
        "domain CI must compose the exact dispatched platform revision",
    )
    require(
        source,
        r"^\s{2}ci:\n\s{4}if: github\.event_name == 'repository_dispatch'$",
        "the expensive composed run must not execute for contract-only pull requests",
    )

    print("platform drift workflow contract: ok")

    return 0


if __name__ == "__main__":
    try:
        if len(sys.argv) > 2:
            print(f"usage: {sys.argv[0]} [workflow]", file=sys.stderr)
            raise SystemExit(2)

        selected_workflow = Path(sys.argv[1]) if len(sys.argv) == 2 else DEFAULT_WORKFLOW
        raise SystemExit(main(selected_workflow))
    except AssertionError as error:
        print(f"platform drift workflow contract: {error}", file=sys.stderr)
        raise SystemExit(1)
