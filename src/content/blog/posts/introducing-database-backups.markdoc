---
layout: post
title: Introducing Database Backups
description: A new feature that will help ensure your database's safety and security.
date: 2024-10-15
lastUpdated: 2026-05-22
cover: /images/blog/introducing-database-backups/cover.avif
timeToRead: 3
author: jake-barnby
category: product, announcement
featured: false
faqs:
  - question: "What are Appwrite Database Backups?"
    answer: "Database Backups capture point in time copies of your project's databases without taking the service offline. They are encrypted, stored in a separate region from your production data, and can be triggered manually or on a schedule. See [Appwrite Databases](/docs/products/databases) for setup."
  - question: "Are backups available on the Free plan?"
    answer: "Database Backups are available on all paid Cloud plans. The Pro plan includes a daily backup retained for 7 days, while the Enterprise plan supports custom schedules and retention windows."
  - question: "Do backups cause downtime?"
    answer: "No. Appwrite uses hot backups, which run in the background while your application keeps serving traffic. There is no interruption to reads, writes, or realtime subscriptions during the backup window."
  - question: "Where are backup files stored?"
    answer: "Backups are written to a geographically separate location from the primary data center. The remote destination is what makes them useful for disaster recovery, since a regional incident doesn't take both your data and its backup down at the same time."
  - question: "Can I trigger a manual backup before a risky deployment?"
    answer: "Yes. Manual backups can be started any time from the Appwrite Console alongside any policy schedules you have configured. Pre deployment snapshots are one of the most common reasons developers reach for manual backups."
  - question: "How do I restore from a backup?"
    answer: "Restores are initiated from the Appwrite Console on the backup you want to use. Restoring writes the backup data back into your project's database; check the Database Backups docs for the current options around in place restores versus restoring to a new database."
---

Database integrity is critical for any data heavy application. That's why we're happy to introduce Database Backups on Appwrite, a feature designed to give you full control over the safety and recoverability of your data without any downtime. This is also a crucial milestone for Appwrite Cloud to get closer to the much anticipated GA release.

Whether you're running production workloads or handling large datasets, Appwrite now makes it easier to back up your databases, securely and efficiently with just a few clicks on your [Appwrite Console](https://cloud.appwrite.io/).

**Database Backups are now available on Appwrite Cloud for all paid plans.**

# What are Appwrite Database Backups?

Appwrite Database Backups help you take a backup of your database with minimal impact to your production performance, ensuring your operations continue smoothly.

## Encryption

Security is a top priority, and Appwrite ensures that all database backups are fully encrypted both in transit and at rest. This encryption protects your sensitive information from unauthorized access or breaches. Even if a backup was intercepted or compromised, the encryption ensures that your data remains unreadable to unauthorized parties, providing an extra layer of security essential for meeting compliance requirements and ensuring data integrity.

Appwrite uses standard TLS communication to transmit all backups securely and ensures that the files are encrypted while at rest.

## Hot backups

All database backups are hot, meaning they are created without causing any downtime or interruptions to your services. This is especially critical for production environments where uptime and performance are key.

By utilizing hot backups, your application can continue to operate as usual. At the same time, your data is being securely copied in the background, ensuring that backups are taken without impacting user experience or slowing down your operations.

## Remote destination

Backups are stored in a geographically separate location, isolated from our primary data center in Frankfurt. This ensures full redundancy and strengthens your disaster recovery strategy. In the event of localized incidents (such as hardware failures, security breaches, or natural disasters), your data remains secure and recoverable, enabling rapid failover and minimizing operational downtime.

# Automating Backups with Policies

You can enable automated backup policies to run in the background. This will ensure you have a daily backup that is stored for 7 days as part of the Pro plan. For added flexibility, manual backups can be initiated at any time. This is particularly useful for pre-deployment snapshots or in cases where immediate backup and restoration are required, giving you complete control over your data integrity with minimal downtime.

## Enterprise plan

We offer more customization for the [Enterprise plan](https://appwrite.io/pricing) using backup policies that offer granular control over your backup strategy, allowing you to:

- Schedule backups at custom intervals or predefined schedules (e.g., daily, weekly, monthly) to match your operational requirements.
- Set retention periods, ensuring backups are kept as long as necessary without accumulating unnecessary storage.
- Specify precise execution times to align with low-traffic periods or maintenance windows, seamlessly integrating into your workflow.

# Secure your databases

Database Backups is designed to offer comprehensive, secure, and automated backups for your project's Databases. With hot, encrypted backups, automated policies, and full control over your recovery process, it's an effective tool to safeguard your data. We're excited to see you use this new feature.

Do you have feedback? Let us know on [Discord](https://appwrite.io/discord). Visit our [documentation](https://appwrite.io/docs/products/databases/backups) to learn how to set up a backup policy.
