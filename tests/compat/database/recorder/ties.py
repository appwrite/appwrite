"""Masks the rows of join finds whose order the library leaves to the server.

    python3 tests/compat/database/recorder/ties.py [--write]

Database::find() orders by the find's orders, then the main document's
$sequence, then (where the rows show the join) each joined $id
(addTieBreaks()). Rows equal on all of those as recorded (a joined $id the
library generated is scrubbed to <uniqid>, so rows ordered by it tie too)
come back in the order the server's plan or the random ids give: each such
row gets a mask, and a later step paging from one of them (a cursor taken
from it) is masked whole. Without --write it only lists them. Run it after
`bin/compat record database` writes new expectations for join cases (Mirror
cases are left out).
"""

import glob
import json
import sys

WRITE = '--write' in sys.argv
ROW_REASON = "rows a join leaves tied on every attribute the find orders by (its orders, then the main document's $sequence) come back in the order the server's plan picks"
PAGE_REASON = "pages from a row of a join tied with others on every ordered attribute: which row that is depends on the server's plan"
OPS = ('database.find', 'database.cursor')


def orders(args):
    out = []
    for q in args.get('queries') or []:
        if isinstance(q, dict) and isinstance(q.get('$query'), dict):
            m = q['$query']
            if m.get('method') in ('orderAsc', 'orderDesc'):
                out.append((m.get('alias') or '', m.get('attribute') or ''))
    return out


def joined(args):
    return any(isinstance(q, dict) and isinstance(q.get('$query'), dict) and 'join' in (q['$query'].get('method') or '').lower()
               for q in args.get('queries') or [])


def key(row, ords, aliases):
    vals = []
    for alias, attr in ords:
        k = f'{alias}.{attr}' if alias and f'{alias}.{attr}' in row else attr
        vals.append(json.dumps(row.get(k)))
    vals.append(json.dumps(row.get('$sequence')))
    # Database::find() breaks ties on each joined $id where the rows show the join (addTieBreaks()).
    for alias in aliases:
        vals.append(json.dumps(row.get(f'{alias}.$id')))
    return tuple(vals)


def join_aliases(args):
    return [q['$query'].get('alias') or '' for q in args.get('queries') or []
            if isinstance(q, dict) and isinstance(q.get('$query'), dict) and 'join' in (q['$query'].get('method') or '').lower()]


def tied(result, ords, aliases):
    if not isinstance(result, list):
        return []
    rows = [r.get('$document') if isinstance(r, dict) else None for r in result]
    if any(not isinstance(r, dict) for r in rows):
        return []
    keys = [key(r, ords, aliases) for r in rows]
    out = set()
    for i in range(len(rows)):
        for j in range(i + 1, len(rows)):
            if keys[i] == keys[j] and rows[i] != rows[j]:
                out.update((i, j))
    return sorted(out)


def refs(value, found):
    if isinstance(value, dict):
        if '$ref' in value:
            found.append((value['$ref'], value.get('path', '')))
        for v in value.values():
            refs(v, found)
    elif isinstance(value, list):
        for v in value:
            refs(v, found)
    return found


total_rows = total_pages = 0
for f in sorted(glob.glob('tests/compat/database/cases/*.json')):
    name = f.split('/')[-1]
    if 'Mirror' in name:
        continue
    d = json.load(open(f))
    changed = False
    for c in d['cases']:
        masked = {}  # bind -> tied row indexes
        for i, s in enumerate(c.get('steps', [])):
            a = s.get('args') or {}
            used = [(b, p) for b, p in refs(a, []) if b in masked]
            if any(p.split('/')[1:2] and p.split('/')[1].isdigit() and int(p.split('/')[1]) in masked[b] for b, p in used):
                if s.get('mask', {}).get('paths') != ['']:
                    s['mask'] = {'paths': [''], 'reason': PAGE_REASON}
                    changed = True
                    total_pages += 1
                    print('page', name, c['name'], i)
                continue
            if s['op'] not in OPS or not joined(a) or s.get('mask'):
                continue
            rows = tied(s.get('expect'), orders(a), join_aliases(a))
            if rows:
                s['mask'] = {'paths': [f'/{r}' for r in rows], 'reason': ROW_REASON}
                changed = True
                total_rows += 1
                bind = s.get('bind')
                if bind:
                    masked[bind] = set(rows)
                print('rows', name, c['name'], i, rows)
    if changed and WRITE:
        json.dump(d, open(f, 'w'), indent=4, ensure_ascii=False)
        open(f, 'a').write('\n')
print(total_rows, 'steps with tied rows;', total_pages, 'steps paging from one', file=sys.stderr)
