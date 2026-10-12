"""Adds the MariaDB/MySQL ports of the library's unit tests that check the
MySQL adapter (SQLGetDocumentTest, SQLTransactionTest, UniqueViolationTest)
to their case files, replacing the phase-2 PostgreSQL stand-ins.

    python3 tests/compat/database/recorder/unit_mysql.py

The tests mock PDO; here each runs against the compat MariaDB and MySQL
(`driver`), on the situation the mock stands for. Expectations come from
`bin/compat record database --case <file>`.
"""

import json
import os

CASES = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'cases')


def a(ref='a'):
    return {'adapter': {'$ref': ref}}


def step(op, ref='a', bind=None, **args):
    s = {'op': op, 'args': {**a(ref), **args}}
    if bind:
        s['bind'] = bind
    return s


def opened(driver, bind='a'):
    return {'op': 'adapter.open', 'args': {'driver': driver}, 'bind': bind}


def timeout_cases(driver, label):
    table = '`${ns}`.`ns_docs`'
    common = [
        opened(driver),
        step('adapter.before', event='*', name='capture'),
        step('adapter.create'),
        step('adapter.create_collection', name='docs'),
        opened(driver, 'b'),
        step('adapter.driver_exec', 'b', sql=f'LOCK TABLES {table} WRITE'),
        step('adapter.set_timeout', milliseconds=200),
    ]
    execute = common + [
        step('adapter.get_document', collection='docs', id='document'),
        step('adapter.driver_exec', 'b', sql='UNLOCK TABLES'),
        step('adapter.get_document', collection='docs', id='document'),
        step('adapter.captured'),
        step('adapter.delete'),
    ]
    fetch = common + [
        step('adapter.start_transaction'),
        step('adapter.get_document', collection='docs', id='document'),
        step('adapter.rollback_transaction'),
        step('adapter.driver_exec', 'b', sql='UNLOCK TABLES'),
        step('adapter.in_transaction'),
        step('adapter.captured'),
        step('adapter.delete'),
    ]
    return [
        {'name': f'testTranslatesExecuteTimeoutClosesCursorAndPreservesOriginalWhenCleanupFails ({label}: a read past its timeout behind a table lock)', 'state': [f'mysql@database-{driver}'], 'steps': execute},
        {'name': f'testTranslatesFetchTimeoutAndClosesCursor ({label}: the timeout inside a transaction)', 'state': [f'mysql@database-{driver}'], 'steps': fetch},
    ]


def transaction_cases(driver, label):
    desynced = [
        opened(driver),
        step('adapter.before', event='*', name='capture'),
        step('adapter.create'),
        step('adapter.start_transaction'),
        # DDL commits the transaction behind the adapter's back.
        step('adapter.driver_exec', sql='CREATE TABLE `${ns}`.`t` (x INT)'),
        step('adapter.in_transaction'),
        step('adapter.commit_transaction'),
        step('adapter.start_transaction'),
        step('adapter.in_transaction'),
        step('adapter.rollback_transaction'),
        step('adapter.in_transaction'),
        step('adapter.captured'),
        step('adapter.delete'),
    ]
    failed = [
        opened(driver),
        step('adapter.create'),
        step('adapter.start_transaction'),
        step('adapter.driver_exec', sql='CREATE TABLE `${ns}`.`t` (x INT)'),
        # Nested: a savepoint, outside any transaction now.
        step('adapter.start_transaction'),
        step('adapter.rollback_transaction'),
        step('adapter.rollback_transaction'),
        step('adapter.start_transaction'),
        step('adapter.driver_query', sql='SELECT nope FROM `${ns}`.`t`'),
        step('adapter.in_transaction'),
        step('adapter.commit_transaction'),
        step('adapter.delete'),
    ]
    return [
        {'name': f'testStartTransactionRecoversFromDesyncedRollback ({label}: the server committed behind the adapter)', 'state': [f'mysql@database-{driver}'], 'steps': desynced},
        {'name': f'testStartTransactionDoesNotMaskBeginFailureAfterDesyncedRollback ({label}: savepoints and errors after an implicit commit)', 'state': [f'mysql@database-{driver}'], 'steps': failed},
    ]


UNIQUE = [
    ('testMySQLDocumentIdConflictIsDuplicate', "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'movie-1' for key 'movies._uid'"),
    ('testMySQLPrimaryKeyConflictIsDuplicate', "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5' for key 'PRIMARY'"),
    ('testMySQLUniqueIndexConflictWithUidInValueIsUnique', "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'prefix_uid_suffix' for key 'slug'"),
    ('testMySQLUniqueIndexConflictWithUidInIndexNameIsUnique', "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a' for key 'movies.slug_uid_index'"),
    ('testMySQLUnparsableMessageIsDuplicate', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
]


def unique_cases():
    out = []
    for name, message in UNIQUE:
        out.append({'name': name, 'steps': [{'op': 'adapter.process_exception', 'args': {'driver': 'mysql', 'sqlstate': '23000', 'native': 1062, 'message': message}}]})
    codes = [('22007', 1366), ('HY000', 1366), ('70100', 1969), ('HY000', 3024), ('HY000', 3699), ('HY000', 3837), ('22004', 1138),
             ('42S01', 1050), ('42S21', 1060), ('42000', 1061), ('42000', 1071), ('22001', 1406), ('01000', 1265), ('22003', 1264),
             ('22003', 1690), ('HY000', 1690), ('42000', 1049), ('42S02', 1049), ('42S02', 1051), ('42000', 1091), ('42000', 1064),
             ('23000', 1452)]
    for driver in ('mariadb', 'mysql'):
        steps = [{'op': 'adapter.process_exception', 'args': {'driver': driver, 'sqlstate': s, 'native': n, 'message': f'm {s} {n}'}} for s, n in codes]
        steps.append({'op': 'adapter.process_exception', 'args': {'driver': driver, 'sqlstate': '23000', 'native': 1062, 'message': "Duplicate entry 'x' for key '_index1'"}})
        out.append({'name': f'{driver} error codes', 'steps': steps})
    return out


def update(file, prefix, cases):
    path = os.path.join(CASES, file)
    data = json.load(open(path, encoding='utf-8'))
    names = {c['name'] for c in cases}
    kept = [c for c in data['cases'] if not c['name'].startswith(prefix) and c['name'] not in names
            and not c['name'].endswith('(phase 2: MySQL adapter)')]
    data['cases'] = kept + cases
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=1, ensure_ascii=False)
        f.write('\n')
    print(file, len(data['cases']))


update('SQLGetDocumentTest.json', '\0', timeout_cases('mariadb', 'MariaDB') + timeout_cases('mysql', 'MySQL'))
update('SQLTransactionTest.json', '\0', transaction_cases('mariadb', 'MariaDB') + transaction_cases('mysql', 'MySQL'))
update('UniqueViolationTest.json', 'testMySQL', unique_cases())
