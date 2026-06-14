---
layout: post
title: "Announcing deployment retention for Appwrite Functions and Sites"
description: Deployment retention lets you automatically delete inactive Appwrite Functions and Sites deployments while keeping deployments within the retention window available for rollback.
date: 2026-05-15
cover: /images/blog/announcing-deployment-retention/cover.avif
timeToRead: 4
author: aditya-oberai
category: announcement
featured: false
faqs:
  - question: "What is deployment retention in Appwrite?"
    answer: "Deployment retention controls how long Appwrite keeps non-active [Functions](/docs/products/functions/deployments#deployment-retention) and [Sites](/docs/products/sites/deployments#deployment-retention) deployments. The active deployment is always kept, and older non-active deployments are deleted after the configured number of days."
  - question: "Does deployment retention delete my active deployment?"
    answer: "No. Appwrite always keeps the active deployment for a Function or Site, even when it is older than the configured retention period. Retention only applies to non-active deployments."
  - question: "What values can I use for deployment retention?"
    answer: "Use `0` to keep non-active deployments forever, or set any value from `1` to `36500` days. The Console includes common presets from 1 week to 10 years, and the API accepts custom day counts in the supported range."
  - question: "Can I configure deployment retention for both Functions and Sites?"
    answer: "Yes. Deployment retention is available for both [Appwrite Functions](/docs/products/functions/deployments#deployment-retention) and [Appwrite Sites](/docs/products/sites/deployments#deployment-retention), with the same `deploymentRetention` setting in the API and Server SDKs."
  - question: "Does changing deployment retention require a redeploy?"
    answer: "No. Deployment retention is a resource setting, not a build setting. You can update it from the Console or Server SDKs without rebuilding your active Function or Site deployment."
  - question: "Can I set deployment retention from a Server SDK?"
    answer: "Yes. Set `deploymentRetention` when you create or update a Function or Site. When using an update method, pass the existing settings you want to keep along with the new retention value."
---

Deployments pile up quickly. Every push to a production branch, every preview deployment, and every manual rebuild leaves behind a version you may need for debugging or rollback. Some of that history is useful. Some of it stops being useful after a week, a month, or a release cycle, but it keeps occupying storage long after you would ever roll back to it.

Today, we are announcing **deployment retention for Appwrite Functions and Sites**.

Deployment retention lets you choose how long Appwrite keeps non-active deployments before automatically deleting them. You free up storage, lower your storage costs, and keep your deployment list focused on the builds you actually care about. Your active deployment is always protected, and you can still keep deployments forever when your workflow calls for a long rollback window.

# What deployment retention gives you

- **Lower storage costs:** Deployment artifacts count toward your project's overall storage allowance on Appwrite Cloud. Retention reclaims that space automatically, so unused builds stop pushing you toward [additional storage overage](/pricing).
- **Cleaner deployment history:** Keep the deployment list focused on recent builds instead of every preview or retry your team has ever created.
- **Protected active deployment:** The deployment currently serving your Function or Site is never removed by retention cleanup.
- **Simple Console controls:** Choose **Keep deployments forever** or pick a retention window from common presets like 1 week, 1 month, 6 months, or 1 year.
- **API support:** Configure the same behavior with the `deploymentRetention` field when creating or updating Functions and Sites.
- **Custom retention windows:** Use any value from `1` to `36500` days, or `0` for unlimited retention.

# How to configure deployment retention

In the Appwrite Console, open the Function or Site you want to configure, go to **Settings** > **Deployment retention**, then choose whether to keep deployments forever or keep non-active deployments for a specific period.

![Deployment retention settings for an Appwrite Function](/images/docs/functions/dark/deployment-retention.avif)

For example, you might keep deployments for 90 days on a production Function and 30 days on a preview-heavy Site. Appwrite will keep the active deployment and clean up older non-active deployments during maintenance.

You can also configure retention through the Server SDKs using `deploymentRetention`. The examples below focus on the relevant field. When you update an existing resource, include any current settings you want to preserve.

```js
const func = await functions.get({
    functionId: '<FUNCTION_ID>'
});

await functions.update({
    functionId: func.$id,
    name: func.name,
    runtime: func.runtime,
    // Include other current function settings as needed.
    deploymentRetention: 90
});

const site = await sites.get({
    siteId: '<SITE_ID>'
});

await sites.update({
    siteId: site.$id,
    name: site.name,
    framework: site.framework,
    // Include other current site settings as needed.
    deploymentRetention: 30
});
```

# When to use deployment retention

Use deployment retention when your deployment history grows faster than your need to inspect it. Every non-active deployment keeps occupying storage in your project, and those artifacts count toward the storage allowance on your [Appwrite Cloud plan](/pricing). For teams using Git-based deploys, preview deployments, frequent rebuilds, or short-lived feature branches, that footprint adds up quickly and can push you into additional storage charges for builds you would never roll back to.

A shorter retention window works well for staging, preview, and internal environments where old builds lose value quickly and storage savings add up the fastest. A longer window fits production resources where you want more historical context for support, incident review, or rollback planning. If your compliance or operational process requires keeping every deployment, set retention to `0` and Appwrite will keep non-active deployments forever.

# Configure deployment retention in Appwrite

Deployment retention is available on [Appwrite Cloud](https://cloud.appwrite.io) today. You can update existing Functions and Sites from the Console, or configure the same setting through the API and Server SDKs.

- [Configure Function deployment retention](/docs/products/functions/deployments#deployment-retention)
- [Configure Site deployment retention](/docs/products/sites/deployments#deployment-retention)
- [Functions API reference](/docs/references/cloud/server-nodejs/functions#update)
- [Sites API reference](/docs/references/cloud/server-nodejs/sites#update)
