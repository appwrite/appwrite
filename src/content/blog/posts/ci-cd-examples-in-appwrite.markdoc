---
layout: post
title: "CI/CD examples in Appwrite CLI"
description: Explore the brand-new Appwrite CLI features in detail and take a look at some CI/CD examples.
date: 2024-08-20
lastUpdated: 2026-06-29
cover: /images/blog/cdi-cover.avif
timeToRead: 5
author: ebenezer-don
category: product
featured: false
faqs:
  - question: "Can I use the Appwrite CLI in a CI/CD pipeline?"
    answer: "Yes, the CLI is built to run non-interactively in pipelines. Flags like `--force` skip confirmation prompts and `--all` pushes or pulls entire resource sets in one go. Combined with environment variables for project ID and API key, you can drive deployments from GitHub Actions, GitLab CI, or any other runner."
  - question: "What does the `--force` flag do in the Appwrite CLI?"
    answer: "It pre-answers any confirmation prompt with yes, so commands like `appwrite push tables` run end-to-end without waiting for input. This is required for CI runs because there is no terminal to type into. Use it on commands you have already validated locally."
  - question: "What is the difference between `appwrite push` and `appwrite pull`?"
    answer: "`push` sends your local resource definitions, like tables and functions, to the Appwrite server, while `pull` does the reverse and downloads the current state into local files. A typical workflow pulls in development to grab manual console changes, edits locally, then pushes to staging or production through CI."
  - question: "How do I authenticate the Appwrite CLI in CI?"
    answer: "Set the `APPWRITE_ENDPOINT`, `APPWRITE_PROJECT_ID`, and `APPWRITE_API_KEY` environment variables, then run `appwrite login --endpoint` or use the project-scoped commands directly. Store the API key as a secret in your CI provider, never in the repo. See [Appwrite Functions](/docs/products/functions) for how API keys interact with function deployments."
  - question: "Can I deploy Appwrite Functions from a Git workflow?"
    answer: "Yes. You can either use the Git integration that ships with Appwrite Functions, which auto-deploys on push to a configured branch, or you can use the CLI's `appwrite deploy function` in your own workflow. Both work, the right pick depends on whether you want Appwrite to drive the pipeline or your own CI."
  - question: "How should I structure environments for staging and production?"
    answer: "Keep separate Appwrite projects per environment so credentials and data are fully isolated. Use the same set of table and function definitions checked into your repo, and let CI run `appwrite push` against the right project based on branch. This avoids accidental writes against production from a feature branch."
---
The Appwrite CLI has undergone significant updates to enhance support for Continuous Integration and Continuous Deployment (CI/CD) pipelines. These changes make it easier to automate deployment processes and ensure robust, non-interactive actions. In this article, we’ll explore the new additions, the benefits of these enhancements for CI/CD pipelines, and best practices for using the Appwrite CLI in your workflows.

# Key features

## Improved non-interactive commands

To support CI/CD pipelines, the Appwrite CLI now fully accommodates non-interactive actions for all commands. This is important for CI/CD pipelines, where manual intervention needs to be minimized.

### The `--force` flag

You can use the `--force` flag with any command that typically prompts for user confirmation, such as `appwrite push tables`. This flag pre-answers all prompts with "YES," allowing the command to run without interruption.

```bash
appwrite push tables --force

```

### The `--all` flag

The `--all` flag can be used to push or pull all available resources for services. This flag is designed to simplify bulk operations, making it easier to manage multiple resources at once in your CI/CD pipelines.

```bash
appwrite pull tables --all

```

In a CI/CD pipeline, this could be part of a deployment process:

```yaml
name: Sync Tables

on:
	release:
		types: [ published ]

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v2
      - name: Install Appwrite CLI
        run: npm install -g appwrite-cli
      - name: Pull Tables
        run: appwrite pull tables --all --force
        env:
          PROJECT_ID: ${{ secrets.APPWRITE_PROJECT_ID }}
          API_KEY: ${{ secrets.APPWRITE_API_KEY }}

```

