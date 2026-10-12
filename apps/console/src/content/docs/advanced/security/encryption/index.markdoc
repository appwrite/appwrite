---
layout: article
title: Encryption
description: Learn about Appwrite's use of encryption across Appwrite's databases and storage buckets to protect user data.
---
Other than applying encryption in [authentication](/docs/products/auth/security), [enforcing HTTPS](/docs/advanced/security/https),
and [generating TLS certificate for domains](/docs/advanced/security/tls), Appwrite also uses encryption for Storage, and Databases to come.
Encryption helps secure your files and data in storage. In the event that an attack happens and a malicious actor gains access to files
or data, encrypted files and data cannot be deciphered, adding a further layer of protection.

## Storage {% #storage %}

For storage, buckets can have its files encrypted. If enabled, files uploaded to the bucket that are smaller than 20MB will be encrypted in the storage provider.
You can enable encryption by going to your bucket's **Settings** > **Security settings** > toggle **Encryption**.
Files are encrypted with AES-128 in Galois/Counter Mode (GCM).

## Databases {% #databases %}
Database columns support encryption for text columns (varchar, text, mediumtext, and longtext). This feature is available on Pro plans and higher. When creating a text column in the UI, encryption can be enabled using a toggle option.
Columns are encrypted with AES-128 in Galois/Counter Mode (GCM).

{% info title="Querying encrypted columns" %}
Note that encrypted columns cannot be queried.
{% /info %}
