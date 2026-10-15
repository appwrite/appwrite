---
layout: article
title: Custom domains
description: Customize your Appwrite platform with custom domains. Learn how to set up and configure custom domains to provide a branded experience for your users.
---

Appwrite custom domains allows you to use your own domain as your Appwrite API endpoint. 

# Third-party cookies {% #third-party-cookies %}
A recent change made in modern browsers will not allow your web app to use 3rd-party cookies. 
This change is done to protect your users' privacy from malicious web tracking services.

When accessing Appwrite from a 3rd party domain, like `cloud.appwrite.io` or `example.com`, 
some browsers will treat our secure cookies as 3rd-party cookies and block them, 
as a fallback Appwrite will store your users' sessions on the browser localStorage.

Using localStorage is very convenient to help you get started quickly with Appwrite, but it is not the best practice for your users' security. 
The browser localStorage can't protect your users' sessions from being hijacked by a 3rd party script or an XSS vulnerability in your web app.

# Appwrite API endpoint {% #endpoint %}
To prevent your browser from blocking your cookies, your Appwrite API endpoint should be set to under same domain of your web app's domain.
When accessing Appwrite from the same domain as the one your app uses, 
Appwrite cookies will no longer be treated as 3rd-party cookies by any browser and will store your users' sessions securely.

