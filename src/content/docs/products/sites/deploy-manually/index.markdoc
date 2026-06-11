---
layout: article
title: Deploy manually
description: Learn to deploy Appwrite Sites manually via the Appwrite Console.
---

Appwrite Sites allows you to host and deploy websites directly within the Appwrite platform. Each site can have many deployments, which can be thought of as versions of the web application.

While we recommend you create deployments through [automatic Git deployments](/docs/products/sites/deploy-from-git), you can also create deployments manually by uploading the source code to the Appwrite Console.

# Manual Deployment

You can upload your sites to be deployed using the Appwrite Console. The example below shows a skeleton SvelteKit app.

```bash
.
├ src/
├ static/
├ package.json
├ svelte.config.js
├ tsconfig.json
└ vite.config.js
```

First, create a build using the `npm run build` command (or an alternative package manager link `yarn` or `pnpm`). Then navigate inside the folder that contains your build output (for example, `./build` in SvelteKit) and package your code files into the `.tar.gz` format:

```bash
tar --exclude code.tar.gz -czf code.tar.gz .
```

Next, navigate to your Appwrite Console and upload the site.

1. Navigate to the site you want to deploy.
2. Head to the **Deployments** tab.
3. Click on the **Create deployment** button.
4. Select the **Manual** option.
    {% only_dark %}
    ![Manual deployment](/images/docs/sites/dark/manual-deployment.avif)
    {% /only_dark %}
    {% only_light %}
    ![Manual deployment](/images/docs/sites/manual-deployment.avif)
    {% /only_light %}
5. Upload `code.tar.gz`.
6. Select **Activate deployment after build**.
7. Click on the **Create** button.

# Debugging

- If you updated your site's configuration but the deployment is not working as expected, you may need to first redeploy your site before the changes take effect.