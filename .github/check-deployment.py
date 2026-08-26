#!/usr/bin/env python3
"""Deployment-manifest checks.

Run by CI and safe to run by hand:

    python3 .github/check-deployment.py

Two things are checked, and neither can be seen to fail any other way.

1. `.cpanel.yml` is parsed by the host, not by us, and a file it rejects
   disables deployment entirely rather than failing loudly. Its parser is
   stricter than most: non-ASCII, tabs, and braces (a YAML flow indicator)
   all break it.

2. This account hosts TWO applications under one document root. RESM is
   served from `public_html/resm/` and this repository must never write
   there, delete at document-root level, or copy recursively into it — any
   of which takes RESM down during a shift. Only three destinations are
   ours: `rerm-app`, `public_html/rerm`, and the single file
   `public_html/index.html`.

Checks run against the *parsed task strings*, not the raw file, so the
comments explaining all of this do not trip their own rules.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

import yaml

REPO = Path(__file__).resolve().parent.parent
MANIFEST = REPO / ".cpanel.yml"
SITE = REPO / "site"

# A recursive delete whose target ends at the document root rather than
# inside a directory we own.
RM_AT_ROOT = re.compile(r"\brm\s+-[a-zA-Z]*r[a-zA-Z]*\s+.*(\$SITE_DIR|public_html)\s*/?\s*$")

# A recursive copy INTO the document root. The landing page is copied as one
# named file precisely so this never has to happen.
CP_INTO_ROOT = re.compile(r"\bcp\s+-[a-zA-Z]*R[a-zA-Z]*\s+.*\$SITE_DIR")

# Any mention of the other application's directory in an executed task.
NAMES_RESM = re.compile(r"public_html/resm|(?<![a-zA-Z])/resm/")


def fail(message: str) -> None:
    print(f"::error::{message}", file=sys.stderr)
    failures.append(message)


failures: list[str] = []


def check_manifest_parses() -> list[str]:
    raw = MANIFEST.read_bytes()

    try:
        raw.decode("ascii")
    except UnicodeDecodeError as exc:
        fail(f".cpanel.yml contains non-ASCII, which the host's parser rejects: {exc}")

    if b"\t" in raw:
        fail(".cpanel.yml contains a tab; the host's parser rejects tabs.")

    document = yaml.safe_load(raw)
    tasks = document["deployment"]["tasks"]

    if not tasks or not all(isinstance(task, str) for task in tasks):
        fail(".cpanel.yml tasks must be a non-empty list of plain strings.")
        return []

    for task in tasks:
        if "{" in task or "}" in task:
            fail(f".cpanel.yml task uses braces, a YAML flow indicator: {task}")

    print(f".cpanel.yml parses: {len(tasks)} tasks")
    return tasks


def check_tasks_leave_resm_alone(tasks: list[str]) -> None:
    for task in tasks:
        # Strip trailing comments so an explanation is never mistaken for a
        # command. A '#' inside the command itself would be unusual here.
        command = task.split("#", 1)[0].strip()
        if not command:
            continue

        if RM_AT_ROOT.search(command):
            fail(f"deletes at document-root level; RESM lives there: {command}")
        if CP_INTO_ROOT.search(command):
            fail(f"copies recursively into the document root; RESM lives there: {command}")
        if NAMES_RESM.search(command):
            fail(f"names RESM's directory: {command}")

    print("deployment stays inside rerm-app, public_html/rerm and public_html/index.html")


def check_site_is_one_file() -> None:
    # DOCUMENT_ROOT is public_html itself, so everything in site/ lands beside
    # RESM. A .htaccess here would be evaluated for /resm/ requests too, which
    # is exactly why the landing page is a static .html with inline CSS.
    contents = sorted(path.name for path in SITE.iterdir())
    if contents != ["index.html"]:
        fail(
            "site/ must contain only index.html — everything in it lands at the "
            f"document root beside RESM. Found: {', '.join(contents) or '(nothing)'}"
        )
        return

    print("site/ contains only index.html")


if __name__ == "__main__":
    check_tasks_leave_resm_alone(check_manifest_parses())
    check_site_is_one_file()

    if failures:
        print(f"\n{len(failures)} deployment problem(s).", file=sys.stderr)
        sys.exit(1)

    print("\ndeployment manifest ok")
