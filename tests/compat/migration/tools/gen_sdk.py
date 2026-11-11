#!/usr/bin/env python3
"""Generates crates/utopia/migration/src/sdk/spec.rs from the Appwrite PHP SDK.

The Rust port sends the PHP SDK's requests and hydrates its models from
tables: the service methods utopia-php/migration calls, the models they
return (and the models and enums those nest), and the enums. This reads them
from the SDK's source, which is generated code of a fixed shape.

    docker run --rm -v "$PWD:/out" --entrypoint cp appwrite-dev -r /usr/src/code/vendor/appwrite/appwrite /out/.sdk
    python3 tests/compat/migration/tools/gen_sdk.py .sdk > crates/utopia/migration/src/sdk/spec.rs
    rm -rf .sdk && cargo fmt --all

Bump `VERSION` in crates/utopia/migration/src/sdk/mod.rs with the SDK.
"""

import json
import os
import re
import sys

# The methods utopia-php/migration calls (its sources, destinations and the
# API reader), as `Service::method`.
METHODS = [
    "Functions::create", "Functions::createVariable", "Functions::getDeployment", "Functions::list",
    "Functions::listDeployments", "Functions::listVariables",
    "Messaging::createAPNSProvider", "Messaging::createEmail", "Messaging::createFCMProvider",
    "Messaging::createMailgunProvider", "Messaging::createMsg91Provider", "Messaging::createPush",
    "Messaging::createResendProvider", "Messaging::createSMS", "Messaging::createSMTPProvider",
    "Messaging::createSendgridProvider", "Messaging::createSubscriber", "Messaging::createTelesignProvider",
    "Messaging::createTextmagicProvider", "Messaging::createTopic", "Messaging::createTwilioProvider",
    "Messaging::createVonageProvider", "Messaging::listMessages", "Messaging::listProviders",
    "Messaging::listSubscribers", "Messaging::listTopics",
    "Project::createEnvironment", "Project::get", "Project::getPolicy", "Project::listEmailTemplates",
    "Project::listEnvironments", "Project::listKeys",
    "Project::listOAuth2Providers", "Project::listPlatforms", "Project::listVariables",
    "Proxy::createAPIRule", "Proxy::createFunctionRule", "Proxy::createRedirectRule", "Proxy::createSiteRule",
    "Proxy::listRules",
    "Sites::create", "Sites::createVariable", "Sites::getDeployment", "Sites::list", "Sites::listDeployments",
    "Sites::listVariables",
    "Storage::createBucket", "Storage::createFile", "Storage::listBuckets", "Storage::listFiles",
    "TablesDB::getRow", "TablesDB::list", "TablesDB::listColumns", "TablesDB::listIndexes", "TablesDB::listRows",
    "TablesDB::listTables",
    "Teams::create", "Teams::createMembership", "Teams::list", "Teams::listMemberships", "Teams::updatePrefs",
    "Users::create", "Users::createArgon2User", "Users::createBcryptUser", "Users::createPHPassUser",
    "Users::createSHAUser", "Users::createScryptModifiedUser", "Users::createScryptUser", "Users::list",
    "Users::updateEmailVerification", "Users::updateLabels", "Users::updateName", "Users::updatePhone",
    "Users::updatePhoneVerification", "Users::updatePrefs", "Users::updateStatus",
    "Webhooks::list",
]

SCALARS = {"string", "int", "float", "bool", "array", "mixed", "callable", "InputFile"}


def q(s):
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"') + '"'


def opt(s):
    return "None" if s is None else f"Some({q(s)})"


def b(v):
    return "true" if v else "false"


def split_top(s):
    """Splits a parameter list at top-level commas."""
    out, depth, cur = [], 0, ""
    for ch in s:
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        if ch == "," and depth == 0:
            out.append(cur.strip())
            cur = ""
        else:
            cur += ch
    if cur.strip():
        out.append(cur.strip())
    return out


