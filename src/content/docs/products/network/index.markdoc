---
layout: article
title: Network
description: Discover Appwrite's network architecture with global regions, edge nodes, and optimized routing. Explore how it ensures low latency, reliable performance, and scalable infrastructure for modern applications.
---

Appwrite's network is designed to deliver low-latency, high-performance experiences for developers and end-users alike. It leverages a robust Content Delivery Network (CDN) with edge locations across multiple regions to ensure fast and reliable data delivery.

With distributed infrastructure and multiple deployment regions, Appwrite enables developers to build globally scalable applications while maintaining data sovereignty. Its architecture integrates seamlessly with APIs, storage, and databases, optimizing both speed and availability.

{% only_dark %}
![Network map](/images/docs/network/dark/all-maps.avif)
{% /only_dark %}
{% only_light %}
![Network map](/images/docs/network/all-maps.avif)
{% /only_light %}

# Components {% #components %}
The Appwrite Network is composed of multiple components that work together to deliver a seamless experience for developers and end-users. These components include:

{% cards %}
{% cards_item href="/docs/products/network/regions" title="Regions" %}
Multi-region deployments for geo-redundancy, compliance and low-latency.
{% /cards_item %}
{% cards_item href="/docs/products/network/edges" title="Edges" %}
Edge nodes for fast request processing and reduced round-trip times.
{% /cards_item %}
{% cards_item href="/docs/products/network/cdn" title="CDN" %}
Global infrastructure for optimized routing, enabling faster and more consistent data delivery.
{% /cards_item %}
{% cards_item href="/docs/products/network/endpoints" title="Endpoints" %}
Dedicated endpoints for region-specific, edge, and compute workloads.
{% /cards_item %}
{% /cards %}

## Region vs Edge {% #region-vs-edge %}

In Appwrite, Regions are where all your core data and services live. This includes your databases, auth, functions, messaging, and storage. Regions are the source of truth, handling heavy workloads and ensuring your application runs reliably while keeping your data compliant with local regulations.

Edges are about speed. They process requests closer to your users using smart geo-routing, reducing latency by handling compute tasks at the nearest edge location. Edges are perfect for serving cached content, executing lightweight computations, and optimizing user interactions.

{% info title="Where to execute?" %}
The Appwrite Network is designed for flexibility. You can choose to run your serverless compute workload in your project's home region by using the `<ID>.<REGION>.appwrite.run` endpoint, or on the edge using the `<ID>.appwrite.network` endpoint. Both your region and edge endpoints can be customized to use your own custom domain.
{% /info %}

# Architecture {% #architecture %}

Appwrite's network is designed to provide a balance between centralized compute and distributed delivery:
- Regions: Core infrastructure and data resides in global regions, where all services like databases, auth, functions, messaging, and storage operate. These regions ensure data sovereignty, compliance, and high availability for critical workloads.
- Edges: Distributed edge locations process requests closer to end-users, leveraging smart geo-routing to minimize latency. These edge handle tasks like caching, static content delivery, and lightweight compute to improve performance.
- Private Routing: Data moves between edge nodes and regions through optimized, low-latency connections to ensure fast and reliable communication.

This setup separates heavy backend processing in regions from latency-sensitive tasks at the edge, enabling efficient handling of global workloads with minimal performance trade-offs.

{% info title="Self-Hosting?" %}
Appwrite's self-hosted setup is designed and optimized for straightforward, single-region environments - making it an ideal choice for small to medium-scale workloads.
All Cloud users have access to the Appwrite Network. If you need to scale your self-hosted deployment or require additional solutions, please [contact us](/contact-us/enterprise).
{% /info %}

# Features {% #features %}


{% cards %}
{% cards_item href="/docs/products/network/dns" title="Domain Name System" %}
Provides dedicated nameservers and DNS management for apex domains with SSL certification.
{% /cards_item %}
{% cards_item href="/docs/products/network/ddos" title="DDoS mitigation" %}
Protects against distributed denial-of-service attacks, ensuring uninterrupted access.
{% /cards_item %}
{% cards_item href="/docs/products/network/tls" title="Transport Layer Security (TLS)" %}
Encrypts data in transit for secure and private communication.
{% /cards_item %}
{% cards_item href="/docs/products/network/waf" title="Web Application Firewall (WAF)" %}
Shields applications from common web vulnerabilities and attacks on the application layer.
{% /cards_item %}
{% cards_item href="/docs/products/network/compression" title="Compression" %}
Reduces data size in transit to improve transfer speed and efficiency.
{% /cards_item %}
{% cards_item href="/docs/products/network/caching" title="Caching" %}
Stores frequently accessed data for faster retrieval and lower latency.
{% /cards_item %}
{% /cards %}