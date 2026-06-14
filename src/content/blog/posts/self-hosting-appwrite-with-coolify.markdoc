---
layout: post
title: Self-hosting Appwrite with Coolify
description: Learn how to self-host Appwrite with Coolify.
date: 2025-02-28
cover: /images/blog/self-hosting-appwrite-with-coolify/cover.avif
timeToRead: 15
author: chirag-aggarwal
category: tutorial
faqs:
  - question: "Can I self-host Appwrite on my own server?"
    answer: "Yes, absolutely! Appwrite was built with self-hosting in mind. You can run it on your own hardware, a VPS, or cloud providers like DigitalOcean, AWS, or Azure. Self-hosting gives you control over your data, security, and setup. You can even modify the source code to fit your exact needs. Tools like Coolify make it even easier by handling the deployment for you, so you don't have to manage containers or complex configurations manually."
  - question: "When should I choose Appwrite Cloud over self-hosting?"
    answer: "If you want to move fast: launch prototypes, MVPs, or production apps without managing servers, Appwrite Cloud is ideal. It's fully managed, so you can deploy and host your entire stack (frontend plus backend). It's also great for teams that want predictable costs and built-in scalability. The platform automatically handles updates, scaling, and backups, letting you focus on building, not maintaining infrastructure."
  - question: "Can I start with Appwrite Cloud and move to self-hosting later?"
    answer: "Yes, absolutely. That's one of the biggest advantages of Appwrite being open-source and cloud-compatible. You can start on Appwrite Cloud to build and test your product quickly, and later migrate to a self-hosted setup with Coolify if you want to. The APIs and developer experience stay consistent between both environments, so switching later in the future should not be a huge deal."
---

Updated on October 6, 2025

Appwrite was built with self-hosting in mind, making running on your own hardware as easy as possible with minimal hassle. Self-hosting allows users to have complete control over their stored data and provides extensive customization capabilities, enabling users to modify and tailor the source code to perfectly align with their needs.

This blog will discuss how to self-host Appwrite on your servers from scratch and some troubleshooting tips you might need.

# What is Coolify?

Coolify is an all-in-one Platform as a Service (PaaS) that allows developers to self-host applications, databases, or services, including Appwrite, without the hassle of managing the servers. It is an open-source and self-hostable solution that provides one-click deployment for hosting various services, including Appwrite on your servers.

It’s intuitive dashboard serves as a centralised command center, allowing users to effortlessly manage and configure all their services from a single, user-friendly interface.

![Coolify Dashboard](/images/blog/self-hosting-appwrite-with-coolify/coolify-dashboard.avif)


# Setting up a VPS

A VPS stands for Virtual Private Server, a machine you can rent on the cloud from various cloud providers. Some examples include Elastic Compute Cloud (EC2) from Amazon AWS, Virtual Machines from Azure, Droplets from DigitalOcean and much more.

