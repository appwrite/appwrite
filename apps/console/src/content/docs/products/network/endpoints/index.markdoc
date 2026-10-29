---
layout: article
title: Endpoints
description: Understand the differences between Appwrite's endpoints, including geo-balanced edges, region-specific services, and custom domains for compute processes.
---

Appwrite offers multiple endpoints to access its services, each designed to optimize specific aspects of performance, routing, and compute. Understanding these endpoints helps you determine the most efficient way to interact with your Appwrite project.

# Edge {% #edge %}

The **`appwrite.network`** domain provides geo-balanced endpoints that route traffic to the nearest edge node based on the user's geographic location.


The edge network endpoints are designed for:
- **Latency-sensitive operations**: Quickly serving cached content, routing requests, or performing lightweight edge computations.
- **Global traffic distribution**: Automatically balancing traffic across the edge network for consistent performance.

Example:
- `https://<ID>.appwrite.network`

Use this endpoint when optimizing for low-latency and global availability is critical for your functions.

# Region {% #region %}

The **`<REGION>.cloud.appwrite.io`** domain directs traffic specifically to the region hosting your Appwrite project's services. This endpoint ensures that requests are processed close to your core data and infrastructure, making it ideal for:

- **Data-intensive operations**: Frequent access to databases, storage, authentication, and other region-hosted services.
- **Regulatory compliance**: Ensuring data residency requirements are met by targeting specific regions.

Example:
- `https://fra.cloud.appwrite.io`
- `https://nyc.cloud.appwrite.io`

Use this endpoint when direct access to region-specific infrastructure is required, this is the endpoint you will use to access your Appwrite API or if you want to execute functions directly from your Appwrite SDK.

# Compute {% #compute %}

The **`<ID>.<REGION>.appwrite.run`** domain is designed for running server-side functions and compute-heavy tasks directly in the region where your services are hosted. It supports custom domains for seamless integration into your workflows. The `appwrite.run` subdomains are auto-generated for each function you create. This endpoint is best suited for:

- **Compute-Intensive Tasks**: Executing server-side functions, handling APIs, or processing asynchronous jobs.
- **Custom Domain Support**: Enabling custom domains for specific function endpoints.

Examples:
- `https://fra.appwrite.run`
- `https://[custom-domain]`

Use this endpoint for scenarios where compute needs to happen close to your data or for deploying APIs under your own domain.

# Summary {% #summary %}

| Endpoint                             | Use Case                                             |
|--------------------------------------|------------------------------------------------------|
| `https://<ID>.appwrite.network`      | Geo-balanced edges for low-latency operations        |
| `https://<REGION>.cloud.appwrite.io` | Direct access to region services                     |
| `https://<ID>.<REGION>.appwrite.run` | Region-based compute and function execution          |

Knowing how these endpoints work helps you choose the right one for your needs, ensuring better performance and alignment with your application's compliance requirements.