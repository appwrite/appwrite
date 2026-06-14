---
layout: post
title: "Appwrite custom domains: setting up and managing endpoints"
description: Learn why custom domains matter for Appwrite security, how to configure a CNAME record, verify your domain, and update your SDK endpoint for same-domain cookies.
date: 2026-03-27
cover: /images/blog/appwrite-custom-domains/cover.avif
timeToRead: 4
author: aditya-oberai
category: product, tutorial, security
featured: false
unlisted: true
faqs:
  - question: "Why should I use a custom domain with Appwrite?"
    answer: "Without a custom domain, your app on my-app.com calls Appwrite on cloud.appwrite.io and the browser treats Appwrite cookies as third-party. Modern browsers block third-party cookies, so Appwrite falls back to localStorage, which any JavaScript on the page can read. Pointing a subdomain like appwrite.my-app.com at Appwrite restores HttpOnly cookies and removes that XSS exposure."
  - question: "How do I configure a custom domain in Appwrite?"
    answer: "In the Appwrite Console, open Settings, Custom domains, Create domain, and enter the subdomain you want to use. Add the CNAME record Appwrite provides at your DNS provider, then click Verify in the Console once the record propagates. After verification, Appwrite automatically provisions an SSL certificate for the domain."
  - question: "How long does DNS propagation take for a custom domain?"
    answer: "DNS changes can take up to 48 hours globally, but most providers propagate in 15 to 30 minutes. If verification fails on the first try, wait a while and retry, and use a tool like dnschecker.org to confirm the CNAME has propagated before retrying."
  - question: "Do I need to update my app code after adding a custom domain?"
    answer: "Yes, but only the SDK endpoint. Update your Client SDK initialization to call setEndpoint with https://your-subdomain/v1 instead of the default Appwrite endpoint. The /v1 path suffix is required, and the rest of your application code stays the same."
  - question: "Does Appwrite handle SSL certificates for custom domains automatically?"
    answer: "Yes. Once your CNAME is verified, Appwrite automatically issues and renews an SSL certificate for the domain. There is no manual configuration, and your custom domain is served over HTTPS immediately. If issuance fails, retry verification, since DNS propagation timing can occasionally cause the first attempt to fail."
---

If your web app runs on `my-app.com` and your Appwrite instance is on `cloud.appwrite.io`, the browser sees them as different domains. That distinction has a direct effect on how your users' sessions are stored, and it is not in your users' favor.

[Custom domains](/docs/advanced/platform/custom-domains) let you point a subdomain of your own domain at the Appwrite API, so the browser treats sessions as first-party. This is not just branding. It is a meaningful security improvement that takes about ten minutes to set up.

# Why this matters: third-party cookies and session storage

Modern browsers restrict third-party cookies to protect users from cross-site tracking. When your app is on `my-app.com` and calls an API on `cloud.appwrite.io`, the browser classifies Appwrite's cookies as third-party and blocks them.

When cookies are blocked, Appwrite falls back to storing sessions in `localStorage`. This keeps your app functional, but `localStorage` has a significant weakness: any JavaScript running on your page can read it. If your app has an XSS vulnerability, or a compromised dependency injects a script, that script can read the session token directly from `localStorage` and use it to make authenticated requests.

Cookies with the `HttpOnly` flag cannot be read by JavaScript at all. An attacker with XSS access cannot steal a session stored in a cookie the way they can steal one stored in `localStorage`. This is the practical security difference between running Appwrite on a third-party domain versus your own domain.

# How custom domains fix the problem

When your app runs on `my-app.com` and your Appwrite API is on `appwrite.my-app.com`, the browser sees both as the same domain. Cookies set by Appwrite are now first-party cookies. They get `HttpOnly` protection, they are not blocked, and they do not fall back to `localStorage`.

You get better session security without changing a single line of application logic, just a DNS record and a configuration step.

# Setting up a custom domain

## Step 1: Open the Custom domains settings

In the Appwrite Console, navigate to your project, then go to **Settings** > **Custom domains** > **Create domain**.

Enter the subdomain you want to use. A common convention is `appwrite.your-domain.com`, but any subdomain works as long as you control the DNS for it.

## Step 2: Add the CNAME record

After entering your domain, Appwrite will display a CNAME record value. Log in to your DNS provider and add a CNAME record pointing your chosen subdomain to the value Appwrite provides.

The exact steps vary by provider. Most registrars have documentation for adding CNAME records. Common providers include:

- Cloudflare: **DNS** > **Records** > **Add record** > Type: CNAME
- GoDaddy: **DNS Management** > **Add** > Type: CNAME
- Namecheap: **Advanced DNS** > **Add New Record** > Type: CNAME
- AWS Route 53: **Hosted Zones** > **Create record** > Record type: CNAME

If your provider is not listed, search for "add CNAME record" in their help documentation.

## Step 3: Verify the domain

Back in the Appwrite Console, click **Verify** after adding the CNAME record. DNS changes can take up to 48 hours to propagate globally, so verification may not succeed immediately.

If verification fails on the first try, wait a few hours and try again. You can check whether your DNS change has propagated using a tool like [dnschecker.org](https://dnschecker.org) before retrying.

## Step 4: Generate the SSL certificate

Once verification succeeds, Appwrite automatically provisions an SSL certificate for your domain. No configuration needed. Your custom domain is immediately served over HTTPS.

# Update your SDK endpoint

With the custom domain configured, update your Appwrite client initialization to use it:

```js
import { Client } from "appwrite";

const client = new Client()
    .setEndpoint('https://appwrite.my-app.com/v1')
    .setProject('<PROJECT_ID>');
```

Replace `appwrite.my-app.com` with your actual subdomain. The `/v1` path suffix is required. Everything else in your app stays the same.

For mobile apps, you can also set a custom domain, though the third-party cookie issue is primarily a concern for web apps. The session security benefits of using a known, controlled endpoint still apply.

# DNS propagation and debugging

DNS changes take time. The 48-hour figure is a worst case, but 15 to 30 minutes is typical for most providers. If verification is not working after an hour, check these things:

- Confirm the CNAME record was saved correctly by checking your DNS provider's dashboard.
- Use a DNS lookup tool to verify the CNAME is resolving to the Appwrite target.
- Some DNS providers cache aggressively. Log out and log back in to see the latest record state.

If the SSL certificate generation fails after verification, try again. Certificate issuance occasionally requires a retry, especially if DNS was still propagating during the first attempt.

# Resources

- [Custom domains documentation](/docs/advanced/platform/custom-domains)
- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)
