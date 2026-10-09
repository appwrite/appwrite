"""Generates src/mb/unicode.rs, mbstring's Unicode case data.

    python3 unicode.py <unicode_data.h> ../src/mb/unicode.rs

<unicode_data.h> is php-src `ext/mbstring/unicode_data.h` at the PHP version
the compat tester runs (tag php-8.5.10). Extracted: the four case tables
(`_uccase_{upper,lower,title,fold}_table`, sorted by code point),
`_uccase_extra_table`, and the `UC_CASED` and `UC_CASE_IGNORABLE` ranges of
`_ucprop_ranges`. After regenerating, re-run
`bin/compat fuzz php-std --op mb.convert_case`.
"""
import re
import sys

src = open(sys.argv[1]).read()


def array(name):
    m = re.search(r"static const [a-z ]+" + re.escape(name) + r"\[\] = \{(.*?)\};", src, re.S)
    if not m:
        raise SystemExit(f"missing {name}")
    return [int(x, 0) for x in re.findall(r"-?0x[0-9a-fA-F]+|-?\d+", m.group(1))]


def scalar(name):
    return int(re.search(r"static const [a-z ]+" + re.escape(name) + r" = (\d+);", src).group(1))


offsets = array("_ucprop_offsets")
ranges = array("_ucprop_ranges")
UC_CASED, UC_CASE_IGNORABLE = 35, 36


def prop(n):
    items = ranges[offsets[n]:offsets[n + 1]]
    return [(items[i], items[i + 1]) for i in range(0, len(items), 2)]


def case_table(kind):
    table = array(f"_uccase_{kind}_table")
    size = scalar(f"_uccase_{kind}_table_size")
    assert len(table) == 2 * size, (kind, len(table), size)
    pairs = {}
    for i in range(size):
        code, value = table[2 * i], table[2 * i + 1]
        if code == 0 and value == 0:
            continue
        assert code not in pairs, (kind, code)
        pairs[code] = value
    return sorted(pairs.items())


def fmt_pairs(pairs):
    return "\n".join(
        "    " + " ".join(f"(0x{a:06x}, 0x{b:08x})," for a, b in pairs[i:i + 6]) for i in range(0, len(pairs), 6)
    )


def fmt_list(items):
    return "\n".join("    " + " ".join(f"0x{x:06x}," for x in items[i:i + 10]) for i in range(0, len(items), 10))


out = ["""//! Unicode case data of PHP's mbstring, generated from php-src
//! `ext/mbstring/unicode_data.h` (tag `php-8.5.10`) by `data/unicode.py`.
//! Do not edit by hand.
//!
//! Case tables map a code point to its simple mapping, or, when the value
//! is above `0xFFFFFF`, to `(length << 24) | index` into [`EXTRA`], where
//! `EXTRA[index]` is the simple mapping and the `length` code points after it
//! are the full (special casing) mapping.
"""]
for kind in ("upper", "lower", "title", "fold"):
    out.append(f"pub(super) static {kind.upper()}: &[(u32, u32)] = &[\n{fmt_pairs(case_table(kind))}\n];\n")
out.append(f"pub(super) static EXTRA: &[u32] = &[\n{fmt_list(array('_uccase_extra_table'))}\n];\n")
out.append("/// `UC_CASED` (DerivedCoreProperties `Cased`) as inclusive ranges.")
out.append(f"pub(super) static CASED: &[(u32, u32)] = &[\n{fmt_pairs(prop(UC_CASED))}\n];\n")
out.append("/// `UC_CASE_IGNORABLE` (DerivedCoreProperties `Case_Ignorable`) as inclusive ranges.")
out.append(f"pub(super) static CASE_IGNORABLE: &[(u32, u32)] = &[\n{fmt_pairs(prop(UC_CASE_IGNORABLE))}\n];\n")
open(sys.argv[2], "w").write("\n".join(out))
