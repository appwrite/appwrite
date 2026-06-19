---
layout: integration
title: Storage with Amazon S3
description: Store files in Amazon S3 instead of your local storage device
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/storage-s3/cover.avif
category: storage
product:
    avatar: '/images/integrations/avatars/aws.avif'
    vendor: AWS
    description: 'Amazon S3 is an object storage service that offers industry-leading scalability, data availability, security, and performance.'
platform:
    - 'Self-hosted'
images:
    - /images/integrations/storage-s3/cover.avif
    - /images/integrations/storage-s3/bucket.avif
    - /images/integrations/storage-s3/access-key.avif
    - /images/integrations/storage-s3/appwrite-file.avif
    - /images/integrations/storage-s3/s3-file.avif
---

Amazon S3 (Simple Storage Service) is an object storage service that offers industry-leading scalability, data availability, security, and performance. You can use Amazon S3 to store and protect any amount of data for a range of use cases, such as data lakes, websites, mobile applications, backup and restore, archive, enterprise applications, IoT devices, and big data analytics.

# How does the integration work?

If you are using self-hosting Appwrite, you can use the Amazon S3 integration for Appwrite Storage to store your files in an S3 bucket instead of on your local hard drive. This is beneficial if you expect large volumes of data or the need to have scalable data storage for your self-hosted Appwrite instance.

# How to implement

To implement the Amazon S3 provider for Appwrite Storage, there are several steps you must complete:

## Step 1: Sign up on AWS

First, you must [sign up for an AWS account](https://signin.aws.amazon.com/signup?request_type=register) and create an S3 bucket. Save the **Bucket name** and **AWS Region** mentioned here for later use.

![Create S3 bucket](/images/integrations/storage-s3/bucket.avif)

To access this bucket from Appwrite Storage, we also need to generate an access key within our AWS account. To do so, click on your username in the top-right corner, click on **Security credentials**, and scroll below to create an access key. Save your **Access key ID** and **Secret access key** for later usage.

![Create AWS access key](/images/integrations/storage-s3/access-key.avif)

## Step 2: Add S3 provider to your Appwrite instance

For this step, you must [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already.

Visit the `.env` file created for your Appwrite instance and update the following environment variables:

```bash
_APP_STORAGE_DEVICE=s3
_APP_STORAGE_S3_BUCKET=<BUCKET_NAME>
_APP_STORAGE_S3_REGION=<AWS_REGION>
_APP_STORAGE_S3_ACCESS_KEY=<ACCESS_KEY_ID>
_APP_STORAGE_S3_SECRET=<SECRET_ACCESS_KEY>
```

Starting from version 1.7.0, you can also use S3-compatible providers by configuring the endpoint:

```bash
# For path-style requests (bucket in the path)
_APP_STORAGE_S3_ENDPOINT=<PROVIDER_ENDPOINT> # e.g., s3.amazonaws.com (without https://)
_APP_STORAGE_S3_BUCKET=<BUCKET_NAME> # bucket name required here

# OR for virtual-hosted-style paths (bucket in the endpoint)
_APP_STORAGE_S3_ENDPOINT=<BUCKET_NAME>.<PROVIDER_ENDPOINT> # e.g., bucket-name.s3.amazonaws.com
_APP_STORAGE_S3_BUCKET= # leave this empty when bucket is in the endpoint
```

After that, run the following Docker Compose commands in your terminal to restart your Appwrite containers and verify if the changes have been successfully applied:

```bash
docker compose up -d --force-recreate
docker compose exec appwrite vars
```

## Step 3: Test the provider

To test your S3, open your Appwrite project, go to the Storage page, create a new bucket, and upload a file.

![File on Appwrite Storage](/images/integrations/storage-s3/appwrite-file.avif)

When you visit your S3 bucket via the AWS Management Console, you should see the same file uploaded there.

![File on Amazon S3](/images/integrations/storage-s3/s3-file.avif)

# Read more about S3 and Appwrite Storage

If you would like to learn more about Amazon S3 and Appwrite Auth, we have some resources that you should visit:

- [What is Amazon S3?](https://docs.aws.amazon.com/AmazonS3/latest/userguide/Welcome.html)
- [How to create an Amazon S3 bucket](https://docs.aws.amazon.com/AmazonS3/latest/userguide/creating-bucket.html)
- [Appwrite self-hosting docs](https://appwrite.io/docs/advanced/self-hosting)
- [Storage environment variables for Appwrite (self-hosted)](https://appwrite.io/docs/advanced/self-hosting/environment-variables#storage)
- [Appwrite Storage API reference](https://appwrite.io/docs/references/cloud/client-web/storage)