---
layout: post
title: "Introducing Imagine: from ideas to real products"
description: We're introducing Imagine, a platform that uses AI to translate ideas into real, production-ready applications, backed by Appwrite Cloud.
date: 2025-12-16
cover: /images/blog/introducing-imagine/cover.avif
timeToRead: 5
author:
  - eldad-fux
  - ariel-weinberger
category: announcement
faqs:
  - question: "What is Imagine?"
    answer: "Imagine is an AI powered platform from the Appwrite team that turns natural language prompts into real, production ready applications backed by Appwrite Cloud. Unlike disposable prototype tools, the output is a full project with database schemas, auth, storage, and deployable code."
  - question: "How is Imagine different from other AI app builders?"
    answer: "Imagine is built and operated by the team behind Appwrite, so it has a first party understanding of Appwrite's data model, permissions, and runtime. Instead of generating throwaway code, it provisions real Appwrite Cloud resources you can extend manually as the product evolves."
  - question: "What can I build with Imagine today?"
    answer: "You can build internal tools, dashboards, web applications, and marketing sites. Mobile applications aren't supported yet, but the team is actively working on adding that."
  - question: "What backend services does Imagine wire up automatically?"
    answer: "Every Imagine project ships with [Appwrite Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), realtime subscriptions, and [Sites](/docs/products/sites) hosting. You don't have to provision any of it separately."
  - question: "Can I edit the code Imagine generates?"
    answer: "Yes. There is a built in code editor, and all output is transparent and readable. You can inspect, modify, and take full ownership of the project as it grows beyond what the AI builder produces."
  - question: "What frontend stack does Imagine use?"
    answer: "Imagine uses [TanStack Start](https://tanstack.com/) for the frontend. The patterns it chooses (typed routing, explicit data fetching) make the generated code easier to read and extend for both humans and AI agents."
---

When we started Appwrite, our mission was clear: reduce the operational and infrastructural burden of building software so developers could focus on what actually matters - product, logic, and user experience. We set out to remove unnecessary complexity, give teams real control over their backend, and make production-grade infrastructure accessible without forcing developers to fight it. That principle has guided every major decision we have made.

Over the past few years, we have also watched a new wave of tools emerge. AI made software creation feel almost magical. You could describe an idea in plain language and get something that looked like an application within minutes. But in practice, most of these tools stopped at the surface. They produced shallow prototypes, tightly constrained demos, or code that could not realistically be deployed, scaled, or maintained. The magic was real, but it ended too early.

Today, we are taking the next step in addressing that gap.

