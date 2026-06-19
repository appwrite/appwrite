---
layout: integration
title: OAuth with Notion
description: Authenticate users with an existing Notion account
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/oauth-notion/cover.avif
category: auth
product: 
    avatar: '/images/integrations/avatars/notion.avif'
    vendor: Notion
    description: 'Notion is a versatile productivity tool that combines note-taking, task management, and collaboration features into a single application.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/oauth-notion/cover.avif
    - /images/integrations/oauth-notion/new-integration.avif
    - /images/integrations/oauth-notion/provider.avif
    - /images/integrations/oauth-notion/redirect-uri.avif
---

Notion is a versatile productivity tool that combines note-taking, task management, and collaboration features into a single application. It allows users to create customizable workspaces where they can organize their projects, documents, and databases. Notion's flexibility comes from its block-based system, which lets users add various types of content, such as text, images, lists, tables, and more, in a modular fashion. This makes it suitable for personal use, team collaboration, and project management.

# How does the integration work?

You can use the Notion OAuth adapter in Appwrite Auth for user authentication and management. This can be convenient for users because they can start using your app without creating a new account. It can also be more secure, because the user has one less password that could become vulnerable.

# How to implement

To implement the Notion OAuth adapter in Appwrite Auth, there are several steps you must complete:

## Step 1: Create a Notion integration

First, head over to Notion and [create a new integration](https://www.notion.so/profile/integrations). Set the integration type as **Public** if you want your integration to be used by any Notion user, not just members of your workspace (otherwise, set it as **Internal**), and add all the mandatory info. In the **Redirect URIs** field, temporarily add the URL `https://temporary-endpoint/`. This temporary URI will be replaced with the final URI once the OAuth2 adapter is configured on Appwrite.

![New Notion integration](/images/integrations/oauth-notion/new-integration.avif)

From the **Configuration** tab, save the **OAuth Client ID** and **OAuth Client Secret** for later usage.

## Step 2: Add Notion OAuth adapter to your Appwrite project

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In your Appwrite project, head over to the **Auth** page, open the **Settings** tab, and click on **Notion** under the **OAuth2 Providers** section.

![Notion provider](/images/integrations/oauth-notion/provider.avif)

Add the **OAuth Client ID** and **OAuth Client Secret** you saved from your Notion integration and copy the URI to add to the **Redirect URIs** field on the **Basic Information** tab in your Notion integration.

![Redirect URI](/images/integrations/oauth-notion/redirect-uri.avif)

## Step 3: Test the provider.

Follow the [OAuth 2 login](https://appwrite.io/docs/products/auth/oauth2#init) flow to test your provider.

# Read more about Notion and Appwrite Auth

If you would like to learn more about Notion and Appwrite Auth, we have some resources that you should visit:

- [Create a Notion integration](https://www.notion.so/profile/integrations)
- [Implement OAuth login in your apps using Appwrite Auth](https://appwrite.io/docs/products/auth/oauth2)
- [Understanding OAuth and OpenID Connect](https://appwrite.io/blog/post/oauth-openid)
- [Appwrite Auth API reference](https://appwrite.io/docs/references/cloud/client-web/account)