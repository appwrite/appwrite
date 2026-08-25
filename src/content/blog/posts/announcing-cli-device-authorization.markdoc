---
layout: post
title: "Announcing OAuth Device Authorization for the Appwrite CLI: Log in from your browser"
description: The Appwrite CLI now signs you in through your browser. Confirm a device code, review permissions, and authorize, without typing credentials into your terminal.
date: 2026-07-29
cover: /images/blog/announcing-cli-device-authorization/cover.avif
timeToRead: 4
author: chirag-aggarwal
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is OAuth Device Authorization in the Appwrite CLI?"
    answer: "OAuth Device Authorization is the new login flow for the Appwrite CLI, based on the OAuth 2.0 device authorization pattern. Instead of prompting for your credentials in the terminal, the CLI shows a code and a URL. You confirm the code in your browser, review the requested permissions, and authorize the CLI with your Appwrite account."
  - question: "Which command starts the new login flow?"
    answer: "The same command as before: `appwrite login`. If you use a self-hosted instance, add the `--endpoint` flag with your instance URL. The only thing that changes is what happens after you run it."
  - question: "What permissions does the Appwrite CLI request?"
    answer: "The authorization screen lists the exact access you grant: viewing your identity and fully managing your Appwrite account on your behalf. You review and approve these permissions in the browser before the CLI can act."
  - question: "How do I revoke the CLI's access to my account?"
    answer: "You can revoke access anytime from your Appwrite account settings. Once revoked, the CLI can no longer act on your behalf until you authorize it again."
  - question: "Does the new login flow affect CI pipelines?"
    answer: "No. Non-interactive mode, which authenticates with an API key through `appwrite client`, is unchanged and remains the recommended approach for CI and scripting."
---

Signing in to a CLI should not mean typing your account password into a terminal prompt. Your identity already lives in your browser, and that is where authentication should happen.

Today, we are announcing **OAuth Device Authorization** for the Appwrite CLI. Running `appwrite login` now hands the sign-in process to your browser, so you can authenticate the CLI with the account session you already trust.

# How it works

The new flow follows the OAuth 2.0 device authorization pattern. Run `appwrite login`, and the CLI displays a device code and a URL, then waits for approval.

![The Appwrite CLI displaying a device code and URL](/images/docs/command-line/cli-login-terminal.avif)

Press Enter to open the URL, or copy it into any browser. Confirm the code on the page matches the one in your terminal and click **Continue**.

![Confirming the device code in the browser](/images/docs/command-line/dark/cli-login-confirm-code.avif)

Review the permissions the CLI requests and click **Authorize**. Once approved, the CLI detects the authorization and finishes signing you in.

![Authorizing the Appwrite CLI in the Console](/images/docs/command-line/dark/cli-login-authorize.avif)

# Consent you can see

Before the CLI can act on your behalf, the browser shows you exactly what access you are granting: viewing your identity and fully managing your Appwrite account.

Authorization is also reversible. You can revoke the CLI's access anytime from your Appwrite account settings.

# Get started

Update the Appwrite CLI to the latest version and run:

```sh
appwrite login
```

The [CLI installation guide](/docs/tooling/command-line/installation#login) walks through every step with screenshots.

{% info title="Seeing an email and password prompt instead?" %}
The CLI either needs an update or is pointed at a self-hosted Appwrite instance, which does not support the OAuth device authorization flow. Run `appwrite update` to get the latest version, or log in with the Appwrite Cloud endpoint explicitly:

```sh
appwrite login --endpoint="https://cloud.appwrite.io/v1"
```

If a self-hosted endpoint persists, delete `prefs.json` from the CLI config directory. On macOS and Linux it lives at `~/.appwrite/prefs.json`, on Windows at `%USERPROFILE%\.appwrite\prefs.json`.
{% /info %}

{% info title="Existing authentication flows" %}
API key based authentication is not affected by this change. Existing CLI sessions and non-interactive flows that authenticate with an API key through `appwrite client` will continue to work, and API keys remain the recommended way to authenticate the CLI in CI workflows. [Learn more about non-interactive mode](/docs/tooling/command-line/non-interactive)
{% /info %}

# More resources

- [Introducing generate command in the Appwrite CLI: Create a type-safe SDK from your schema](/blog/post/appwrite-generate)
- [Announcing the Projects API: Configure your project with Server SDKs](/blog/post/announcing-projects-api)
- [Announcing the Keys API: Create and manage API keys with Server SDKs](/blog/post/announcing-api-keys-api)
- [Join the Appwrite Community on Discord](https://appwrite.io/discord)
