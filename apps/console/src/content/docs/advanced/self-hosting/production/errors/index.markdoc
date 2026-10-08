---
layout: article
title: Error monitoring
description: Configure error reporting and monitoring for your self-hosted Appwrite instance in production.
---

By default, your Appwrite installation comes with error reporting turned off. You can [enable dev mode](/docs/advanced/self-hosting/production/debugging#development-mode) to get access to more verbose error logs and stack traces.

In production, it is highly recommended to turn error reporting off. To do so, make sure the Appwrite container environment variable `_APP_ENV` is set to `production` and not `development`.

To monitor errors in production, configure the `_APP_LOGGING_CONFIG` environment variable with your provider's DSN. The supported DSN formats are:

- Sentry: `sentry://PUBLIC_KEY@HOST:PORT/PROJECT_ID`
- LogOwl: `logowl://SERVICE_TICKET@SERVICE_HOST/`
- Raygun: `raygun://RAYGUN_API_KEY/`
- AppSignal: `appsignal://API_KEY/`