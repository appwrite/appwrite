#!/usr/bin/env python3
"""Summarises a compare.sh run of one service ($SERVICE).

For every step it reports throughput, latency percentiles, CPU time per
request (API container and PostgreSQL + Redis), and memory (baseline,
average and peak under load), as a Markdown table on stdout.

With `--json <path>` it also writes the run as JSON, per step and per
endpoint, with the environment it ran in. Committed as
tests/benchmarks/results/<service>.json, it is what `bin/compat appwrite`
shows on the docs site:

    SERVICE=users report.py <run directory> [--json tests/benchmarks/results/users.json]
"""
import csv
import datetime
import glob
import json
import os
import platform
import subprocess
import sys

UNITS = {'B': 1, 'KiB': 1024, 'MiB': 1024 ** 2, 'GiB': 1024 ** 3, 'kB': 1000, 'MB': 1000 ** 2, 'GB': 1000 ** 3}
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))


def parse_mem(text):
    value = text.split('/')[0].strip()
    for unit in sorted(UNITS, key=len, reverse=True):
        if value.endswith(unit):
            return float(value[: -len(unit)]) * UNITS[unit]
    try:
        return float(value)
    except ValueError:
        return 0.0


def cgroup_delta(spec):
    try:
        before, after = spec.split(':')
        return (int(after) - int(before)) / 1e6 if before and after else None
    except ValueError:
        return None


def integrate(rows, name):
    """CPU-seconds from `docker stats` samples (percent of one CPU)."""
    samples = sorted((int(r[0]), float(r[2].rstrip('%') or 0)) for r in rows if r[1] == name)
    total = 0.0
    for (t0, p0), (t1, _) in zip(samples, samples[1:]):
        total += p0 / 100.0 * max(t1 - t0, 1)
    return total


def seconds(duration):
    """`60s`, `2m`, `1m30s` → seconds."""
    total, number = 0.0, ''
    for c in str(duration):
        if c.isdigit() or c == '.':
            number += c
        elif number:
            total += float(number) * {'s': 1, 'm': 60, 'h': 3600}.get(c, 1)
            number = ''
    return total + (float(number) if number else 0.0)


def steps(directory):
    out = []
    for meta_path in sorted(glob.glob(os.path.join(directory, '*.meta.json'))):
        label = os.path.basename(meta_path)[: -len('.meta.json')]
        meta = json.load(open(meta_path))
        k6_path = os.path.join(directory, f'{label}.k6.json')
        if not os.path.exists(k6_path):
            continue
        k6 = json.load(open(k6_path))
        stats_path = os.path.join(directory, f'{label}.stats.csv')
        rows = list(csv.reader(open(stats_path))) if os.path.exists(stats_path) else []
        container = meta['container']
        app_cpu = cgroup_delta(meta['cpu_app_usec'])
        source = 'cgroup'
        if app_cpu is None:
            app_cpu = integrate(rows, container)
            source = 'docker stats'
        db_cpu = cgroup_delta(meta['cpu_db_usec'])
        redis_cpu = cgroup_delta(meta['cpu_redis_usec'])
        if db_cpu is None:
            db_cpu = sum(integrate(rows, n) for n in {r[1] for r in rows if r[1] != container})
            redis_cpu = 0.0
        requests = max(k6.get('requests', 0), 1)
        mems = [parse_mem(r[3]) for r in rows if r[1] == container and len(r) > 3]
        baseline = 0.0
        baseline_path = os.path.join(directory, f'{label}.baseline.txt')
        if os.path.exists(baseline_path):
            content = open(baseline_path).read().strip().split(',')
            if len(content) > 1:
                baseline = parse_mem(content[1])
        out.append({
            'label': label, 'meta': meta, 'k6': k6, 'requests': requests, 'cpu_source': source,
            'app_cpu': app_cpu, 'db_cpu': (db_cpu or 0) + (redis_cpu or 0),
            'mem_baseline': baseline, 'mem_avg': sum(mems) / len(mems) if mems else 0.0, 'mem_peak': max(mems) if mems else 0.0,
        })
    return out


def markdown(found):
    lines = [
        '| target | load | req/s | p50 ms | p95 ms | p99 ms | API CPU ms/req | DB+Redis CPU ms/req | mem baseline MiB | mem avg MiB | mem peak MiB | failures |',
        '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
    ]
    for s in found:
        meta, k6, lat = s['meta'], s['k6'], s['k6'].get('latency', {})
        lines.append('| {t} | {m} {l} | {rps:.0f} | {p50:.1f} | {p95:.1f} | {p99:.1f} | {cpu:.2f} | {db:.2f} | {mb:.0f} | {ma:.0f} | {mp:.0f} | {f} |'.format(
            t=meta['target'], m=meta['mode'], l=meta['level'], rps=k6.get('rps', 0),
            p50=lat.get('p50') or 0, p95=lat.get('p95') or 0, p99=lat.get('p99') or 0,
            cpu=s['app_cpu'] * 1000 / s['requests'], db=s['db_cpu'] * 1000 / s['requests'],
            mb=s['mem_baseline'] / 2 ** 20, ma=s['mem_avg'] / 2 ** 20, mp=s['mem_peak'] / 2 ** 20,
            f=k6.get('failures', 0)))
    return '\n'.join(lines)


