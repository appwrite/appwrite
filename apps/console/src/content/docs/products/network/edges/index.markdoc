---
layout: article
title: Edges
description: Learn about Appwrite edges, where lightweight compute tasks like caching, request routing, and content delivery are handled. Understand how edges enhance performance by bringing operations closer to end-users.
---

Appwrite edges are strategically distributed locations designed to process requests closer to your users. These edge nodes handle latency-sensitive operations, such as caching, routing, and quick computations, to deliver faster, more efficient interactions while reducing the load on your application's core infrastructure.

{% only_dark %}
![Edges map](/images/docs/network/dark/edges-map.avif)
{% /only_dark %}
{% only_light %}
![Edges map](/images/docs/network/edges-map.avif)
{% /only_light %}

{% info title="We're expanding!" %}
Currently, Appwrite's edge network includes 6 locations. We are actively working to expand the number of edge locations globally. New locations will be strategically prioritized to ensure the best possible global coverage and performance for all users.
{% /info %}

# List {% #list %}

Appwrite edges are globally distributed, ensuring low-latency interactions for users around the world. Here's a list of locations with active or upcoming edge support:

| Location            | Code{% width=120 %}   | Status {% width=120 %}         |
|---------------------|--------|-----------------|
| Frankfurt           | FRA    | Available       |
| New York            | NYC    | Available       |
| Sydney              | SYD    | Available       |
| San Francisco       | SFO    | Available       |
| Singapore           | SGP    | Available       |
| Toronto             | TOR    | Available       |
| Bangalore           | BLR    | 2025            |
| Amsterdam           | AMS    | 2025            |
| London              | LON    | 2025            |

Edges complement Appwrite regions, working together to provide fast, reliable, and scalable application performance.

# Routing {% #routing %}

Appwrite's edges handle geo-aware routing to balance traffic across the network efficiently. When a user makes a request, it is directed to the nearest available edge node based on their geographic location. This ensures optimal performance by minimizing latency and distributing traffic evenly across edge nodes.

Geo-aware routing helps handle high traffic loads by intelligently directing requests to the best-performing edge, reducing congestion and maintaining consistent response times. By leveraging this approach, Appwrite ensures that your application delivers fast and reliable experiences to users worldwide.

{% info title="Edge vs Region" %}
Use an edge when compute needs to happen close to your users, like serving static content, doing local computation or data processing. Use a region when compute needs to happen closer to your data, such as frequent access to your Appwrite database or storage.
{% /info %}

# Design {% #design %}

Edges are optimized to reduce latency and improve user experience by processing operations closer to the source of requests. Key aspects include:

- Geo-routing: Smart routing ensures user requests are served by the optimal edge node.
- Caching: Frequently accessed data is cached at edges to reduce round-trips to core regions.
- Lightweight compute: Handles quick, resource-efficient computations for real-time tasks.
- Optimization: The edge network planned locations are strategically designed with fewer, high-capacity edges to maximize cache efficiency and achieve higher cache-hit ratios.

Edges enhance Appwrite's ability to deliver fast, reliable experiences by optimizing interactions and reducing latency for end-users. Together with regions, they create a robust infrastructure designed for modern, globally distributed applications.

{% info title="Self-Hosting?" %}
Appwrite's self-hosted deployments operate in a single region by default. The geo-distributed edge network with its routing features is available to Cloud users. For edge infrastructure in self-hosted environments or multi-region setups, please [contact us](/contact-us/enterprise) to explore enterprise options.
{% /info %}