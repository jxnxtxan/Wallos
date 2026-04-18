#!/usr/bin/env bash
# Integration tests for api/income/* and api/ledger/get_ledger.php.
# Each request runs in a fresh PHP process (Wallos closes SQLite after each script).
#
# From repo root:
#   export WALLOS_TEST_API_KEY='your-key'   # optional
#   bash scripts/test_api_income_ledger.sh
#
# Requires: Docker, bash, jq

set -euo pipefail
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

API_KEY="${WALLOS_TEST_API_KEY:-wallos-test-api-key-001}"

if ! command -v jq &>/dev/null; then
  echo 'ERROR: jq is required (brew install jq).' >&2
  exit 2
fi

failed=0
pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; failed=$((failed + 1)); }

# chdir so connect_endpoint.php resolves ../../db/wallos.db to project db/
run_income_get() {
  docker run --rm -v "$ROOT:/var/www/html" -e "QUERY_STRING=$1" php:8.3-cli \
    php -r 'chdir("/var/www/html/api/income");
      $_SERVER["REQUEST_METHOD"]="GET";
      parse_str(getenv("QUERY_STRING")?:"", $_GET);
      $_REQUEST = $_GET;
      include "/var/www/html/api/income/get_income.php";'
}

run_ledger_get() {
  docker run --rm -v "$ROOT:/var/www/html" -e "QUERY_STRING=$1" php:8.3-cli \
    php -r 'chdir("/var/www/html/api/ledger");
      $_SERVER["REQUEST_METHOD"]="GET";
      parse_str(getenv("QUERY_STRING")?:"", $_GET);
      $_REQUEST = $_GET;
      include "/var/www/html/api/ledger/get_ledger.php";'
}

run_income_add_form() {
  docker run --rm -v "$ROOT:/var/www/html" -e "BODY=$1" php:8.3-cli \
    php -r 'chdir("/var/www/html/api/income");
      $_SERVER["REQUEST_METHOD"]="POST";
      $_SERVER["CONTENT_TYPE"]="application/x-www-form-urlencoded";
      parse_str(getenv("BODY")?:"", $_POST);
      $_GET = [];
      $_REQUEST = $_POST;
      include "/var/www/html/api/income/add_income.php";'
}

run_income_delete_form() {
  docker run --rm -v "$ROOT:/var/www/html" -e "BODY=$1" php:8.3-cli \
    php -r 'chdir("/var/www/html/api/income");
      $_SERVER["REQUEST_METHOD"]="POST";
      $_SERVER["CONTENT_TYPE"]="application/x-www-form-urlencoded";
      parse_str(getenv("BODY")?:"", $_POST);
      $_GET = [];
      $_REQUEST = $_POST;
      include "/var/www/html/api/income/delete_income.php";'
}

jtest() { echo "$1" | jq -e "$2" >/dev/null 2>&1; }

out="$(run_income_get "api_key=${API_KEY}&type=all")"
if jtest "$out" '.success == true' && jtest "$out" '(.entries | type) == "array"'; then
  pass 'get_income.php list'
else
  fail "get_income.php list: $out"
fi

out="$(run_income_get "api_key=${API_KEY}&id=1&item_type=entry")"
if jtest "$out" '.success == true and .item.id == 1'; then
  pass 'get_income.php single entry'
else
  fail "get_income.php single: $out"
fi

out="$(run_ledger_get "api_key=${API_KEY}&scope=month")"
if jtest "$out" '.success == true' && jtest "$out" '(.ledger.members | type) == "array"'; then
  pass 'get_ledger.php month'
else
  fail "get_ledger.php month: $out"
fi

out="$(run_ledger_get "api_key=${API_KEY}&scope=range")"
if jtest "$out" '.success == false'; then
  pass 'get_ledger.php range validation'
else
  fail "get_ledger.php range should fail: $out"
fi

out="$(run_ledger_get "api_key=${API_KEY}&scope=range&start_date=2026-01-01&end_date=2026-01-31")"
if jtest "$out" '.success == true'; then
  pass 'get_ledger.php range ok'
else
  fail "get_ledger.php range ok: $out"
fi

out="$(run_income_get "api_key=totally-wrong-key")"
if jtest "$out" '.success == false'; then
  pass 'get_income.php invalid api_key'
else
  fail "get_income.php invalid key: $out"
fi

add_body="api_key=${API_KEY}&type=entry&household_id=1&amount=77.77&currency_id=1&income_date=2026-03-01&note=sh_api_test_marker"
out="$(run_income_add_form "$add_body")"
if jtest "$out" '.success == true'; then
  pass 'add_income.php'
  list_out="$(run_income_get "api_key=${API_KEY}&type=entry")"
  nid="$(echo "$list_out" | jq -r '.entries[] | select(.note=="sh_api_test_marker") | .id' | head -1)"
  if [[ -z "${nid:-}" || "$nid" == "null" ]]; then
    fail "could not find added income row (list: $list_out)"
  else
    del_body="api_key=${API_KEY}&id=${nid}&type=entry"
    out2="$(run_income_delete_form "$del_body")"
    if jtest "$out2" '.success == true'; then
      pass 'delete_income.php'
    else
      fail "delete_income.php: $out2"
    fi
  fi
else
  fail "add_income.php: $out"
fi

echo ""
if [[ "$failed" -eq 0 ]]; then
  echo 'All API checks passed.'
else
  echo "${failed} check(s) failed."
fi
exit "$failed"
