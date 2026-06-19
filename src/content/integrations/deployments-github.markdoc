---
layout: integration
title: Deployments with GitHub
description: Manage versions and deploy Appwrite Functions
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/deployments-github/cover.avif
category: deployments
product: 
    avatar: '/images/integrations/avatars/github.avif'
    vendor: GitHub
    description: 'GitHub is a web-based platform that facilitates version control and collaborative software development using Git.'
platform: 
    - 'Self-hosted'
images: 
    - /images/integrations/deployments-github/cover.avif
    - /images/integrations/deployments-github/create.avif
    - /images/integrations/deployments-github/installed.avif
---

GitHub is a web-based platform that facilitates version control and collaborative software development using Git. It enables developers to host, review code, manage projects, and work together on software development. GitHub offers tools for issue tracking, project management, and documentation, enhancing team collaboration. It hosts millions of open-source projects from developers across the world.

# How does the integration work?

Appwrite supports a Git integration to enable the generation of repositories for function templates and manage automatic deployments for any Appwrite Functions through GitHub.

> Note: For the automatic Git deployment to work, Appwrite needs to receive communication from GitHub, this means your Appwrite project must be accessible on the internet. If you're running on localhost, you need to run a proxy like [ngrok](https://ngrok.com/).
> 

# How to implement

To implement the GitHub integration for Appwrite Functions, there are several steps you must complete:

## Step 1: Create a GitHub App

Visit the [Developer Settings on GitHub](https://github.com/settings/apps) to create a new GitHub app. 

![Create new GitHub app](/images/integrations/deployments-github/create.avif)

Add a GitHub name (keep in mind that this will be publicly displayed), a relevant description, and a homepage URL for your application. Then, add the following URLs:

| Type of URL | URL |
| --- | --- |
| Callback URL | `https://[HOSTNAME_OR_IP]/v1/vcs/github/callback` |
| Callback URL | `https://[HOSTNAME_OR_IP]/v1/account/sessions/oauth2/callback/github/console` |
| Webhook URL | `https://[HOSTNAME_OR_IP]/v1/vcs/github/events` |

Under the **Callback URLs** section, check the **Request user authentication (OAuth)** during **installation** box. After that, request the following permissions:

{% tabs %}
{% tabsitem #repository title="Repository permissions" %}
| Permission | Access |
| --- | --- |
| Administration | Read and write |
| Checks | Read and write |
| Commit Statuses | Read and write |
| Contents | Read and write |
| Issues | Read and write |
| Metadata | Read-only |
| Pull Requests | Read and write |
| Webhooks | Read and write |
{% /tabsitem %}

{% tabsitem #account title="Account permissions" %}
| Permission | Access |
| --- | --- |
| Email address | Read-only |
{% /tabsitem %}
{% /tabs %}

Under the **Subscribe to events** section, check the **Pull request** and **Push** boxes. Lastly, allow **Any account** to install the GitHub app.

Once the app is created, create a new **client secret** and **private key**, and configure a **webhook secret**.

## Step 2: Add GitHub provider to the Appwrite instance

For this step, you must [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already.

Visit the `.env` file created for your Appwrite instance and update the following environment variables:

| Variable | Description |
| --- | --- |
| _APP_DOMAIN | Your main Appwrite domain used to access the Appwrite Console. When setting a public suffix domain, Appwrite will attempt to issue a valid SSL certificate automatically. When used with a dev domain, Appwrite will assign a self-signed SSL certificate. If you're using a proxy for localhost development, such as [ngrok](https://ngrok.com/), this will be the domain of your localhost proxy. |
| _APP_DOMAIN_TARGET | A hostname to serve as a CNAME target for your Appwrite custom domains. You can use the same value as used for the Appwrite _APP_DOMAIN variable. If you're using a proxy for localhost development, such as [ngrok](https://ngrok.com/), this will be the domain of your localhost proxy, such as `dd65-2405-201-4013-d8d7-b4c5-fb73-39f9-285c.ngrok.io`. |
| _APP_DOMAIN_FUNCTIONS | This will be used for system-generated [function domains](https://appwrite.io/docs/products/functions/domains). When a function domain is generated, it will be `[UNIQUE_ID].[_APP_DOMAIN_FUNCTIONS]`. If `_APP_DOMAIN_FUNCTIONS` is set to example.com for example, the generated domain for functions will be something like `64d4d22db370ae41a32e.example.com`. You can use the same value as used for the Appwrite `_APP_DOMAIN` variable. |
| _APP_VCS_GITHUB_APP_NAME | Name of your GitHub app. This is visible as the slug in your GitHub app's public link. For example, if the public link of your GitHub app is https://github.com/apps/test-appwrite-integration, then the app name will be test-appwrite-integration. |
| _APP_VCS_GITHUB_PRIVATE_KEY | RSA private key from GitHub wrapped with double quotes and newlines replaced with `\n`.  |
| _APP_VCS_GITHUB_APP_ID | GitHub application ID from your GitHub app. |
| _APP_VCS_GITHUB_CLIENT_ID | GitHub client ID from your GitHub app. |
| _APP_VCS_GITHUB_CLIENT_SECRET | GitHub client secret from your GitHub app. |
| _APP_VCS_GITHUB_WEBHOOK_SECRET | GitHub webhook secret from your GitHub app. |

This is how the fields would look like in the `.env` file:

```bash
_APP_DOMAIN=appwrite.example.com
_APP_DOMAIN_TARGET=appwrite.example.com
_APP_DOMAIN_FUNCTIONS=functions.example.com
_APP_VCS_GITHUB_APP_NAME=my-github-app
_APP_VCS_GITHUB_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\nMIIEogIBAAKCAQEAuT8f3lo/X83hfvb0ZN/KD2pl86o/jl3ywKrkj/PQZBmtEv/z\nIugE//sfFoHWc4cizkcji+n3FNU+GEdvMioKuJlPBqPTY8hAbVn7R0geZLpDV/rs\n[...]\n-----END RSA PRIVATE KEY-----"
_APP_VCS_GITHUB_APP_ID=12415
_APP_VCS_GITHUB_CLIENT_ID=Iv1.35asdf43asd
_APP_VCS_GITHUB_CLIENT_SECRET=35rsdse532q13
_APP_VCS_GITHUB_WEBHOOK_SECRET=super-secret
```

After that, run the following Docker Compose commands in your terminal to restart your Appwrite containers and verify if the changes have been successfully applied:

```bash
docker compose up -d --force-recreate
docker compose exec appwrite vars
```

## Step 3: Test the provider

To test the provider, go to the **Settings** page in your Appwrite project, navigate to the **Git configuration** section, and add an installation. On successful installation, you should be able to find your app installation in the same section.

![Installed GitHub app](/images/integrations/deployments-github/installed.avif)

# Read more about GitHub and Appwrite Functions

If you would like to learn more about GitHub, we have some resources that you should visit:

- [Create a GitHub app](https://docs.github.com/en/apps/creating-github-apps/about-creating-github-apps/about-creating-github-apps)
- [GitHub setup docs for Appwrite Functions (self-hosted)](https://appwrite.io/docs/advanced/self-hosting/functions)
- [Appwrite Functions API reference](https://appwrite.io/docs/references/cloud/server-nodejs/functions)