---
layout: integration
title: Logging with Raygun
description: Integrate Raygun, an application performane monitoring (APM) tool with Appwrite to diagnose and fix errors quicker.
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/logging-raygun/cover.avif
category: logging
product:
    avatar: '/images/integrations/avatars/raygun.avif'
    vendor: Raygun
    description: 'Raygun is an application performance monitoring (APM) and error tracking tool designed for software developers to identify and resolve issues in their applications quickly. It provides real-time insights into software performance, error diagnostics, and user experience, helping teams to detect, diagnose, and fix errors faster. With features like crash reporting, real user monitoring, and deployment tracking, Raygun helps ensure the stability and efficiency of applications.'
platform:
    - 'Self-hosted'
images:
    - /images/integrations/logging-raygun/cover.avif
    - /images/integrations/logging-raygun/api-key.avif
    - /images/integrations/logging-raygun/crash-reporting.avif
    - /images/integrations/logging-raygun/error.avif
---

Raygun is an application performance monitoring (APM) and error tracking tool designed for software developers to identify and resolve issues in their applications quickly. It provides real-time insights into software performance, error diagnostics, and user experience, helping teams to detect, diagnose, and fix errors faster. With features like crash reporting, real user monitoring, and deployment tracking, Raygun helps ensure the stability and efficiency of applications, making it an essential tool for maintaining high-quality software and delivering a seamless user experience.

# How does the integration work?

If you are using self-hosting Appwrite, you can use the Raygun provider for error-tracking and logging for your Appwrite instance.

# How to implement

To implement the Raygun provider for logging, there are several steps you must complete:

## Step 1: Sign up for Raygun

First, [sign up on Raygun](https://app.raygun.com/signup?product=cr) (pick **Crash Reporting** as the product you're most interested in) and create your first application. Head to **Application settings** from the left-side navbar and copy your **API Key**.

![API key](/images/integrations/logging-raygun/api-key.avif)

## Step 2: Add Raygun provider to your Appwrite instance

For this step, you must [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven't already.

The Appwrite logging configuration uses a DSN format that follows the pattern `raygun://API_KEY/`. Use your Raygun API key in the DSN.

Visit the `.env` file created for your Appwrite instance and update the following environment variable:

```bash
_APP_LOGGING_CONFIG=raygun://API_KEY/
```

{% info title="Using Appwrite < 1.5.6?" %}
For versions prior to 1.5.6, use the old syntax format:
```bash
_APP_LOGGING_PROVIDER=raygun
_APP_LOGGING_CONFIG=RAYGUN_API_KEY
```
{% /info %}

After that, run the following Docker Compose commands in your terminal to restart your Appwrite containers and verify if the changes have been successfully applied:

```bash
docker compose up -d --force-recreate
docker compose exec appwrite vars
```

## Step 3: Test the provider

Once the Raygun provider is configured, run the following command in your terminal:

```bash
docker compose exec appwrite ssl --domain="wrongdomain.com"
```

Doing so will show errors in your Raygun Crash Reporting dashboard.

![Raygun Crash Reporting](/images/integrations/logging-raygun/crash-reporting.avif)

You can also view the error summary for further details.

![Error](/images/integrations/logging-raygun/error.avif)

# Read more about Raygun and Appwrite

If you would like to learn more about Raygun and Appwrite, we have some resources that you should visit:

- [Sign up for Raygun](https://app.raygun.com/signup?product=cr)
- [Handling errors in Appwrite self-hosted instances](https://appwrite.io/docs/advanced/self-hosting/production#errors)