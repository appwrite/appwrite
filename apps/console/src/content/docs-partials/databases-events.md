{% table %}

- Name
- Description

---

- `tablesdb.*`
- This event triggers on any database event.
  Returns [Database Object](/docs/references/cloud/models/database)

---

- `tablesdb.*.tables.*`
- This event triggers on any table event.
  Returns [Table Object](/docs/references/cloud/models/table)

---

- `tablesdb.*.tables.*.columns.*`
- This event triggers on any columns event.
  Returns [Column Object](/docs/references/cloud/models/columnList)

---

- `tablesdb.*.tables.*.columns.*.create`
- This event triggers when a column is created.
  Returns [Column Object](/docs/references/cloud/models/columnList)

---

- `tablesdb.*.tables.*.columns.*.update`
- This event triggers when a column is updated.
  Returns [Column Object](/docs/references/cloud/models/columnList)

---

- `tablesdb.*.tables.*.columns.*.delete`
- This event triggers when a column is deleted.
  Returns [Column Object](/docs/references/cloud/models/columnList)

---

- `tablesdb.*.tables.*.create`
- This event triggers when a table is created.
  Returns [Table Object](/docs/references/cloud/models/table)

---

- `tablesdb.*.tables.*.delete`
- This event triggers when a table is deleted.
  Returns [Table Object](/docs/references/cloud/models/table)

---

- `tablesdb.*.tables.*.rows.*`
- This event triggers on any rows event.
  Returns [Row Object](/docs/references/cloud/models/row)

---

- `tablesdb.*.tables.*.rows.*.create`
- This event triggers when a row is created.
  Returns [Row Object](/docs/references/cloud/models/row)

---

- `tablesdb.*.tables.*.rows.*.delete`
- This event triggers when a row is deleted.
  Returns [Row Object](/docs/references/cloud/models/row)

---

- `tablesdb.*.tables.*.rows.*.update`
- This event triggers when a row is updated.
  Returns [Row Object](/docs/references/cloud/models/row)

---

- `tablesdb.*.tables.*.rows.*.upsert`
- This event triggers when a row is upserted.
  Returns [Row Object](/docs/references/cloud/models/row)

---

- `tablesdb.*.tables.*.indexes.*`
- This event triggers on any indexes event.
  Returns [Index Object](/docs/references/cloud/models/columnIndex)

---

- `tablesdb.*.tables.*.indexes.*.create`
- This event triggers when an index is created.
  Returns [Index Object](/docs/references/cloud/models/columnIndex)

---

- `tablesdb.*.tables.*.indexes.*.update`
- This event triggers when an index is updated.
  Returns [Index Object](/docs/references/cloud/models/columnIndex)

---

- `tablesdb.*.tables.*.indexes.*.delete`
- This event triggers when an index is deleted.
  Returns [Index Object](/docs/references/cloud/models/columnIndex)

---

- `tablesdb.*.tables.*.update`
- This event triggers when a table is updated.
  Returns [Table Object](/docs/references/cloud/models/table)

---

- `tablesdb.*.create`
- This event triggers when a database is created.
  Returns [Database Object](/docs/references/cloud/models/database)

---

- `tablesdb.*.delete`
- This event triggers when a database is deleted.
  Returns [Database Object](/docs/references/cloud/models/database)

---

- `tablesdb.*.update`
- This event triggers when a database is updated.
  Returns [Database Object](/docs/references/cloud/models/database){% /table %}
