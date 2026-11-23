---
layout: post
title: "Security update regarding the Axios npm incident"
description: Appwrite's production repositories, SDKs, and tooling chain were not impacted by the compromised Axios npm releases.
date: 2026-03-31
cover: /images/blog/security-update-regarding-the-axios-npm-incident/cover.avif
timeToRead: 3
author: eldad-fux
category: security
featured: false
faqs:
  - question: "Were Appwrite SDKs affected by the compromised Axios npm releases?"
    answer: "No. Appwrite's production repositories, SDKs, and tooling chain were reviewed and were not impacted. The JavaScript and TypeScript SDKs use the native `fetch` API rather than Axios, so the affected packages were not in the customer-facing dependency tree."
  - question: "Do I need to take emergency action as an Appwrite customer?"
    answer: "Based on Appwrite's internal assessment, no emergency action is required specifically for Appwrite services or SDK usage. You should still review your own application dependencies and pin Axios (and any other direct dependencies) to known-good versions in production."
  - question: "How do I protect my own projects from compromised npm packages?"
    answer: "Pin direct dependencies to specific versions, commit your lockfile, and use `npm ci` (or the equivalent for your package manager) in CI so installs resolve against the lockfile instead of pulling new versions through semver ranges. This dramatically reduces the chance of pulling a freshly published malicious release."
  - question: "What is a software supply chain attack?"
    answer: "It is when an attacker compromises a dependency, build system, or distribution channel that your project trusts, rather than attacking your application directly. The Axios incident was a classic example: a maintainer account was compromised and malicious versions were published to npm, so anything resolving to those versions would have pulled the payload."
  - question: "What changes has Appwrite made to its release process?"
    answer: "Appwrite added stronger lockfile handling for dependency reproducibility, updated SDK generation and release tooling so dependency changes are more visible in review, and enforced stricter install behavior in CI so the dependency tree stays aligned with the reviewed lockfile. These are preventive improvements rather than a response to a direct compromise."
  - question: "Why are lockfiles important during a supply chain incident?"
    answer: "Lockfiles record the exact versions and hashes of every package installed. With a committed lockfile and a strict installer, your build will reinstall those same versions instead of pulling newly published (possibly malicious) releases through semver-compatible ranges, which is exactly what got many projects compromised in past incidents."
---

We want to share a brief update regarding the recent Axios supply chain incident on npm, where malicious package versions were reportedly published after a maintainer account was compromised.

After reviewing Appwrite's production repositories, SDKs, and tooling chain, we can confirm that Appwrite's SDKs and tooling were not impacted by the compromised Axios releases.

Our JavaScript and TypeScript SDKs use native platform capabilities such as `fetch` rather than Axios, and our review did not identify exposure in the parts of our stack that ship to customers. Based on our internal assessment, no emergency customer action is required specifically for Appwrite services or Appwrite SDK usage.

With that said, incidents like this are a strong reminder of the risks involved in modern software supply chains. Even when a project is not directly affected, transitive dependencies and package resolution behavior can create avoidable exposure if dependency versions are left too open.

# What we recommend

We recommend that customers review their own JavaScript and TypeScript projects and make sure dependencies are pinned appropriately, especially in production environments. Version pinning and committed lockfiles reduce the chance of unintentionally pulling newly published malicious or compromised packages through semver-compatible ranges.

As part of our response, we have also introduced additional safeguards across our TypeScript-based SDK workflow:

- We have added stronger lockfile handling to improve dependency reproducibility.
- We have updated our SDK generation and release process to make dependency changes more visible during review.
- We are enforcing stricter install behavior in CI so dependency trees stay aligned with reviewed lockfiles instead of resolving new versions automatically.

These changes are not a response to a direct compromise in Appwrite, but an extra layer of protection to further harden our release process against future ecosystem incidents.

# Our assessment

Appwrite's SDKs and tooling chain were not impacted by the compromised Axios packages. We have still taken additional preventive steps to strengthen our dependency management and release pipeline, and we encourage all customers to do the same in their own projects.

We will continue monitoring the ecosystem and tightening safeguards where it makes sense.
