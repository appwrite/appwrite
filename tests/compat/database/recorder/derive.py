"""Derives the MariaDB and MySQL adapter cases from the PostgreSQL ones.

    python3 tests/compat/database/recorder/derive.py

Every `cases/Adapter-<Area>.json` (but `Adapter-PostgresTest.json`, the
PostgreSQL suite's own tests) becomes `cases/Adapter-MariaDB-<Area>.json`
and `cases/Adapter-MySQL-<Area>.json`: the same steps on an adapter opened
with `driver` `mariadb` or `mysql` (and `process_exception` and the fuzz
operations with that driver), each case snapshotting the database
(`mysql@database-<driver>`), without expectations: `bin/compat record
database --case Adapter-MariaDB` (and `Adapter-MySQL`) records them from
PHP. Re-run it when the PostgreSQL cases change.
"""

import copy
import glob
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
CASES = os.path.join(os.path.dirname(HERE), 'cases')
DRIVER_OPS = {'adapter.open', 'adapter.process_exception', 'adapter.fuzz_find', 'adapter.fuzz_roundtrip'}
DRIVERS = {'mariadb': 'MariaDB', 'mysql': 'MySQL'}


UNORDERED = "MySQL 8 lists INFORMATION_SCHEMA.COLUMNS, which getSchemaAttributes() reads without an ORDER BY, in no fixed order"


def derive_step(step, driver):
    step = copy.deepcopy(step)
    step.pop('expect', None)
    if driver == 'mysql' and step.get('op') == 'adapter.schema_attributes':
        step['mask'] = {'paths': [''], 'reason': UNORDERED}
    if step.get('op') in DRIVER_OPS:
        args = step.get('args')
        if not isinstance(args, dict):
            args = {}
        step['args'] = {**args, 'driver': driver}
    return step


def derive_case(case, driver):
    out = {k: v for k, v in case.items() if k not in ('steps', 'expect', 'state')}
    if 'steps' in case:
        out['steps'] = [derive_step(s, driver) for s in case['steps']]
    elif 'op' in case:
        out.update(derive_step({k: v for k, v in case.items() if k in ('op', 'args')}, driver))
    out['state'] = [f'mysql@database-{driver}']
    return out


def main():
    for path in sorted(glob.glob(os.path.join(CASES, 'Adapter-*.json'))):
        name = os.path.basename(path)[len('Adapter-'):-len('.json')]
        if name == 'PostgresTest' or name.split('-')[0] in DRIVERS.values():
            continue
        data = json.load(open(path, encoding='utf-8'))
        for driver, label in DRIVERS.items():
            derived = {
                'source': f'vendor/utopia-php/database/src/Database/Adapter/{label}.php',
                'cases': [derive_case(c, driver) for c in data['cases']],
            }
            target = os.path.join(CASES, f'Adapter-{label}-{name}.json')
            with open(target, 'w', encoding='utf-8') as f:
                json.dump(derived, f, indent=1, ensure_ascii=False)
                f.write('\n')
            print(target, len(derived['cases']))


if __name__ == '__main__':
    main()
