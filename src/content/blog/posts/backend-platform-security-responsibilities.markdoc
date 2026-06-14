---
layout: post
title: "Security responsibilities when using backend platforms"
description: Understanding the shared responsibility model for security when building on backend-as-a-service platforms, and what that means for your application.
date: 2026-03-20
cover: /images/blog/backend-platform-security-responsibilities/cover.avif
timeToRead: 6
author: aditya-oberai
category: security
featured: false
unlisted: true
faqs:
  - question: "What is the shared responsibility model for backend platforms?"
    answer: "It is a framework, originally formalized by cloud providers like AWS and Azure, that divides security duties between the platform and the customer. The platform secures underlying infrastructure (hardware, networking, hypervisors, managed service software), and you secure your application code, data, access policies, and platform configuration."
  - question: "What does a backend platform typically secure on my behalf?"
    answer: "A well-maintained platform usually handles physical and network security, platform-level patching of the OS and runtimes, encryption in transit and at rest, high availability and redundancy, and the internals of authentication services such as password hashing and session management. The exact line varies between IaaS, PaaS, and BaaS offerings."
  - question: "What security tasks remain my responsibility when using a BaaS?"
    answer: "Your application code, access control configuration, API key and secret management, the data you choose to send to the platform, third-party integrations, user account policies (such as MFA and password rules), and monitoring and incident response are all on you. The platform provides primitives, but applying them correctly is the developer's job."
  - question: "How does Appwrite handle authentication security at the platform layer?"
    answer: "Appwrite handles password hashing using Argon2, brute-force protection with configurable thresholds, secure session token management, and multi-factor authentication. These are implemented at the platform layer, so you do not need to build or audit them yourself."
  - question: "Why is open source useful for evaluating a backend platform's security?"
    answer: "Because Appwrite is open source, you can review every line of security-relevant code instead of trusting vendor claims. This is valuable for compliance reviews, internal audits, and any environment where you want to verify the implementation of authentication, permissions, or audit logging directly."
---

