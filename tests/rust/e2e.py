#!/usr/bin/env python3
"""Black-box checks of the Rust Users API, ported from
tests/e2e/Services/Users/UsersBase.php (routes served by Rust only).

The authoritative suite is the PHP E2E suite run through Traefik; this
script exists for fast local iteration without the PHP stack.
Env: ENDPOINT, PROJECT, KEY
"""
import json, os, sys, time, traceback, uuid
import requests

ENDPOINT = os.environ.get('ENDPOINT', 'http://127.0.0.1:8090/v1')
PROJECT = os.environ.get('PROJECT', 'test')
KEY = os.environ['KEY']
S = requests.Session()
HEADERS = {'content-type': 'application/json', 'x-appwrite-project': PROJECT, 'x-appwrite-key': KEY}


def call(method, path, body=None, params=None, headers=None, raw=False):
    h = dict(HEADERS) if headers is None else headers
    url = ENDPOINT + path
    if method == 'GET':
        r = S.get(url, params=params, headers=h)
    else:
        r = S.request(method, url, data=json.dumps(body) if body is not None else None, headers=h)
    try:
        data = r.json() if r.content else None
    except ValueError:
        data = r.text
    return (r.status_code, data, r) if raw else (r.status_code, data)


def q(method, attribute=None, values=None):
    d = {'method': method}
    if attribute is not None:
        d['attribute'] = attribute
    if values is not None:
        d['values'] = values
    return json.dumps(d)


def uid():
    return uuid.uuid4().hex[:20]


def phone_number(prefix='+1'):
    return prefix + str(time.time_ns())[-10:]


TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


STATE = {}


@test
def create_user():
    email = f'cristiano.ronaldo.{uid()}@manchester-united.co.uk'
    code, user, r = call('POST', '/users', {'userId': 'unique()', 'email': email, 'password': 'password', 'name': 'Cristiano Ronaldo'}, raw=True)
    assert code == 201, (code, user)
    assert '"prefs":{}' in r.text, r.text
    assert user['name'] == 'Cristiano Ronaldo'
    assert user['email'] == email
    assert user['status'] is True
    assert user['labels'] == []
    assert user['registration']
    assert user['emailCanonical'] == email
    assert user['emailIsFree'] is False and user['emailIsDisposable'] is False
    assert user['emailIsCorporate'] is True and user['emailIsCanonical'] is True
    assert user['hash'] == 'argon2'
    assert user['password'].startswith('$argon2id$v=19$m=7168,t=5,p=1$'), user['password']
    assert user['hashOptions'] == {'type': 'argon2', 'memory_cost': 7168, 'time_cost': 5, 'threads': 1}
    assert len(user['targets']) == 1 and user['targets'][0]['providerType'] == 'email'
    assert user['passwordPwned'] is False
    assert r.headers.get('content-type') == 'application/json; charset=UTF-8'
    STATE['user'] = user

    code, dup = call('POST', '/users', {'userId': user['$id'], 'email': f'x{uid()}@example.com'})
    assert code == 409 and dup['type'] == 'user_already_exists', dup
    code, user1 = call('POST', '/users', {'userId': 'user1', 'email': f'lionel.messi.{uid()}@psg.fr', 'password': 'password', 'name': 'Lionel Messi'})
    assert code in (201, 409), user1


