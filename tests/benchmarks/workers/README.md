# Worker mode benchmarks

Compare **combined** (one `appwrite-worker` + one `appwrite-task-scheduler`) vs **separate** (per-queue worker/scheduler containers) for end-to-end latency and memory efficiency.

## Run

```bash
# Current branch: combined then separate
./tests/benchmarks/workers/run.sh

# Also swap the stack to `main` and re-run (destructive to running compose)
./tests/benchmarks/workers/run.sh --with-main

# Single mode
./tests/benchmarks/workers/run.sh combined
```

Optional env:

| Variable | Default | Meaning |
|----------|---------|---------|
| `APPWRITE_ENDPOINT` | `http://localhost/v1` | API base |
| `BENCH_USERS` | `50` | Users created/deleted |
| `BENCH_ATTRIBUTES` | `20` | Attributes (databases worker wait) |
| `BENCH_DOCUMENTS` | `200` | Documents create/delete |
| `BENCH_IDLE_SECONDS` | `8` | Idle memory sample window |

Results: `tests/benchmarks/workers/results/*.json`
