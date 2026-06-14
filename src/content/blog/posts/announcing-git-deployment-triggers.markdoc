---
layout: post
title: "Announcing Git deployment triggers for Appwrite Functions and Sites"
description: Git deployment triggers let you control which branches and file changes create automatic deployments for Appwrite Functions and Sites.
date: 2026-05-29
cover: /images/blog/announcing-git-deployment-triggers/cover.avif
timeToRead: 4
author: harsh-mahajan
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What are Git deployment triggers in Appwrite?"
    answer: "Git deployment triggers let you control which branch pushes and file changes create automatic deployments for Appwrite Functions and Sites."
  - question: "Are Git deployment triggers only for preview deployments?"
    answer: "No. Git deployment triggers apply to automatic Git deployments for Functions and Sites. They can be used for production branches, staging branches, preview branches, and monorepo workflows."
  - question: "What do branch filters do?"
    answer: "Branch filters match Git branch names. Add patterns like `main`, `staging`, or `preview/**` to create deployments only from matching branches."
  - question: "What do path filters do?"
    answer: "Path filters match files changed in a commit or pull request. Leave the field empty to create deployments for all file changes, or add patterns like `apps/web/**` or `functions/api/**` to deploy only when matching files change."
  - question: "Can I exclude branches or paths?"
    answer: "Yes. Prefix a pattern with `!` to exclude matching branches or paths, such as `!docs/**` for documentation-only changes."
---

Git-based deployments make it easy to connect a repository and let Appwrite build from your pushes. That works well for small projects, but many teams quickly move into workflows with staging branches, preview branches, shared packages, and monorepos where a single repository powers several Functions and Sites.

In those setups, not every Git change should create every deployment. A docs-only change should not rebuild a production Site. A backend package change may need to rebuild an API Function, but not a marketing page. A team might want previews from the `preview/**` branch pattern, while keeping release deploys limited to `main`.

Today, we are announcing **Git deployment triggers for Appwrite Functions and Sites**.

Git deployment triggers let you control which branches and file changes create automatic deployments.

# What Git deployment triggers give you

- **Control over automatic deployments:** Choose the branch pushes and file changes that should start a build.
- **Cleaner preview workflows:** Create deployments from the branches your team uses for previews, staging, testing, or release candidates.
- **Better monorepo support:** Deploy a Function or Site only when files in the relevant folders change.
- **Simple glob patterns:** Use familiar patterns like `main`, `preview/**`, `apps/web/**`, and `!docs/**`. See the [build trigger docs](/docs/products/sites/deploy-from-git#build-triggers) for supported glob pattern formats.
- **Support for Functions and Sites:** Configure the same behavior across both Appwrite compute products.

# Branch filters

**Branch filters** match branch names. Add patterns to limit automatic deployments to specific branches.

| Pattern | Matches | Example use |
| --- | --- | --- |
| `main` | The `main` branch | Deploy from your production branch |
| `staging` | The `staging` branch | Deploy from a staging branch |
| `preview/**` | Branches nested under `preview/` | Deploy from branches like `preview/new-checkout` |

Branch filters work alongside your production branch setting. The production branch still controls which deployment becomes active for the Function or Site. Matching non-production branches can still create deployments for preview and testing workflows.

# Path filters

**Path filters** match files changed in a commit or pull request. Leave this field empty to create deployments for all file changes, or add patterns to deploy only when specific files change.

| Pattern | Matches | Example use |
| --- | --- | --- |
| `apps/web/**` | Files nested under `apps/web/` | Deploy a Site when its app files change |
| `packages/ui/**` | Files nested under `packages/ui/` | Deploy when shared UI components change |
| `!docs/**` | Excludes files nested under `docs/` | Skip docs-only changes |

Path filters are especially useful in monorepos. If one repository contains a web app, background Functions, shared packages, and docs, each Appwrite resource can listen only to the files it actually needs.

# How to configure build triggers

In the Appwrite Console, open the Function or Site you want to configure, then go to its Git settings and update **Build triggers**.

![Build triggers settings in the Appwrite Console](/images/blog/announcing-git-deployment-triggers/build-triggers.avif)

- **Sites:** Go to **Sites** > your site > **Settings** > **Git repository**.
- **Functions:** Go to **Functions** > your function > **Settings** > **Configuration** > **Git settings**.

You can also configure the same behavior through the API and Server SDKs using `providerBranches` for branch filters and `providerPaths` for path filters.

# Get started with Git deployment triggers

Git deployment triggers help keep automatic deployments aligned with how your team ships. They reduce unnecessary builds, make monorepos easier to manage, and give each Function or Site a clearer connection to the branches and files that matter.

- [Configure build triggers for Sites](/docs/products/sites/deploy-from-git#build-triggers)
- [Configure build triggers for Functions](/docs/products/functions/deploy-from-git#build-triggers)
