#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${KRAYIN_ROOT:-}" ]]; then
    echo "Set KRAYIN_ROOT to the extracted Krayin CRM v2.2.8 source directory." >&2
    exit 2
fi

exec php "$(dirname "$0")/krayin-import-csv-repro.php"
