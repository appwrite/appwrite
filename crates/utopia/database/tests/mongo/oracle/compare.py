"""Compares the filters, options and pipelines PHP's MongoDB adapter sends
(`mongo_filters.php`) with the Rust adapter's (`MONGO_DIFFERENTIAL=<file>`):
key order kept, doubles compared as numbers.

    python3 compare.py php.json rust.json
"""

import json
import sys


def parse(text):
    def hook(pairs):
        if len(pairs) == 1 and pairs[0][0] == '$numberDouble':
            return ('double', float(pairs[0][1]))
        return ('object', list(pairs))

    return json.loads(text, object_pairs_hook=hook)


php = json.load(open(sys.argv[1]))
rust = json.load(open(sys.argv[2]))
different = 0
for name, sent in php.items():
    if not isinstance(sent, str):
        continue
    if name not in rust or parse(sent) != parse(rust[name]):
        different += 1
        print('DIFFERENT', name)
        print('  php ', sent)
        print('  rust', rust.get(name))
print(f'{len(php)} cases, {different} different')
sys.exit(1 if different else 0)
