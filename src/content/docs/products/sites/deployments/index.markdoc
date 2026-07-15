---
layout: article
title: Deployments
description: Efficiently deploy your web apps with Appwrite. Explore deployment options, strategies, and best practices.
---

Each site can have many deployments, which can be thought of as versions of the web application. Sites can be created and deployed using different methods to meet your unique development habits.

# Deployment status

Throughout the life cycle of a deployment, it can have any of the following status:

| Status | Description |
| --- | --- |
| `active` | The deployment is built and currently activated and ready to be accessed. A site can have one active deployment and the deployment must be active before being executed. |
| `ready` | A deployment is built, but is not activated. Any `ready` deployment can be activated to replace the current active deployment. A ready deployment can also be [previewed](#preview-deployments) by authorized members of your Appwrite organization before activation. |
| `building` | A deployment is being built. Check the [deployment logs](#deployment-logs) for more info. |
| `processing` | The creation of a site deployment has begun and has not finished. |
| `waiting` | The deployment is queued but has not been picked up for processing. |
| `failed` | A deployment was not successful. Check the [deployment logs](#deployment-logs) for more info for debugging. |

# Deployment logs

When you build a deployment, the logs generated will be saved for debugging purposes. You can find these build logs by navigating to the **Deployments** tab of your site, clicking the three-dots menu beside a deployment, and clicking **Logs**.

{% only_dark %}
![Deployment logs](/images/docs/sites/dark/deployment-logs.avif)
{% /only_dark %}
{% only_light %}
![Deployment logs](/images/docs/sites/deployment-logs.avif)
{% /only_light %}

# Create deployment

To manually trigger a deployment of your app from the Appwrite Console, you can head to the **Deployments** tab of your site, click on the **Create deployment** button, and select one of the following:

- **Git**: Lets you select a branch on your connected Git repo and whether you would like to activate the build post-deployment
- **CLI**: Lets you run a [CLI command](/docs/products/sites/deploy-from-cli#cli) in your site's directory
- **Manual**: Lets you upload a [.tar.gz file](/docs/products/sites/deploy-manually#manual-deployment) containing your site's build output

{% only_dark %}
![Create deployment](/images/docs/sites/dark/create-deployment.avif)
{% /only_dark %}
{% only_light %}
![Create deployment](/images/docs/sites/create-deployment.avif)
{% /only_light %}

# Cancel deployment

If a site is being deployed and you wish to stop this deployment, you can head to the **Deployments** tab of your site, click on the three-dots menu, and click on the **Cancel** button.

{% only_dark %}
![Cancel deployment](/images/docs/sites/dark/cancel-deployment.avif)
{% /only_dark %}
{% only_light %}
![Cancel deployment](/images/docs/sites/cancel-deployment.avif)
{% /only_light %}

# Update deployment

Some site settings require redeploying your site to be reflected in your active deployment. When you update a site by changing its **Git repository**, **Build settings**, and **Environment variables**, you must redeploy your site before those changes take effect.

# Redeploy

After updating the configuration, redeploy your site for changes to take effect. You can also redeploy to retry failed builds.

1. Navigate to your site on Appwrite Console.
2. Under the **Deployments** tab, find the status of the current active deployment.
3. Redeploy by clicking the triple-dots beside a deployment and hitting the **Redeploy** button.

{% only_dark %}
![Redeploy](/images/docs/sites/dark/redeploy.avif)
{% /only_dark %}
{% only_light %}
![Redeploy](/images/docs/sites/redeploy.avif)
{% /only_light %}

Redeployment behavior varies depending on how the initial deployment was created.

{% info title="Benefits for Pro+ users" %}
Users subscribed to the Appwrite Pro plan or above receive certain special benefits:

- [Express builds](/changelog/entry/2024-08-10) for quicker deployments, resulting in reduced wait times and smoother workflows
- Longer [build timeouts](/docs/advanced/billing/compute#build-timeouts) (45 minutes vs 15 minutes on Free; Enterprise is custom)
- Customizable [build and runtime specifications](/docs/advanced/billing/compute) for CPU and memory on each site
{% /info %}

# Deployment retention {% #deployment-retention %}

Deployment retention controls how long Appwrite keeps non-active site deployments. The active deployment is always kept. When a non-active deployment is older than the configured retention period, Appwrite automatically deletes it during maintenance. Set the value to `0` to keep non-active deployments forever.

To configure deployment retention from the Appwrite Console:

1. Navigate to **Sites**.
2. Open the site you want to configure.
3. Go to **Settings** > **Deployment retention**.
4. Turn on **Keep deployments forever**, or turn it off and choose how long to keep non-active deployments.
5. Click **Update**.

{% only_dark %}
![Site deployment retention settings](/images/docs/sites/dark/deployment-retention.avif)
{% /only_dark %}
{% only_light %}
![Site deployment retention settings](/images/docs/sites/deployment-retention.avif)
{% /only_light %}

The Console provides common presets from `1 Week` to `10 Years`. When using the API or a Server SDK, set `deploymentRetention` to the number of days to keep non-active deployments. The value must be between `0` and `36500`, where `0` means unlimited retention.

When updating a site with a Server SDK, pass the existing settings you do not intend to change and update only `deploymentRetention`.

{% multicode %}

```server-nodejs
const site = await sites.get({
    siteId: '<SITE_ID>'
});

await sites.update({
    siteId: site.$id,
    name: site.name,
    framework: site.framework,
    enabled: site.enabled ?? undefined,
    logging: site.logging ?? undefined,
    timeout: site.timeout ?? undefined,
    installCommand: site.installCommand ?? undefined,
    buildCommand: site.buildCommand ?? undefined,
    startCommand: site.startCommand ?? undefined,
    outputDirectory: site.outputDirectory ?? undefined,
    buildRuntime: site.buildRuntime ?? undefined,
    adapter: site.adapter ?? undefined,
    fallbackFile: site.fallbackFile ?? undefined,
    installationId: site.installationId ?? undefined,
    providerRepositoryId: site.providerRepositoryId ?? undefined,
    providerBranch: site.providerBranch ?? undefined,
    providerSilentMode: site.providerSilentMode ?? undefined,
    providerRootDirectory: site.providerRootDirectory ?? undefined,
    buildSpecification: site.buildSpecification ?? undefined,
    runtimeSpecification: site.runtimeSpecification ?? undefined,
    deploymentRetention: 90
});
```

{% /multicode %}
