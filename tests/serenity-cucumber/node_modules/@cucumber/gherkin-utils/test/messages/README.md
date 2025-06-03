# What's this?

Drop message ndjson files in this directory (with `.ndjson` extension). They
will be picked up by `../messageTests.tsx` which will try to walk through the
GherkinDocument messages it contains .

This is useful whenever we come across a message stream that causes a runtime error
in HTML Reporter or any other tool depending on `@cucumber/gherkin-utils` so we can diagnose
and fix any issues.

Sometimes we will get message files from users that might be useful for reproducing a bug.
Put these in `./production` - they will not be added to git.