### Pushing a single resource

You can also push a single resource by specifying its type. This allows you to manage individual resources more precisely:

Push a function:

```bash
appwrite push function --function-id="function_id"

```

Push a table:

```bash
appwrite push table
```

## Headless login

Previously, the Appwrite CLI supported non-interactive login using API keys for authorization. This was done using the following command:

```bash
appwrite client --endpoint "https://<YOUR-ENDPOINT>/v1" --project-id "<YOUR-PROJECT-ID>" --key "<YOUR-API-KEY>"

```

While this method is effective for many automated workflows, it has limitations, particularly when user-level permissions or actions are required. To address this, the latest version introduces a headless login feature. This new feature allows you to log in and execute commands from the user level without requiring interactive input during the login process:

```bash
appwrite login --email user@appwrite.com --password password

```

If you have Multi-Factor Authentication (MFA) enabled, you can use the `--mfa` parameter. You can use external tools and libraries to generate an MFA code from the MFA secret, which you can pass into the automation as an environment variable:

```bash
appwrite login --email user@appwrite.com --password password --mfa MFA_CODE
```

Here’s how you might integrate headless login into a Jenkins pipeline:

```groovy
pipeline {
    agent any
    environment {
        APPWRITE_EMAIL = credentials('appwrite-email')
        APPWRITE_PASSWORD = credentials('appwrite-password')
        APPWRITE_ENDPOINT = credentials('appwrite-endpoint')
    }
    stages {
        stage('Login to Appwrite') {
            steps {
                sh 'appwrite login --email $APPWRITE_EMAIL --password $APPWRITE_PASSWORD --endpoint $APPWRITE_ENDPOINT'
            }
        }
        stage('Push Tables') {
            steps {
                sh 'appwrite push tables --all --force'
            }
        }
    }
}

```

# Benefits of the enhanced CLI for CI/CD

- **Streamlined deployment**

Adapting CI/CD pipelines with these new features ensures that deployments are streamlined and automated, reducing the risk of human error and increasing deployment speed.

- **Full automation**

Using flags like `--force` and `--all` means that all necessary commands can be executed automatically, fitting seamlessly into your CI/CD workflows.

- **User-level command execution**

The headless login feature extends the flexibility of the Appwrite CLI by allowing user-level command execution, which is useful for scenarios where user-specific actions are required.

# Best practices for using Appwrite CLI in CI/CD

To ensure the best results when using the Appwrite CLI in your CI/CD pipelines, it’s important to follow these best practices:

1. **Secure credentials management:** Use environment variables or secret management features in your CI/CD platform to store sensitive information like API keys, emails, and passwords securely.
2. **Backup data:** Always back up your current database before starting a migration process. This ensures you can restore your data if something goes wrong.
3. **Test in a staging environment:** Implement your changes in a staging environment first to identify any potential issues without affecting your production data.
4. **Synchronize project settings:** Use the `appwrite pull all` command to synchronize your `appwrite.config.json` file with all settings and schemas from your project. This ensures that your local configuration matches the server configuration.
5. **Version control:** Use version control (e.g., Git) to track changes to your schema and configuration files. This allows you to roll back changes if needed.
6. **Monitor performance:** Monitor the performance of your CI/CD pipelines to identify bottlenecks or issues that may impact deployment speed.

# Conclusion

The latest updates to the Appwrite CLI make it much easier to use in CI/CD pipelines. Now, you can run deployment commands without any prompts, handle bulk operations more smoothly, and log in without a user interface. By following the recommended best practices and adding these features to your CI/CD workflows, you can make your deployment process more efficient and secure, ensuring a smoother development lifecycle.

# More resources:

- [Appwrite CLI commands](https://appwrite.io/docs/tooling/command-line/commands)
- [Appwrite non-interactive docs](https://appwrite.io/docs/tooling/command-line/non-interactive)
