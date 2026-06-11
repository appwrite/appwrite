{% table %}

- Name
- Description

---

- `sites.*`
- This event triggers on any sites event.
  Returns [Site Object](/docs/references/cloud/models/site)

---

- `sites.*.create`
- This event triggers when a site is created.
  Returns [Site Object](/docs/references/cloud/models/site)

---

- `sites.*.delete`
- This event triggers when a site is deleted.
  Returns [Site Object](/docs/references/cloud/models/site)

---

- `sites.*.deployments.*`
- This event triggers on any deployments event.
  Returns [Deployment Object](/docs/references/cloud/models/deployment)

---

- `sites.*.deployments.*.create`
- This event triggers when a deployment is created.
  Returns [Deployment Object](/docs/references/cloud/models/deployment)

---

- `sites.*.deployments.*.delete`
- This event triggers when a deployment is deleted.
  Returns [Deployment Object](/docs/references/cloud/models/deployment)

---

- `sites.*.deployments.*.update`
- This event triggers when a deployment is updated.
  Returns [Deployment Object](/docs/references/cloud/models/deployment)

---

- `sites.*.update`
- This event triggers when a site is updated.
  Returns [Site Object](/docs/references/cloud/models/site)

{% /table %}
