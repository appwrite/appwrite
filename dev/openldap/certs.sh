#!/bin/sh
# Certificates for the OpenLDAP fixture: a private CA and a server certificate
# it signed, valid for the hostnames the tests and a developer dial. Run before
# starting the openldap service; the files are mounted into it, and the CA is
# what LDAPSessionIntegrationTest passes as caCertificate.
#
#   ./dev/openldap/certs.sh
#   docker compose --profile openldap up -d --wait openldap
#   docker compose --profile openldap-seed run --rm openldap-seed
set -eu

DIR="$(cd "$(dirname "$0")" && pwd)/certs"
mkdir -p "$DIR"

if [ -f "$DIR/ca.crt" ] && [ -f "$DIR/ldap.crt" ] && [ -f "$DIR/ldap.key" ]; then
  echo "LDAP certificates already exist in $DIR"
  exit 0
fi

openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
  -subj "/CN=Appwrite Test LDAP CA" \
  -keyout "$DIR/ca.key" -out "$DIR/ca.crt" 2>/dev/null

openssl req -newkey rsa:2048 -nodes -sha256 \
  -subj "/CN=openldap" \
  -keyout "$DIR/ldap.key" -out "$DIR/ldap.csr" 2>/dev/null

printf 'subjectAltName=DNS:openldap,DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n' > "$DIR/ldap.ext"

openssl x509 -req -sha256 -days 3650 \
  -in "$DIR/ldap.csr" -CA "$DIR/ca.crt" -CAkey "$DIR/ca.key" -CAcreateserial \
  -extfile "$DIR/ldap.ext" -out "$DIR/ldap.crt" 2>/dev/null

rm -f "$DIR/ldap.csr" "$DIR/ldap.ext" "$DIR/ca.srl"

# The directory runs as its own user and must be able to read the key.
chmod 644 "$DIR/ldap.key" "$DIR/ca.key"

echo "LDAP certificates written to $DIR"
