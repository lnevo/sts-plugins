#!/usr/bin/env sh
# Fails when a plugin other than hart_layout names a HART job, place, shipment or host.
# Layout values belong in settings or in a layout plugin's preset.
cd "$(dirname "$0")/.." || exit 2
PATTERN='SOUTH-SCALE|SHEN-COKE|SHEN|Shenango|Neville|CK1|D749|\bNVL\b|STG-|Scully|SCULLY|Demmler|DEMMLER|McKees|Mckeesport|COKE-USS|COKE-CLEV|COKE-RELOAD|COST1|minipc|hart_[a-z]'
hits=$(grep -rnE "$PATTERN" plugins --include='*.php' --include='*.js' --include='*.json' --include='*.md' | grep -v '^plugins/hart_layout/')
if [ -n "$hits" ]; then
  echo "$hits"
  echo "FAIL: layout names outside plugins/hart_layout"
  exit 1
fi
echo "ok: no layout names outside plugins/hart_layout"