Appwrite also offers one-click deployments without using Coolify on DigitalOcean, AWS Marketplace, etc. If you are interested, you can learn more about them on our [Github repository](https://github.com/appwrite/appwrite#one-click-setups).

This blog will focus on droplets from the DigitalOcean, but the same steps should apply to any VPS after you have it set up and running.

1. Go to [DigitalOcean's website](https://www.digitalocean.com/) and create an account or sign into an existing one.
2. After logging in to the control panel, click the **"Create"** menu in the top navigation bar and select **"Droplets"**.
3. You can choose any region and data centre. (Choosing one near your location might improve performance and latency.)

4. 
    ![Create Droplet](/images/blog/self-hosting-appwrite-with-coolify/create-droplet.avif)

4. For OS, select **Ubuntu** and select the latest version.
5. Choose a Droplet plan based on your expected workload.
6. Add block storage if you expect to store large files *(optional but recommended for production)*.
7. Enable backups for production environments *(optional but recommended)*.
8. Add your SSH key for secure access:

**Adding SSH Key**

SSH stands for **Secure Shell**. It is a protocol that allows you to securely access your server without using a password. It is more secure than passwords and easier to manage.

**a.** Select an existing key if you've previously added one.

![Add SSH Key](/images/blog/self-hosting-appwrite-with-coolify/add-ssh-key.avif)

**b.** Click on “New SSH Key” if creating a new one.

**c.** Open your system’s command line or terminal, and generate a new SSH key pair using `ssh-keygen -t rsa -b 4096`.

![Generate SSH Key](/images/blog/self-hosting-appwrite-with-coolify/generate-ssh-key.avif)

**d.** The command will generate two keys, one private and one public. One ending with .pub will be the public one; copy it.

**e.** Add the public key to DigitalOcean and give it a name.

![Add SSH Key to DigitalOcean](/images/blog/self-hosting-appwrite-with-coolify/add-ssh-key-to-digitalocean.avif)

Choose a hostname that helps identify your Appwrite instance (e.g., appwrite-self-hosting), and then click "Create Droplet". It will take a few minutes to provision the droplet.

# Initial server setup

Once your Droplet is provisioned, SSH into your server using your private key:

```bash
ssh root@your_server_ip
```

Now, update your system packages:

```bash
apt update && apt upgrade -y
```

Set up a firewall (UFW) to protect your server:

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

Create a non-root user with sudo privileges *(optional but recommended)*:

```bash
adduser appwrite-admin
usermod -aG sudo
```

# Setting up Appwrite on Coolify

Now that we have a VPS setup, the rest of the process should be the same regardless of which VPS you rent from any cloud provider.

Install Coolify on your server using this command:

```bash
curl -sSL <https://coolify.io/install> | bash
```

Once the installation is complete, open the Coolify dashboard in your web browser.

1. Sign up for a new account.
2. Click the **Create Project** button to start a new project.
3. Select or create an environment type. By default, a **production** environment is already available.
4. Click **Add new resource**.
5. Search for and select **Appwrite** from the list of services.

![Add Appwrite](/images/blog/self-hosting-appwrite-with-coolify/add-appwrite.avif)

The configuration fields will be pre-filled with the recommended settings, but you can customise them. When ready, click the **Deploy** button to initiate the deployment process.

After deployment, access your Appwrite instance's console by clicking the console link in the **Links** section of the Appwrite service.

# Troubleshooting common Issues

## Too many redirects

While accessing the console, you might run into the error `ERR_TOO_MANY_REDIRECTS` *(Site redirected you too many times)*. There can be a couple of issues here:

1. Since Coolify uses its configuration of **Traefik**, which is a reverse proxy and load balancer, instead of the official Appwrite one, you will have to turn off the **Strip Prefixes** option in the **Settings** page for the Appwrite console and Appwrite Realtime service.
2. If you are using Cloudflare to manage your deployment's DNS and turning off the above option doesn't help, you must update the SSL/TLS encryption setting to **Full** in the Cloudflare dashboard. From the dashboard, navigate to Your Domain -> SSL/TLS -> Overview and change the setting to Full.

## Container Health Checks Failing

![Container Health Checks Failing](/images/blog/self-hosting-appwrite-with-coolify/container-health-checks-failing.avif)

Currently, Coolify will mark all the Appwrite containers as unhealthy due to a lack of health checks configured. Still to verify if a container is healthy manually:

1. Check container logs in the Coolify dashboard for specific error messages.
2. Ensure your server meets the minimum resource requirements.
3. Verify that all required ports are open and not blocked by firewalls.
4. Check for Docker storage issues with df -h to ensure you haven't run out of disk space.

# Appwrite Cloud vs Self-hosting with Coolify

Appwrite also offers Cloud, a subscription-based all-in-one cloud platform that eliminates the complexities of managing infrastructure and hosting. With Appwrite Cloud, you can build, deploy, and host your entire application, frontend and backend, from a single platform.

It provides instant access to Appwrite’s complete suite of services, including authentication, databases, functions, storage, messaging, and hosting, all tightly integrated and managed for you. This means no manual server setup, no configuration headaches, and no maintenance, just a seamless integrated environment where you can go from idea to live app in minutes.

Choosing between Appwrite Cloud and self-hosting with Coolify depends on your project's needs. Here’s a side-by-side comparison to help you decide:

| Aspect | Appwrite Cloud | Self-Hosting with Coolify |
|--------|---------------|--------------------------|
| **Ease of setup** | Quick and beginner-friendly. | Requires technical expertise to set up VPS, Coolify, and Appwrite. |
| **Scalability** | Automatically adjusts based on demand, ensuring seamless performance. | You must provision additional resources as your app grows. |
| **Maintenance** | Appwrite handles updates, scaling, and security patches. | You are responsible for server maintenance, updates, and security patches. |
| **Security** | Built-in security features,DDoS protection, encryption, and backups without extra configuration. | Security is fully in your hands, configure firewalls, SSL, and backups manually. |
| **Support** | Priority support depending on your Appwrite Cloud plan, with faster issue resolution. | Community support or self-managed troubleshooting. |
| **Cost** | Predictable, subscription-based pricing, ideal for teams wanting to avoid unexpected infrastructure costs. | Pay for VPS, storage, and bandwidth and other infrastructural requirements. |
| **Customization** | Limited customization and depends on available features and settings provided by Appwrite. | Highly customizable since you can modify source code and integrate with other services. |
| **Data sovereignty** | Data is stored in Appwrite’s cloud infrastructure, which is fully complaint with GDPR regulations. | Data remains on your own infrastructure. |

# Conclusion

Self-hosting Appwrite with Coolify combines the strength of an all-in-one dev platform with the flexibility and autonomy of self-hosting. It enables developers to create powerful applications while retaining data sovereignty and possibly incurring lower operational expenses.

By following this guide, you've successfully deployed a production-capable Appwrite instance that you completely own. As your application grows, the union of Appwrite's robust features and Coolify's efficient management will continue to provide a solid foundation for your development requirements.

# More resources

To learn more about self-hosting Appwrite on Coolify, you can check out the following resources:

- [Self Hosting Docs - Appwrite](https://appwrite.io/docs/advanced/self-hosting)
- [Coolify Docs for Appwrite](https://coolify.io/docs/services/appwrite)
- [Self Host Appwrite on Coolify by Self-Host Everything](https://www.youtube.com/watch?v=9plbBKZNuvY)
