---
layout: article
title: Logs
description: Learn how Appwrite Sites handles logs
---

Each time a URL path on an Appwrite Site is requested, a log is created. Each log has a unique ID. You can find site logs logged in the **Logs** tab.

# Logs table

In your site's **Logs** tab, you will see a table of your recent logs. The following information is shown in this table:

| Column | Description |
| --- | --- |
| Log ID | Unique identifier for each log |
| Status code | The HTTP status of the request |
| Created | Timestamp of when the log was created |
| Method | The HTTP method used to create the request |
| Path | The URL path the request was made to |
| Duration | The time taken for the request |

# Log details

When you click on a log, you will be shown a set of log details.

{% only_dark %}
![Log details](/images/docs/sites/dark/log-details.avif)
{% /only_dark %}
{% only_light %}
![Log details](/images/docs/sites/log-details.avif)
{% /only_light %}

You can find both request and response information, including parameters and headers.

{% info title="Response logs for SSR apps" %}

If your app uses **SSR hosting** on Appwrite Sites, you can also observe `console.log` and `console.error` outputs in the response logs.

{% /info %}

# Disable logs

You can optionally disable logging for your site, which will exclude `console.log` and `console.error` outputs from the response logs and make site responses slightly faster. Here are the steps to disable logs:

1. Navigate to your site on Appwrite Console.
2. Under the **Settings** tab, find the **Logging** section.
3. Disable logs and click on the **Update** button.

{% only_dark %}
![Logging settings](/images/docs/sites/dark/logging-settings.avif)
{% /only_dark %}
{% only_light %}
![Logging settings](/images/docs/sites/logging-settings.avif)
{% /only_light %}

# Log retention

Logs are not retained forever in order to be compliant with GDPR and other data privacy standards. Free plan organizations will retain logs for 24 hours, Pro plan organizations will retain logs for 7 days.

If you need longer log retention, you can log to an Appwrite table. Remember to configure proper permissions and implement Appwrite Functions or other scheduled tasks to expire and clean up logs.