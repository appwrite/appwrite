# This script uses the package's .env file and refreshes the Supabase and NHost fixture dumps the e2e databases load.

set -e
cd "$(dirname "$0")"

source ../../.env

echo "Updating Supabase Backup..."
export PGPASSWORD=$SOURCE_SUPABASE_TEST_DATABASE_PASSWORD
pg_dumpall -U $SOURCE_SUPABASE_TEST_DATABASE_USER -h $SOURCE_SUPABASE_TEST_HOST -p 5432 --globals-only --no-role-passwords --file=supabase/1_globals.sql
pg_dump -U $SOURCE_SUPABASE_TEST_DATABASE_USER -h $SOURCE_SUPABASE_TEST_HOST -p 5432 --clean --file=supabase/2_main.sql $SOURCE_SUPABASE_TEST_DATABASE_NAME
unset PGPASSWORD
echo "Done"

echo "Updating NHost Backup..."
export PGPASSWORD=$SOURCE_NHOST_TEST_DATABASE_PASSWORD
NHOST_HOST=$SOURCE_NHOST_TEST_SUBDOMAIN.db.$SOURCE_NHOST_TEST_REGION.nhost.run
pg_dumpall -U $SOURCE_NHOST_TEST_DATABASE_USER -h $NHOST_HOST -p 5432 --globals-only --no-role-passwords --file=nhost/1_globals.sql
pg_dump -U $SOURCE_NHOST_TEST_DATABASE_USER -h $NHOST_HOST -p 5432 --clean --file=nhost/2_main.sql $SOURCE_NHOST_TEST_DATABASE_NAME
unset PGPASSWORD
echo "Done"
