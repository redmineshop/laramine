#!/bin/sh
# Load the live LDAP fixture into a directory that is already listening.
# The container must allow cn=admin,cn=config to bind with LDAP_CONFIG_PASSWORD.
set -eu

HOST="${LDAP_HOST:-127.0.0.1}"
PORT="${LDAP_PORT:-389}"
BASE="${LDAP_BASE_DN:-dc=example,dc=test}"
ADMIN="${LDAP_ADMIN_DN:-cn=admin,dc=example,dc=test}"
PASS="${LDAP_ADMIN_PASSWORD:-adminsecret}"
CONFIG_DN="${LDAP_CONFIG_DN:-cn=admin,cn=config}"
CONFIG_PASS="${LDAP_CONFIG_PASSWORD:-adminsecret}"
LDIF="$(CDPATH= cd -- "$(dirname "$0")" && pwd)/directory.ldif"

i=0
while [ "$i" -lt 30 ]; do
  if ldapsearch -x -H "ldap://${HOST}:${PORT}" -D "$ADMIN" -w "$PASS" -b "$BASE" -s base >/dev/null 2>&1; then
    break
  fi
  i=$((i + 1))
  sleep 1
done

ldapsearch -x -H "ldap://${HOST}:${PORT}" -D "$ADMIN" -w "$PASS" -b "$BASE" -s base >/dev/null

DB_DN="$(ldapsearch -x -LLL -H "ldap://${HOST}:${PORT}" -D "$CONFIG_DN" -w "$CONFIG_PASS" -b cn=config "(olcSuffix=${BASE})" dn | sed -n 's/^dn: //p' | head -n 1)"
if [ -z "$DB_DN" ]; then
  echo "LDAP database configuration was not found." >&2
  exit 1
fi

ldapmodify -x -H "ldap://${HOST}:${PORT}" -D "$CONFIG_DN" -w "$CONFIG_PASS" <<EOF
dn: ${DB_DN}
changetype: modify
replace: olcAccess
olcAccess: to attrs=userPassword,shadowLastChange by self write by dn="${ADMIN}" write by anonymous auth by * none
olcAccess: to * by self read by dn="${ADMIN}" write by anonymous read by users read by * none
EOF

ldapadd -c -x -H "ldap://${HOST}:${PORT}" -D "$ADMIN" -w "$PASS" -f "$LDIF" || true
