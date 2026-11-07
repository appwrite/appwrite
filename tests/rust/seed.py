#!/usr/bin/env python3
"""Seeds a minimal platform (team, project, API key, web platform) for local
integration tests of the Rust API. Prints the API key secret.

Requires: psql on PATH, PGPASSWORD, and the `cryptography` package.
Env: DB_HOST, DB_PORT, DB_USER, DB_NAME, _APP_OPENSSL_KEY_V1
"""
import base64, hashlib, json, os, secrets, subprocess, sys
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

KEY = os.environ.get('_APP_OPENSSL_KEY_V1', 'your-secret-key').encode()[:16].ljust(16, b'\0')

def encrypt(value: str) -> str:
    iv = secrets.token_bytes(12)
    out = AESGCM(KEY).encrypt(iv, value.encode(), None)
    data, tag = out[:-16], out[-16:]
    return json.dumps({'data': base64.b64encode(data).decode(), 'method': 'aes-128-gcm', 'iv': iv.hex(), 'tag': tag.hex(), 'version': '1'})

def q(s):
    return "'" + s.replace("'", "''") + "'"

def sql(statements):
    cmd = ['psql', '-q', '-v', 'ON_ERROR_STOP=1', '-h', os.environ.get('DB_HOST', '127.0.0.1'), '-p', os.environ.get('DB_PORT', '5432'),
           '-U', os.environ.get('DB_USER', 'user'), '-d', os.environ.get('DB_NAME', 'appwrite')]
    subprocess.run(cmd, input='\n'.join(statements), text=True, check=True)

def main():
    project = sys.argv[1] if len(sys.argv) > 1 else 'test'
    scopes = sys.argv[2].split(',') if len(sys.argv) > 2 else ['users.read', 'users.write', 'sessions.read', 'sessions.write']
    secret = 'standard_' + secrets.token_hex(128)
    auths = {
        'limit': 0, 'maxSessions': 10,
        'passwordStrength': {'min': 8, 'uppercase': False, 'lowercase': False, 'number': False, 'symbols': False},
        'passwordHistory': 0, 'passwordDictionary': False, 'duration': 31536000, 'personalDataCheck': False,
        'passwordPwned': {'enabled': True, 'sessions': False, 'users': False},
        'disposableEmails': False, 'canonicalEmails': False, 'freeEmails': False, 'corporateEmails': False,
        'invalidateSessions': True, 'mfaFactors': {'totp': True, 'email': True, 'phone': True, 'custom': False},
    }
    perms = json.dumps(['read("team:team1/owner")', 'read("team:team1/developer")'])
    sql([
        'INSERT INTO "appwrite"."_console_teams" (_id, _uid, "_createdAt", "_updatedAt", name, total, prefs, _permissions) '
        "VALUES (1, 'team1', now(), now(), 'Team', 1, '{}', '[]') ON CONFLICT DO NOTHING;",
        'INSERT INTO "appwrite"."_console_projects" (_id, _uid, "_createdAt", "_updatedAt", "teamInternalId", "teamId", name, region, database, services, apis, auths, onboarding, _permissions) '
        f"VALUES (1, {q(project)}, now(), now(), '1', 'team1', 'Test', 'default', 'database_db_main', '{{}}', '{{}}', {q(json.dumps(auths))}, '[]', {q(perms)}::jsonb) ON CONFLICT DO NOTHING;",
        'INSERT INTO "appwrite"."_console_keys" (_uid, "_createdAt", "_updatedAt", "resourceType", "resourceId", "resourceInternalId", name, scopes, secret, sdks, _permissions) '
        f"VALUES ({q(secrets.token_hex(10))}, now(), now(), 'projects', {q(project)}, '1', 'Test key', {q(json.dumps(scopes))}::jsonb, {q(encrypt(secret))}, '[]'::jsonb, '[]');",
        'INSERT INTO "appwrite"."_console_platforms" (_uid, "_createdAt", "_updatedAt", "projectInternalId", "projectId", type, name, hostname, _permissions) '
        f"VALUES ({q(secrets.token_hex(10))}, now(), now(), '1', {q(project)}, 'web', 'Localhost', 'localhost', '[]');",
    ])
    # Console admin (owner of team1) and a project user, both with sessions.
    console_secret = secrets.token_hex(32)
    user_secret = secrets.token_hex(32)
    expire = "now() + interval '1 day'"
    def session(ns, sid, uid_, seq, secret_):
        return (f'INSERT INTO "appwrite"."{ns}_sessions" (_uid, "_createdAt", "_updatedAt", "userInternalId", "userId", provider, secret, expire, factors, _permissions) '
                f"VALUES ({q(sid)}, now(), now(), {q(str(seq))}, {q(uid_)}, 'email', {q(encrypt(hashlib.sha256(secret_.encode()).hexdigest()))}, {expire}, '[\"password\"]'::jsonb, '[]');")
    sql([
        'INSERT INTO "appwrite"."_console_users" (_id, _uid, "_createdAt", "_updatedAt", name, email, status, labels, "passwordHistory", "mfaRecoveryCodes", prefs, _permissions) '
        "VALUES (1, 'admin', now(), now(), 'Admin', 'admin@appwrite.io', true, '[]', '[]', '[]', '{}', '[]');",
        session('_console', 'adminsession', 'admin', 1, console_secret),
        'INSERT INTO "appwrite"."_console_memberships" (_uid, "_createdAt", "_updatedAt", "userInternalId", "userId", "teamInternalId", "teamId", roles, confirm, _permissions) '
        "VALUES ('m1', now(), now(), '1', 'admin', '1', 'team1', '[\"owner\"]'::jsonb, true, '[]');",
        'INSERT INTO "appwrite"."_1_users" (_id, _uid, "_createdAt", "_updatedAt", name, email, status, labels, "passwordHistory", "mfaRecoveryCodes", prefs, impersonator, search, _permissions) '
        "VALUES (1000000, 'enduser', now(), now(), 'End User', 'end@appwrite.io', true, '[]', '[]', '[]', '{}', false, 'enduser end@appwrite.io End User', '[\"read(\\\"any\\\")\"]'::jsonb);",
        session('_1', 'endsession', 'enduser', 1000000, user_secret),
    ])
    store = lambda u, s_: base64.b64encode(json.dumps({'id': u, 'secret': s_}).encode()).decode()
    print(json.dumps({'key': secret, 'console': store('admin', console_secret), 'user': store('enduser', user_secret)}))

main()
