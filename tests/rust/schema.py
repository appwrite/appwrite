#!/usr/bin/env python3
"""Generates the PostgreSQL DDL that utopia-php/database creates for the
collections used by the Rust Users service.

Only for local integration tests of the Rust API without the PHP stack.
In real deployments PHP owns the schema (installation and migrations).

Usage: schema.py <project-namespace> [--shared]
"""
import sys

S = 'string'; I = 'integer'; B = 'boolean'; D = 'datetime'; F = 'float'

def a(id, t, size=0, array=False):
    return (id, t, size, array)

COMMON = {
    'users': [a('name', S, 256), a('email', S, 320), a('phone', S, 16), a('status', B), a('labels', S, 128, True),
              a('passwordHistory', S, 16384, True), a('password', S, 16384), a('hash', S, 256), a('hashOptions', S, 65535),
              a('passwordUpdate', D), a('prefs', S, 65535), a('registration', D), a('emailVerification', B),
              a('phoneVerification', B), a('reset', B), a('mfa', B), a('mfaRecoveryCodes', S, 256, True),
              a('authenticators', S, 16384), a('sessions', S, 16384), a('tokens', S, 16384), a('challenges', S, 16384),
              a('memberships', S, 16384), a('targets', S, 16384), a('search', S, 16384), a('accessedAt', D),
              a('emailCanonical', S, 320), a('emailIsFree', B), a('emailIsDisposable', B), a('passwordPwned', B),
              a('emailIsCorporate', B), a('emailIsCanonical', B), a('impersonator', B), a('photoId', S, 255),
              a('photoSize', I, 8)],
    'tokens': [a('userInternalId', S, 255), a('userId', S, 255), a('type', I), a('secret', S, 512), a('expire', D),
               a('userAgent', S, 16384), a('ip', S, 45)],
    'authenticators': [a('userInternalId', S, 255), a('userId', S, 255), a('type', S, 255), a('verified', B),
                       a('data', S, 65535), a('identifier', S, 64), a('name', S, 128), a('accessedAt', D)],
    'challenges': [a('userInternalId', S, 255), a('userId', S, 255), a('type', S, 255), a('token', S, 512),
                   a('code', S, 512), a('expire', D), a('passkey', S, 16384)],
    'sessions': [a('userInternalId', S, 255), a('userId', S, 255), a('provider', S, 128), a('providerUid', S, 2048),
                 a('providerAccessToken', S, 16384), a('providerAccessTokenExpiry', D), a('providerRefreshToken', S, 16384),
                 a('secret', S, 512), a('userAgent', S, 16384), a('ip', S, 45), a('countryCode', S, 2),
                 a('continentCode', S, 2), a('latitude', F), a('longitude', F)]
                + [a(k, S, 255) for k in ['timeZone', 'weatherCode', 'postalCode', 'autonomousSystemNumber',
                   'autonomousSystemOrganization', 'connectionType', 'connectionUsageType', 'connectionOrganization', 'isp']]
                + [a(k, S, 256) for k in ['osCode', 'osName', 'osVersion', 'clientType', 'clientCode', 'clientName',
                   'clientVersion', 'clientEngine', 'clientEngineVersion', 'deviceName', 'deviceBrand', 'deviceModel']]
                + [a('factors', S, 256, True), a('expire', D), a('mfaUpdatedAt', D)],
    'identities': [a('userInternalId', S, 255), a('userId', S, 255), a('provider', S, 128), a('providerUid', S, 2048),
                   a('providerEmail', S, 320), a('photo', S, 2048), a('providerAccessToken', S, 16384),
                   a('providerAccessTokenExpiry', D), a('providerRefreshToken', S, 16384), a('providerIdToken', S, 16384),
                   a('secrets', S, 16384), a('scopes', S, 255, True), a('expire', D)],
    'teams': [a('name', S, 128), a('total', I), a('search', S, 16384), a('prefs', S, 65535), a('labels', S, 128, True)],
    'memberships': [a('userInternalId', S, 255), a('userId', S, 255), a('teamInternalId', S, 255), a('teamId', S, 255),
                    a('roles', S, 128, True), a('invited', D), a('joined', D), a('confirm', B), a('secret', S, 256),
                    a('search', S, 16384)],
    'providers': [a('name', S, 128), a('provider', S, 255), a('type', S, 128), a('enabled', B),
                  a('credentials', S, 16384), a('options', S, 16384), a('search', S, 65535)],
    'topics': [a('name', S, 128), a('subscribe', S, 128, True), a('emailTotal', I), a('smsTotal', I), a('pushTotal', I),
               a('targets', S, 16384), a('search', S, 16384), a('sequence', I), a('qos', I), a('expiry', I)],
    'subscribers': [a(k, S, 255) for k in ['targetId', 'targetInternalId', 'userId', 'userInternalId', 'topicId', 'topicInternalId']]
                   + [a('providerType', S, 128), a('search', S, 16384)],
    'targets': [a('userId', S, 255), a('userInternalId', S, 255), a('sessionId', S, 255), a('sessionInternalId', S, 255),
                a('providerType', S, 255), a('providerId', S, 255), a('providerInternalId', S, 255),
                a('identifier', S, 255), a('name', S, 255), a('expired', B)],
}
PROJECT_ONLY = {
    'functions': [a('name', S, 2048), a('enabled', B), a('events', S, 256, True)],
}
PLATFORM = {
    'projects': [a('teamInternalId', S, 255), a('teamId', S, 255), a('name', S, 128), a('region', S, 128),
                 a('description', S, 256), a('database', S, 256), a('accessedAt', D), a('services', S, 16384),
                 a('apis', S, 16384), a('auths', S, 16384), a('platforms', S, 16384), a('webhooks', S, 16384),
                 a('keys', S, 16384), a('search', S, 16384), a('labels', S, 128, True), a('onboarding', S, 65536),
                 a('status', S, 100)],
    'keys': [a('resourceType', S, 255), a('resourceId', S, 255), a('resourceInternalId', S, 255), a('name', S, 255),
             a('scopes', S, 255, True), a('secret', S, 512), a('expire', D), a('accessedAt', D), a('sdks', S, 255, True)],
    'platforms': [a('projectInternalId', S, 255), a('projectId', S, 255), a('type', S, 255), a('name', S, 256),
                  a('key', S, 255), a('store', S, 256), a('hostname', S, 256)],
    'webhooks': [a('projectInternalId', S, 255), a('projectId', S, 255), a('name', S, 255), a('url', S, 255),
                 a('httpUser', S, 255), a('httpPass', S, 255), a('security', B), a('events', S, 255, True),
                 a('signatureKey', S, 2048), a('enabled', B), a('logs', S, 1000000), a('attempts', I)],
}

