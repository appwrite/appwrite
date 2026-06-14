---
layout: post
title: "Managing your endless website assets on your repo has a price and it's called cold start"
description: Storing all website assets in version control is convenient, but it has hidden costs. Learn when cold starts, CI time, and duplicated storage cause drawbacks, and when solutions like Appwrite Storage are a better fit.
date: 2026-03-11
cover: /images/blog/managing-website-assets-repo-cold-start/cover.avif
timeToRead: 8
author: eldad-fux
category: product
featured: false
draft: false
callToAction: true
faqs:
  - question: "Should I store website assets in my Git repository?"
    answer: "Small, code coupled assets like icons and component images are fine in the repo, since they version with the code that references them. Large, frequently updated, or shared assets are usually better stored separately. The right split depends on asset volume, deploy frequency, and how often the assets change relative to your code."
  - question: "What is a cold start and how do assets affect it?"
    answer: "A cold start is the time it takes for a fresh instance to spin up, install dependencies, build, and serve the first request. Large asset trees inflate the build artifact and stretch every step of that path. The more static files you ship from the repo, the longer cold starts get on every new deploy or environment."
  - question: "How does asset storage save on CI time?"
    answer: "Every CI run clones the repo, so a smaller repo means faster clones, smaller caches, and faster build steps that process static files. Moving heavy assets out of version control trims clone size and reduces the per file work the build has to do. The cumulative savings add up across many branches and parallel jobs."
  - question: "When should I move assets to Appwrite Storage?"
    answer: "Move assets to [Appwrite Storage](/docs/products/storage) when they are large, numerous, change independently from code, or are shared across many deployments. Storage gives you buckets, permissions, CDN-backed URLs, and image transformation, so you can serve and optimize files without touching the repo. Start with the heaviest or most duplicated assets first."
  - question: "What is image transformation in Appwrite Storage?"
    answer: "Image transformation lets you store one source image and request different sizes or formats per response, like resizing, cropping, or converting to WebP or AVIF on the fly. This avoids storing multiple variants and removes the need for build time image processing. See the [image transformation docs](/docs/products/storage/images) for parameters and examples."
  - question: "Does duplicated asset storage across deployments cost more?"
    answer: "Yes, if every deployment ships a full copy of the built assets, you pay for the same bytes many times over. Production, staging, preview, and regional deployments each store the same files. Centralizing shared assets in a storage layer and linking by URL reduces total storage and simplifies updates."
---

Keeping website assets in your repository feels right. Images, fonts, and static files live next to your code. You get versioning, a single source of truth, and a simple deploy story: push to Git, and your site builds with everything it needs. For many projects, that workflow is exactly what you want. For others, especially as asset volume and deployment footprint grow, the same approach has a cost. That cost often shows up first as cold start, then in CI and storage. This article walks through where the pain comes from and when to consider moving assets to a dedicated storage layer.

# When assets in the repo start to hurt

The upside of assets in VCS is real. Version control tracks every change. Rollbacks are straightforward. New developers clone once and have the full project. The tradeoff is that every time something runs from that repo, a fresh clone, a CI job, or a new deployment, it has to pull and process all of those files.

Large asset directories mean larger clones, longer installs, and heavier builds. On platforms that scale to zero or spin up new instances per deploy, that work happens on cold start. The first request after a deploy, or the first build in a new environment, pays the full cost: clone repo, install dependencies, build (including copying or processing every image and static file), then serve. The more assets you ship from the repo, the longer and more expensive that cold path becomes.

Cold start shows up in different ways depending on how you deploy. With serverless or edge functions, a new invocation may need to fetch and unpack the full deployment artifact; large static folders inflate that artifact and the time to first byte. With container-based hosting (for example, [Appwrite Sites](/docs/products/sites)), each new deployment builds from the repo, so clone and build time scale with repo size. With static site generators or frontend frameworks that copy `public` or `static` into the output, every build touches every file. Order-of-magnitude numbers help: a repo that’s 100MB of code and config but 400MB of images and fonts can easily add tens of seconds to every fresh clone and build, and that cost is paid on every new branch, every new environment, and every cache miss.

# CI time and build cost

CI pipelines that build and test your site also pay that cost. Every run clones the repository. The more assets you store in the repo, the more data gets transferred and the longer the clone step takes. Build steps that process the `public` or `static` folder-optimizing images, generating manifests, or bundling assets-scale with the number and size of files. That slows down every pull request and main-branch build.

Build caches (e.g. GitHub Actions cache, persistent volumes) soften the blow by reusing node_modules and sometimes build output. They don’t remove the cost entirely. Cache keys often include lockfile or branch; when the cache misses or you’re on a new runner, you’re back to a full clone and a full build. Large asset trees also make cache upload and download slower, and some CI systems limit cache size or evict old entries. So even with caching, a repo that’s heavy on assets tends to have slower and less predictable CI runs.

Multiply that by many branches, frequent commits, and parallel jobs, and the cumulative cost in compute time and queue delay can add up. Moving large or frequently changing assets out of the repo can trim clone size and build time, so CI spends less time moving and processing files that don’t need to live next to your code.

# Duplicated storage across deployments

