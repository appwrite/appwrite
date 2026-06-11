---
layout: article
title: Templates
description: "Learn about Appwrite Sites' templates that let you jump start site development."
---

Appwrite provides a variety of Site Templates to help you jump-start your web app development.

# Find templates

You can find all available templates by navigating to the Appwrite Console under your project > **Sites** > **Templates**.

{% only_dark %}
![Site templates](/images/docs/sites/dark/site-templates.avif)
{% /only_dark %}
{% only_light %}
![Site templates](/images/docs/sites/site-templates.avif)
{% /only_light %}

You can filter sites by searching, filter by use case, or filter by framework. Click **Create site** to create a site from a template.

# Create with templates

The create site wizard for templates will include the following steps:

## Configure site details

Pick a display name for your site and an ID. You will later use the ID to programmatically configure the site.

## Setup a GitHub repo

You can choose to clone a new repository to your GitHub profile or organization or to connect to an existing repository.

If you choose to connect to an existing repository intead of creating a new one, you must specify the output folder of your build under the [Branch](#select-a-production-branch) step.

## Configure repository

Add the name of the repository you'd either like to create (if creating new repo) or add the source code to (if choosing existing repo).

The connected repository will hold the source code for your site. When the code in this repository is updated, new deployments will be created.

## Select production branch

Select the production branch, root directory, and the silent mode setting for the repo you selected.

The production branch specifies the branch connected to your Appwrite Site. When new commits are made to this branch, a new deployment is automatically created and deployed.

The root directory specifies the folder containing the source code of your your site.

When a PR is made to the branch, a new deployment is built and a preview URL becomes available. A comment is made to your PR about the build unless you enable **Silent mode**.

## Add required environment variables

Appwrite Sites uses environment variables to pass constants and secrets to your sites. You can provide information like API keys and other secrets in this step.

## Configure domain name

Configure the subdomain of the Appwrite Site to be deployed. Once deployment completes, your application will be available to access on this domain. You can also [add a custom domain](/docs/products/sites/domains#add-a-custom-domain) to this site later.