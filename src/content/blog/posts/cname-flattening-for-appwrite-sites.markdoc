---
layout: post
title: CNAME flattening for Appwrite sites
description: Appwrite Sites now supports CNAME flattening, making it easier to connect custom domains without transferring DNS control or changing nameservers.
date: 2026-03-16
cover: /images/blog/cname-flattening-for-appwrite-sites/cover.avif
timeToRead: 5
author: aishwari
category: tutorial
featured: false
unlisted: true
faqs:
  - question: "What is CNAME flattening and why does it matter for apex domains?"
    answer: "CNAME flattening lets a DNS provider resolve a CNAME-like alias at the apex of a domain and return only the final A or AAAA record. This works around the rule that a real CNAME record cannot coexist with the MX, SPF, DKIM, and TXT records that a root domain needs. The result is that yourdomain.com can point to Appwrite without breaking email or domain verification."
  - question: "How is CNAME flattening different from changing nameservers?"
    answer: "Changing nameservers transfers full DNS control to a new provider, which can cause downtime during propagation, force you to recreate every existing record, and break features tied to your current provider, like Cloudflare proxying or email forwarding. CNAME flattening keeps you on your existing DNS provider and only adds the records needed to point your apex domain at Appwrite."
  - question: "Which DNS providers support CNAME flattening for Appwrite Sites?"
    answer: "Most modern providers support an equivalent feature under different names: Cloudflare calls it CNAME Flattening, AWS Route 53 calls it ALIAS records, DNSimple uses ALIAS, NS1 uses ANAME, and Google Cloud DNS supports it via forwarding zones. If your provider does not support flattening, you can still point your nameservers to Appwrite instead."
  - question: "How do I set up an apex domain on Appwrite Sites?"
    answer: "There is no special toggle. In the Appwrite Console, open your project, go to Settings then Domains, click Add Domain, enter your root domain, and add the DNS records Appwrite provides at your DNS provider. Once verified, Appwrite handles CNAME flattening on its end and your apex domain serves your site directly."
  - question: "Will my domain break if Appwrite's IP addresses change?"
    answer: "No. Because flattening resolves the alias dynamically at query time, your DNS response always returns Appwrite's current IPs. If those addresses change on the platform side, your domain follows automatically with no manual updates required."
---

For most developers, the last step of deploying a site is connecting a custom domain. It should be the easiest part, but DNS often makes it the hardest. Previously, when using Appwrite Sites, pointing an apex domain to your site required changing your nameservers to Appwrite. While this approach worked, migrating nameservers comes with several inconveniences:

- **Downtime during migration:** Your site may remain unavailable until DNS propagation fully completes, which can cause brief but noticeable periods of downtime.
- **Misconfigured DNS records:** Manually recreating all existing DNS records under a new nameserver is error-prone, and a single mistake can break services tied to your domain.
- **Proxied DNS conflicts:** If you rely on a provider like Cloudflare for proxied DNS (DDoS protection, caching, etc.), switching nameservers away means losing those features.
- **Mail forwarding disruption:** Providers like Cloudflare offer built-in email routing and forwarding. Migrating nameservers can silently break these configurations, causing missed emails.

Appwrite Sites now supports CNAME flattening, which means `yourdomain.com` can point directly to your Appwrite project. No subdomain. No redirect. No compromise.

[Read the official announcement.](https://appwrite.io/blog/post/announcing-cname-flattening)

# The DNS rule that's been slowing you down

DNS has a hard rule: **You cannot place a CNAME record at the root of your domain.**

A CNAME record says "this name is an alias for another name, go look that up instead." That works perfectly on subdomains:

`yourproject.yourdomain.com` → CNAME → `yourproject.appwrite.io`

But at the root, your domain needs other critical records to exist alongside it: your MX records (email delivery), SPF and DKIM (email authentication), TXT records for domain verification.

A CNAME at the root conflicts with all of them. Put one there and you risk silently breaking your email setup, landing in spam folders, or failing domain verification entirely.

# What CNAME flattening actually does

CNAME flattening solves the apex domain problem without requiring you to change nameservers.

Instead of storing a CNAME record at the root, your DNS resolver **follows the CNAME chain at query time** and returns only the final A or AAAA IP address, as if you'd hardcoded the IP directly.

From the outside, it looks like a normal A/AAAA record. Under the hood, your DNS provider is dynamically resolving the alias and returning the final IP address every time.

That means if Appwrite's IPs ever change, your domain stays correct automatically. No manual updates. No downtime.

Cloudflare calls this **CNAME Flattening**. AWS Route 53 calls it **ALIAS records**. DNSimple and NS1 call it **ANAME**.

Different names, same idea. And now it's supported by Appwrite.

# What this unlocks for your projects

With CNAME flattening support in Appwrite, you get:

- **A clean root domain:** `yourdomain.com` loads your app exactly as intended
- **Zero redirect overhead:** no bouncing through `www` before landing on your content
- **Intact DNS records:** MX, SPF, DKIM, TXT records all coexist at the apex without conflict
- **Automatic IP resolution:** if Appwrite infrastructure changes, your domain follows automatically

# Which DNS providers support this?

Most modern DNS providers support CNAME flattening or an equivalent feature:

- **Cloudflare:** CNAME Flattening (enabled by default on proxied records)
- **AWS Route 53:** ALIAS records at the zone apex
- **DNSimple:** ALIAS records
- **NS1:** ANAME records
- **Google Cloud DNS:** supported via forwarding zones

If your DNS provider doesn't support flattening, you can also manage your domain through Appwrite by pointing your nameservers to Appwrite.

# Setting it up

There's no special toggle. No extra configuration.

Just add your root domain the same way you'd add any custom domain in Appwrite:

1. Open your **Appwrite Console** and select your project
2. Go to **Settings → Domains**
3. Click **Add Domain** and enter your root domain: `yourdomain.com`
4. Add the DNS records Appwrite provides to your DNS provider
5. Wait for propagation (typically a few minutes to a few hours)

That's it. Appwrite handles CNAME flattening on its end once your domain is verified.

Full setup details are available in the [Appwrite custom domains documentation.](https://appwrite.io/docs/advanced/platform/custom-domains)

# One less thing to work around

The best developer tools are the ones that make you forget the problem ever existed.

CNAME flattening is one of those things. Small on the surface, meaningful in practice.

Your root domain now works the way you always expected it to. Ship with confidence.

# Resources

- [Appwrite Sites docs](https://appwrite.io/docs/products/sites)
- [Appwrite Discord](https://appwrite.io/discord)
