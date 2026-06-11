---
layout: article
title: AWS deployment
description: Deploy Appwrite on Amazon Web Services using the one-click Marketplace app.
---

Deploy Appwrite on AWS using the pre-configured Marketplace app.

# One-click installation {% #one-click %}

{% section #marketplace-install step=1 title="Launch from AWS Marketplace" %}

1. Visit the [Appwrite AWS Marketplace page](https://aws.amazon.com/marketplace/pp/prodview-2hiaeo2px4md6)
2. Click **Continue to Subscribe**
3. Review and accept the subscription terms
4. Click **Continue to Configuration**
5. Choose your preferred region and software version
6. Click **Continue to Launch**

{% /section %}

{% section #configure-instance step=2 title="Configure your instance" %}

1. Choose **Launch through EC2** action
2. Select instance type:
   - **t3.medium** minimum (2 vCPU, 4 GB RAM)
   - **t3.large** or larger for production
3. Configure security group to allow:
   - HTTP (80)
   - HTTPS (443)
   - SSH (22) from your IP
4. Select your EC2 key pair
5. Click **Launch**

{% /section %}

{% section #complete-setup step=3 title="Complete setup" %}

1. Wait for instance to launch and pass status checks
2. Navigate to your instance's public IP address in a web browser
3. Complete the initial Appwrite setup wizard

{% /section %}

# Custom installations {% #custom-installations %}

For manual installations on AWS EC2, ECS, or other configurations, follow the [general installation guide](/docs/advanced/self-hosting/installation) which covers Docker setup and configuration for any Linux server.

# Production considerations {% #production %}

For production deployments, optimization, and advanced AWS configuration, see the [production deployment guide](/docs/advanced/self-hosting/production).

# Next steps {% #next-steps %}

After successful deployment:

- [Configure services](/docs/advanced/self-hosting/configuration) - Set up email, storage, and other services
- [Production optimization](/docs/advanced/self-hosting/production) - Prepare for production workloads
- [Updates and maintenance](/docs/advanced/self-hosting/production/updates) - Keep your instance up to date