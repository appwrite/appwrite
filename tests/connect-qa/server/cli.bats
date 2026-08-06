#!/usr/bin/env bats

setup_file() {
  local exit_code=0
  (cd "$APP_DIR" && bash -c "$SNIPPET_CMD") \
    > "$BATS_FILE_TMPDIR/stdout" 2> "$BATS_FILE_TMPDIR/stderr" \
    || exit_code=$?
  echo "$exit_code" > "$BATS_FILE_TMPDIR/exit-code"
}

print_captured_run() {
  echo "--- exit code ---"
  cat "$BATS_FILE_TMPDIR/exit-code"
  echo "--- stdout ---"
  cat "$BATS_FILE_TMPDIR/stdout"
  echo "--- stderr ---"
  cat "$BATS_FILE_TMPDIR/stderr"
}

@test "sample exits 0 (both API calls succeeded)" {
  print_captured_run
  [ "$(cat "$BATS_FILE_TMPDIR/exit-code")" -eq 0 ]
}

@test "sample prints the updated policy and the policy list" {
  print_captured_run
  # Two results are printed, so expect at least two non-empty stdout lines.
  [ "$(grep -c -v '^[[:space:]]*$' "$BATS_FILE_TMPDIR/stdout")" -ge 2 ]
  grep -Eiq "$SNIPPET_EXPECT" "$BATS_FILE_TMPDIR/stdout"
}
