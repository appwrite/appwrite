---
layout: post
title: Announcing Appwrite 1.9.6 for self-hosted deployments
description: Appwrite 1.9.6 is a maintenance release that fixes the upgrade and migration issues reported after 1.9.5, along with healthcheck and installer reliability improvements.
cover: /images/blog/appwrite-1-9-6-self-hosted-release/cover.avif
date: 2026-07-23
timeToRead: 3
author: chirag-aggarwal
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is new in Appwrite 1.9.6 for self-hosted deployments?"
    answer: "Appwrite 1.9.6 fixes the upgrade and migration issues reported after 1.9.5. It repairs missing Git provider attributes on Functions and Sites automatically, makes migrations idempotent, fixes container and MongoDB healthchecks, and gives the installer more time to pull images and start services."
  - question: "Do I need to upgrade from 1.9.5 to 1.9.6?"
    answer: "If you hit migration errors or healthcheck failures while upgrading to 1.9.5, yes. 1.9.6 resolves those issues and repairs affected Functions and Sites automatically during migration."
  - question: "How do I upgrade my self-hosted instance to 1.9.6?"
    answer: "Back up your data, pull the 1.9.6 image, run the upgrade command, and then run the migration. See the [upgrade guide](/docs/advanced/self-hosting/production/updates) for the full steps."
  - question: "Does Appwrite 1.9.6 change any APIs?"
    answer: "No. 1.9.6 is a patch release on top of 1.9.5 with the same API surface, so no changes to your application code or SDKs are required."
---

**Appwrite 1.9.6** is now available for self-hosted deployments.

After the 1.9.5 release, many of you reported issues while upgrading, most of them related to migrations. Appwrite 1.9.6 fixes those issues.

Here is what changed.

# Migration fixes

The migration to 1.9.5 could fail or leave instances in an inconsistent state in a few scenarios. 1.9.6 addresses them:

- Missing `providerBranches` and `providerPaths` attributes on Functions and Sites are now repaired automatically during migration. Operators no longer need to apply metadata and table changes manually.
- Migrations are now idempotent. Attribute batches skip attributes that already exist, and the V25 migration skips collections that are absent, so re-running a migration after a partial failure works as expected.

# Healthcheck and installer reliability

- The Appwrite container healthcheck now uses the public version endpoint instead of a side-effecting call.
- MongoDB is only marked healthy once a writable replica-set primary is available, which prevents services from starting against a database that is not ready.
- The `doctor` task now reports database and queue connectivity correctly.
- The installer allows more time to pull images and start Compose services, which fixes timeouts on slower connections.

# Service updates

This release also updates the bundled services: the self-hosted Console moves to 8.7.30, the Open Runtimes Executor to 0.25.4, and the Browser service to 0.3.3.

# Upgrade your self-hosted instance

Back up your data first, then run the upgrade command:

```bash
docker run -it --rm \
    --publish 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="upgrade" \
    appwrite/appwrite:1.9.6
```

After the upgrade is complete, run the migration:

```sh
cd appwrite/
docker compose exec appwrite migrate
```

For the full walkthrough, see the [upgrade guide](/docs/advanced/self-hosting/production/updates).

# Self-host Appwrite 1.9.6

If you are setting up Appwrite for the first time, all you need is Docker installed. Run the installer with the 1.9.6 image, then open `http://localhost:20080` to complete the setup wizard.

```bash
docker run -it --rm \
    --publish 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.9.6
```

For system requirements and the full walkthrough, see the [installation guide](/docs/advanced/self-hosting/installation).

# More resources

- [Release notes for 1.9.6](https://github.com/appwrite/appwrite/releases/tag/1.9.6)
- [Upgrade your self-hosted instance](/docs/advanced/self-hosting/production/updates)
- [Self-hosting installation guide](/docs/advanced/self-hosting/installation)
- [Join the Appwrite Community on Discord](https://appwrite.io/discord)