def method(root, service, name):
    src = open(os.path.join(root, "Services", service + ".php")).read()
    m = re.search(r"public function " + re.escape(name) + r"\((.*?)\)(?:: ([^\n]+))?\n    \{\n(.*?)\n    \}\n", src, re.S)
    if not m:
        sys.exit(f"no method {service}::{name}")
    params, ret, body = m.group(1), (m.group(2) or "").strip(), m.group(3)
    args = []
    types = {}
    for p in split_top(params):
        pm = re.match(r"(\??)([\\\w|]+) \$(\w+)(?: = (.+))?$", p)
        if not pm:
            sys.exit(f"{service}::{name}: cannot read parameter {p!r}")
        ty = pm.group(2).split("\\")[-1]
        nullable = pm.group(1) == "?" or pm.group(4) == "null"
        args.append((pm.group(3), ty, nullable, pm.group(4)))
        types[pm.group(3)] = ty
    http = re.search(r"Client::METHOD_(\w+)", body).group(1)
    path = re.search(r"\$apiPath = str_replace\(.*?'([^']*)'\n        \);", body, re.S).group(1)
    out_params = []
    for pm in re.finditer(r"(if \(!is_null\(\$(\w+)\)\) \{\n\s+)?\$apiParams\['([^']+)'\] = \$(\w+);", body):
        arg = pm.group(4)
        out_params.append((pm.group(3), arg, pm.group(1) is not None, types.get(arg, "") not in SCALARS))
    headers = [
        (h.group(1), h.group(2)) for h in re.finditer(r"\$apiHeaders\['([^']+)'\] = '([^']*)';", body)
    ]
    variants = [
        (v.group(1), v.group(2), v.group(3))
        for v in re.finditer(
            r"if \(\(\$response\['([^']+)'\] \?\? null\) === '([^']*)'\) \{\n\s+return \\Appwrite\\Models\\(\w+)::from",
            body,
        )
    ]
    model = None
    if not variants:
        rm = re.fullmatch(r"\\Appwrite\\Models\\(\w+)", ret)
        model = rm.group(1) if rm else None
    chunked = "CHUNK_SIZE" in body
    return {
        "service": service, "name": name, "http": http, "path": path, "args": args, "params": out_params,
        "headers": headers, "model": model, "variants": variants, "chunked": chunked,
    }


def model(root, name):
    src = open(os.path.join(root, "Models", name + ".php")).read()
    ctor = re.search(r"public function __construct\((.*?)\) \{", src, re.S).group(1)
    frm = re.search(r"public static function from\(array \$data\): static\n    \{\n(.*?)\n    \}\n", src, re.S).group(1)
    to = re.search(r"public function toArray\(\): array\n    \{\n(.*?)\n    \}\n", src, re.S).group(1)
    required = re.findall(r"if \(!array_key_exists\('([^']+)', \$data\)\) \{\n\s+throw", frm)
    defaults = []
    dm = re.search(r"\$data \+= \[\n(.*?)\n        \];", frm, re.S)
    if dm:
        for line in dm.group(1).split("\n"):
            lm = re.fullmatch(r"\s*'([^']+)' => (.*),", line)
            if not lm:
                sys.exit(f"{name}: cannot read default {line!r}")
            # The Rust side decodes them as JSON, which these literals are.
            json.loads(lm.group(2))
            defaults.append((lm.group(1), lm.group(2)))
    known = None
    km = re.search(r"extractAdditionalPropertiesFromFields\(\s*\$data,\s*\[(.*?)\]\s*\)", frm, re.S)
    if km:
        known = re.findall(r"'([^']+)'", km.group(1))
    body = re.search(r"return new static\((.*?)\n        \);", frm, re.S).group(1)
    exprs = {}
    for part in re.split(r",\n            (?=\w+: )", body.strip()):
        pm = re.match(r"(\w+): (.*)$", part.strip(), re.S)
        exprs[pm.group(1)] = pm.group(2)
    fields = []
    for p in split_top(ctor):
        pm = re.match(r"public (\??)([\w\\]+) \$(\w+)(?: = (.+))?$", p.strip())
        if not pm:
            sys.exit(f"{name}: cannot read property {p!r}")
        prop, ty = pm.group(3), pm.group(2).split("\\")[-1]
        e = exprs.get(prop, "")
        additional = e.strip() == "$additionalProperties"
        km2 = re.search(r"\$data\['([^']+)'\]", e)
        key = "" if additional else km2.group(1)
        optional = "?? null" in e or e.startswith("array_key_exists(")
        hm = re.search(r"hydrateTypedValue\((\w+)::class, \$(?:item|data\['[^']+'\])(, true)?\)", e)
        fields.append({
            "prop": prop, "key": key, "ty": ty, "nullable": pm.group(1) == "?", "optional": optional,
            "additional": additional, "hydrate": hm.group(1) if hm else None, "allows_null": bool(hm and hm.group(2)),
            "list": "array_map(" in e,
        })
    to_array = re.findall(r"'([^']+)' => static::serializeValue\(\$this->(\w+)\)", to)
    return {"name": name, "required": required, "defaults": defaults, "fields": fields, "known": known,
            "to_array": to_array}