UNIQUE = {'users': [['email'], ['phone']], 'targets': [['identifier']], 'authenticators': [['identifier']],
          'memberships': [['teamInternalId', 'userInternalId']], 'subscribers': [['targetInternalId', 'topicInternalId']]}

def sqltype(t, size, array):
    if array:
        return 'JSONB'
    if t == S:
        return 'TEXT' if size > 16381 else f'VARCHAR({size})'
    if t == I:
        return 'BIGINT' if size >= 8 else 'INTEGER'
    return {B: 'BOOLEAN', D: 'TIMESTAMP(3)', F: 'DOUBLE PRECISION'}[t]

def table(ns, coll, attrs, shared):
    t = f'"appwrite"."{ns}_{coll}"'
    cols = ''.join(f'"{i}" {sqltype(ty, sz, arr)}, ' for i, ty, sz, arr in attrs)
    tenant = '_tenant INTEGER DEFAULT NULL,' if shared else ''
    out = [f'CREATE TABLE {t} (_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, _uid VARCHAR(255) NOT NULL, {tenant} "_createdAt" TIMESTAMP(3) DEFAULT NULL, "_updatedAt" TIMESTAMP(3) DEFAULT NULL, {cols} _permissions JSONB DEFAULT NULL);']
    if shared:
        out.append(f'CREATE UNIQUE INDEX "{ns}_{coll}_uid" ON {t} ("_uid" COLLATE utf8_ci_ai, "_tenant");')
    else:
        out.append(f'CREATE UNIQUE INDEX "{ns}_{coll}_uid" ON {t} ("_uid" COLLATE utf8_ci_ai);')
    out.append(f'CREATE INDEX "{ns}_{coll}_permissions" ON {t} USING gin (_permissions);')
    for i, cols_ in enumerate(UNIQUE.get(coll, [])):
        c = ', '.join(([] if not shared else ['"_tenant"']) + [f'"{x}"' for x in cols_])
        out.append(f'CREATE UNIQUE INDEX "{ns}_{coll}_unique_{i}" ON {t} ({c});')
    p = f'"appwrite"."{ns}_{coll}_perms"'
    out.append(f'CREATE TABLE {p} (_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, _tenant INTEGER DEFAULT NULL, _type VARCHAR(12) NOT NULL, _permission VARCHAR(255) NOT NULL, _document VARCHAR(255) NOT NULL);')
    out.append(f'CREATE UNIQUE INDEX "{ns}_{coll}_ukey" ON {p} (_document, _type, _permission{", _tenant" if shared else ""});')
    return '\n'.join(out)

def main():
    ns = sys.argv[1] if len(sys.argv) > 1 else '_1'
    shared = '--shared' in sys.argv
    print('CREATE SCHEMA IF NOT EXISTS "appwrite";')
    print("DO $$ BEGIN CREATE COLLATION utf8_ci_ai (provider = icu, locale = 'und-u-ks-level1', deterministic = false); EXCEPTION WHEN others THEN BEGIN CREATE COLLATION utf8_ci_ai (locale = 'C'); EXCEPTION WHEN others THEN NULL; END; END $$;")
    for coll, attrs in {**PLATFORM, **COMMON}.items():
        print(table('_console', coll, attrs, False))
    for coll, attrs in {**COMMON, **PROJECT_ONLY}.items():
        print(table(ns, coll, attrs, shared))

main()
