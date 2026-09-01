{% info title="Running these examples" %}
The SQL on this page runs in the Console **SQL editor**, in `psql`, or through any PostgreSQL driver.

Run one statement at a time. The SQL editor and the SQL API both accept a single statement per request, so a block containing several statements is rejected rather than run in sequence.

Through the SQL API, a database allows `SELECT`, `INSERT`, `UPDATE`, and `DELETE` by default. To run the schema examples with an API key, add the statement types you need to the database:

```bash
curl -X PATCH \
  -H "X-Appwrite-Project: <PROJECT_ID>" \
  -H "X-Appwrite-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
      "sqlApiAllowedStatements": ["SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "ALTER", "DROP"]
  }' \
  https://<REGION>.cloud.appwrite.io/v1/postgresql/<DATABASE_ID>
```

`TRUNCATE`, `GRANT`, and `REVOKE` can be added the same way. `EXPLAIN`, `ANALYZE`, `WITH`, `REFRESH`, and transaction control statements such as `BEGIN` and `COMMIT` cannot, so run those in the SQL editor or over a direct connection.

Opening the Console SQL editor on a database adds every statement type it supports, so a database you have already used from the Console needs no change.

Pages in this section reuse table names such as `customers` and `orders` with different columns. Work through one page at a time in an empty database, or drop the tables between pages.
{% /info %}