If you run the same site in multiple environments or regions, like with [Appwrite Sites](/docs/products/sites) where each site can have several deployments for different regions or previews, each deployment typically gets a full copy of the build output. When that output includes every image, font, and static file from the repo, you’re duplicating the same bytes across every deployment. Storage and egress costs scale with the number of deployments and the size of those assets.

A simple way to see the impact: if your built site (including all static assets) is 2GB and you run five deployments (e.g. production, staging, and three preview environments), you’re storing 10GB of largely identical data. Add more previews per pull request or more regions, and that multiplier grows. Preview deployments are especially easy to overlook; they’re short-lived, but while they exist, they consume storage, and with a heavy asset set they can make “deploy every PR” policies noticeably more expensive.

That duplication is sometimes exactly what you want: each deployment is self-contained and easy to reason about. For very large or numerous assets, though, storing them once in a shared layer (such as [Appwrite Storage](/docs/products/storage)) and referencing them by URL can reduce total storage and simplify updates. You change the asset in one place, and every deployment that links to it gets the update without a new build or redeploy.

# Real-world scenarios where the cost shows up

A few patterns make the tradeoff especially visible:

**Documentation or marketing sites with hundreds of images.** Screenshots, diagrams, and hero images are often checked into the repo so content and assets stay in sync. As the library grows, every clone and every build copies and processes all of them. Moving reference images or media to storage and linking from the content (or generating URLs at build time) keeps the repo lean while still giving you a single place to update assets.

**Sites with large or frequently updated media.** High-resolution images, video thumbnails, or assets that change on a schedule (e.g. campaign banners) don’t benefit much from Git history. They bloat the repo and trigger full rebuilds when only the asset changed. Storing them in a bucket and referencing by URL keeps code deploys fast and lets you update media without touching the repo.

**Multiple deployments of the same app.** When the same codebase is deployed to production, staging, and per-PR previews-or to multiple regions-every deployment ships a full copy of the built assets. Shared assets (logos, fonts, common images) are duplicated each time. Moving those to a single storage bucket and serving them via CDN-backed URLs cuts duplication and often improves cache hit rates for users.

None of this means “never put assets in the repo.” It means being aware of where the cost appears so you can choose.

# Where storage solutions fit

Storage services like Appwrite Storage are not a replacement for “assets in the repo” in every case. They’re a better fit when:

- **Assets are large or numerous** and are slowing clones, builds, or cold starts.
- **Assets change independently from code** (e.g. user uploads, marketing imagery, media libraries). Versioning them in Git is awkward; putting them in a bucket with an API and CDN is a better fit.
- **The same assets are used across multiple deployments or apps.** One source of truth in storage avoids duplication and keeps updates in sync.
- **You want to offload optimization and delivery.** A dedicated storage layer can handle resizing, format conversion, and CDN delivery so the app and CI don’t have to. [Image transformation](/docs/products/storage/images) (e.g. on-the-fly resize, crop, or format conversion) is one example: you store a single source image and serve the size or format you need per request, which can cut bandwidth and storage and improve load times without extra build steps or multiple variants in the repo.

**When to keep assets in the repo:** Small, code-coupled assets-icons, component-level images, or anything that truly belongs with a specific version of the app-are still a good fit for version control. So are assets that must stay in lockstep with code (e.g. a component that expects a specific image path in the tree). The goal is to use the right place for each kind of asset: repo when it’s part of the codebase, storage when it’s data or shared media.

# A balanced approach

Keeping some assets in the repo and others in storage is a common and practical approach. Keep UI assets and small static files in the repo so they’re versioned with the code that references them. Use [Appwrite Storage](/docs/products/storage) (or similar) for large media, user-generated content, or assets shared across many deployments. Reference them via URL in your app (for example, a base URL from environment config so the same codebase can point at storage in production and at local or repo-backed paths in development).

That way you keep a clean, fast clone and build, and you avoid paying the cold start and duplication cost for assets that don’t need to live in version control.

You don’t have to move everything at once. Start with the heaviest or most duplicated assets (e.g. a shared image or font set used by every deployment), put them in a bucket and switch the app to use the storage URLs. [Appwrite Storage](/docs/products/storage) already provides buckets, permissions, CDN-backed URLs, and [image transformation](/docs/products/storage/images) so you can serve and optimize assets with the same control you’re used to. Leave the rest in the repo until the next time you feel the pain: slower CI, a cold start complaint, or a storage bill that’s higher than you’d like. Re-evaluate as the project grows; the right split today may shift as asset volume and deployment count increase.

Repo-based assets are fine until they’re not, and “not” usually shows up as a slow clone, a sluggish CI run, or a bill that’s higher than you expected. If you’re hitting that wall, moving the heaviest or most duplicated assets to storage is often enough to get back to a fast, predictable workflow. If you’re not there yet, no need to overthink it. Just keep the tradeoff in mind for the next time the build starts to feel heavy.

# More resources

- [Appwrite Storage documentation](/docs/products/storage)
- [Appwrite Sites documentation](/docs/products/sites)
- [7 reasons to migrate from Vercel to Appwrite Sites](/blog/post/migrate-from-vercel-to-appwrite-sites)
