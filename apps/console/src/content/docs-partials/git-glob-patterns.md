| Pattern | What it does                              | Example          | Matches                             |
| ------- | ----------------------------------------- | ---------------- | ----------------------------------- |
| `main`  | Matches exact text                        | `main`           | `main`                              |
| `*`     | Matches within one path segment           | `release/*`      | `release/1.0`                       |
| `**`    | Matches across nested path segments       | `preview/**`     | `preview/test`, `preview/team/test` |
| `?`     | Matches one character in one path segment | `v?.?`           | `v1.0`, `v2.5`                      |
| `[abc]` | Matches one character from a set          | `[Mm]ain`        | `main`, `Main`                      |
| `[a-z]` | Matches one character in a range          | `release/[0-9]*` | `release/1.0`                       |
| `\`     | Escapes a special character               | `file\?.txt`     | `file?.txt`                         |
| `!`     | Excludes matching branches or paths       | `!docs/**`       | Excludes changes under `docs/`      |
