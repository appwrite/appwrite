---
layout: article
title: TLS
description: Appwrite helps keep the web secure by generating TLS (Transport Layer Security) certificates for all user and generated domains.
---

Appwrite generates TLS certificates to ensure your API traffic is appropriately encrypted. The certificate authority used depends on your deployment type:

- **Self-hosted deployments** use [Let's Encrypt](https://letsencrypt.org/), an open source and not-for-profit certificate authority provided by the Internet Security Research Group (ISRG) that secures more than 363 million websites.
- **Appwrite Cloud** uses [Certainly](https://docs.fastly.com/products/certainly), Fastly's certificate authority, for Sites and Functions.

TLS certificates are generated for all of the following.
- Appwrite products and endpoints, like Databases, Storage, Authentication, Functions, Messaging, and all other endpoints.
- [Custom domains](/docs/products/network/custom-domains) that you configure for your Appwrite projects.
- [Domains for Appwrite Functions](/docs/products/functions/domains), generated or user provided.
- [Domains for Appwrite Sites](/docs/products/sites/domains), generated or user provided.

TLS certificates are crucial to ensure all connections between your apps and Appwrite Cloud are encrypted.
This protects your users from attack vectors like man-in-the-middle and eavesdropping attacks.

# CAA records {% #caa-records %}

If your domain has restrictive [CAA records](/docs/products/network/caa-records) in DNS, you must authorize the certificate authority Appwrite uses before a certificate can be issued. On Appwrite Cloud, add `certainly.com` to your CAA policy. Domains without any CAA records do not require this step.

[Learn more about CAA records >](/docs/products/network/caa-records)