def run(*args):
    try:
        return subprocess.run(args, capture_output=True, text=True, timeout=30, cwd=ROOT).stdout.strip() or None
    except (OSError, subprocess.SubprocessError):
        return None


def cpu_model():
    if platform.system() == 'Darwin':
        return run('sysctl', '-n', 'machdep.cpu.brand_string')
    try:
        for line in open('/proc/cpuinfo'):
            if line.startswith('model name'):
                return line.split(':', 1)[1].strip()
    except OSError:
        pass
    return platform.processor() or None


def image(container):
    found = run('docker', 'inspect', '-f', '{{.Config.Image}} {{.Image}} {{.Created}}', container)
    if not found:
        return {'container': container}
    name, digest, created = (found.split() + [None, None, None])[:3]
    return {'container': container, 'image': name, 'id': (digest or '').replace('sha256:', '')[:12], 'created': created}


def document(found, directory):
    duration = os.environ.get('DURATION', '60s')
    workload_seconds = seconds(duration) or None
    containers = {s['meta']['target']: s['meta']['container'] for s in found}
    out_steps = []
    for s in found:
        meta, k6 = s['meta'], s['k6']
        workload = k6.get('workload', {})
        measured = workload.get('requests') or 0
        endpoints = {}
        for name, e in (k6.get('endpoints') or {}).items():
            endpoints[name] = {
                'route': e.get('route'),
                **({'note': e['note']} if e.get('note') else {}),
                'count': e.get('count'),
                'rps': round(e['count'] / workload_seconds, 2) if workload_seconds and e.get('count') else None,
                **{k: round(e[k], 3) for k in ('avg', 'p50', 'p90', 'p95', 'p99', 'max') if e.get(k) is not None},
            }
        out_steps.append({
            'target': meta['target'],
            'mode': meta['mode'],
            'level': meta['level'],
            'rps': round(measured / workload_seconds, 1) if workload_seconds and measured else round(k6.get('rps', 0), 1),
            'requests': measured or k6.get('requests', 0),
            'failures': k6.get('failures', 0),
            'dropped': workload.get('dropped', 0),
            'latency': {k: round(v, 3) for k, v in (k6.get('latency') or {}).items() if v is not None},
            'cpu_ms_per_request': round(s['app_cpu'] * 1000 / s['requests'], 3),
            'db_cpu_ms_per_request': round(s['db_cpu'] * 1000 / s['requests'], 3),
            'memory_mib': {
                'baseline': round(s['mem_baseline'] / 2 ** 20, 1),
                'avg': round(s['mem_avg'] / 2 ** 20, 1),
                'peak': round(s['mem_peak'] / 2 ** 20, 1),
            },
            'endpoints': endpoints,
        })
    service = os.environ.get('SERVICE', 'users')
    memory = run('sysctl', '-n', 'hw.memsize') if platform.system() == 'Darwin' else None
    return {
        'service': service,
        'file': f'tests/benchmarks/results/{service}.json',
        'workload': {
            'script': f'tests/benchmarks/api/{service}.js',
            'duration': duration,
            'hashing': os.environ.get('HASHING', 'false') == 'true',
            'seed': int(os.environ.get('APPWRITE_BENCHMARK_SEED', '200')),
            'run': os.path.basename(os.path.normpath(directory)),
        },
        'environment': {
            'date': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
            'commit': run('git', 'rev-parse', '--short', 'HEAD'),
            'branch': run('git', 'rev-parse', '--abbrev-ref', 'HEAD'),
            'machine': {
                'os': platform.system(),
                'arch': platform.machine(),
                'cpu': cpu_model(),
                'cores': os.cpu_count(),
                'memory_gib': round(int(memory) / 2 ** 30) if memory and memory.isdigit() else None,
            },
            'docker': run('docker', 'version', '--format', '{{.Server.Version}}'),
            'k6': (run('k6', 'version') or '').split(' (')[0] or None,
            'cpu_source': found[0]['cpu_source'] if found else None,
            'php': image(containers.get('php', 'appwrite')),
            'rust': image(containers.get('rust', 'appwrite-rust')),
            'database': image(os.environ.get('DB_CONTAINER', 'appwrite-postgresql')),
            'endpoint': {'php': os.environ.get('PHP_ENDPOINT'), 'rust': os.environ.get('RUST_ENDPOINT')},
        },
        'steps': out_steps,
    }


def main(argv):
    args = [a for a in argv if not a.startswith('--')]
    directory = args[0] if args else '.'
    found = steps(directory)
    print(markdown(found))
    if '--json' in argv:
        path = argv[argv.index('--json') + 1]
        with open(path, 'w') as f:
            json.dump(document(found, directory), f, indent=2)
            f.write('\n')
        print(f'wrote {path}', file=sys.stderr)


if __name__ == '__main__':
    main(sys.argv[1:])
