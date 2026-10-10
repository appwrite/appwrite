#!/usr/bin/env python3
"""Summarises a users-compare.sh run into a Markdown report.

For every step it reports throughput, latency percentiles, CPU time per
request (API container and PostgreSQL + Redis), and memory (baseline,
average and peak under load).
"""
import csv
import glob
import json
import os
import sys

UNITS = {'B': 1, 'KiB': 1024, 'MiB': 1024 ** 2, 'GiB': 1024 ** 3, 'kB': 1000, 'MB': 1000 ** 2, 'GB': 1000 ** 3}


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


def main(directory):
    lines = [
        '| target | load | req/s | p50 ms | p95 ms | p99 ms | API CPU ms/req | DB+Redis CPU ms/req | mem baseline MiB | mem avg MiB | mem peak MiB | failures |',
        '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
    ]
    for meta_path in sorted(glob.glob(os.path.join(directory, '*.meta.json'))):
        label = os.path.basename(meta_path)[: -len('.meta.json')]
        meta = json.load(open(meta_path))
        k6_path = os.path.join(directory, f'{label}.k6.json')
        if not os.path.exists(k6_path):
            continue
        k6 = json.load(open(k6_path))
        rows = list(csv.reader(open(os.path.join(directory, f'{label}.stats.csv')))) if os.path.exists(os.path.join(directory, f'{label}.stats.csv')) else []
        container = meta['container']
        app_cpu = cgroup_delta(meta['cpu_app_usec'])
        if app_cpu is None:
            app_cpu = integrate(rows, container)
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
        lat = k6.get('latency', {})
        lines.append('| {t} | {m} {l} | {rps:.0f} | {p50:.1f} | {p95:.1f} | {p99:.1f} | {cpu:.2f} | {db:.2f} | {mb:.0f} | {ma:.0f} | {mp:.0f} | {f} |'.format(
            t=meta['target'], m=meta['mode'], l=meta['level'], rps=k6.get('rps', 0),
            p50=lat.get('p50') or 0, p95=lat.get('p95') or 0, p99=lat.get('p99') or 0,
            cpu=app_cpu * 1000 / requests, db=((db_cpu or 0) + (redis_cpu or 0)) * 1000 / requests,
            mb=baseline / 2 ** 20, ma=(sum(mems) / len(mems) if mems else 0) / 2 ** 20, mp=(max(mems) if mems else 0) / 2 ** 20,
            f=k6.get('failures', 0)))
    print('\n'.join(lines))


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else '.')
