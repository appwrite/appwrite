#!/usr/bin/env python3
"""Benchmark Utopia\\Image\\Image under Swoole: the PHP class (Imagick) against the utopia extension.

    docker build --target development --tag appwrite-utopia --build-arg UTOPIA_EXTENSION=true --build-arg TESTING=true .
    python3 crates/extension/bench/run.py [--duration 15] [--workers 4] [--out target/bench]

Both run in the same image, limited to as many CPUs as it has workers; the extension is turned
off by leaving its ini file out. Per request shape and implementation, a fresh container gets a
warm-up, a closed-loop run (throughput) and an open-loop run at half the slower implementation's
throughput (latency at equal load). CPU time and memory come from the container's cgroup. Needs k6.
"""
import argparse
import json
import os
import pathlib
import subprocess
import time
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[2]
RESOURCES = ROOT / 'packages/image/tests/resources'
SOURCES = {'photo.jpg': 'disk-a/kitten-1.jpg', 'large.png': 'resize/opacity_0.2.png',
           'small.png': 'resize/100x100.png', 'animation.gif': 'disk-a/kitten-3.gif'}
SHAPES = [
    ('photo 1837x1920 to 400x400 JPEG q80', 'src=photo.jpg&width=400&height=400&output=jpg&quality=80'),
    ('photo 1837x1920 to 800x800 WebP q75', 'src=photo.jpg&width=800&height=800&output=webp&quality=75'),
    ('photo 1837x1920 to 400x400 AVIF q60', 'src=photo.jpg&width=400&height=400&output=avif&quality=60'),
    ('PNG 1837x1920 with alpha to 400x400 PNG', 'src=large.png&width=400&height=400&output=png'),
    ('PNG 100x100 to 64x64 JPEG', 'src=small.png&width=64&height=64&output=jpg&quality=80'),
    ('animated GIF 320x320 to 160x160 GIF', 'src=animation.gif&width=160&height=160&output=gif'),
]
PORT = 18080
NAME = 'utopia-image-bench'


def sh(*args, **kwargs):
    return subprocess.run(args, check=True, capture_output=True, text=True, **kwargs).stdout


def cgroup(path):
    return sh('docker', 'exec', NAME, 'cat', f'/sys/fs/cgroup/{path}')


def cpu_seconds():
    return int(next(line for line in cgroup('cpu.stat').splitlines() if line.startswith('usage_usec')).split()[1]) / 1e6


def start(implementation, workers):
    subprocess.run(['docker', 'rm', '-f', NAME], capture_output=True)
    scan = ['-e', 'PHP_INI_SCAN_DIR=/tmp/ini'] if implementation == 'imagick' else []
    sh('docker', 'run', '-d', '--name', NAME, '--cpus', str(workers), '--memory', '4g', '-p', f'127.0.0.1:{PORT}:8080',
       '-e', f'WORKERS={workers}', '-e', f'IMAGE_MEMORY_LIMIT={os.environ.get("IMAGE_MEMORY_LIMIT", "")}', *scan, '-v', f'{ROOT}/packages:/usr/src/code/packages:ro', '-v', f'{HERE}:/bench:ro',
       'appwrite-utopia', 'sh', '-c',
       # The Imagick run scans a copy of conf.d without the extension's ini.
       'mkdir -p /tmp/ini && cp /usr/local/etc/php/conf.d/*.ini /tmp/ini/ && rm -f /tmp/ini/utopia.ini && sleep infinity')
    sh('docker', 'exec', NAME, 'mkdir', '-p', '/tmp/sources')
    for name, source in SOURCES.items():
        sh('docker', 'cp', str(RESOURCES / source), f'{NAME}:/tmp/sources/{name}')
    sh('docker', 'exec', '-d', NAME, 'php', '/bench/server.php')
    for _ in range(100):
        try:
            found = urllib.request.urlopen(f'http://127.0.0.1:{PORT}/implementation', timeout=1).read().decode()
            assert found == implementation, f'wanted {implementation}, the server runs {found}'
            return
        except OSError:
            time.sleep(0.1)
    raise SystemExit('the server did not start')


def load(query, duration, **env):
    out = sh('k6', 'run', '--quiet', '--no-usage-report', '-e', f'URL=http://127.0.0.1:{PORT}/preview?{query}',
             '-e', f'DURATION={duration}s', *(arg for key, value in env.items() for arg in ('-e', f'{key}={value}')),
             str(HERE / 'load.js'))
    return json.loads(out[out.index('{'):])


def measure(implementation, query, workers, duration, rate=None):
    start(implementation, workers)
    load(query, 3, VUS=workers)  # warm-up
    idle = int(cgroup('memory.current'))
    before = cpu_seconds()
    result = load(query, duration, RATE=rate) if rate else load(query, duration, VUS=workers * 2)
    result['cpu_ms'] = (cpu_seconds() - before) * 1000 / max(result['requests'], 1)
    result['memory_idle'] = idle
    result['memory_peak'] = int(cgroup('memory.peak'))
    result['response_bytes'] = result['bytes'] / max(result['requests'], 1)
    subprocess.run(['docker', 'rm', '-f', NAME], capture_output=True)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--duration', type=int, default=15)
    parser.add_argument('--workers', type=int, default=4)
    parser.add_argument('--out', type=pathlib.Path, default=ROOT / 'target/bench')
    args = parser.parse_args()
    rows = []
    for label, query in SHAPES:
        closed = {name: measure(name, query, args.workers, args.duration) for name in ('imagick', 'extension')}
        rate = max(1, round(min(run['rate'] for run in closed.values()) / 2))
        opened = {name: measure(name, query, args.workers, args.duration, rate=rate) for name in ('imagick', 'extension')}
        rows.append({'shape': label, 'query': query, 'rate': rate, 'closed': closed, 'open': opened})
        print(f'{label}: ' + ', '.join(f'{name} {run["rate"]:.0f} req/s, {run["cpu_ms"]:.1f} CPU ms/req' for name, run in closed.items()),
              flush=True)
    args.out.mkdir(parents=True, exist_ok=True)
    (args.out / 'results.json').write_text(json.dumps({'workers': args.workers, 'duration': args.duration, 'shapes': rows}, indent=1) + '\n')
    print(f'wrote {args.out / "results.json"}')


if __name__ == '__main__':
    main()
