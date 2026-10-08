---
layout: integration
title: OAuth with Discord
description: Authenticate users with an existing Discord account
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/oauth-discord/cover.avif
category: auth
product: 
    avatar: '/images/integrations/avatars/discord.avif'
    vendor: Discord
    description: 'Discord is a popular communication platform designed for creating communities.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/oauth-discord/cover.avif
    - /images/integrations/oauth-discord/new-app.avif
    - /images/integrations/oauth-discord/oauth2.avif
    - /images/integrations/oauth-discord/provider.avif
---

Discord is a popular communication platform designed for creating communities. It supports text, voice, and video communication, making it ideal for both casual conversations and organized group activities. Initially popular among gamers, Discord has expanded to various communities, including education, business, and hobbyist groups.

# How does the integration work?

You can use the Discord OAuth adapter in Appwrite Auth for user authentication and management. This can be convenient for users because they can start using your app without creating a new account. It can also be more secure, because the user has one less password that could become vulnerable.

# How to implement

To implement the Discord OAuth adapter in Appwrite Auth, there are several steps you must complete:

## Step 1: Register a Discord Developer application

First, head to the Discord Developer portal and [register an application](https://discord.com/developers/applications).

![New Discord app](/images/integrations/oauth-discord/new-app.avif)

Head to the **OAuth2** tab under **Settings** and save the **client ID** and **client secret**. Once we create the adapter on Appwrite, we will return to add a redirect URI.

## Step 2: Add Discord OAuth adapter to your Appwrite project

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In your Appwrite project, head over to the **Auth** page, open the **Settings** tab, and click on **Discord** under the **OAuth2 Providers** section.

![OAuth2 settings](/images/integrations/oauth-discord/oauth2.avif)

Add the **client ID** and **client secret** you saved from your Discord app and copy the URI to add to the **Redirect** field in the **OAuth2** settings in your Discord app.

![Discord provider](/images/integrations/oauth-discord/provider.avif)

## Step 3: Test the provider.

Follow the [OAuth 2 login](https://appwrite.io/docs/products/auth/oauth2#init) flow to test your provider.

# Read more about Discord and Appwrite Auth

If you would like to learn more about Discord and Appwrite Auth, we have some resources that you should visit:

- [Register a Discord Developer application](https://discord.com/developers/applications)
- [Implement OAuth login in your apps using Appwrite Auth](https://appwrite.io/docs/products/auth/oauth2)
- [Understanding OAuth and OpenID Connect](https://appwrite.io/blog/post/oauth-openid)
- [Appwrite Auth API reference](https://appwrite.io/docs/references/cloud/client-web/account)