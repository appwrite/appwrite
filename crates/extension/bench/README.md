# Benchmark

`Utopia\Image\Image` under Swoole: the PHP class (Imagick) against the extension.

```sh
docker build --target development --tag appwrite-utopia --build-arg UTOPIA_EXTENSION=true --build-arg TESTING=true .
python3 crates/extension/bench/run.py
```

`server.php` is a Swoole HTTP server that does what Appwrite's preview endpoints do with an
image (decode, crop, encode), with Appwrite's 64 MB image memory limit. It is not the Appwrite
stack: no auth, database or storage. Both implementations run in the same image, in a container
limited to 4 CPUs with 4 workers; the Imagick run leaves the extension's ini file out. `run.py`
gives each request shape a fresh container, a warm-up, a closed-loop run with 8 connections
(throughput) and an open-loop run at half the slower implementation's throughput (latency at
equal load). CPU time and memory are the container's cgroup counters. It needs k6.

## Results

2026-11-11, an Apple Silicon laptop (Docker Desktop, 10 cores), 15 seconds a run, k6 on the same
machine: indicative, and a second run moved throughput by up to 15%. Each cell is Imagick → extension.

| Request | Throughput, req/s | CPU per request, ms | Peak memory, MB | p99 at equal load, ms |
| --- | --- | --- | --- | --- |
| Photo to 400×400 JPEG | 53 → 133 | 73.9 → 21.8 | 309 → 120 | 144 → 40 (27/s) |
| Photo to 800×800 WebP | 20 → 33 | 146.7 → 59.5 | 248 → 97 | 358 → 72 (10/s) |
| Photo to 400×400 AVIF | 24 → 25 | 154.3 → 98.8 | 310 → 120 | 350 → 361 (12/s) |
| Large PNG to 400×400 PNG | 9 → 43 | 216.5 → 68.9 | 239 → 142 | 313 → 136 (4/s) |
| 100×100 PNG to 64×64 JPEG | 2,945 → 10,718 | 0.9 → 0.3 | 108 → 53 | 6 → 2 (1472/s) |
| Animated GIF to 160×160 GIF | 4 → 13 | 851.3 → 227.6 | 956 → 155 | 821 → 260 (2/s) |

No request failed in any run. Without the 64 MB limit the first and fourth rows come out the same.

AVIF ties on throughput because both spend the time in the same encoder (libheif with libaom).
