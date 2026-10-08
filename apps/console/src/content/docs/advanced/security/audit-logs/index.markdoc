---
layout: article
title: Audit logs
description: Appwrite provides audit logs to help detect anomalies and investigate security incidents.
---

All Appwrite products, like Authentication, Databases, Storage, Functions, and Messaging, provide detailed audit logs.
Audit logs are important in detecting and responding to security incidents.
Through audit logs, you can detect incidents through anomalous activities,
trace the source of security incidents, and understand the scope of users affected so you can respond more quickly
and effectively.

# Access audit logs {% #access-audit-logs %}

You can access audit logs for different products under the **Activity** tab where applicable.
Logs are available for tables, rows, and individual users.

{% only_dark %}
![Project settings screen](/images/docs/advanced/security/dark/activity.avif)
{% /only_dark %}

{% only_light %}
![Project settings screen](/images/docs/advanced/security/activity.avif)
{% /only_light %}

# Format {% #format %}

Audit log entries under the **Activity** tab has the following structure.
Each entry describes an event.

{% table %}
- Column
- Description
---
- User
- Name of the user that performed the event.
---
- Event
- The name of the [event](/docs/apis/events).
---
- Location
- The physical of the user when they performed the action.
---
- IP
- The IP of the user when they performed an action.
---
- Date
- The date and time of the event.
{% /table %}

# Retention {% #retention %}

GDPR data retention rules require any personal data that is collected or processed to be kept
only for as long as data are required to achieve the purpose for which the information was collected.
For this reason, audit logs are retained for only 7 days for Pro organizations and 1 hour
for Free organizations.
