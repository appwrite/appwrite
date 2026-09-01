{% info title="Running these examples" %}
The SQL on this page runs in the Console **SQL editor**, in `psql`, or through any PostgreSQL driver.

Run one statement at a time. The SQL editor and the SQL API both accept a single statement per request, so a block containing several statements is rejected rather than run in sequence.

Through the SQL API with an API key, a database allows `SELECT`, `INSERT`, `UPDATE`, and `DELETE` by default. Add `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `GRANT`, or `REVOKE` to the database's allowed statements to run the schema examples. `EXPLAIN`, `ANALYZE`, `WITH`, `REFRESH`, and transaction control statements such as `BEGIN` and `COMMIT` cannot be added to that list, so run those in the SQL editor or over a direct connection.

Pages in this section reuse table names such as `customers` and `orders` with different columns. Work through one page at a time in an empty database, or drop the tables between pages.
{% /info %}