We are introducing [Imagine](https://imagine.dev), a platform that uses AI to translate ideas into real, production-ready applications, backed by Appwrite Cloud. Imagine does not generate isolated code snippets or disposable demos. It produces applications that are wired to real services - authentication, databases, storage, and functions - and designed to evolve beyond the first prompt. The goal is not to abstract infrastructure away entirely, but to remove it as a constant obstacle in the earliest and most fragile stages of building.

{% youtube src="https://www.youtube-nocookie.com/embed/xXYCVfA8joM" thumbnail="/images/blog/introducing-imagine/imagine-1.avif" /%}

Imagine is built on a simple observation: most ideas never fail because they were engineered poorly. They fail because they never make it far enough to be tested, shared, or used. The gap is not between "AI-generated" and "expert-built" software. The gap is between something that exists and something that never gets created at all.

Strong engineering remains rare and deeply valuable. That does not change. What does change is how quickly ideas can move from concept to something tangible. When experienced builders use AI on top of solid infrastructure, they move faster and explore more. When less technical creators have access to the same foundations, more ideas get a chance to exist in the first place.

This is the direction we are taking with [Imagine](https://imagine.dev). Not to lower the bar for production software, but to lower the cost of getting started, communicating intent, and turning an idea into a system that can grow.

# What is Imagine, and how it connects to Appwrite

Imagine is an agentic application-building platform designed and operated by the same team that builds Appwrite. It is not layered on top of the platform, and it is not an external system stitched together through adapters. Imagine is natively integrated into Appwrite’s developer platform and operates within a controlled environment defined by Appwrite’s primitives, APIs, and operational guarantees.

Every part of what Imagine produces - frontend structure, backend services, infrastructure configuration, and deployment - is generated with a first-party understanding of Appwrite’s data models, permission system, and execution constraints. Imagine is trained on Appwrite’s patterns and best practices, and it generates applications that align with how Appwrite Cloud is actually meant to be used in production.

Because of this, there is no glue code, no schema translation layer, and no brittle dependency chain between systems. When Imagine creates an application, it provisions real Appwrite Cloud resources: databases and tables, columns and indexes, functions, authentication settings, storage buckets, permissions, and deployment targets. The result is a real Appwrite project, not an abstraction that has to be reconciled later.

![Imagine](/images/blog/introducing-imagine/imagine-2.avif)

All generated code is transparent and approachable. You can inspect it, extend it, and incrementally take more control as your application evolves. As Imagine matures, we are working toward making this transition even smoother, but from day one the system is designed to grow alongside real development workflows rather than block them.

The frontend is built on top of [TanStack Start](https://tanstack.com/). This is a deliberate choice that supports the controlled environment approach. By relying on proven, explicit patterns for state management, data fetching, and routing, Imagine can generate applications that are predictable, composable, and easier to reason about, both for AI and for humans. Combined with Appwrite Cloud handling identity, data, storage, and execution behind the scenes, this allows Imagine to focus on assembling real systems rather than improvising fragile solutions.

This tight integration is intentional. By building both the platform and the system that generates against it, we can optimize for coherence, reliability, and long-term product evolution. That is what allows Imagine to support real products, not just early-stage demos.

With Imagine, you can build anything from internal tools and dashboards to full web applications and marketing sites. Mobile support is not available yet, but it is actively being worked on and will be added.

# What you can build with Imagine

Imagine is not a single feature or workflow. It is a complete environment for turning an idea into a running application, with the core building blocks already in place. Instead of assembling tools, services, and infrastructure piece by piece, Imagine provides a coherent set of capabilities that work together from the first prompt through deployment and iteration.

Below is an overview of the core capabilities available in Imagine today.

## AI application builder

The AI builder lets you describe an application using natural language or by uploading visual references. From that input, Imagine generates a complete frontend with real, readable code. You can iterate conversationally, refine behavior, and adjust structure as the application takes shape.

Behind the scenes, Imagine is powered by a system of specialized agents rather than a single model or long, simplistic and complex system prompt. Each agent focuses on a different part of the workflow, such as product structure, frontend architecture, backend modeling, data schemas, permissions, and infrastructure. Together, they simulate the way a strong product team breaks down and builds an application.

The agents operate in a coordinated, controlled workflow where outputs are validated and refined before being assembled. The system is designed to detect inconsistencies, correct mistakes, and recover from failed attempts without requiring a full restart. In internal testing, this approach has shown promising results across both simple projects and more complex applications with multiple dependencies.

## Built-in code editor

A built-in code editor is included to give you direct visibility into the output. You can inspect what is generated, make manual changes, and debug issues without leaving the environment. The goal is not to hide the code, but to make it easier to reach a useful starting point.

## Built-in cloud backend

Every Imagine project runs on Appwrite Cloud by default. There is no separate provisioning step and no need to wire external services together.

You get access to:

- Authentication, including OAuth, email and password, anonymous users, JWT, and more
- Databases with built-in permission models
- File storage with previews and transformations
- Serverless Functions with logs and debugging tools (coming soon)
- Site hosting backed by a global CDN
- Messaging and notifications (coming soon)
- Realtime subscriptions for database and system events

Because Imagine is built directly on Appwrite, the backend is not just an add-on or a generated afterthought. It is the foundation the application is built on.

## SEO-ready by default

Applications generated with Imagine include core SEO fundamentals out of the box. This includes static optimization, metadata handling, structured data, sitemap generation, and search-engine-friendly rendering.

These capabilities are built in, not layered on through plugins or third-party services, so teams can ship with sensible defaults from the start.

## Managed infrastructure, production by design

Every application inherits Appwrite Cloud’s global infrastructure, high-availability architecture, and low-latency routing.

Scaling, uptime, backups, and performance tuning are handled at the platform level. This allows teams to focus on application behavior and user experience without needing to operate or tune infrastructure early on. Appwrite Cloud auto-scale for you.

## Security as a baseline

Security is treated as a default property of every generated application, not something added later, same as the approach Appwrite is known for, in Imagine all settings are tuned to be secure by default and as a last minute requirment before or after going to production.

Imagine applications benefit from:

- Hardened authentication flows
- DDoS protection
- Abuse and anomaly detection
- Built-in encryption
- Data migration tooling
- Simple configuration controls for advanced policies in Imagine Studio

These protections are applied consistently using the same security posture Appwrite Cloud applies across all projects.

## Compliance without additional overhead

Applications built with Imagine inherit Appwrite’s compliance readiness, including GDPR, HIPAA, SOC-2, and CCPA.

This reduces the need for bespoke audits, custom compliance setups, or additional third-party services during early and growth stages.

# How we think about pricing

Imagine’s pricing is built around a simple principle: you should pay in proportion to the actual work required to turn an idea into a real product. Instead of pricing based on abstract tiers or feature gates, Imagine uses credits to represent the relative complexity of what the agent is asked to generate.

A simple task, such as creating a static landing page or a basic CRUD interface, consumes fewer credits. More complex requests, such as generating an application with multiple data models, permission rules, background functions, and integrations, require more planning, validation, and iteration by the agent and therefore consume more credits. This approach allows pricing to scale naturally with the depth of what you are building.

The free tier is designed to be genuinely useful. It includes enough daily credits to explore Imagine, test ideas, and experiment with real applications at an occasional scale. It is intended to let people understand what Imagine can do without pressure, rather than acting as a limited demo.

Every Imagine account also includes a generous allocation of cloud resources by default. These resources are sized to comfortably support small to medium-scale websites and applications, covering hosting, storage, bandwidth, and core backend services without requiring additional configuration.

For teams running production workloads, the Pro plan allows applications to grow beyond included limits using a pay-per-usage model, similar to how Appwrite Cloud operates at scale. This ensures that applications can expand smoothly as usage increases, without forcing premature upgrades or hard caps.

For organizations with more advanced requirements, higher scale, or specific commercial or security needs, our team works directly with customers to provide tailored plans and configurations. If you need custom adjustments or are planning to operate at significant scale, we encourage you to [contact our team to discuss the most appropriate setup](https://imagine.dev/enterprise).

![Imagine](/images/blog/introducing-imagine/imagine-3.avif)

# **Get started**

The results blew us away in early tests, and we can’t wait for you to test it. Projects that took three months to build were done in just a few minutes. This is not a vibe coding tool or a simple landing page generator. Imagine building full platforms with everything that Appwrite has created and refined in collaboration with the open-source community over the years.

This is the start of a new chapter for Appwrite.

We urge you not to take our word for it. [Sign up](https://imagine.dev/) and be one of the first to try out Imagine and see what it can do, and as always, help us spread the word.

[**Try it. Break it. Build something real with it.**](https://imagine.dev/)