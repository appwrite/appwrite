{% table %}

- Name
- Description

---

- `teams.*`
- This event triggers on any teams event.
  Returns [Team Object](/docs/references/cloud/models/team)

---

- `teams.*.create`
- This event triggers when a team is created.
  Returns [Team Object](/docs/references/cloud/models/team)

---

- `teams.*.delete`
- This event triggers when a team is deleted.
  Returns [Team Object](/docs/references/cloud/models/team)

---

- `teams.*.memberships.*`
- This event triggers on any team memberships event.
  Returns [Membership Object](/docs/references/cloud/models/membership)

---

- `teams.*.memberships.*.create`
- This event triggers when a membership is created.
  Returns [Membership Object](/docs/references/cloud/models/membership)

---

- `teams.*.memberships.*.delete`
- This event triggers when a membership is deleted.
  Returns [Membership Object](/docs/references/cloud/models/membership)

---

- `teams.*.memberships.*.update`
- This event triggers when a membership is updated.
  Returns [Membership Object](/docs/references/cloud/models/membership)

---

- `teams.*.memberships.*.update.status`
- This event triggers when a team memberships status is updated.
  Returns [Membership Object](/docs/references/cloud/models/membership)

---

- `teams.*.update`
- This event triggers when a team is updated.
  Returns [Team Object](/docs/references/cloud/models/team)

---

- `teams.*.update.prefs`
- This event triggers when a team's preferences are updated.
  Returns [Team Object](/docs/references/cloud/models/team)

---

- `users.*`
- This event triggers on any user's event.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.create`
- This event triggers when a user is created.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.delete`
- This event triggers when a user is deleted.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.recovery.*`
- This event triggers on any user's recovery token event.
  Returns [Token Object](/docs/references/cloud/models/token)

---

- `users.*.recovery.*.create`
- This event triggers when a recovery token for a user is created.
  Returns [Token Object](/docs/references/cloud/models/token)

---

- `users.*.recovery.*.update`
- This event triggers when a recovery token for a user is validated.
  Returns [Token Object](/docs/references/cloud/models/token)

---

- `users.*.sessions.*`
- This event triggers on any user's sessions event.
  Returns [Session Object](/docs/references/cloud/models/session)

---

- `users.*.sessions.*.create`
- This event triggers when a session for a user is created.
  Returns [Session Object](/docs/references/cloud/models/session)

---

- `users.*.sessions.*.delete`
- This event triggers when a session for a user is deleted.
  Returns [Session Object](/docs/references/cloud/models/session)

---

- `users.*.update`
- This event triggers when a user is updated.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.update.email`
- This event triggers when a user's email address is updated.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.update.name`
- This event triggers when a user's name is updated.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.update.password`
- This event triggers when a user's password is updated.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.update.prefs`
- This event triggers when a user's preferences is updated.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.update.status`
- This event triggers when a user's status is updated.
  Returns [User Object](/docs/references/cloud/models/user)

---

- `users.*.verification.*`
- This event triggers on any user's verification token event.
  Returns [Token Object](/docs/references/cloud/models/token)

---

- `users.*.verification.*.create`
- This event triggers when a verification token for a user is created.
  Returns [Token Object](/docs/references/cloud/models/token)

---

- `users.*.verification.*.update`
- This event triggers when a verification token for a user is validated.
  Returns [Token Object](/docs/references/cloud/models/token)

{% /table %}
