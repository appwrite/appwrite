---
layout: integration
title: OAuth with Amazon
description: Authenticate users with an existing Amazon account
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/oauth-amazon/cover.avif
category: auth
product: 
    avatar: '/images/integrations/avatars/amazon.avif'
    vendor: Amazon
    description: 'Amazon is a global e-commerce platform that offers a wide selection of products, from electronics, books, and apparel to household goods, groceries, and digital media.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/oauth-amazon/cover.avif
    - /images/integrations/oauth-amazon/security-profile.avif
    - /images/integrations/oauth-amazon/provider.avif
    - /images/integrations/oauth-amazon/allowed-return-urls.avif
---

Amazon is a global e-commerce platform that offers a wide selection of products, from electronics, books, and apparel to household goods, groceries, and digital media. Amazon offers an OAuth provider to simplify the process of integrating Amazon services into various applications, enhancing the authentication user experience while maintaining robust security standards.

# How does the integration work?

You can use the Amazon OAuth adapter in Appwrite Auth for user authentication and management. This can be convenient for users because they can start using your app without creating a new account. It can also be more secure, because the user has one less password that could become vulnerable.

# How to implement

To implement the Amazon OAuth adapter in Appwrite Auth, there are several steps you must complete:

## Step 1: Create an Amazon Developer account

First, [sign up for an Amazon Developer account](https://www.amazon.com/ap/register?clientContext=130-6830764-7565500&openid.identity=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0%2Fidentifier_select&siteState=clientContext%3D140-5240481-9393408%2CsourceUrl%3Dhttps%253A%252F%252Fdeveloper.amazon.com%252Fsettings%252Fconsole%252Fregistration%253Freturn_to%253D%252Fapps-and-games%2Csignature%3DR6AIXlj2Bj2Fwc7EBuqfl0CNCjUbh6Ej3D&marketPlaceId=ATVPDKIKX0DER&language=en_US&pageId=amzn_developer_portal&openid.return_to=https%3A%2F%2Fdeveloper.amazon.com%2Fsettings%2Fconsole%2Fregistration%3Freturn_to%3D%252Fapps-and-games&prevRID=93HMKYWW3JS4T3Q5EJ37&openid.assoc_handle=mas_dev_portal&openid.mode=checkid_setup&prepopulatedLoginId=&failedSignInCount=0&openid.claimed_id=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0%2Fidentifier_select&openid.ns=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0), head to the [Login With Amazon console](https://developer.amazon.com/loginwithamazon/console/site/lwa/overview.html), and create a new security profile with details of your app.

![New security profile](/images/integrations/oauth-amazon/security-profile.avif)

Save the generated **Client ID** and **Client Secret** for later usage.

## Step 2: Add Amazon OAuth adapter to your Appwrite project

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In your Appwrite project, head over to the **Auth** page, open the **Settings** tab, and click on Amazon under the **OAuth2 Providers** section.

![Amazon provider](/images/integrations/oauth-amazon/provider.avif)

Add the **Client ID** and **Client secret** you saved from the security profile you created. Copy the URI to add to the **Allowed Return URLs** field in the **Web Settings** of the security profile in the Amazon Developer console.

![New security profile](/images/integrations/oauth-amazon/allowed-return-urls.avif)

## Step 3: Test the provider.

Follow the [OAuth 2 login](https://appwrite.io/docs/products/auth/oauth2#init) flow to test your provider.

# Read more about Amazon and Appwrite Auth

If you would like to learn more about Amazon and Appwrite Auth, we have some resources that you should visit:

- [Sign up for an Amazon Developer account](https://www.amazon.com/ap/register?clientContext=130-6830764-7565500&openid.identity=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0%2Fidentifier_select&siteState=clientContext%3D140-5240481-9393408%2CsourceUrl%3Dhttps%253A%252F%252Fdeveloper.amazon.com%252Fsettings%252Fconsole%252Fregistration%253Freturn_to%253D%252Fapps-and-games%2Csignature%3DR6AIXlj2Bj2Fwc7EBuqfl0CNCjUbh6Ej3D&marketPlaceId=ATVPDKIKX0DER&language=en_US&pageId=amzn_developer_portal&openid.return_to=https%3A%2F%2Fdeveloper.amazon.com%2Fsettings%2Fconsole%2Fregistration%3Freturn_to%3D%252Fapps-and-games&prevRID=93HMKYWW3JS4T3Q5EJ37&openid.assoc_handle=mas_dev_portal&openid.mode=checkid_setup&prepopulatedLoginId=&failedSignInCount=0&openid.claimed_id=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0%2Fidentifier_select&openid.ns=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0)
- [Implement OAuth login in your apps using Appwrite Auth](https://appwrite.io/docs/products/auth/oauth2)
- [Understanding OAuth and OpenID Connect](https://appwrite.io/blog/post/oauth-openid)
- [Appwrite Auth API reference](https://appwrite.io/docs/references/cloud/client-web/account)