@test
def create_hashed_users():
    cases = [
        ('md5', {'password': '144fa7eaa4904e8ee120651997f70dcc'}, 'md5'),
        ('bcrypt', {'password': '$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq'}, 'bcrypt'),
        ('argon2', {'password': '$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI'}, 'argon2'),
        ('sha', {'password': '4243da0a694e8a2f727c8060fe0507c8fa01ca68146c76d2c190805b638c20c6bf6ba04e21f11ae138785d0bff63c416e6f87badbffad37f6dee50094cc38c70', 'passwordVersion': 'sha512'}, 'sha'),
        ('scrypt', {'password': '3fdef49701bc4cfaacd551fe017283513284b4731e6945c263246ef948d3cf63b5d269c31fd697246085111a428245e24a4ddc6b64c687bc60a8910dbafc1d5b', 'passwordSalt': 'appwrite', 'passwordCpu': 16384, 'passwordMemory': 13, 'passwordParallel': 2, 'passwordLength': 64}, 'scrypt'),
        ('phpass', {'password': '$P$Br387rwferoKN7uwHZqNMu98q3U8RO.'}, 'phpass'),
        ('scrypt-modified', {'password': 'UlM7JiXRcQhzAGlaonpSqNSLIz475WMddOgLjej5De9vxTy48K6WtqlEzrRFeK4t0COfMhWCb8wuMHgxOFCHFQ==', 'passwordSalt': 'UxLMreBr6tYyjQ==', 'passwordSaltSeparator': 'Bw==', 'passwordSignerKey': 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ=='}, 'scryptMod'),
    ]
    for path, extra, hash_name in cases:
        body = {'userId': 'unique()', 'email': f'{path}.{uid()}@appwrite.io', 'name': path, **extra}
        code, user = call('POST', f'/users/{path}', body)
        assert code == 201, (path, code, user)
        assert user['hash'] == hash_name, (path, user['hash'])
        assert user['password'] == extra['password'], path
        if path == 'sha':
            assert user['hashOptions'] == {'version': 'sha512'}, user['hashOptions']
        if path == 'scrypt':
            assert user['hashOptions']['costCpu'] == 16384 and user['hashOptions']['salt'] == 'appwrite'
        if path == 'scrypt-modified':
            assert user['hashOptions']['signerKey'] == extra['passwordSignerKey']
    for bad in ['0', 'not base64!']:
        code, err = call('POST', '/users/scrypt-modified', {'userId': 'smbad', 'email': f'smbad.{uid()}@appwrite.io', 'password': 'UlM7JiXRcQhzAGla', 'passwordSalt': bad, 'passwordSaltSeparator': 'Bw==', 'passwordSignerKey': 'XyEK'})
        assert code == 400 and err['type'] == 'general_argument_invalid', err
    code, err = call('GET', '/users/smbad')
    assert code == 404 and err['type'] == 'user_not_found'
    code, err = call('POST', '/users/sha', {'userId': 'unique()', 'email': f'x{uid()}@appwrite.io', 'password': 'password', 'passwordVersion': 'sha512/224'})
    assert code == 500, (code, err)


@test
def param_errors():
    code, err = call('POST', '/users', {})
    assert code == 400 and err['message'] == 'Param "userId" is not optional.', err
    code, err = call('POST', '/users', {'userId': '_bad'})
    assert code == 400 and err['message'].startswith('Invalid `userId` param: Parameter must contain at most 36 chars.'), err
    code, err = call('POST', '/users', {'userId': 'unique()', 'password': 'short'})
    assert code == 400 and err['message'] == 'Invalid `password` param: Password must be between 8 and 256 characters long. or null', err
    code, err = call('POST', '/users', {'userId': 'unique()', 'name': ''})
    assert code == 400 and 'Invalid `name` param' in err['message'], err
    code, err = call('POST', '/users', {'userId': 'unique()', 'phone': '+920000000000'})
    assert code == 400 and err['message'] == "Invalid `phone` param: Phone number must start with a '+' can have a maximum of fifteen digits. or null", err


@test
def auth_errors():
    code, err = call('GET', '/users', headers={'x-appwrite-project': PROJECT})
    assert code == 401 and err['type'] == 'general_unauthorized_scope' and err['message'] == 'User (role: guests) missing scopes (["users.read"])', err
    code, err = call('GET', '/users', headers={'x-appwrite-project': PROJECT, 'x-appwrite-key': 'standard_nope'})
    assert code == 401 and err['type'] == 'user_unauthorized', err
    code, err = call('GET', '/users', headers={'x-appwrite-key': KEY})
    assert code == 403 and err['type'] == 'project_id_missing', err
    code, err = call('GET', '/users', headers={'x-appwrite-project': 'missing-project'})
    assert code == 404 and err['type'] == 'project_not_found', err
    code, err = call('GET', '/users/x', headers={'x-appwrite-project': PROJECT, 'origin': 'http://evil.com'})
    assert code == 403 and err['type'] == 'general_unknown_origin', err
    code, err = call('GET', '/userz')
    assert code == 404 and err['type'] == 'general_route_not_found', err
    assert set(err.keys()) >= {'message', 'code', 'type', 'version', 'file', 'line', 'trace'}, err


@test
def create_user_types():
    for email, phone, password in [(True, False, False), (False, True, False), (True, True, True), (False, False, False)]:
        body = {'userId': 'unique()'}
        if email:
            body['email'] = f'types.{uid()}@appwrite.io'
        if phone:
            body['phone'] = phone_number()
        if password:
            body['password'] = 'password'
        code, user = call('POST', '/users', body)
        assert code == 201, (body, user)
        assert (user['email'] != '') == email and (user['phone'] != '') == phone and (user['password'] not in ('', None)) == password, user


@test
def list_users():
    code, data = call('GET', '/users')
    assert code == 200 and data['total'] >= 3 and isinstance(data['total'], int), data
    user = STATE['user']
    code, data = call('GET', '/users', params={'queries[]': [q('equal', 'name', [user['name']])]})
    assert code == 200 and any(u['$id'] == user['$id'] for u in data['users']), data
    code, data = call('GET', '/users', params={'queries[]': [q('equal', 'status', [True])]})
    assert code == 200 and data['total'] > 0
    code, data = call('GET', '/users', params={'queries[]': [q('equal', 'registration', [user['registration']])]})
    assert code == 200 and data['total'] == 1, data
    code, data = call('GET', '/users', params={'queries[]': [q('select', None, ['name'])]})
    assert code == 400 and data['message'] == 'Invalid `queries` param: Invalid query method: select', data
    code, data = call('GET', '/users', params={'queries[]': [q('cursorAfter', None, ['unknown'])]})
    assert code == 400 and data['type'] == 'general_cursor_not_found', data
    code, page1 = call('GET', '/users', params={'queries[]': [q('limit', None, [2])]})
    assert code == 200 and len(page1['users']) == 2
    code, page2 = call('GET', '/users', params={'queries[]': [q('limit', None, [1]), q('cursorAfter', None, [page1['users'][0]['$id']])]})
    assert code == 200 and page2['users'][0]['$id'] == page1['users'][1]['$id'], (page1, page2)
    code, back = call('GET', '/users', params={'queries[]': [q('limit', None, [1]), q('cursorBefore', None, [page1['users'][1]['$id']])]})
    assert code == 200 and back['users'][0]['$id'] == page1['users'][0]['$id'], back
    code, data = call('GET', '/users', params={'search': user['name']})
    assert code == 200 and any(u['$id'] == user['$id'] for u in data['users']), data
    code, data = call('GET', '/users', params={'search': user['$id']})
    assert code == 200 and data['total'] == 1, data
    code, data = call('GET', '/users', params={'search': '>'})
    assert code == 200 and data['total'] == 0, data
    code, data = call('GET', '/users', params={'total': 'false'})
    assert code == 200 and data['total'] > 0  # PHP casts "false" to true
    code, data = call('GET', '/users', params={'total': '0'})
    assert code == 200 and data['total'] == 0 and len(data['users']) > 0
    code, data = call('GET', '/users', params={'queries[]': [q('orderDesc', 'accessedAt')]})
    assert code == 200
    code, data = call('GET', '/users', params={'queries[]': [q('equal', 'labels', ['x'])]})
    assert code == 400 and data['message'] == 'Invalid `queries` param: Invalid query: Cannot query equal on attribute "labels" because it is an array.', data


@test
def get_user():
    user = STATE['user']
    code, data = call('GET', f'/users/{user["$id"]}')
    assert code == 200 and data['$id'] == user['$id'] and len(data['targets']) == 1, data
    code, data = call('GET', '/users/does-not-exist')
    assert code == 404 and data['message'] == 'User with the requested ID could not be found.' and data['type'] == 'user_not_found' and data['code'] == 404, data


@test
def update_attributes():
    uid_ = STATE['user']['$id']
    code, u = call('PATCH', f'/users/{uid_}/name', {'name': ''})
    assert code == 200 and u['name'] == '', u
    code, u = call('PATCH', f'/users/{uid_}/name', {'name': 'Updated name'})
    assert code == 200 and u['name'] == 'Updated name' and u['$updatedAt'] >= STATE['user']['$updatedAt']
    code, data = call('GET', '/users', params={'search': 'Updated name'})
    assert code == 200 and any(x['$id'] == uid_ for x in data['users']), data
    new_email = f'users.service.{uid()}@updated.com'
    code, u = call('PATCH', f'/users/{uid_}/email', {'email': ''})
    assert code == 200 and u['email'] == '', u
    code, u = call('PATCH', f'/users/{uid_}/email', {'email': new_email})
    assert code == 200 and u['email'] == new_email and u['emailVerification'] is False, u
    assert any(t['identifier'] == new_email for t in u['targets']), u['targets']
    code, data = call('GET', '/users', params={'search': f'"{new_email}"'})
    assert code == 200 and data['total'] == 1, data
    code, err = call('PATCH', f'/users/{uid_}/email', {'email': new_email})
    assert code == 409 and err['type'] == 'user_target_already_exists', err
    phone = phone_number('+91')
    code, u = call('PATCH', f'/users/{uid_}/phone', {'number': phone})
    assert code == 200 and u['phone'] == phone, u
    code, other = call('POST', '/users', {'userId': 'unique()'})
    code, err = call('PATCH', f'/users/{other["$id"]}/phone', {'number': phone})
    assert code == 409 and err['type'] == 'user_target_already_exists', err
    code, data = call('GET', '/users', params={'search': phone})
    assert code == 200 and data['total'] == 1, data
    code, u = call('PATCH', f'/users/{uid_}/phone', {'number': ''})
    assert code == 200 and u['phone'] == ''
    code, u = call('PATCH', f'/users/{uid_}/status', {'status': False})
    assert code == 200 and u['status'] is False
    code, u = call('PATCH', f'/users/{uid_}/status', {'status': True})
    assert code == 200 and u['status'] is True
    code, u = call('PATCH', f'/users/{uid_}/verification', {'emailVerification': True})
    assert code == 200 and u['emailVerification'] is True
    code, err = call('PATCH', f'/users/{uid_}/verification', {'emailVerification': 'true'})
    assert code == 400, err
    code, u = call('PATCH', f'/users/{uid_}/impersonator', {'impersonator': True})
    assert code == 200 and u['impersonator'] is True
    code, u = call('PATCH', f'/users/{uid_}/impersonator', {'impersonator': False})
    assert code == 200 and u['impersonator'] is False
    code, err = call('PATCH', '/users/missing-user/impersonator', {'impersonator': True})
    assert code == 404


@test
def labels():
    uid_ = STATE['user']['$id']
    cases = [(['admin'], 200, ['admin']), (['vip', 'pro'], 200, ['vip', 'pro']), ([], 200, []),
             (['vip', 'vip', 'pro'], 200, ['vip', 'pro']), (['invalid-label'], 400, None),
             (['a' * 129], 400, None), ([['a'] * 101], 400, None)]
    for labels_, status, expected in cases:
        code, u = call('PUT', f'/users/{uid_}/labels', {'labels': labels_})
        assert code == status, (labels_, code, u)
        if expected is not None:
            assert u['labels'] == expected, u
    code, err = call('PUT', f'/users/{uid_}/labels', {})
    assert code == 400, err
    code, err = call('PUT', '/users/dne/labels', {'labels': ['x']})
    assert code == 404, err


@test
def prefs():
    uid_ = STATE['user']['$id']
    code, data = call('PATCH', f'/users/{uid_}/prefs', {'prefs': {'funcKey1': 'funcValue1', 'funcKey2': 'funcValue2'}})
    assert code == 200 and data == {'funcKey1': 'funcValue1', 'funcKey2': 'funcValue2'}, data
    code, data = call('GET', f'/users/{uid_}/prefs')
    assert code == 200 and data == {'funcKey1': 'funcValue1', 'funcKey2': 'funcValue2'}, data
    code, err = call('PATCH', f'/users/{uid_}/prefs', {'prefs': 'bad'})
    assert code == 400, err
    code, err = call('PATCH', f'/users/{uid_}/prefs', {})
    assert code == 400, err
    code, err = call('PATCH', f'/users/{uid_}/prefs', {'prefs': {}})
    assert code == 400 and err['message'] == 'Invalid `prefs` param: Value must be a valid object.', err


@test
def password():
    code, user = call('POST', '/users', {'userId': 'unique()', 'email': f'pw.{uid()}@appwrite.io'})
    assert code == 201 and user['password'] is None, user
    code, u = call('PATCH', f'/users/{user["$id"]}/password', {'password': ''})
    assert code == 200 and u['password'] == '', u
    code, u = call('PATCH', f'/users/{user["$id"]}/password', {'password': 'password2'})
    assert code == 200 and u['password'].startswith('$argon2id$v=19$m=7168,t=5,p=1$') and u['hash'] == 'argon2', u


@test
def tokens_sessions_jwt():
    uid_ = STATE['user']['$id']
    code, t = call('POST', f'/users/{uid_}/tokens')
    assert code == 201 and t['userId'] == uid_ and len(t['secret']) == 6 and t['expire'], t
    code, t = call('POST', f'/users/{uid_}/tokens', {'length': 15, 'expire': 60})
    assert code == 201 and len(t['secret']) == 15
    code, err = call('POST', f'/users/{uid_}/tokens', {'length': 1, 'expire': 1})
    assert code == 400 and 'secret' not in err
    code, err = call('POST', f'/users/{uid_}/tokens', {'expire': 999999999})
    assert code == 400
    code, s = call('POST', f'/users/{uid_}/sessions')
    assert code == 201 and s['userId'] == uid_ and s['provider'] == 'server' and s['secret'] and s['expire'], s
    assert s['countryName'] == 'Unknown' and s['factors'] == ['server'], s
    code, data = call('GET', f'/users/{uid_}/sessions')
    assert code == 200 and data['total'] >= 1 and data['sessions'][0]['secret'] != s['secret'], data
    code, j = call('POST', f'/users/{uid_}/jwts')
    assert code == 201 and j['jwt'].count('.') == 2
    code, j = call('POST', f'/users/{uid_}/jwts', {'sessionId': s['$id'], 'duration': 5})
    assert code == 201
    code, err = call('POST', f'/users/{uid_}/jwts', {'duration': 0})
    assert code == 500, err
    code, other = call('POST', '/users', {'userId': 'unique()'})
    code, err = call('DELETE', f'/users/{other["$id"]}/sessions/{s["$id"]}')
    assert code == 404 and err['type'] == 'user_session_not_found', err
    code, _ = call('DELETE', f'/users/{uid_}/sessions/{s["$id"]}')
    assert code == 204
    code, _ = call('DELETE', f'/users/{uid_}/sessions')
    assert code == 204
    code, data = call('GET', f'/users/{uid_}/sessions')
    assert code == 200 and data['total'] == 0, data


@test
def targets():
    uid_ = STATE['user']['$id']
    email = f'random-email.{uid()}@mail.org'
    code, t = call('POST', f'/users/{uid_}/targets', {'targetId': 'unique()', 'providerType': 'email', 'identifier': email})
    assert code == 201 and t['identifier'] == email and t['providerId'] is None, t
    code, err = call('POST', f'/users/{uid_}/targets', {'targetId': 'unique()', 'providerType': 'email', 'identifier': 'not-an-email'})
    assert code == 400 and err['type'] == 'general_invalid_email', err
    code, err = call('POST', f'/users/{uid_}/targets', {'targetId': t['$id'], 'providerType': 'email', 'identifier': f'x{uid()}@mail.org'})
    assert code == 409 and err['type'] == 'user_target_already_exists', err
    new_email = f'random-email-2.{uid()}@mail.org'
    code, u = call('PATCH', f'/users/{uid_}/targets/{t["$id"]}', {'identifier': new_email})
    assert code == 200 and u['identifier'] == new_email and u['expired'] is False, u
    code, data = call('GET', f'/users/{uid_}/targets')
    assert code == 200 and data['total'] >= 1 and any(x['$id'] == t['$id'] for x in data['targets']), data
    code, data = call('GET', f'/users/{uid_}/targets', params={'queries[]': [q('equal', 'providerType', ['email'])]})
    assert code == 200 and data['total'] >= 1
    code, g = call('GET', f'/users/{uid_}/targets/{t["$id"]}')
    assert code == 200 and g['$id'] == t['$id']
    code, _ = call('DELETE', f'/users/{uid_}/targets/{t["$id"]}')
    assert code == 204
    code, err = call('GET', f'/users/{uid_}/targets/{t["$id"]}')
    assert code == 404 and err['type'] == 'user_target_not_found', err


@test
def mfa():
    code, user = call('POST', '/users', {'userId': 'unique()', 'email': f'mfa.{uid()}@appwrite.io', 'password': 'password'})
    uid_ = user['$id']
    code, f = call('GET', f'/users/{uid_}/mfa/factors')
    assert code == 200 and f == {'totp': False, 'phone': False, 'email': False, 'recoveryCode': False, 'custom': False}, f
    code, err = call('GET', f'/users/{uid_}/mfa/recovery-codes')
    assert code == 404 and err['type'] == 'user_recovery_codes_not_found'
    code, codes = call('PATCH', f'/users/{uid_}/mfa/recovery-codes')
    assert code == 201 and len(codes['recoveryCodes']) == 6, codes
    code, err = call('PATCH', f'/users/{uid_}/mfa/recovery-codes')
    assert code == 409
    code, got = call('GET', f'/users/{uid_}/mfa/recovery-codes')
    assert code == 200 and got == codes, (got, codes)
    code, f = call('GET', f'/users/{uid_}/mfa/factors')
    assert f['recoveryCode'] is True
    code, again = call('PUT', f'/users/{uid_}/mfa/recovery-codes')
    assert code == 200 and again['recoveryCodes'] != codes['recoveryCodes']
    code, u = call('PATCH', f'/users/{uid_}/mfa', {'mfa': True})
    assert code == 200 and u['mfa'] is True
    code, err = call('DELETE', f'/users/{uid_}/mfa/authenticators/totp')
    assert code == 404 and err['type'] == 'user_authenticator_not_found'
    code, err = call('GET', f'/users/{uid_}/mfa/challenges/nope')
    assert code == 401 and err['type'] == 'user_invalid_token'


@test
def memberships_identities_passkeys():
    uid_ = STATE['user']['$id']
    code, data = call('GET', f'/users/{uid_}/memberships')
    assert code == 200 and data == {'total': 0, 'memberships': []}, data
    code, err = call('GET', f'/users/{uid_}/memberships', params={'queries[]': [q('equal', 'roles', ['x'])]})
    assert code == 400 and err['message'] == 'Invalid `queries` param: Invalid query: Cannot query equal on attribute "roles" because it is an array.', err
    code, data = call('GET', f'/users/{uid_}/memberships', params={'queries[]': [q('contains', 'roles', ['x'])]})
    assert code == 200, data
    code, err = call('GET', '/users/identities', params={'search': 'x'})
    assert code == 400 and err['type'] == 'general_query_invalid', err
    code, data = call('GET', '/users/identities')
    assert code == 200 and data['total'] == 0, data
    code, err = call('DELETE', '/users/identities/nope')
    assert code == 404 and err['type'] == 'user_identity_not_found'
    code, data = call('GET', f'/users/{uid_}/passkeys')
    assert code == 200 and data == {'total': 0, 'passkeys': []}, data
    code, err = call('GET', f'/users/{uid_}/passkeys/nope')
    assert code == 404 and err['type'] == 'user_passkey_not_found'


@test
def delete_user():
    code, user = call('POST', '/users', {'userId': 'unique()', 'email': f'del.{uid()}@appwrite.io', 'phone': phone_number()})
    assert code == 201, user
    code, _ = call('DELETE', f'/users/{user["$id"]}')
    assert code == 204
    code, err = call('DELETE', f'/users/{user["$id"]}')
    assert code == 404
    code, again = call('POST', '/users', {'userId': 'unique()', 'email': user['email']})
    assert code == 201, again  # targets were deleted with the user


@test
def headers():
    code, data, r = call('GET', '/users', raw=True, headers={**HEADERS, 'accept-encoding': 'gzip'})
    assert code == 200
    assert r.headers.get('server') == 'Appwrite' and r.headers.get('x-content-type-options') == 'nosniff'
    assert 'x-debug-speed' in r.headers


@test
def session_auth():
    console = os.environ.get('CONSOLE_SESSION')
    user = os.environ.get('USER_SESSION')
    if not console or not user:
        return
    admin = {'content-type': 'application/json', 'x-appwrite-project': PROJECT, 'x-appwrite-mode': 'admin',
             'origin': 'http://localhost', 'cookie': f'a_session_console={console}'}
    code, data, r = call('GET', '/users', headers=admin, raw=True)
    assert code == 200 and data['total'] >= 1, data
    assert r.headers.get('access-control-allow-origin') == 'http://localhost', r.headers
    code, created = call('POST', '/users', {'userId': 'unique()', 'email': f'admin.{uid()}@appwrite.io'}, headers=admin)
    assert code == 201, created
    code, s = call('POST', f'/users/{created["$id"]}/sessions', headers=admin)
    assert code == 201 and s['secret'], s
    client = {'content-type': 'application/json', 'x-appwrite-project': PROJECT, 'origin': 'http://localhost',
              'cookie': f'a_session_{PROJECT}={user}'}
    code, err = call('GET', '/users', headers=client)
    assert code == 401 and err['message'] == 'end@appwrite.io (role: users) missing scopes (["users.read"])', err
    code, err = call('POST', '/users/enduser/sessions', headers=client)
    assert code == 401 and err['type'] == 'general_unauthorized_scope', err
    session_header = {'content-type': 'application/json', 'x-appwrite-project': PROJECT, 'x-appwrite-session': user}
    code, err = call('GET', '/users', headers=session_header)
    assert code == 401 and 'end@appwrite.io' in err['message'], err
    # Impersonators may discover users.
    code, _ = call('PATCH', '/users/enduser/impersonator', {'impersonator': True})
    assert code == 200
    code, data = call('GET', '/users', headers=client)
    assert code == 200 and data['total'] >= 1, data
    code, err = call('GET', '/users/enduser/mfa/recovery-codes', headers=client)
    assert code == 401, err
    code, data = call('GET', '/users', headers={**client, 'x-appwrite-impersonate-user-id': created['$id']})
    assert code == 200, data
    code, _ = call('PATCH', '/users/enduser/impersonator', {'impersonator': False})
    # JWT
    code, j = call('POST', '/users/enduser/jwts', {'sessionId': 'endsession'})
    jwt_headers = {'content-type': 'application/json', 'x-appwrite-project': PROJECT, 'x-appwrite-jwt': j['jwt']}
    code, err = call('GET', '/users', headers=jwt_headers)
    assert code == 401 and 'end@appwrite.io' in err['message'], err
    code, err = call('GET', '/users', headers={**jwt_headers, 'x-appwrite-jwt': j['jwt'] + 'x'})
    assert code == 401 and err['type'] == 'user_jwt_invalid' and err['message'].startswith('Failed to verify JWT.'), err
    code, err = call('GET', '/users', headers={**client, 'x-appwrite-jwt': j['jwt']})
    assert code == 403 and err['type'] == 'user_jwt_and_cookie_set', err
    code, err = call('GET', '/users', headers={**admin, 'x-appwrite-project': 'console'})
    assert code == 400 and err['type'] == 'general_bad_request', err


def main():
    only = sys.argv[1:]
    failed = 0
    ran = 0
    for t in TESTS:
        if only and t.__name__ not in only:
            continue
        ran += 1
        start = time.time()
        try:
            t()
            print(f'PASS {t.__name__} ({(time.time() - start) * 1000:.0f} ms)')
        except Exception:
            failed += 1
            print(f'FAIL {t.__name__}')
            traceback.print_exc(limit=3)
    print(f'{ran - failed}/{ran} passed')
    sys.exit(1 if failed else 0)


main()
