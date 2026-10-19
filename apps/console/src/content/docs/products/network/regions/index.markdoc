---
layout: article
title: Regions
description: Learn about Appwrite regions, where core services like databases, auth, functions, sites, and storage are hosted. Understand data sovereignty, fault isolation, and scalability for compliant, high-performance deployments
---

Appwrite regions are geographic locations where all your application's core infrastructure is deployed. Each region operates as an independent, highly available cluster, managing the storage, processing, and serving of your data and Appwrite services.

{% only_dark %}
![Regions map](/images/docs/network/dark/regions-map.avif)
{% /only_dark %}
{% only_light %}
![Regions map](/images/docs/network/regions-map.avif)
{% /only_light %}

# List {% #list %}

Appwrite is currently available in the following list of regions:

| Region {% width=120 %}             | Code{% width=120 %}   | Endpoint                                | Status{% width=120 %}          |
|---------------------|--------|-----------------------------------------|-----------------|
| Frankfurt           | FRA    | `https://fra.cloud.appwrite.io/v1`      | Available       |
| New York            | NYC    | `https://nyc.cloud.appwrite.io/v1`      | Available       |
| Sydney              | SYD    | `https://syd.cloud.appwrite.io/v1`      | Available       |
| San Francisco       | SFO    | `https://sfo.cloud.appwrite.io/v1`      | Available       |
| Singapore           | SGP    | `https://sgp.cloud.appwrite.io/v1`      | Available       |
| Toronto             | TOR    | `https://tor.cloud.appwrite.io/v1`      | Available       |
| Bangalore           | BLR    | `coming soon`                           | TBD         |
| Amsterdam           | AMS    | `coming soon`                           | TBD         |
| London              | LON    | `coming soon`                           | TBD         |

Regions are designed to be entirely independent unless explicitly connected, which provides control over data replication and compliance. We're constantly working to add new regions to our network to provide developers with more options for deploying their applications.

# Choosing a region {% #choosing-a-region %}

Selecting a region impacts both your application’s performance and its compliance with local regulations. Regions are isolated, so your data and services remain contained within the selected location. When choosing a region, consider proximity to your primary user base to reduce latency and improve response times. Additionally, ensure the region aligns with legal requirements for data residency and sovereignty specific to your application's domain.

{% info title="Region vs Edge" %}
Use a region when compute needs to happen close to your data, such as frequent access to your Appwrite database or storage. Use an edge when compute needs to happen closer to your users, like serving static content, performing local computations, or handling data processing at the edge.
{% /info %}


# Design {% #design %}

Regions are isolated environments designed for predictable performance and data security. Key aspects include:

- Data storage: All data remains within the region and adheres to local data residency laws.
- Fault isolation: Each region is self-contained, so failures in one region do not impact others.
- Scalability: Resources within a region scale dynamically to meet application demands.
- Networking: Regions connect via secure, low-latency private networks.
- High availability: Redundant power, networking, and hardware configurations in data centers.
- Cache efficiency: Fewer, data-dense regions boost hit probability, ensuring popular content is readily available.

Regions provide the foundation for running scalable, reliable applications with full control over data locality and compliance.
