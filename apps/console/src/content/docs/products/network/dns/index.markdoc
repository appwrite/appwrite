---
layout: article
title: Appwrite DNS service
description: Learn about Appwrite's DNS service and how to configure domain records for your applications
---

Appwrite provides a dedicated DNS (Domain Name System) service through its `appwrite.zone` nameservers to help you manage domain records for your applications. This service is ideal for apex domains (root domains) that cannot use CNAME records due to DNS protocol limitations.

The DNS service enables you to configure custom domains for Sites, Functions, and APIs while providing automatic SSL certificate management and high availability. Whether you need to set up subdomains or apex domains, Appwrite's DNS service offers a complete solution.

# Benefits {% #benefits %}

- **Support for apex domains**: Use root domains like `example.com` directly without workarounds
- **Automatic SSL certificate management**: All domains get valid SSL certificates automatically
- **Integrated management**: Simplified configuration for Sites, Functions, and APIs
- **High availability**: Built on reliable infrastructure with global distribution

# Managing DNS records {% #managing-dns-records %}

You can manage DNS records for your domains in two places within the Appwrite Console:

## Organization-level DNS management

To manage DNS records at the organization level:

1. Navigate to your organization in the Appwrite Console
2. Select the **Domains** tab
3. Here you can view and manage all your DNS records across different projects

This is the central place to manage domain records when you're using Appwrite's DNS service by configuring NS records to point to `ns1.appwrite.zone` and `ns2.appwrite.zone`.

## Service-specific domain management

You can also manage domains directly from specific services:

- **Sites**: Navigate to a site and select the **Domains** tab
- **Functions**: Navigate to a function and select the **Domains** tab
- **API Endpoints**: Configure custom domains in your project settings under **Settings** > **Custom domains**

# Record types {% #record-types %}

Appwrite DNS supports various DNS record types to meet your domain configuration needs:

| Record Type | Description |
|-------------|-------------|
| A | Maps a domain to an IPv4 address |
| AAAA | Maps a domain to an IPv6 address |
| CNAME | Maps a domain to another domain (alias) |
| MX | Specifies mail servers for the domain |
| TXT | Stores text information (often used for verification) |
| NS | Specifies the nameservers for the domain |
| SRV | Specifies services available for a domain (used for Voice over IP, instant messaging, etc.) |
| [CAA](/docs/products/network/caa-records) | Specifies which certificate authorities (CAs) are authorized to issue certificates for a domain |
| HTTPS | Provides configuration for HTTPS connections |
| ALIAS | Similar to CNAME but can be used at the zone apex |

# Setting up apex domains {% #setting-up-apex-domains %}

Apex domains (also known as root domains) are domains without a subdomain prefix, like `example.com` instead of `www.example.com`. Appwrite offers two methods for setting up apex domains:

## Using NS records {% #using-ns-records %}

This method delegates DNS management to Appwrite by changing your domain's nameservers:

1. Navigate to your domain registrar's DNS settings
2. Find the NS (nameserver) record settings
3. Replace the existing nameservers with `ns1.appwrite.zone` and `ns2.appwrite.zone`
4. Wait for DNS propagation (may take up to 48 hours)
5. Return to Appwrite Console to verify and configure domain settings

{% info title="DNS delegation" %}
When you change your NS records to `ns1.appwrite.zone` and `ns2.appwrite.zone`, you're delegating complete DNS management to Appwrite. This means all existing DNS records (like email MX records) will need to be recreated in Appwrite's DNS configuration.
{% /info %}

## Using CNAME flattening {% #using-cname-flattening %}

Some DNS providers support CNAME-like behavior at the apex level through a feature called **CNAME flattening**. This allows you to use your apex domain without delegating nameservers to Appwrite.

Depending on your DNS provider, this feature may be provided through **CNAME records**, **ALIAS records**, or **ANAME records**. Check your provider's documentation for the specific DNS record they support.

To use this method:

1. In the Appwrite Console, add your apex domain and copy the provided CNAME and [CAA](/docs/products/network/caa-records) records
2. In your DNS provider's settings, create an ALIAS, ANAME, or CNAME record at the apex (depending on what your provider supports)
3. Point the record to the Appwrite hostname provided in the console
4. Add the [CAA record](/docs/products/network/caa-records) at the apex level in your DNS provider's settings, alongside any CAA records you already have
5. Wait for DNS propagation (may take up to 48 hours)
6. Return to Appwrite Console to verify the domain

This method allows you to maintain control over your remaining DNS configuration while still using your apex domain with Appwrite.

{% info title="Existing CAA records?" %}
If your apex already has CAA records for other certificate authorities, do not replace them. CAA records are additive. Add Appwrite's record next to your existing ones to keep every authorized CA. See [Setting multiple CAA records](/docs/products/network/caa-records#multiple).
{% /info %}

# Adding records in Appwrite {% #adding-records %}

If you delegated your domain to Appwrite's DNS servers using NS records, you can add and manage records:

1. Navigate to your organization's **Domains** tab in the Appwrite Console
2. Find your domain and click **Manage Records**
3. Click **Add Record** and select the record type
4. Fill in the required information based on the record type
5. Save the record

{% info title="Record propagation" %}
New DNS records may take time to propagate across the internet. This process typically takes minutes but can sometimes take longer depending on various factors like DNS cache settings.
{% /info %}