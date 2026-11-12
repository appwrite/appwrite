#!/usr/bin/env python3
"""Run the parity corpus through a deployed Appwrite's storage preview endpoint.

    APPWRITE_API_KEY=... python3 crates/extension/parity/api.py --endpoint https://fra.cloud.staging.appwrite.io/v1 \\
        --project <id> --corpus target/parity/corpus --out target/parity/api/extension [--broken 120]

Uploads the valid files, and a sample of the broken ones, to a bucket named `parity` (created if
missing; the key needs buckets.write, files.read and files.write), then requests the same operations
case.php runs and writes `cases.jsonl` and the responses in compare.php's layout. Run it against a
deployment on each implementation, into `<results>/imagick` and `<results>/extension`, and compare:

    php crates/extension/parity/compare.php <results>
"""
import argparse
import hashlib
import json
import os
import pathlib
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

# case.php's operations as the preview endpoint's parameters.
OPERATIONS = {
    'thumb-jpg': {'width': 300, 'height': 300, 'output': 'jpg', 'quality': 80},
    'gravity-webp': {'width': 200, 'height': 100, 'gravity': 'top-left', 'output': 'webp', 'quality': 75},
    'height-png': {'height': 150, 'output': 'png'},
    'styled-png': {'width': 256, 'height': 256, 'opacity': 0.6, 'background': '336699', 'borderWidth': 6,
                   'borderColor': 'ff0000', 'borderRadius': 40, 'rotation': 90, 'output': 'png'},
    'focal-avif': {'width': 240, 'height': 180, 'output': 'avif', 'quality': 60},
    'same-gif': {'width': 160, 'height': 160, 'output': 'gif'},
    'unset-jpg': {'width': 320, 'output': 'jpg'},
}
TYPES = {'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp', 'image/avif': 'avif'}


class Api:
    def __init__(self, endpoint, project, key):
        self.endpoint, self.headers = endpoint.rstrip('/'), {'X-Appwrite-Project': project, 'X-Appwrite-Key': key}

    def call(self, method, path, body=None, headers=None, query=None):
        url = self.endpoint + path + ('?' + urllib.parse.urlencode(query) if query else '')
        if isinstance(body, dict):
            body, headers = json.dumps(body).encode(), {'Content-Type': 'application/json'}
        request = urllib.request.Request(url, data=body, method=method, headers={**self.headers, **(headers or {})})
        try:
            with urllib.request.urlopen(request, timeout=120) as response:
                return response.status, response.headers, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.headers, error.read()


def file_id(path):
    """Stable and unique per content, so a rerun reuses uploads and a changed corpus does not."""
    return 'p' + hashlib.sha1(path.name.encode() + path.read_bytes()).hexdigest()[:30]


def upload(api, path):
    identifier = file_id(path)
    status, _, _ = api.call('GET', f'/storage/buckets/parity/files/{identifier}')
    if status == 200:
        return identifier, 200, b''
    boundary = uuid.uuid4().hex
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="fileId"\r\n\r\n{identifier}\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{path.name}"\r\n'
            f'Content-Type: application/octet-stream\r\n\r\n').encode() + path.read_bytes() + f'\r\n--{boundary}--\r\n'.encode()
    status, _, answer = api.call('POST', '/storage/buckets/parity/files', body,
                                 {'Content-Type': f'multipart/form-data; boundary={boundary}'})
    return identifier, status, answer


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--endpoint', required=True)
    parser.add_argument('--project', required=True)
    parser.add_argument('--corpus', type=pathlib.Path, required=True)
    parser.add_argument('--out', type=pathlib.Path, required=True)
    parser.add_argument('--broken', type=int, default=120, help='How many broken files to try, spread over the corpus')
    args = parser.parse_args()
    api = Api(args.endpoint, args.project, os.environ['APPWRITE_API_KEY'])

    status, _, answer = api.call('GET', '/storage/buckets/parity')
    if status == 404:
        status, _, answer = api.call('POST', '/storage/buckets', {'bucketId': 'parity', 'name': 'Parity', 'fileSecurity': False,
                                                                  'maximumFileSize': 30_000_000})
    if status not in (200, 201):
        raise SystemExit(f'bucket: {status} {answer[:300]!r}')

    broken = sorted((args.corpus / 'broken').iterdir())
    broken = broken[::max(1, len(broken) // args.broken)][:args.broken] if args.broken else []
    cases = [(path, operation) for path in sorted((args.corpus / 'valid').iterdir()) for operation in OPERATIONS]
    cases += [(path, 'thumb-jpg') for path in broken]

    (args.out / 'files').mkdir(parents=True, exist_ok=True)
    uploaded, counts = {}, {}
    with (args.out / 'cases.jsonl').open('w') as log:
        for path, operation in cases:
            row = {'file': path.name, 'operation': operation}
            if path not in uploaded:
                uploaded[path] = upload(api, path)
            identifier, status, answer = uploaded[path]
            if status not in (200, 201):
                row.update(status='error', **{'class': f'upload {status}'}, message=answer[:120].decode('utf-8', 'replace'))
            else:
                started = time.time()
                status, headers, body = api.call('GET', f'/storage/buckets/parity/files/{identifier}/preview', query=OPERATIONS[operation])
                kind = (headers.get('Content-Type') or '').split(';')[0]
                row['ms'] = round((time.time() - started) * 1000)
                row['cache'] = headers.get('X-Appwrite-Cache')
                if status == 200 and kind in TYPES:
                    name = f'{path.name}.{operation}.{TYPES[kind]}'
                    (args.out / 'files' / name).write_bytes(body)
                    row.update(status='ok', output=name, bytes=len(body))
                else:
                    try:
                        error = json.loads(body)
                        row.update(status='error', **{'class': f'{status} {error.get("type")}'}, message=str(error.get('message'))[:120])
                    except ValueError:
                        row.update(status='error', **{'class': f'{status} {kind}'}, message=body[:120].decode('utf-8', 'replace'))
            log.write(json.dumps(row) + '\n')
            log.flush()
            outcome = row['status'] if row['status'] == 'ok' else row['class']
            counts[outcome] = counts.get(outcome, 0) + 1
    print(json.dumps(counts, indent=1))


if __name__ == '__main__':
    main()
