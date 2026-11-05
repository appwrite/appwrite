---
layout: post
title: February 2024 - Incident Report
cover: /images/blog/privacy.avif
description: In this post, we detail the incidents encountered in February 2024, including high memory usage, database restarts, repeated gateway timeouts, and executor crashes. We share our analysis, user impact, and the resolution steps taken to mitigate these issues, reinforcing our commitment to transparency and continuous improvement.
date: 2024-03-15
timeToRead: 3
author: christy-jacob
category: cloud
featured: false
faqs:
  - question: "What is an incident report?"
    answer: "An incident report is a public write up of an outage or degradation, what caused it, who was affected, and how it was resolved. Mature cloud platforms publish them to keep customers informed and to document the lessons learned."
  - question: "Why does Appwrite publish monthly incident reports?"
    answer: "Transparency builds trust with developers who run production workloads on Appwrite Cloud. Sharing incidents, root causes, and mitigations gives customers the information they need to plan around any disruptions and to evaluate Appwrite's reliability over time."
  - question: "How can I check the current status of Appwrite Cloud?"
    answer: "Real time uptime and active incidents are published on the Appwrite status page. Each incident is updated as engineers investigate, mitigate, and resolve it."
  - question: "What is a gateway timeout and why does it happen?"
    answer: "A gateway timeout means a request reached the load balancer but the upstream service did not respond in time. It is usually caused by routing issues, an overloaded service, or a slow database query, not by the load balancer itself."
  - question: "How does Appwrite reduce the chance of similar incidents?"
    answer: "Each incident is followed by a postmortem that produces concrete action items, for example reverting a problematic config change, tightening alerts, or hardening deployment automation. Those items are tracked through to completion."
  - question: "Does Appwrite Cloud offer a Service Level Agreement (SLA)?"
    answer: "Appwrite Cloud offers SLAs on paid plans. The Cloud pricing page lists the current uptime commitments and how credits are handled if those targets are missed."
---

As Appwrite Cloud continues to grow and scale, it is inevitable that we face challenges and obstacles that test the resilience of our systems and our team's ability to respond swiftly. February 2024 was no exception, as we encountered a series of incidents that put our protocols to the test. While these moments are opportunities for growth, they also remind us of the importance of transparency and communication with our community. This blog post aims to provide a detailed overview of the incidents we experienced throughout the month, including the causes, our responses, and the lessons learned. Our commitment to continuous improvement drives us to share these insights openly, reinforcing our dedication to reliability and trust.

## TLDR; for February 2024
| Date       | Title                                              | Duration         |
|------------|----------------------------------------------------|------------------|
| 2024-02-01 | High Memory Usage During Scheduled Backups         | 4 hours, 6 mins  |
| 2024-02-12 | Database Restart Incident                          | 1 hour, 34 mins  |
| 2024-02-13 | Gateway Timeouts in Traefik Containers             | 15 mins          |
| 2024-02-14 | Gateway Timeouts in Traefik Containers             | 8 mins           |
| 2024-02-15 | Gateway Timeouts in Traefik Containers             | 8 mins           |
| 2024-02-19 | Executor Crash and Bad Webhook                     | 27 mins          |
| 2024-02-21 | Gateway Timeouts in Traefik Containers             | 25 mins          |
| 2024-02-24 | Gateway Timeouts in Traefik Containers             | 30 mins          |
| **Total**  |                                                    | **7 hours, 33 mins** |


#### High Memory Usage During Scheduled Backups
- **Date:** February 1, 2024
- **Duration:** 4 hours and 6 minutes
- **Summary:** High memory usage in a database cluster caused widespread impact on cloud components.
- **Description:** A high memory usage alert was triggered for one of the database clusters, leading to unresponsive behavior that impacted critical cloud components, including Databases, API, Functions, etc. The root cause was identified as a high memory load during scheduled backups, affecting API performance for all users on the cloud.
- **User Impact:** Projects on the affected database were impacted, and partial API performance degradation during the initial phases.
- **Resolution and Steps Taken:** The problematic database was temporarily disconnected from the connection string, allowing for a necessary restart. After addressing the high memory usage, the database was reconnected, mitigating the issue.

#### Database Restart Incident
- **Date:** February 12, 2024
- **Duration:** 1 hour and 34 minutes
- **Summary:** Manual restart of a database affected project APIs.
- **Description:** An unexpected manual restart of the `database_db_fra1_self_hosted_2_0` database was triggered by an engineer, impacting all projects associated with this database. The root cause was identified as high memory usage, compounded by a scheduling error due to time zone confusion.
- **User Impact:** Projects on the affected database experienced downtime and service disruption.
- **Resolution and Steps Taken:** Transparent communication was maintained with affected users throughout the incident. Post-incident, measures were implemented to ensure such time zone errors are avoided in the future.

#### Repeated Gateway Timeouts in Traefik Containers
- **Dates:** February 13, 14, 15, 21, & 24
- **Duration:** Varied; a total of 86 minutes across all incidents
- **Summary:** Multiple incidents of gateway timeouts following cloud deployments.
- **Description:** Routine cloud deployments triggered gateway timeout errors due to a specific configuration introduced to Traefik (`--serversTransport.maxIdleConnsPerHost=-1`), affecting routing post-deployment. This issue was consistent across several incidents, impacting users' ability to access Appwrite Cloud through the API and console.
- **User Impact:** Sporadic gateway timeout errors on the Appwrite API and console, affecting user access to Appwrite Cloud.
- **Resolution and Steps Taken:** Immediate action involved restarting the Appwrite service to force correct container routing. The root cause was addressed by reverting the Traefik configuration change on February 25, 2024, which resolved the gateway timeout errors.

#### Executor Crash and Bad Webhook
- **Date:** February 19, 2024
- **Duration:** 27 minutes
- **Summary:** A crash in the functions executor combined with a faulty webhook led to increased API latency and failures.
- **Description:** The system experienced a crash in one of its executors, triggered by a faulty webhook. This incident was compounded by a high load on the function executions, triggering multiple webhooks simultaneously, which blocked API workers ultimately leading to increased latency and monitors being triggered.
- **User Impact:** Users experienced API timeouts and failed function executions, leading to service disruption.
- **Resolution and Steps Taken:** Immediate actions included restarting the API and executor to recover from the crash. The problematic webhook was removed, and steps were taken to restart the webhook worker, ensuring pending jobs could continue to execute.
