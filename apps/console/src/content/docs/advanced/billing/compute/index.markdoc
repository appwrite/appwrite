---
layout: article
title: Compute
description: Learn about CPU and memory options for Appwrite Functions and Sites on Cloud, including separate build and runtime specifications and plan build timeouts.
---

On Appwrite Cloud, paid plans let you choose how much **CPU** and **memory** apply to **build** work and to **runtime** work. [Functions](/docs/products/functions) and [Sites](/docs/products/sites) each expose two settings: a **build specification** (install, compile, bundle, package) and a **runtime specification** (executions for functions; serving traffic and SSR for sites). You can pick different tiers for each phase so heavy builds do not force you to oversize steady execution, and vice versa.

These options help you tune performance and cost: for example, a large install or compiler step can use a higher build spec while a smaller spec covers routine invocations or traffic.

# Specifications {% #specifications %}

Appwrite Cloud offers the following specification tiers. The same tiers are available for **build** and **runtime** independently on each function or site:

| Memory | CPU cores | Hourly usage |
|--------|-----------|--------------|
| 512MB  | 0.5       | 0.25         |
| 512MB  | 1         | 0.5          |
| 1GB    | 1         | 1            |
| 2GB    | 2         | 4            |
| 4GB    | 2         | 8            |
| 4GB    | 4         | 16           |

{% info title="Note" %}
On Appwrite Cloud, **Pro** plan organizations can change build and runtime specifications from the default 512MB and 0.5 CPU. The Free plan uses the default. For custom compute options, contact our [sales team](https://appwrite.io/contact-us/enterprise).
{% /info %}

# Build timeouts {% #build-timeouts %}

On **Appwrite Cloud**, each function and site **deployment build** (install, compile, bundle, and package) must finish within a **maximum build duration** that depends on your organization plan:

| Plan | Maximum build duration |
| --- | --- |
| Free | 15 minutes |
| Pro / Scale | 45 minutes |
| Enterprise | Custom |

These limits apply to the **build** phase only. [Function execution timeout](/docs/products/functions/functions#timeout) and [site request timeout](/docs/products/sites/develop#timeouts) are separate settings. Compare plans on the [pricing page](/pricing).

On **self-hosted** instances, the global ceiling for configurable build timeouts is [`_APP_COMPUTE_BUILD_TIMEOUT`](/docs/advanced/self-hosting/configuration/environment-variables) (default 900 seconds). Individual function and site settings cannot exceed that server-wide maximum.

Configure specifications in the Appwrite Console under each function or site **Settings** - **Resource limits**. See also the [Functions](/docs/products/functions/functions#resource-limits) and [Sites](/docs/products/sites/develop#resource-limits) configuration guides.

# GB-Hours {% #gb-hours %}

GB-hours quantify compute use by combining memory (in GB) and duration (in hours). Both **build** activity and **execution** (function runs or site serving, depending on the product) draw from your plan's GB-hour pool according to the specifications in effect for that phase.

How it works:

- Memory allocation: The GB value comes from the spec in use for that phase (build or runtime).
- Duration: Time that phase is active (for example, build duration or execution time).
- Calculation: Memory (GB) multiplied by hours gives GB-hours for that usage.

**Example:**

If a function runs with 4 GB of memory for 2 hours of execution time:

**4GB * 2 hours = 8 GB-hours**

That counts toward your execution GB-hours for the billing period.

## Pricing {% #pricing %}

- The Free plan includes up to 100 GB-hours of execution and build time per month.

- The Pro plan includes up to 1,000 GB-hours of execution and build time per month. Additional usage is billed at $0.09 per GB-hour.

Once the monthly GB-hours limit is reached, additional usage is billed automatically. Set budget alerts and a budget cap to avoid unexpected charges.
