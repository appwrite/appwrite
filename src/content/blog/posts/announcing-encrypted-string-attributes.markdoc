---
layout: post
title: "Announcing encrypted text column support: Built-in encryption for sensitive fields"
description: Easily encrypt sensitive text fields at rest, with no manual encryption logic.
date: 2025-07-10
lastUpdated: 2026-06-29
cover: /images/blog/announcing-encrypted-string-attributes/cover.avif
timeToRead: 5
author: jake-barnby
category: announcement
featured: false
faqs:
  - question: "What is encryption at rest and why does it matter?"
    answer: "Encryption at rest means data is encrypted on disk when stored, so an attacker who gains access to the underlying database files still can't read the raw values. It matters for fintech, healthcare, messaging, and any app handling personal or regulated data. It's a baseline requirement for many compliance frameworks."
  - question: "How do I encrypt a text column in Appwrite?"
    answer: "Mark the text column as encrypted when you create it in your [Appwrite Databases](/docs/products/databases) table. From that point, Appwrite encrypts the value before writing it to disk and decrypts it transparently when you read it. Your client code stays exactly the same."
  - question: "What encryption algorithm does Appwrite use for encrypted columns?"
    answer: "Appwrite uses AES-128 in Galois/Counter Mode (GCM), an industry-standard authenticated encryption algorithm. The encryption is applied server-side before the value reaches the database, and Appwrite manages keys for you. Deployments that require FIPS-compliant environments are also supported."
  - question: "Can I query or filter on encrypted text columns?"
    answer: "No, encrypted columns can't be queried or used in filters. Encryption is intentionally one-way for queries to prevent leaking sensitive information through search patterns. If you need to look up records by a sensitive value, store a separate hashed or tokenized column for lookups."
  - question: "Do I need to manage encryption keys myself?"
    answer: "No, [Appwrite](/docs/advanced/security) handles key management transparently. You don't generate, store, or rotate keys yourself, which removes a common source of implementation errors. The clients see encrypted fields as regular text values."
  - question: "Is encrypted text column support available on the Free plan?"
    answer: "Encrypted text columns are available on Appwrite Cloud's Pro and Enterprise plans, plus all self-hosted deployments. If you're on the Free plan, you'd need to upgrade or self-host to use this feature."
---
Appwrite is secure by default. We build every single product and feature with the highest regard for security.

With this in mind, we introduce **encrypted text column support** for Appwrite Databases, a new feature to enhance your databases’ security.

This critical addition lets you store sensitive data securely, encrypted at rest, directly within your databases.

# Built-in security, zero complexity

Previously, storing confidential or sensitive data required manual encryption and decryption. This not only increased complexity but also introduced potential security risks from implementation errors.

With encrypted text column support, Appwrite handles encryption transparently using industry-standard AES-GCM encryption. Your data remains secure, encrypted server-side, without additional effort or complex custom code. Additionally, Appwrite ensures compliance with common security standards, including support for environments requiring FIPS compliance.

# Serving security-focused industries

It is essential for creating secure apps in fintech, healthcare, messaging, and other sectors where security and compliance are paramount. It ensures data protection out of the box, freeing you from manually managing encryption keys. 

Key features entail:

- **Server-side encryption at rest**: Your data is encrypted on the server automatically.
- **Transparent encryption**: Clients see and interact with encrypted fields just like regular text values.
- **Zero-effort protection**: No need to implement manual encryption or key management.
- **Secure by design**: Fields can't be queried, ensuring sensitive data stays protected.

# Setting the security standard

Appwrite delivers comprehensive, secure, easy-to-use encryption that is seamlessly integrated into your workflow. When a text column is marked as encrypted, Appwrite applies AES-128 encryption in Galois/Counter Mode (GCM) before writing it to the database.

This new feature is available on Appwrite Cloud Pro and Enterprise plans, and self-hosted. Encrypted text column support significantly enhances your application's security posture, making Appwrite a trusted choice for handling sensitive data.

# More resources

- [Read the documentation to get started](/docs/advanced/security/encryption)
- [Learn more about Appwrite security](/docs/advanced/security)
- [Improve your Appwrite developer experience with dev keys](/blog/post/improve-devex-dev-keys)
