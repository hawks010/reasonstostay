#!/usr/bin/env bash
set -euo pipefail

THEME_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPORT="$THEME_DIR/tools/self-audit-report.txt"

pass_count=0
fail_count=0

section() {
  echo "" | tee -a "$REPORT"
  echo "== $1 ==" | tee -a "$REPORT"
}

pass() {
  echo "PASS: $1" | tee -a "$REPORT"
  pass_count=$((pass_count + 1))
}

fail() {
  echo "FAIL: $1" | tee -a "$REPORT"
  fail_count=$((fail_count + 1))
}

# reset report
: > "$REPORT"
echo "RTS Self-Audit Report" | tee -a "$REPORT"
echo "Theme: $THEME_DIR" | tee -a "$REPORT"
echo "Date: $(date -u +"%Y-%m-%d %H:%M:%SZ")" | tee -a "$REPORT"

section "1. PHP syntax lint"
php_files=$(find "$THEME_DIR" -type f -name "*.php" | wc -l | tr -d ' ')
echo "PHP files: $php_files" | tee -a "$REPORT"
lint_failed=0
while IFS= read -r f; do
  out=$(php -l "$f" 2>&1) || true
  if ! echo "$out" | grep -q "No syntax errors detected"; then
    lint_failed=1
    echo "$out" | tee -a "$REPORT"
  fi
done < <(find "$THEME_DIR" -type f -name "*.php" -print)
if [ "$lint_failed" -eq 0 ]; then
  pass "All PHP files pass php -l"
else
  fail "Some PHP files failed php -l"
fi

section "2. Stub and truncation markers"
markers_failed=0
markers=("@@generated" "@generated" "TODO" "FIXME" "stub" "pass;" "<<<<<<<" ">>>>>>>" "=======")
for m in "${markers[@]}"; do
  if grep -R --line-number --fixed-string "$m" "$THEME_DIR/src" "$THEME_DIR/inc" >/dev/null 2>&1; then
    markers_failed=1
    echo "Found marker: $m" | tee -a "$REPORT"
    grep -R --line-number --fixed-string "$m" "$THEME_DIR/src" "$THEME_DIR/inc" | head -n 20 | tee -a "$REPORT"
  fi
done
if [ "$markers_failed" -eq 0 ]; then
  pass "No stub/truncation markers found in src/inc"
else
  fail "Stub/truncation markers present"
fi

section "3. Required Action Scheduler hooks wired"
search_dirs=()
if [ -d "$THEME_DIR/inc" ]; then search_dirs+=("$THEME_DIR/inc"); fi
if [ -d "$THEME_DIR/src" ]; then search_dirs+=("$THEME_DIR/src"); fi
hooks=("rts_process_letter" "rts_auto_process_tick" "rts_bulk_rescan" "rts_bulk_admin_action" "rts_rescan_quarantine_loop" "rts_scan_pump" "rts_aggregate_analytics" "rts_process_import_batch")
for h in "${hooks[@]}"; do
  if grep -R --line-number -E "add_action\([^\)]*['\"]${h}['\"]" "${search_dirs[@]}" >/dev/null 2>&1; then
    pass "Hook handler registered for $h"
  else
    fail "No add_action handler found for $h"
  fi
done

section "4. Bulk action nonce verification"
if grep -R --line-number "wp_verify_nonce" "$THEME_DIR/inc/cpt-letters-complete.php" >/dev/null 2>&1; then
  pass "Bulk handler contains explicit nonce verification"
else
  fail "Bulk handler missing explicit nonce verification"
fi


section "5. Bulk action async behaviour"
if grep -n "foreach (\$post_ids" "$THEME_DIR/inc/cpt-letters-complete.php" >/dev/null 2>&1; then
  fail "Bulk handler appears to loop inline over post_ids (must be async only)"
else
  pass "Bulk handler does not loop inline over post_ids"
fi

section "6. Bulk async job handlers present"
jobs_file="$THEME_DIR/inc/rts-bulk-jobs.php"
if [ -f "$jobs_file" ] && grep -q "function rts_handle_bulk_rescan" "$jobs_file"; then
  pass "Bulk rescan batch job handler exists"
else
  fail "Bulk rescan batch job handler missing"
fi
if [ -f "$jobs_file" ] && grep -q "function rts_handle_bulk_admin_action" "$jobs_file"; then
  pass "Bulk admin action batch job handler exists"
else
  fail "Bulk admin action batch job handler missing"
fi
if [ -f "$jobs_file" ] && grep -q "function rts_handle_quarantine_loop" "$jobs_file"; then
  pass "Quarantine rescan loop handler exists"
else
  fail "Quarantine rescan loop handler missing"
fi

section "7. Final summary"

echo "" | tee -a "$REPORT"
echo "Pass: $pass_count" | tee -a "$REPORT"
echo "Fail: $fail_count" | tee -a "$REPORT"

if [ "$fail_count" -eq 0 ]; then
  echo "OVERALL: PASS" | tee -a "$REPORT"
  exit 0
fi

echo "OVERALL: FAIL" | tee -a "$REPORT"
exit 1