For example, if your app runs on [my-app.com](https://my-app.com), 
you can set the subdomain [appwrite.my-app.com](https://appwrite.my-app.com) to access the Appwrite API. 
This will allow browsers to respect the Appwrite sessions cookies as they are set on the same domain as your app.

# Add a custom domain {% #domain %}

1. Go to the Appwrite Console and navigate to your project.
2. Click on the **Settings** tab in the left sidebar.
3. Select the **Custom domains** section and click **Create domain**.
4. Add your domain, and copy associated CNAME record to your DNS provider. See the [Add a CNAME record](#cname-record) section.
5. If the Console also shows a [CAA record](/docs/products/network/caa-records), add it to your DNS provider so Appwrite's certificate authority is authorized to issue a certificate for your domain. Existing CAA records should be kept in place. See [Setting multiple CAA records](/docs/products/network/caa-records#multiple).
6. Verify your domain. DNS changes might take up to 48 hours to propagate worldwide, you may not be able to do this in the same day.
7. Once you verify your domain, you can generate an SSL certificate.

With these steps, your Appwrite project will accept API requests from your custom domain.

If you encounter any issues during the setup process or have questions, don't hesitate to [contact us](/contact-us), and we'll be happy to assist you.

# Add a CNAME record {% #cname-record %}

A [CNAME record](https://en.wikipedia.org/wiki/CNAME_record) (or a Canonical Name record) is a type of resource record in the Domain Name System (DNS), which maps one domain name (an alias) to another.

Every DNS host has its own way of updating DNS settings, and, unfortunately, their dashboard interfaces usually aren't the most intuitive. We recommend that you read the help documentation of your DNS host, also do not hesitate to contact their support for help with their interface and settings.

Below, you'll find a list of registrars and links to their DNS setting documentation. If your domain provider isn't listed above, please [contact us](/contact-us), and we'll include their settings as well.

| Provider        | Documentation |
| --------------- | -------- |
| IONOS           | [Settings](https://www.ionos.com/help/domains/dns-settings/) |
| 101domain       | [Settings](https://help.101domain.com/domain-management/name-servers-dns/modifying-name-servers-and-records/managing-name-server-records) |
| 123 Reg         | [A Record](https://www.123-reg.co.uk/support/domains/how-do-i-point-my-domain-name-to-an-ip-address/) / [CNAME Record](https://www.123-reg.co.uk/support/domains/how-do-i-set-up-a-cname-record-on-my-domain-name/) |
| AWS Route 53    | [Settings](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/resource-record-sets-creating.html) |
| Alfahosting     | [Settings](https://alfahosting.de/antworten-auf-ihre-fragen/?cid=78#faqContent) |
| Binero          | [Settings](https://docs.binero.com/dns.html) |
| Bluehost        | [A Record](https://my.bluehost.com/hosting/help/whats-an-a-record) / [CNAME Record](https://my.bluehost.com/hosting/help/cname) / [Settings](https://my.bluehost.com/hosting/help/559) |
| ClouDNS         | [A Record](https://www.cloudns.net/wiki/article/10/) / [CNAME Record](https://www.cloudns.net/wiki/article/13/) |
| Cloudflare      | [Settings](https://support.cloudflare.com/hc/en-us/articles/360019093151) |
| Crazydomains    | [Settings](https://www.crazydomains.com.au/help/manage-dns-records-in-cpanel/) |
| DNS Made Easy   | [A Record](https://support.dnsmadeeasy.com/support/solutions/articles/47001024724-a-record) / [CNAME Record](https://support.dnsmadeeasy.com/support/solutions/articles/47001001393-cname-record) |
| DNSimple        | [A Record](https://support.dnsimple.com/articles/manage-a-record/) / [CNAME Record](https://support.dnsimple.com/articles/manage-cname-record/) |
| DigitalOcean    | [A Record](https://www.digitalocean.com/community/tutorials/an-introduction-to-digitalocean-dns#a-records) / [CNAME Record](https://www.digitalocean.com/community/tutorials/an-introduction-to-digitalocean-dns#cname-records) / [Settings](https://www.digitalocean.com/community/tutorials/an-introduction-to-digitalocean-dns) |
| DreamHost       | [A Record](https://help.dreamhost.com/hc/en-us/articles/215414867-How-do-I-add-custom-DNS-records-#A_record) / [CNAME Record](https://help.dreamhost.com/hc/en-us/articles/215414867-How-do-I-add-custom-DNS-records-#CNAME_record) |
| Freeparking     | [Settings](https://www.freeparking.co.nz/help/manage-dns-records-in-freeparking-dashboard) |
| Gandi           | [A Record](https://wiki.gandi.net/en/dns/zone/a-record) / [CNAME Record](https://wiki.gandi.net/en/dns/zone/cname-record) |
| Godaddy         | [A Record](https://www.godaddy.com/help/add-an-a-record-19238) / [CNAME Record](https://www.godaddy.com/help/add-a-cname-record-19236) |
| Google Domains  | [A Record](https://support.google.com/a/answer/2579934?hl=en&ref_topic=2721296) / [CNAME Record](https://support.google.com/a/answer/47283?hl=en) |
| Host Europe     | [Settings](https://www.hosteurope.de/faq/domains/verwaltung/nameserver-eintraege/) |
| Hover           | [Settings](https://help.hover.com/hc/en-us/articles/217282457-Managing-DNS-records) |
| Hostinger       | [Settings](https://support.hostinger.com/en/articles/1583249-how-to-manage-dns-records-at-hostinger) |
| Infomaniak      | [Settings](https://www.infomaniak.com/en/support/faq/2000/change-dns-zone-simple-mode) |
| InMotion Hosting| [Settings](https://www.inmotionhosting.com/support/product-guides/wordpress-hosting/central/domains/dns-management/) / [CNAME Record](https://www.inmotionhosting.com/support/domain-names/create-cname-record/) |
| Internet.bs     | [Settings](https://faq.internetbs.net/hc/en-gb/sections/360004926357-DNS-Nameservers) |
| LeaseWeb        | [Settings](https://kb.leaseweb.com/products/hosting/domain-name) |
| LCN.com         | [Settings](https://www.lcn.com/support/articles/how-to-manage-dns-settings-in-cpanel/) |
| Loopia          | [Settings](https://support.loopia.com/wiki/dns-editor-a-and-cname-2/) |
| Media Temple    | [Settings](https://mediatemple.zendesk.com/hc/en-us/articles/204403794-How-can-I-change-the-DNS-records-for-my-domain) |
| Namecheap       | [A Record](https://www.namecheap.com/support/knowledgebase/article.aspx/319/78/how-can-i-set-up-an-a-address-record-for-my-domain) / [CNAME Record](https://www.namecheap.com/support/knowledgebase/article.aspx/9256/2208/how-can-i-set-up-a-cname-record-for-my-domain) |
| Namesilo        | [A Record](https://www.namesilo.com/Support/DNS-Manager) / [CNAME Record](https://www.namesilo.com/Support/DNS-Manager) |
| Network Solutions | [A Record](https://customerservice.networksolutions.com/prweb/PRAuth/webkm/help/article/manage-dns-adns-records) / [CNAME Record](https://customerservice.networksolutions.com/prweb/PRAuth/webkm/help/article/manage-dns-adns-records) |
| One.com         | [Settings](https://help.one.com/hc/en-us/articles/115005595925-Manage-your-DNS-settings) |
| OVH             | [Settings](https://help.ovhcloud.com/csm/en-dns-edit-dns-zone?id=kb_article_view&sysparm_article=KB0051682) |
| Porkbun         | [A Record](https://kb.porkbun.com/article/54-pointing-your-domain-to-hosting-with-a-records) / [CNAME Record](https://kb.porkbun.com/article/68-how-to-edit-dns-records) |
| Register.it     | [Settings](https://www.register.it/assistenza/cambiare-dns/) |
| SiteGround      | [Settings](https://www.siteground.com/kb/manage-dns-records/) |
| United Domains  | [A Record](https://www.uniteddomains.com/faq/question/11) / [CNAME Record](https://www.uniteddomains.com/faq/question/14)|
| Vercel          | [Settings](https://vercel.com/docs/custom-domains) |
| Wix             | [Settings](https://support.wix.com/en/article/connecting-a-wix-domain-to-an-external-site) |
| Yahoo Small Business | [A Record](https://help.turbify.com/s/article/how-do-i-add-edit-and-delete-an-a-record) / [CNAME Record](https://help.turbify.com/s/article/how-do-i-add-edit-and-delete-a-cname-record) |

DNS changes might take up to 48 hours to propagate worldwide. This means that it might take up to two days for your new domain to become accessible using Appwrite. For debugging, you can try using [this online tool](https://dnschecker.org/) to check your DNS propagation status.

In addition to the DNS setup, you might also want to update the "Allowed Domains" section in your Appwrite project settings. By default, Appwrite only allows API calls from localhost, appwrite.io, and your project's default custom domains. You can add your custom domain to this list to ensure that API requests from your domain are accepted.
