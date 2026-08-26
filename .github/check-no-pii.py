#!/usr/bin/env python3
"""Refuse to ship member PII.

Run by CI and safe to run by hand:

    python3 .github/check-no-pii.py

This repository is **public**. The roster it is written to process contains
~1,950 volunteers' full names, home addresses, phone numbers and email
addresses. The application's own database is a private MySQL host behind a
login and is the right place for that data; a git repository is neither, and
nothing removes a blob from history once it is pushed.

Two ways it gets in, and both have happened rather than being hypothetical:

  1. The export itself is dropped in the working directory to test the
     importer, and `git add -A` sweeps it up.
  2. Rows are *copied out of* the export into documentation or test fixtures,
     because a real example is more convincing than an invented one.

The second is the one that slipped through: the spreadsheet guard caught the
file, and then real names, member numbers, an email address and a member's
home address were pasted into docs/ and tests/ by hand. This check exists for
that path.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent

# Files that legitimately contain address-like text.
SKIP_SUFFIXES = {".png", ".jpg", ".jpeg", ".gif", ".ico", ".woff", ".woff2"}

EMAIL = re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")

# Addresses that are ours, reserved for documentation, or obviously invented.
# RFC 2606 reserves example.com/net/org and .example/.invalid/.test precisely
# so that documentation never has to borrow a real one.
ALLOWED_EMAIL = re.compile(
    r"""
    ^(?:
        [A-Za-z0-9._%+-]+@(?:example\.(?:com|net|org)|.+\.(?:example|invalid|test|localhost))
      | [A-Za-z0-9._%+-]+@reshiftmanager\.com
      | noreply@.*
    )$
    """,
    re.VERBOSE,
)

# A US street address of the shape the export carries ("52 Sample Ct").
# Deliberately narrow: it must be a number, then words, then a street suffix.
STREET = re.compile(
    r"\b\d{1,6}\s+(?:[A-Z][A-Za-z.'-]*\s+){1,4}"
    r"(?:St|Street|Rd|Road|Ave|Avenue|Ct|Court|Dr|Drive|Ln|Lane|Blvd|Boulevard|Cyn|Canyon|Way|Trl|Trail|Cir|Circle|Pkwy|Pl|Place)\b"
)

# Phone numbers in the export's exact format.
PHONE = re.compile(r"\((\d{3})\)\s*(\d{3})-(\d{4})")

# The NANP reserves 555-0100 through 555-0199 for fictional use, and area code
# 555 is not assignable at all. Both are safe in documentation; a real Houston
# number is not.
def phone_is_reserved(area: str, exchange: str, line: str) -> bool:
    if area == "555":
        return True
    return exchange == "555" and line.startswith("01")

# Invented street names we allow, so the CSV tests can still exercise a comma
# and a newline inside a quoted address cell.
ALLOWED_STREET = re.compile(r"\bExample\s+Way\b|\bMain\s+St(?:reet)?\b", re.IGNORECASE)


def tracked_text_files() -> list[Path]:
    out = subprocess.run(
        ["git", "ls-files", "-z"], cwd=REPO, capture_output=True, text=True, check=True
    ).stdout
    files = []
    for name in out.split("\0"):
        if not name:
            continue
        path = REPO / name
        if path.suffix.lower() in SKIP_SUFFIXES or not path.is_file():
            continue
        files.append(path)
    return files


def main() -> int:
    problems: list[str] = []

    for path in tracked_text_files():
        rel = path.relative_to(REPO)
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue

        # This file names the patterns it looks for; matching itself is not a leak.
        if rel.as_posix() == ".github/check-no-pii.py":
            continue

        for number, line in enumerate(text.splitlines(), start=1):
            for address in EMAIL.findall(line):
                if not ALLOWED_EMAIL.match(address):
                    problems.append(
                        f"{rel}:{number}: email address {address!r} — use an @example.com address"
                    )
            for street in STREET.findall(line):
                if not ALLOWED_STREET.search(street):
                    problems.append(
                        f"{rel}:{number}: street address {street!r} — invent one"
                    )
            for area, exchange, subscriber in PHONE.findall(line):
                if phone_is_reserved(area, exchange, subscriber):
                    continue
                problems.append(
                    f"{rel}:{number}: phone number ({area}) {exchange}-{subscriber} — "
                    "use the reserved fiction range, e.g. (555) 555-0100"
                )

    if problems:
        for problem in problems:
            print(f"::error::{problem}", file=sys.stderr)
        print(
            f"\n{len(problems)} possible member detail(s) in a PUBLIC repository.\n"
            "The roster belongs in the application database, never in git — and git\n"
            "history keeps whatever is pushed to it. Replace real values with\n"
            "invented ones; RFC 2606 reserves example.com for exactly this.",
            file=sys.stderr,
        )
        return 1

    print(f"no member PII in {len(tracked_text_files())} tracked files")
    return 0


if __name__ == "__main__":
    sys.exit(main())
