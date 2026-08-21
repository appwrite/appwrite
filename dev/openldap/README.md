# LDAP development directory

A throwaway OpenLDAP server for developing and testing LDAP authentication. It
runs offline, needs no accounts, and seeds itself on first start.

Note this is a *directory server*, not an identity provider. Keycloak, Auth0 and
WorkOS are the wrong shape for testing this: they consume a directory the same
way Appwrite does. Their "add an identity provider" flows also lead to SAML or
OIDC rather than LDAP, which is worth knowing if you find yourself being asked
for an X.509 signing certificate — LDAP does not use one.

## Start it

```bash
docker compose --profile openldap up -d openldap openldap-seed
```

`openldap-seed` waits for the server, loads `seed/seed.ldif`, and exits. It is a
separate container because the image rewrites and then deletes its own bootstrap
directory during startup, which a bind mount cannot survive.

Re-run it after editing the fixtures; `ldapadd -c` skips entries that already
exist, so it is safe to run repeatedly:

```bash
docker compose --profile openldap up -d --force-recreate openldap-seed
```

## Configure a project

Both containers share the `appwrite` network, so the host is the service name:

```bash
curl -X PATCH http://localhost/v1/project/auth/ldap \
  -H 'Content-Type: application/json' \
  -H "X-Appwrite-Project: $PROJECT_ID" \
  -H 'X-Appwrite-Mode: admin' \
  -b cookies.txt \
  -d '{
    "host": "openldap",
    "port": 389,
    "encryption": "none",
    "baseDn": "dc=appwrite,dc=test",
    "bindDn": "cn=admin,dc=appwrite,dc=test",
    "bindPassword": "adminpassword",
    "userFilter": "(uid={{username}})",
    "emailAttribute": "mail",
    "nameAttribute": "cn",
    "enabled": true
  }'
```

`encryption: none` is fine here and only here: the traffic never leaves the
Docker network. Any real deployment needs `tls` or `ssl`, because a simple bind
sends the password in the clear.

## Sign in

```bash
curl -X POST http://localhost/v1/account/sessions/ldap \
  -H 'Content-Type: application/json' \
  -H "X-Appwrite-Project: $PROJECT_ID" \
  -d '{"username": "alice", "password": "alicepass"}'
```

## What the fixtures cover

| Entry | Password | Purpose |
|---|---|---|
| `alice` | `alicepass` | In `appwrite-users`. Signs in, and passes a group provisioning filter. |
| `bob` | `bobpass` | Valid credentials, not in the group. Signs in normally; refused when a group filter is set. |
| `noemail` | `noemailpass` | No `mail` attribute. Refused, because an account cannot be created without an email address. |

To exercise the provisioning filter, add it to the configuration:

```json
{"provisionFilter": "(&(cn=appwrite-users)(member={{username}}))"}
```

`{{username}}` is replaced with the authenticated entry's distinguished name,
since membership attributes hold DNs. With that set, `alice` is admitted and
`bob` is refused.

## Inspecting the directory

```bash
docker compose exec openldap ldapsearch -x -H ldap://localhost \
  -D "cn=admin,dc=appwrite,dc=test" -w adminpassword \
  -b "dc=appwrite,dc=test" "(objectClass=inetOrgPerson)"
```

## Alternatives

`lldap` has a web UI for managing users instead of LDIF files, and `glauth` is a
single binary configured from a text file. Either is fine. OpenLDAP is used here
because it is what people actually run, so its quirks surface during development
rather than after release.
