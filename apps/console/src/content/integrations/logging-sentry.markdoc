---
layout: integration
title: Logging with Sentry
description: Connect Sentry to your self-hosted Appwrite instance for improved error-tracking, logging, and overall reliability of your application.
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/logging-sentry/cover.avif
category: logging
product:
    avatar: '/images/integrations/avatars/sentry.avif'
    vendor: Sentry
    description: 'Sentry is an open-source error-tracking and performance-monitoring tool designed to help developers identify, diagnose, and fix issues in real-time.'
platform:
    - 'Self-hosted'
images:
    - /images/integrations/logging-sentry/cover.avif
    - /images/integrations/logging-sentry/project.avif
    - /images/integrations/logging-sentry/issues.avif
    - /images/integrations/logging-sentry/issue-details.avif
---

Sentry is an open-source error-tracking and performance-monitoring tool designed to help developers identify, diagnose, and fix issues in real-time. It provides comprehensive insights into application health by capturing and analyzing error events, performance metrics, and user feedback. Sentry supports multiple programming languages and frameworks, making it versatile for various development environments. With features like stack traces, context data, and alerting, Sentry allows teams to quickly pinpoint the root cause of issues and improve the overall quality and reliability of their software applications.

# How does the integration work?

If you are using self-hosting Appwrite, you can use the Sentry provider for error-tracking and logging for your Appwrite instance.

# How to implement

To implement the Sentry provider for logging, there are several steps you must complete:

## Step 1: Set up Sentry

First, [sign up on Sentry](https://sentry.io/signup/) and create a new project.

![Create project](/images/integrations/logging-sentry/project.avif)

Head to **Settings** > **Projects** > Select your Sentry project > **Client Keys (DSN)**. Save the DSN for further usage.

## Step 2: Add Sentry provider to your Appwrite instance

For this step, you must [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven't already.

The Appwrite logging configuration uses a DSN format that follows the pattern `sentry://PROJECT_ID:SENTRY_API_KEY@SENTRY_HOST/`. For example, if your Sentry DSN is `https://abcdef@sentry.example.com/1`, then the Appwrite logging configuration will be `sentry://1:abcdef@sentry.example.com/`.

Visit the `.env` file created for your Appwrite instance and update the following environment variable:

```bash
_APP_LOGGING_CONFIG=sentry://PROJECT_ID:SENTRY_API_KEY@SENTRY_HOST/
```

{% info title="Using Appwrite < 1.5.6?" %}
For versions prior to 1.5.6, use the old syntax format:
```bash
_APP_LOGGING_PROVIDER=sentry
_APP_LOGGING_CONFIG=SENTRY_PROJECT_ID;SENTRY_PUBLIC_KEY@SENTRY_HOST
```
{% /info %}

After that, run the following Docker Compose commands in your terminal to restart your Appwrite containers and verify if the changes have been successfully applied:

```bash
docker compose up -d --force-recreate
docker compose exec appwrite vars
```

## Step 3: Test the provider

Once the Sentry provider is configured, run the following command in your terminal:

```bash
docker compose exec appwrite ssl --domain="your-domain.com"
```

Doing so will show errors in your Sentry **Issues** page.

![Issues](/images/integrations/logging-sentry/issues.avif)

You can also view the error details for more information.

![Issue details](/images/integrations/logging-sentry/issue-details.avif)

# Read more about Sentry and Appwrite

If you would like to learn more about Sentry and Appwrite, we have some resources that you should visit:

- [Sign up for Sentry](https://sentry.io/signup/)
- [Handling errors in Appwrite self-hosted instances](https://appwrite.io/docs/advanced/self-hosting/production#errors)