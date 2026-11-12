---
layout: article
title: AI suggestions
description: Use AI suggestions to automatically generate database schemas. Learn how to create tables with recommended columns and indexes based on your table name and context.
---
AI suggestions generate columns and indexes for your tables based on the table name, existing database structure, and optional context you provide.
This feature analyzes your database to recommend appropriate schema designs that follow best practices.

{% section #create-table step=1 title="Create table" %}
Navigate to **Databases** in the Appwrite Console, select your database, and click **Create table**.

Enter a descriptive table name. AI suggestions will use this name to generate relevant columns and indexes.
{% /section %}

{% section #enable-suggestions step=2 title="Enable AI suggestions" %}
In the table creation dialog, enable **AI suggestions**.

{% only_dark %}
![Enable AI suggestions](/images/docs/databases/dark/ai-suggestions-enable.avif)
{% /only_dark %}
{% only_light %}
![Enable AI suggestions](/images/docs/databases/ai-suggestions-enable.avif)
{% /only_light %}

Optionally, provide additional context about your use case to refine the suggestions. For example, if creating an `Orders` table, you might add context like "e-commerce orders with payment tracking."

Click **Generate suggestions** to analyze your table name and database structure.
{% /section %}

{% section #review-suggestions step=3 title="Review suggestions" %}
AI suggestions will generate recommended columns and indexes for your table.

{% only_dark %}
![Review AI suggestions](/images/docs/databases/dark/ai-suggestions-review.avif)
{% /only_dark %}
{% only_light %}
![Review AI suggestions](/images/docs/databases/ai-suggestions-review.avif)
{% /only_light %}

Review each suggested column:
- Column name and type
- Whether it's required or optional
- Default values
- Array configurations

Review suggested indexes to optimize query performance.
{% /section %}

{% section #apply-changes step=4 title="Apply or modify" %}
You can modify any suggestion before applying:
- Edit column names, types, or configurations
- Remove suggestions you don't need
- Add additional columns manually

Click **Apply** to apply your schema. The table will be created with your approved columns and indexes.

{% info title="Manual columns" %}
You can always add, modify, or remove [columns](/docs/products/databases/tables#columns) after table creation by navigating to your table's **Columns** tab.
{% /info %}
{% /section %}
