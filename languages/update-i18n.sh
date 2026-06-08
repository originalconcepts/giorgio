#!/usr/bin/env bash
# Standard i18n: POT → merge PO → compile MO.
# Run from plugin root: bash languages/update-i18n.sh
#
# Loco: edit ocws-he_IL.po, then either Save+Compile here, or run this script after Save.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

EXCLUDE=".git,node_modules,vendor,languages,.cursor,.claude,languages-copy,languages-2"

wp i18n make-pot . languages/ocws.pot \
  --slug=oc-woo-shipping \
  --domain=ocws \
  --package-name="Original Concepts WooCommerce Advanced Shipping" \
  --exclude="$EXCLUDE"

wp i18n update-po languages/ocws.pot languages/
wp i18n make-mo languages/

echo "OK: ocws.pot, ocws-he_IL.po + .mo, ocws-en_US.po + .mo"
