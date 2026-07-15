---
layout: article
title: Deployments
description: Efficiently deploy your serverless functions with Appwrite. Explore deployment options, strategies, and best practices for seamless function execution.
---

Each function can have many deployments, which can be thought of as versions of the mini-application.
Functions can be created and deployed in different ways to meet your unique development habits. 

# Deployment status {% #deployment-status %}
Throughout the life cycle of a deployment, it could have the following status.

{% table %}
* Status 
* description
---
* `active`
* The deployment is built and currently activated and ready to be executed. A function can have one active deployment and deployment a must be active before being executed.
---
* `ready`
* A deployment is built, but is not activated. `ready` deployments can be activated to replace the current active deployment.
---
* `building`
* A deployment is being built. Check the [build log](#build-logs) for more detailed logs.
---
* `processing`
* The function deployment has begun and has not finished.
---
* `waiting`
* The deployment is queued but has not been picked up for processing.
---
* `failed`
* A deployment was not successful. Check the [build log](#build-logs) for detailed logs for debugging.
{% /table %}

# Update deployment {% #update-deployment %}
Some Function settings require redeploying your function to be reflected in your active deployment.
When you update a function by changing it's **Git settings**, **Build settings**, and **Environment variables**, 
you need to redeploy your function before they take effect.

# Build logs  {% #build-logs %}
When you build a deployment, the logs generated will be saved for debugging purposes.
You can find build logs by navigating to the **deployments** tab of your function, clicking the three-dots menu beside, and click **Logs**.

# Redeploy {% #redeploy %} 

After updating the configuration, redeploy your function for changes to take effect. You can also redeploy to retry failed builds.

1. Navigate to **Functions**.
2. Open the function you wish to inspect.
3. Under the **Deployments** tab, find the status of the current active deployment.
4. Redeploy by clicking the triple-dots beside an execution, and hitting the **Redeploy** button.

Redeployment behavior varies depending on how the initial deployment was created.

{% info title="Benefits for Pro+ users" %}
Users subscribed to the Appwrite Pro plan or above receive certain special benefits:

- [Express builds](/changelog/entry/2024-08-10) for quicker deployments, resulting in reduced wait times and smoother workflows
- Longer [build timeouts](/docs/advanced/billing/compute#build-timeouts) (45 minutes vs 15 minutes on Free; Enterprise is custom)
- Customizable [build and runtime specifications](/docs/advanced/billing/compute) for CPU and memory on each function
{% /info %}

# Deployment retention {% #deployment-retention %}
Deployment retention controls how long Appwrite keeps non-active function deployments. The active deployment is always kept. When a non-active deployment is older than the configured retention period, Appwrite automatically deletes it during maintenance. Set the value to `0` to keep non-active deployments forever.

To configure deployment retention from the Appwrite Console:

1. Navigate to **Functions**.
2. Open the function you want to configure.
3. Go to **Settings** > **Deployment retention**.
4. Turn on **Keep deployments forever**, or turn it off and choose how long to keep non-active deployments.
5. Click **Update**.

{% only_dark %}
![Function deployment retention settings](/images/docs/functions/dark/deployment-retention.avif)
{% /only_dark %}
{% only_light %}
![Function deployment retention settings](/images/docs/functions/deployment-retention.avif)
{% /only_light %}

The Console provides common presets from `1 Week` to `10 Years`. When using the API or a Server SDK, set `deploymentRetention` to the number of days to keep non-active deployments. The value must be between `0` and `36500`, where `0` means unlimited retention.

When updating a function with a Server SDK, pass the existing settings you do not intend to change and update only `deploymentRetention`.

{% multicode %}

```server-nodejs
const func = await functions.get({
    functionId: '<FUNCTION_ID>'
});

await functions.update({
    functionId: func.$id,
    name: func.name,
    runtime: func.runtime,
    execute: func.execute ?? undefined,
    events: func.events ?? undefined,
    schedule: func.schedule ?? undefined,
    timeout: func.timeout ?? undefined,
    enabled: func.enabled ?? undefined,
    logging: func.logging ?? undefined,
    entrypoint: func.entrypoint ?? undefined,
    commands: func.commands ?? undefined,
    scopes: func.scopes ?? undefined,
    installationId: func.installationId ?? undefined,
    providerRepositoryId: func.providerRepositoryId ?? undefined,
    providerBranch: func.providerBranch ?? undefined,
    providerSilentMode: func.providerSilentMode ?? undefined,
    providerRootDirectory: func.providerRootDirectory ?? undefined,
    buildSpecification: func.buildSpecification ?? undefined,
    runtimeSpecification: func.runtimeSpecification ?? undefined,
    deploymentRetention: 90
});
```

{% /multicode %}
