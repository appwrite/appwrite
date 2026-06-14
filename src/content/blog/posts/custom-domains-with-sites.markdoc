---
layout: post
title: Custom domains with Appwrite Sites
description: Learn how to use custom domains with Appwrite Sites.
date: 2025-07-31
cover: /images/blog/custom-domains-with-sites/cover.avif
timeToRead: 5
author: dennis-ivy
category: tutorial
faqs:
  - question: "How do I add a custom domain to Appwrite Sites?"
    answer: "Open your site in the Appwrite Console, go to the Domains tab, and click Add domain. Enter your domain (for example example.com), then add the NS records Appwrite shows you (ns1.appwrite.zone and ns2.appwrite.zone) at your domain registrar. See [Appwrite Sites](/docs/products/sites)."
  - question: "What is an NS record?"
    answer: "An NS (Name Server) record tells the internet which servers are authoritative for a domain's DNS. By pointing your domain's NS records to Appwrite, you delegate DNS management to Appwrite, which lets Appwrite issue SSL certificates and route traffic to your deployed site."
  - question: "How long does domain verification take in Appwrite Sites?"
    answer: "DNS propagation usually takes a few minutes, but it can take a few hours in rare cases. After updating your NS records at your registrar, go back to the Appwrite Console and click Retry on the domain. If it does not verify immediately, wait a few minutes and try again."
  - question: "Can I point a custom domain to a specific branch of my site?"
    answer: "Yes. When you add a domain to a site connected to GitHub, you can point it to a specific branch. If you just want the latest production version, choose Active Deployment so the domain always serves whatever is currently live."
  - question: "Why does my site still show the old page after verification?"
    answer: "Browsers and intermediate DNS resolvers cache DNS responses, so even after Appwrite reports the domain as verified, you may temporarily see the old page. Clear your browser cache, try in an incognito window, or test from a different device or network to confirm."
  - question: "Does Appwrite Sites issue SSL certificates automatically?"
    answer: "Yes. Once your domain is verified, Appwrite Sites automatically provisions and renews SSL certificates so your site is served over HTTPS. You do not need to manage certificates manually."
---

Appwrite Sites, the open-source Vercel alternative, is now available to all organizations. Of course, with hosting comes the next step of connecting custom domains. In this article, we’ll walk you through the steps to do just that.

If you prefer to learn from videos, we will also have a [video tutorial](https://youtu.be/gN5_ig0OIXc) on the Appwrite YouTube channel.

Before we start, we’ll assume two things:

1. You have a domain purchased with a domain registrar (Namecheap, GoDaddy, Bluehost, etc)
2. You’ve deployed your website with Appwrite Sites

We’ll use Namecheap in this tutorial; however, if there are different steps for any providers, we will note these and link related tutorials in the footnotes.

You can also learn how to [host with Appwrite Sites](https://youtu.be/VtDe6hDw91k).

# Adding a domain

In your Appwrite console, you’ll want to add a domain to your deployed website.

1. Go to the Sites tab
2. Select the Site to which you wish to add the domain
3. Go to the `Domains` tab and click `Add domain`

Type in the domain name you have hosted with your provider, i.e, `example.com`.

You can point your domain to a specific branch if you connect via GitHub. If you are unsure about this step, you’re better off selecting `Active Deployment` to ensure the latest version of your website is live.

When you click **Add**, you'll see NS records that look like this:

- `ns1.appwrite.zone`
- `ns2.appwrite.zone`

{% info title="About NS records" %}
NS (Name Server) tells the internet which servers are responsible for knowing a domain's address (like www.example.com) and how to find it. They act like a directory listing, pointing users to the correct "address book" for a specific domain.
{% /info %}

We can’t verify our domain until we give these to our domain name provider, so for now, you can save them or close out the tab. In the next step, we’ll cover where you can find all your DNS records.

# View your DNS records

Once you’ve added a domain to your deployed Site, you’ll be able to view all your Site's DNS records from your account organization:

![Organization overview in Appwrite console](/images/blog/custom-domains-with-sites/organization-overview.avif)

1. In the top left corner of the console, select `Organization Overview`
2. Select the `Domains` tab

Here you’ll see every domain you’ve added across all projects in the selected organization.

Click on the domains row to view all DNS records. Keep this tab open as you’ll need both NS records in the next step.

![NS records for your domain](/images/blog/custom-domains-with-sites/dns-records.avif)

# Adding NS records in Namecheap

In your Namecheap dashboard, find the domain you want to use and select `Manage`.

Go to `Domain` and under the `NAMESERVERS` section, select `Custom DNS` and add both of the NS records that Appwrite provided.

Make sure you click the green check mark to save this change.

![NS settings in Namecheap](/images/blog/custom-domains-with-sites/ns-records-namecheap.avif)

# Verifying your domain

Once you’ve added your NS records to Namecheap, you have successfully pointed your domain to Appwrite's name servers. All that's left to do is verify your domain from your Appwrite console.

![Retry button to check domain verification status](/images/blog/custom-domains-with-sites/retry-dns-checks.avif)

This step can take a few minutes, or even a few hours, in some cases, although this is rare.

In any case, go back to the domain section from your Appwrite organization, select the domain, and click `Retry`.

If this doesn’t work immediately, try again in a few minutes. It may take several attempts with time in between.

Your website should be live once you receive the `Verified` status.

![Domain verified successfully on Appwrite console](/images/blog/custom-domains-with-sites/domain-verified.avif)

Once again, even after your website is verified, it may take some time to see the website. Try clearing your browser’s cache or visiting the domain from a different device to test.

# Next steps

You can now access your website at your custom domain. If you have any questions, please join our [Discord server](https://appwrite.io/discord). Here are some more resources to help you get started with Appwrite Sites:

- [Appwrite Sites documentation on domains](/docs/products/sites/domains)
- [Build and deploy a personal portfolio on Appwrite Sites](/blog/post/portfolio-template-sites)