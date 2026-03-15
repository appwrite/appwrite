Get a list of all the project's users. You can use the query params to filter your results.

You may filter on the following attributes: `name`, `email`, `phone`, `status`, `passwordUpdate`, `registration`, `emailVerification`, `phoneVerification`, `labels`, `accessedAt`.

To sort by last user activity, use:
- `Query::orderAsc("accessedAt")` – oldest activity first
- `Query::orderDesc("accessedAt")` – most recent activity first