def enum(root, name):
    src = open(os.path.join(root, "Enums", name + ".php")).read()
    fm = re.search(r"public static function from\(string \$value\): self\n    \{\n(.*?)\n    \}", src, re.S)
    return {"name": name, "values": re.findall(r"'([^']*)' => self::", fm.group(1))}


def is_model(root, name):
    return os.path.isfile(os.path.join(root, "Models", name + ".php"))


def is_enum(root, name):
    return os.path.isfile(os.path.join(root, "Enums", name + ".php"))


def main():
    sdk = sys.argv[1]
    root = os.path.join(sdk, "src", "Appwrite")
    version = re.search(r"'x-sdk-version' ?=> ?'([^']+)'", open(os.path.join(root, "Client.php")).read()).group(1)
    methods = [method(root, *m.split("::")) for m in sorted(METHODS, key=lambda m: tuple(m.split("::")))]
    models, enums = {}, set()
    todo = []
    for m in methods:
        todo += [m["model"]] if m["model"] else []
        todo += [v[2] for v in m["variants"]]
        enums |= {a[1] for a in m["args"] if is_enum(root, a[1])}
    while todo:
        n = todo.pop()
        if n in models:
            continue
        models[n] = model(root, n)
        for f in models[n]["fields"]:
            for t in (f["hydrate"], f["ty"]):
                if t and is_model(root, t):
                    todo.append(t)
                elif t and is_enum(root, t):
                    enums.add(t)

    o = []
    o.append(f"//! Generated by tests/compat/migration/tools/gen_sdk.py from the Appwrite PHP SDK {version}: the")
    o.append("//! service methods utopia-php/migration calls, the models they return and the enums they use.")
    o.append("//! Do not edit by hand.")
    o.append("")
    o.append("use super::spec_types::*;")
    o.append("")
    o.append("/// The service methods.")
    o.append("pub static METHODS: &[Method] = &[")
    for m in methods:
        o.append("Method {")
        o.append(f"service: {q(m['service'])}, name: {q(m['name'])}, http: {q(m['http'])}, path: {q(m['path'])},")
        o.append("args: &[" + "".join(
            f"Arg {{ name: {q(a[0])}, ty: {q(a[1])}, nullable: {b(a[2])}, default: {opt(a[3])} }},"
            for a in m["args"]) + "],")
        o.append("params: &[" + "".join(
            f"Param {{ key: {q(p[0])}, arg: {q(p[1])}, conditional: {b(p[2])}, enumerated: {b(p[3])} }},"
            for p in m["params"]) + "],")
        o.append("headers: &[" + "".join(f"({q(k)}, {q(v)})," for k, v in m["headers"]
                                          if k != "X-Appwrite-Project") + "],")
        o.append(f"model: {opt(m['model'])},")
        o.append("variants: &[" + "".join(f"({q(a)}, {q(v)}, {q(c)})," for a, v, c in m["variants"]) + "],")
        o.append(f"chunked: {b(m['chunked'])},")
        o.append("},")
    o.append("];")
    o.append("")
    o.append("/// The models.")
    o.append("pub static MODELS: &[Model] = &[")
    for n in sorted(models):
        md = models[n]
        o.append("Model {")
        o.append(f"name: {q(n)},")
        o.append("required: &[" + "".join(f"{q(k)}," for k in md["required"]) + "],")
        o.append("defaults: &[" + "".join(f"({q(k)}, {q(v)})," for k, v in md["defaults"]) + "],")
        o.append("fields: &[")
        for f in md["fields"]:
            o.append(
                f"Field {{ prop: {q(f['prop'])}, key: {q(f['key'])}, ty: {q(f['ty'])}, nullable: {b(f['nullable'])}, "
                f"optional: {b(f['optional'])}, additional: {b(f['additional'])}, hydrate: {opt(f['hydrate'])}, "
                f"allows_null: {b(f['allows_null'])}, list: {b(f['list'])} }},")
        o.append("],")
        if md["known"] is None:
            o.append("known: None,")
        else:
            o.append("known: Some(&[" + "".join(f"{q(k)}," for k in md["known"]) + "]),")
        o.append("to_array: &[" + "".join(f"({q(k)}, {q(p)})," for k, p in md["to_array"]) + "],")
        o.append("},")
    o.append("];")
    o.append("")
    o.append("/// The enums.")
    o.append("pub static ENUMS: &[Enum] = &[")
    for n in sorted(enums):
        e = enum(root, n)
        o.append(f"Enum {{ name: {q(n)}, values: &[" + "".join(f"{q(v)}," for v in e["values"]) + "] },")
    o.append("];")
    print("\n".join(o))


if __name__ == "__main__":
    main()
