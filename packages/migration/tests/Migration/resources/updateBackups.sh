# This script uses your .env file and updates the backups.tar for NHost and Supabase.

source ../../../.env 

echo "Updating Supabase Backup..."
export PGPASSWORD=$SUPABASE_TEST_PASSWORD
pg_dump -U $SUPABASE_TEST_USERNAME -h $SUPABASE_TEST_HOST -p 5432 --clean --file=supabase/dump.sql $SUPABASE_TEST_DATABASE
unset PGPASSWORD
echo "Done"

echo "Updating NHost Backup..."
export PGPASSWORD=$NHOST_TEST_PASSWORD
pg_dump -U $NHOST_TEST_USERNAME -h $NHOST_TEST_SUBDOMAIN.db.$NHOST_TEST_REGION.nhost.run -p 5432 --clean --file=nhost/dump.sql $NHOST_TEST_DATABASE
unset PGPASSWORD
echo "Done"
