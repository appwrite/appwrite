---
layout: post
title: "Announcing Timestamp Overrides: Accurate timelines for production workloads"
description: Move historical data into Appwrite without losing context or disrupting chronological accuracy.
date: 2025-08-13
lastUpdated: 2026-06-29
cover: /images/blog/announcing-timestamp-overrides/cover.avif
timeToRead: 5
author: jake-barnby
category: announcement
featured: false
faqs:
  - question: "What are timestamp overrides in Appwrite Databases?"
    answer: "Timestamp overrides let you set `$createdAt` and `$updatedAt` manually when creating or importing rows instead of having Appwrite auto-stamp the current time. This is useful for migrating historical data, preserving original event order, and maintaining accurate audit trails."
  - question: "Why do I need to override timestamps in Appwrite?"
    answer: "When you import data from another system, automatic timestamps make every record look brand new, which breaks analytics, feeds, and user-facing timelines. Overrides keep the original event time so a user's join date or an order's placement time stays accurate after the migration."
  - question: "How do I set a custom $createdAt value in Appwrite?"
    answer: "Include `$createdAt` (and/or `$updatedAt`) as fields in your row payload when calling a privileged API. The value must be a valid ISO-8601 string like `2023-10-05T14:48:00Z`. Omit the fields and Appwrite stamps them automatically as usual."
  - question: "Can I set custom timestamps from a client SDK?"
    answer: "No. Timestamp overrides require an API key or Console access via the CSV Import wizard for security reasons. Allowing arbitrary client-set timestamps would let users forge audit trails, so the feature is restricted to privileged callers."
  - question: "What is ISO-8601 and why does Appwrite require it?"
    answer: "ISO-8601 is the international standard for date and time strings (for example `2024-05-21T10:30:00Z`). It is unambiguous, time-zone aware via the trailing `Z` or offset, and sorts correctly as a string. Appwrite validates this format on timestamp overrides and rejects values that do not parse cleanly."
  - question: "Are timestamp overrides available on self-hosted Appwrite?"
    answer: "Yes. The feature is available on Appwrite Cloud and ships to self-hosted in the next release. Once your server version supports it, you can use the same API key or CSV Import flow described in the [Appwrite Databases documentation](/docs/products/databases)."
---
In many data workflows, imported records automatically take on the time they are added to the new system. This works for some use cases, but when migrating data or integrating systems, preserving original timestamps becomes essential for accurate records and reporting. 

Whether it is maintaining accurate audit trails, reliable analytics, proper chronological order, or a customer’s “joined on” date, every detail matters.

That’s why we’re introducing **Timestamp Overrides**. A simple but powerful way to set `$createdAt` and `$updatedAt` manually when importing or creating rows, so your history stays accurate without extra steps. This means your data looks exactly how it should from day one, with no extra scripts or workarounds.

# Preserve timestamps without extra workarounds

Appwrite has always managed timestamps automatically, setting `$createdAt` and `$updatedAt` to the current time whenever a row is created or updated. That’s perfect for most workflows, like building new apps or adding fresh data, because it’s accurate, automatic, and requires zero extra effort.

Migrations and bulk imports, however, require a different approach. When you import existing data, automatic timestamps overwrite the original ones, making every record look new and breaking analytics, feeds, and user-facing timelines. 

With **Timestamp Overrides**, you can now set those fields yourself. Using an API key or through the CSV Import wizard, you can explicitly define `$createdAt` and `$updatedAt` values instead of having Appwrite set them automatically.  

Include them when you need to preserve history; skip them when you want Appwrite’s defaults. It’s a flexible control that fits both new and legacy workflows.

# Immediate benefits

This change isn’t just about convenience. It unlocks a few tangible benefits for real-world workflows:

- **Accurate history:** Timestamps match when events actually happened.
- **Easier migrations:** Move data without relying on custom fields or workarounds..
- **Correct user timelines:** "Joined on" or "Ordered on" dates stay true.
- **Seamless CSV imports:** Set `$createdAt` and `$updatedAt` directly from your CSV.

# What you need to know before using it

Before you go ahead and start using this feature, here are a few important points about how Timestamp Overrides work:

- **Privileged-only:** This feature requires an API key or Console access via CSV Import. It isn’t available through client-side SDK calls for security reasons.
- **Opt-in per row:** If you leave `$createdAt` or `$updatedAt` out of your payload, Appwrite will auto-stamp them as usual.
- **Validation:** Both fields must be valid ISO-8601 strings (e.g., `2023-10-05T14:48:00Z`). Invalid values are rejected.
- **Partial override:** You can set one field and let Appwrite handle the other; for example, preserve `$createdAt` but allow `$updatedAt` to update automatically.
- **SDK usage:** Include the fields inside the row data itself; no new API parameters or endpoints are required.

# Built for enterprise‑grade data integrity

Data integrity isn't optional for teams operating in regulated industries or managing production workloads. It’s a requirement. Timestamp Overrides make it possible to migrate or backfill data into Appwrite without losing the original event history, ensuring audit trails and compliance standards remain intact.

This addition makes it easier for enterprises to adopt Appwrite at scale with full confidence in their data integrity.

# Get started

Whether importing a few thousand records or years of historical data, this feature allows you to control timestamps when needed and let Appwrite handle them when you don’t. 

It’s available now on **Cloud**, and will be coming to **Self-Hosted** in the next release.

# More resources 
- [Read the documentation to learn more](/docs/products/databases/rows#custom-timestamps)
- [Announcing CSV Import: Bring in large datasets to Appwrite with ease](/blog/post/announcing-csv-imports)
- [Announcing Bulk API: Handle heavy data workloads with ease](/blog/post/announcing-bulk-api)