When you build on a backend platform (whether that's a cloud provider, a BaaS solution, or a managed database service) you're entering into what the industry calls a "shared responsibility model." The platform secures some things. You secure others. Understanding exactly where that boundary sits is one of the most important security decisions a development team makes.

Get it wrong in one direction and you'll over-invest in controls the platform already handles. Get it wrong in the other and you'll ship an application with critical security gaps you didn't know existed.

# What is the shared responsibility model?

The shared responsibility model is a framework, originally formalized by cloud providers like AWS and Azure, that divides security duties between the infrastructure provider and the customer. At a high level:

- **The platform is responsible for** securing the underlying infrastructure: physical hardware, network fabric, hypervisors, and the software that runs the managed services themselves.
- **You are responsible for** securing what runs on top of the platform: your application code, your data, your access policies, and how you configure the platform's services.

The line shifts depending on the service model. With IaaS (Infrastructure as a Service), you manage almost everything above the virtual machine. With PaaS (Platform as a Service) and SaaS-like BaaS platforms, the provider manages more, but you still own your application logic and data security.

# What backend platforms typically handle

When using a well-maintained backend platform, you can generally expect the provider to take responsibility for:

- **Physical and network security.** Data centers, network isolation, DDoS mitigation, and hardware security are the provider's domain.
- **Platform-level patching.** The underlying OS, database engine, and runtime environment are kept up to date by the platform.
- **Encryption in transit.** TLS termination and secure transport between services is typically handled out of the box.
- **Encryption at rest.** Most platforms encrypt stored data by default, though you should verify the encryption standard and key management approach.
- **High availability and redundancy.** Uptime SLAs, failover, and backup infrastructure are generally managed by the platform.
- **Authentication service security.** If the platform provides authentication, it should handle password hashing, brute-force protection, and secure session management internally.

# What you are responsible for

No matter how capable the platform, these responsibilities remain yours:

- **Application-level access control.** Defining who can access what in your application is your job. Platforms provide permission primitives (RBAC, resource-level policies, team scopes), but how you configure and apply them is entirely up to you.
- **Your own code security.** SQL injection, XSS, insecure deserialization, and other application-layer vulnerabilities are introduced by your code, not the platform's. Static analysis tools, code review practices, and secure coding standards are your responsibility.
- **API key and secret management.** Storing API keys securely, rotating them regularly, and ensuring they're never committed to version control is a developer responsibility regardless of which platform you're using.
- **Data you send to the platform.** The platform secures data at rest, but you control what data you send. Over-collecting sensitive data that doesn't need to be stored is a risk you create.
- **Third-party integrations.** When your application connects to external services, the security of those connections and the data shared across them is your responsibility.
- **User account policies.** Enforcing strong password policies, requiring multi-factor authentication, and managing account recovery flows is your team's decision, not the platform's default.
- **Monitoring and incident response.** Platform providers may offer logging and alerting tools, but configuring those tools and responding to alerts falls to your team.

# Common misconceptions that lead to security gaps

**"The platform handles security."** This is the most dangerous misunderstanding. The platform handles its security. Your application security is still entirely yours.

**"We don't need access control because the data isn't sensitive."** Access control is not only about protecting sensitive data; it's about ensuring application integrity. Unauthenticated users modifying or deleting data causes incidents regardless of whether that data is regulated.

**"Our platform is SOC 2 certified, so we're covered."** SOC 2 certification covers the platform's own controls. It does not certify the security of what you build on top of it.

**"We'll add security later."** Security controls are cheapest when built in. Authentication bypass bugs, insecure direct object references, and exposed admin endpoints discovered post-launch are expensive to fix and damaging to user trust.

# Choosing a platform with clear security documentation

When evaluating backend platforms, look for those that:

- Publish a clear shared responsibility documentation outlining what they handle versus what you handle
- Provide granular permission systems that let you enforce least-privilege access
- Offer audit logging so you can track who accessed what
- Are transparent about their own security certifications and third-party audits
- Support security best practices by default, like Argon2 password hashing, session expiration, and rate limiting on authentication endpoints

# Appwrite's security model

Appwrite is an open-source developer infrastructure platform for building web, mobile, and AI apps. It includes both a backend server, providing authentication, databases, file storage, serverless functions, real-time subscriptions, and messaging, and a fully integrated hosting solution for deploying static and server-side rendered frontends. Appwrite can be fully self-hosted on any Docker-compatible infrastructure or used as a managed service through [Appwrite Cloud](https://cloud.appwrite.io).

Within the shared responsibility model, Appwrite clearly defines what the platform handles versus what you handle:

- **Authentication security**: Appwrite handles password hashing using Argon2, brute-force protection with configurable thresholds, secure session token management, and multi-factor authentication, all implemented correctly at the platform layer so you don't have to.
- **Granular permissions**: Appwrite's permission system lets you define access at the database table, row, storage bucket, and individual file level, making it practical to enforce least-privilege access across your entire data layer.
- **Transparent codebase**: Because Appwrite is open source, you can review every line of security-relevant code. There is no need to trust vendor claims about what the platform does or doesn't do; you can verify the implementation directly.
- **Audit visibility**: Appwrite logs resource events with timestamps and user context, giving your team the trail needed for incident response and compliance reviews.

Your responsibilities remain: your application logic, your access control configuration, your API key management, and your input validation. But Appwrite handles the platform-layer security correctly so you can focus on what's uniquely yours.

# Apply your application security on top of a platform you can verify

No backend platform removes your responsibility for application security. But choosing a platform that handles its layer correctly, is transparent about what it does, and gives you the primitives to enforce least-privilege access makes your job significantly smaller. With Appwrite, you can verify every security-relevant decision in the open-source codebase rather than trusting vendor claims.

- [Appwrite Security overview](/docs/advanced/security)
- [Appwrite Authentication docs](/docs/products/auth)
- [Appwrite Databases permissions](/docs/products/databases/permissions)
- [Appwrite Audit Logs](/docs/advanced/security/audit-logs)
