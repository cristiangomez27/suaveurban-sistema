#!/usr/bin/env bash
set -euo pipefail

if rg -n "<<<<<<<|>>>>>>>" --glob '*.php' .; then
  echo "Se detectaron marcadores de conflicto en archivos PHP."
  exit 1
fi

echo "OK: no hay marcadores de conflicto en archivos PHP."
