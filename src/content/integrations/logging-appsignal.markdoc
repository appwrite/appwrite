---
layout: integration
title: Logging with AppSignal
description: Monitor application health in real time, store logs and track issues on your Appwrite instance with the help of AppSignal intergration.
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/logging-appsignal/cover.avif
category: logging
product:
    avatar: '/images/integrations/avatars/appsignal.avif'
    vendor: AppSignal
    description: 'AppSignal is a comprehensive monitoring and error-tracking tool designed for developers to gain insights into the performance of their applications.'
platform:
    - 'Self-hosted'
images:
    - /images/integrations/logging-appsignal/cover.avif
    - /images/integrations/logging-appsignal/api-key.avif
    - /images/integrations/logging-appsignal/errors-list.avif
    - /images/integrations/logging-appsignal/error.avif
---

AppSignal is a comprehensive monitoring and error-tracking tool designed for developers to gain insights into the performance of their applications. It provides detailed metrics on application performance, error tracking, and server health, enabling developers to diagnose and resolve issues efficiently. AppSignal supports various programming languages and frameworks, offering seamless integration and an intuitive interface. It helps teams monitor application health in real time, receive alerts on critical issues, and analyze performance data to optimize their code and infrastructure, ultimately ensuring a smooth user experience and maintaining high application reliability.

# How does the integration work?

If you are using self-hosting Appwrite, you can use the AppSignal provider for error-tracking and logging for your Appwrite instance.

# How to implement

To implement the AppSignal provider for logging, there are several steps you must complete:

## Step 1: Sign up for AppSignal

First, [sign up on AppSignal](https://appsignal.com/users/sign_up) and create your first organization (or join an existing one). Head to the **App settings** from the left sidebar and click on **Push & deploy**. Copy the **app-specific Push API key** for further usage.

![API key](/images/integrations/logging-appsignal/api-key.avif)

## Step 2: Add AppSignal provider to your Appwrite instance

For this step, you must [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven't already.

The Appwrite logging configuration uses a DSN format that follows the pattern `appsignal://API_KEY/`. Use your AppSignal Push API key as the API_KEY in the DSN.

Visit the `.env` file created for your Appwrite instance and update the following environment variable:

```bash
_APP_LOGGING_CONFIG=appsignal://API_KEY/
```

{% info title="Using Appwrite < 1.5.6?" %}
For versions prior to 1.5.6, use the old syntax format:
```bash
_APP_LOGGING_PROVIDER=appSignal
_APP_LOGGING_CONFIG=APPSIGNAL_API_KEY
```
{% /info %}

After that, run the following Docker Compose commands in your terminal to restart your Appwrite containers and verify if the changes have been successfully applied:

```bash
docker compose up -d --force-recreate
docker compose exec appwrite vars
```

## Step 3: Test the provider

Once the AppSignal provider is configured, run the following command in your terminal:

```bash
docker compose exec appwrite ssl --domain="wrongdomain.com"
```

Doing so will show errors in your AppSignal **Errors** page.

![Errors list](/images/integrations/logging-appsignal/errors-list.avif)

You can also view the error summary for further details.

![Error](/images/integrations/logging-appsignal/error.avif)

# Read more about AppSignal and Appwrite

If you would like to learn more about AppSignal and Appwrite, we have some resources that you should visit:

- [Sign up for AppSignal](https://appsignal.com/users/sign_up)
- [Handling errors in Appwrite self-hosted instances](https://appwrite.io/docs/advanced/self-hosting/production#errors)