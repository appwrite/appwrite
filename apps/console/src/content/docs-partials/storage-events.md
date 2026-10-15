{% table %}

- Name
- Description

---

- `buckets.*`
- This event triggers on any buckets event.
  Returns [Bucket Object](/docs/references/cloud/models/bucket)

---

- `buckets.*.create`
- This event triggers when a bucket is created.
  Returns [Bucket Object](/docs/references/cloud/models/bucket)

---

- `buckets.*.delete`
- This event triggers when a bucket is deleted.
  Returns [Bucket Object](/docs/references/cloud/models/bucket)

---

- `buckets.*.files.*`
- This event triggers on any files event.
  Returns [File Object](/docs/references/cloud/models/file)

---

- `buckets.*.files.*.create`
- Since the Appwrite SDK chunks files in 5MB increments, this event will trigger for each 5MB chunk. A file is fully uploaded when `chunksTotal` equals `chunksUploaded`.
  Returns [File Object](/docs/references/cloud/models/file)

---

- `buckets.*.files.*.delete`
- This event triggers when a file is deleted.
  Returns [File Object](/docs/references/cloud/models/file)

---

- `buckets.*.files.*.update`
- This event triggers when a file is updated.
  Returns [File Object](/docs/references/cloud/models/file)

---

- `buckets.*.update`
- This event triggers when a bucket is updated.
  Returns [Bucket Object](/docs/references/cloud/models/bucket)

{% /table %}
