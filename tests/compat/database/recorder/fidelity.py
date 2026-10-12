"""Compares what PHP returned while the e2e suites were recorded with the
expectations `bin/compat record` wrote for the cases, test by test: where a
case's own steps differ, its dependency slice does not reproduce the suite's
state (or a value is nondeterministic).

    python3 tests/compat/database/recorder/fidelity.py <postgres|shared> [-v]

Values are compared after the same scrubs as the runner's (now, uniqid, long)
plus masking sequences, which a slice legitimately renumbers.
"""

import datetime
import glob
import hashlib
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
CASES = os.path.join(HERE, '..', 'cases')
NOW = datetime.datetime.now(datetime.timezone.utc).timestamp()
DATETIME = re.compile(r'^(\d{4}-\d\d-\d\d)[T ](\d\d:\d\d:\d\d)(\.\d+)?([+-]\d\d:?\d\d|Z)?$')
TOKEN = re.compile(r'[0-9a-z]+', re.I)


def near(s):
    m = DATETIME.match(s)
    if not m:
        return False
    try:
        t = datetime.datetime.fromisoformat(f"{m.group(1)}T{m.group(2)}{m.group(3) or ''}{m.group(4) or '+00:00'}")
    except ValueError:
        return False
    return abs(t.timestamp() - NOW) <= 180000


def uniqid(t):
    if not 13 <= len(t) <= 36 or not re.fullmatch(r'[0-9a-f]+', t):
        return False
    return abs(int(t[:8], 16) - NOW) <= 86400 * 3


def scrub(v, key=None):
    if isinstance(v, str):
        if len(v) > 65536:
            return f"<long:{len(v.encode())}:{hashlib.sha256(v.encode()).hexdigest()}>"
        if near(v):
            return '<now>'
        if key in ('$sequence', '$id') and v.isdigit():
            return '<sequence>'
        return TOKEN.sub(lambda m: '<uniqid>' if uniqid(m.group(0)) else m.group(0), v)
    if isinstance(v, list):
        return [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: scrub(x, k) for k, x in v.items()}
    return v


def main():
    profile = sys.argv[1] if len(sys.argv) > 1 else 'postgres'
    verbose = '-v' in sys.argv
    out = json.load(open(os.path.join(HERE, 'out', f'{profile}.json')))
    originals = out['originals']
    prefix = 'Scope-SharedTables-' if profile == 'shared' else 'Scope-'
    totals = {'cases': 0, 'faithful': 0, 'steps': 0, 'same': 0}
    per_file = {}
    for path in sorted(glob.glob(os.path.join(CASES, '*.json'))):
        name = os.path.basename(path)
        if not (name.startswith(prefix) or (profile == 'postgres' and name == 'PostgresTest.json')):
            continue
        if profile == 'postgres' and name.startswith('Scope-SharedTables-'):
            continue
        data = json.load(open(path))
        f = per_file.setdefault(name, {'cases': 0, 'faithful': 0, 'steps': 0, 'same': 0})
        for case in data['cases']:
            own = originals.get(case['name'], [])
            steps = case['steps'][-len(own):] if own else []
            steps = [s for s in steps]
            # db.log steps follow some own steps; align on ops.
            recorded = [s for s in case['steps'] if s['op'] != 'db.log'][-len(own):] if own else []
            same = 0
            first = None
            for o, s in zip(own, recorded):
                if 'expect' not in s:
                    continue
                a, b = scrub(o['result']), scrub(s['expect'])
                if a == b:
                    same += 1
                elif first is None:
                    first = (o['op'], json.dumps(a)[:300], json.dumps(b)[:300])
            f['cases'] += 1
            f['steps'] += len(own)
            f['same'] += same
            if same == len(own):
                f['faithful'] += 1
            elif verbose and first:
                print(f"{case['name']}: {same}/{len(own)}; first difference at {first[0]}\n    suite:  {first[1]}\n    case:   {first[2]}")
    for name, f in per_file.items():
        for k in totals:
            totals[k] += f[k]
        print(f"{name}: {f['faithful']}/{f['cases']} cases faithful, {f['same']}/{f['steps']} own steps identical")
    print(f"total: {totals['faithful']}/{totals['cases']} cases faithful, {totals['same']}/{totals['steps']} own steps identical")


main()
