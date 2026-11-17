"""Runs a benchmark script on the PHP class and on the utopia extension, alternating, and
prints the median of each operation side by side.

    python3 crates/extension/bench/run.py <script.php> <repository root> <utopia.so> <rounds>

The script prints one JSON object of operation -> nanoseconds; _APP_EXTENSION_LIBRARIES,
set to the script's name (`dsn.php` -> `dsn`), switches which implementation it gets.
"""
import json, os, statistics, subprocess, sys
script, root, lib, rounds = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
# OPcache is built into PHP 8.5; older PHP has it as a shared extension that -n leaves out.
flags = ["-n", "-d", "opcache.enable_cli=1", "-d", "memory_limit=1G"]
probe = 'echo function_exists("opcache_get_status") ? 1 : 0;'
if subprocess.run(["php", *flags, "-r", probe], capture_output=True, text=True).stdout.strip() != "1":
    flags[1:1] = ["-d", "zend_extension=opcache"]
active = 'echo (opcache_get_status(false)["opcache_enabled"] ?? false) ? 1 : 0;'
if subprocess.run(["php", *flags, "-r", active], capture_output=True, text=True).stdout.strip() != "1":
    sys.exit("OPcache is not enabled for the CLI; the numbers would not match production")
library = os.path.splitext(os.path.basename(script))[0]
results = {"php": [], "rust": []}
for _ in range(rounds):
    for impl in ("php", "rust"):
        env = dict(os.environ, _APP_EXTENSION_LIBRARIES=library if impl == "rust" else "")
        out = subprocess.run(["php", *flags, "-d", f"extension={lib}", script, root],
                             env=env, capture_output=True, text=True, check=True).stdout
        results[impl].append(json.loads(out.strip().splitlines()[-1]))
ops = [k for k in results["php"][0] if k != "peak memory MB"]
print(f"{'operation':28} {'PHP ns':>9} {'Rust ns':>9} {'Rust/PHP':>9}")
for op in ops:
    p = statistics.median(r[op] for r in results["php"]); r = statistics.median(x[op] for x in results["rust"])
    print(f"{op:28} {p:9.0f} {r:9.0f} {r/p:9.2f}")
