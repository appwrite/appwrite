"""Runs a benchmark script on the PHP class and on the utopia extension, alternating, and
prints the median of each operation side by side.

    python3 crates/extension/bench/run.py <script.php> <repository root> <utopia.so> <rounds>

The script prints one JSON object of operation -> nanoseconds; _APP_EXTENSION_LIBRARIES
switches which implementation it gets.
"""
import json, os, statistics, subprocess, sys
script, root, lib, rounds = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
results = {"php": [], "rust": []}
for _ in range(rounds):
    for impl in ("php", "rust"):
        env = dict(os.environ, _APP_EXTENSION_LIBRARIES="dsn" if impl == "rust" else "")
        out = subprocess.run(["php", "-n", "-d", "opcache.enable_cli=1", "-d", "memory_limit=1G", "-d", f"extension={lib}", script, root],
                             env=env, capture_output=True, text=True, check=True).stdout
        results[impl].append(json.loads(out.strip().splitlines()[-1]))
ops = [k for k in results["php"][0] if k != "peak memory MB"]
print(f"{'operation':28} {'PHP ns':>9} {'Rust ns':>9} {'Rust/PHP':>9}")
for op in ops:
    p = statistics.median(r[op] for r in results["php"]); r = statistics.median(x[op] for x in results["rust"])
    print(f"{op:28} {p:9.0f} {r:9.0f} {r/p:9.2f}")
