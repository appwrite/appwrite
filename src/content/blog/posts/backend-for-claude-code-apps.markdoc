---
layout: post
title: "How to add a backend to apps built with Claude Code"
description: Learn how to give apps built with Claude Code a reliable backend with Appwrite. Create a project, set up an API key, install the Appwrite Claude Code plugin, and let your agent build authentication, databases, and storage for you.
date: 2026-04-25
cover: /images/blog/backend-for-claude-code-apps/cover.avif
timeToRead: 10
author: atharva
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "How do I add a backend to apps I build with Claude Code?"
    answer: "Install the Appwrite plugin for Claude Code, then point it at an Appwrite project using an API key. From there, Claude Code can create auth flows, database schemas, storage buckets, and functions for you via the Appwrite MCP server."
  - question: "What does the Appwrite plugin for Claude Code include?"
    answer: "It bundles the Appwrite API MCP server, the Appwrite Docs MCP server, and eleven agent skills covering the Appwrite CLI and all major SDKs. The skills load on demand whenever Claude Code is writing Appwrite-related code."
  - question: "What scopes should I give my Appwrite API key for Claude Code?"
    answer: "Grant only the scopes Claude Code needs for the immediate task: auth scopes when setting up authentication, database scopes when building a schema, and so on. Avoid selecting all scopes, and set a short expiration so a leaked key doesn't stay dangerous forever."
  - question: "Do I need to self-host Appwrite to use it with Claude Code?"
    answer: "No. [Appwrite Cloud](https://cloud.appwrite.io) works out of the box with Claude Code. Self-hosting is fine too, you just point the MCP server at your own endpoint instead of the Cloud URL."
  - question: "Why not just have Claude Code build a custom backend from scratch?"
    answer: "LLMs can now probe and exploit hand-rolled backends autonomously, so unmaintained custom code is a liability. A platform that gets patched as new attack classes emerge is safer than bespoke auth and storage code that nobody is paid to keep current."
  - question: "Can Claude Code build full-stack apps with Appwrite?"
    answer: "Yes. With the plugin installed, Claude Code can scaffold the frontend in your framework of choice and wire it up to [Appwrite Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), and [Functions](/docs/products/functions) in the same session. You then deploy to [Appwrite Sites](/docs/products/sites) or any host you prefer."
---

Claude Code is at its best when it is building working software, not prototypes. You describe a feature, it edits files, runs commands, and iterates until the app actually behaves the way you asked for. That loop holds up well for the frontend. It falls apart the moment your app needs to remember anything: a user, a session, a saved note, an uploaded file.

That is the gap a backend fills, and it is the part most Claude Code tutorials skip. It is also the part that matters most for security. LLMs are now capable of probing and exploiting software on their own, which means a hand-rolled backend written once and forgotten is a liability. Your app needs a backend that is actively maintained, with auth, permissions, and data access handled by a product that gets patched as new classes of attack emerge, rather than by custom code that no one is paid to keep current.

This guide walks through that setup end to end with Appwrite: creating a project, issuing an API key for the MCP server, installing the Appwrite plugin for Claude Code, and then letting your agent wire up authentication, databases, and storage on top of a backend that is built and maintained for production.

# A reliable backend for your apps

Appwrite is an open-source, all-in-one development platform that gives your app its backend infrastructure and web hosting from a single place. One project ties together Auth, Databases, Storage, Functions, Realtime, Messaging, and Hosting, so you stop wiring separate services into every new app. It runs on Appwrite Cloud or self-hosted, works with any framework, and fits cleanly into a Claude Code workflow where the agent handles the glue.

# What you will set up

By the end of this guide, your Claude Code session will be connected to:

- **An Appwrite project** that holds your users, data, and files.
- **The Appwrite API MCP server**, which lets Claude Code create and manage resources in that project directly from a prompt.
- **The Appwrite Docs MCP server**, which gives the agent live access to Appwrite's current documentation instead of whatever it was trained on.
- **Eleven agent skills** covering the Appwrite CLI and every major SDK, loaded on demand when Claude Code is writing backend code.

The Claude Code plugin bundles all of the above into a single install. The detailed install reference lives in the [Claude Code plugin docs](/docs/tooling/ai/ai-dev-tools/claude-code); this post is the end-to-end walkthrough.

# Prerequisites

- An [Appwrite Cloud](https://cloud.appwrite.io) account or a self-hosted Appwrite instance.
- [Claude Code](https://code.claude.com/docs/en/overview) installed and working in your terminal.
- A project you want to give a backend to. It does not need to be an Appwrite project yet.

# Step 1: Create an Appwrite project

Head to the Appwrite Console and create a new project. Give it a name that matches the app you are building, pick the region closest to your users, and open the project.

From the project's overview page, grab two values you will need in a moment:

- **Project ID**: visible in the URL and on the overview page.
- **API endpoint**: shown as **API endpoint** on the overview page. For Cloud, it looks like `https://<region>.cloud.appwrite.io/v1`.

Keep that tab open. The next step happens in the same project.

# Step 2: Create an API key for the MCP server

The Appwrite API MCP server authenticates to your project with a server-side API key. This is the credential that lets Claude Code read, write, and manage resources on your behalf, so it is worth setting up carefully.

In the Console, go to **Overview → Integrations → API keys** and click **Create API key**.

![Create API key dialog in the Appwrite Console](/images/docs/platform/dark/create-api-key.avif)

Fill in the dialog:

| Field | Value |
| --- | --- |
| Name | Any name you can recognise later. `Claude Code MCP` is a reasonable default. |
| Expiration date | Set a short expiration date. Avoid `Never`; a key that cannot expire is a key that eventually leaks and stays dangerous. Pick the shortest window that gets you through your current work and rotate the key when it ends. |
| Scopes | Grant only the scopes Claude Code actually needs for the task in front of you. If you are setting up authentication, that is the relevant scopes under **Auth**. If you are building a database schema, that is the relevant scopes under **Database**. Start minimal and add more later when a specific task requires it. Do not select all scopes. |

Click **Create**. On the key's page, reveal the **API secret** and copy it. Treat the secret like any other production credential: do not paste it into a chat, a public repo, or a screenshot.

You now have the three values the MCP server needs: endpoint, project ID, and API key.

# Step 3: Install the Appwrite plugin for Claude Code

The plugin ships the Appwrite API MCP server, the Appwrite Docs MCP server, and the agent skills as one install. Run these two commands in your terminal:

```bash
claude plugins marketplace add appwrite/claude-plugin
claude plugins install appwrite@appwrite
```

Then start Claude Code and configure the plugin from inside the session:

1. Run `/plugins`.
2. Open the **Installed** tab.
3. Select **Appwrite**.
4. Choose **Configure options**.
5. Enter the endpoint, project ID, and API key you collected in the previous step.
6. Run `/reload-plugins` to apply the configuration to the current session.

After the reload, run `/mcp` to confirm that `appwrite-api` and `appwrite-docs` are both listed. If either is missing, the [plugin docs](/docs/tooling/ai/ai-dev-tools/claude-code) cover the manual MCP setup as a fallback.

# What your agent can build now

With the MCP servers and skills loaded, Claude Code has enough context to set up the common backend building blocks for you. The rest of this post walks through the ones most apps need.

## Set up authentication

Authentication is usually the first thing an app built with Claude Code needs once it has a backend. Appwrite's Auth service handles sign-up, sign-in, sessions, OAuth, magic URLs, and password recovery.

A prompt like this is usually enough to get started:

```
Add email and password authentication to this app using Appwrite.
Use the TypeScript SDK on the client. Create a sign-up page, a sign-in
page, and a protected /dashboard route that redirects to sign-in when
there is no active session.
```

Claude Code will pull the relevant skill (TypeScript in this case), use the Docs MCP server to check the latest SDK patterns, and write the wiring directly into your project. If you also want OAuth, name the provider in the prompt like `Add Google OAuth sign-in` and the agent will walk through the provider setup in the Console for you.

For admin-side operations (listing users, creating users from a seed script, disabling accounts), the API MCP server lets the agent call the Users API directly without your writing a single cURL command. A prompt like `Create a test user with email test@demo.test` will create the user in your project in place.

## Create and query databases

The TablesDB service is where the rest of the app's state lives. You describe the shape of the data you want, and Claude Code can use the API MCP server to create the database, tables, and columns in your project without you touching the Console.

A prompt like:

```
Create a notes database in my Appwrite project. Add a `notes` table
with columns: title (text, required), content (text, required),
color (text, optional), and userId (text, required). Enable row
security so each note is scoped to its owner.
```

Results in the agent calling the API MCP server to:

1. Create the database.
2. Create the `notes` table.
3. Add each column with the correct type.
4. Turn on row-level security on the table.

Once the schema exists, you can keep going in the same session: `Write a function on the dashboard page that lists the current user's notes and lets them create a new one.` The agent will pick the right SDK skill, use the Docs MCP server to confirm the current query syntax, and add the client code.

## Upload and serve files

For attachments and any user-generated media, the Storage service gives you buckets with their own permissions and file-level rules. The agent can create a bucket and wire up uploads in one pass:

```
Create a storage bucket called `avatars` in my project with a 2MB file
size limit and permissions that let users upload their own avatar.
Then update the profile page to upload the selected file to that
bucket and display it.
```

The API MCP server handles the bucket creation; the TypeScript skill handles the client-side upload code.

## Run serverless functions

When a task needs to run on a trusted server (webhooks, scheduled jobs, anything that should not run in the browser), Appwrite Functions gives you a serverless runtime with the same identity and permissions model as the rest of the project.

Claude Code can scaffold a function from a prompt, deploy it with the Appwrite CLI skill, and hook it up to the event you care about:

```
Create an Appwrite Function in Node.js that runs every time a new user
signs up and sends them a welcome email via Resend. Use an environment
variable for the Resend API key.
```

The CLI skill knows the current `appwrite.json` layout and the `appwrite push functions` flow, so the deploy step works the first time more often than not.

# A workflow that holds up

The pattern that works best once the setup is done is to stop thinking about the backend as a separate project. You describe behavior like `users can save notes`, `admins can impersonate users`, or `uploads are scoped to the owner`, and the agent makes the changes in both places: Appwrite for the data and auth, your codebase for the UI and glue.

The MCP servers keep the two in sync. The skills keep the SDK code current. The API key keeps the agent scoped to exactly the project you pointed it at.

# Resources

- [Appwrite plugin for Claude Code (docs)](/docs/tooling/ai/ai-dev-tools/claude-code)
- [Appwrite API MCP server](/docs/tooling/mcp/api)
- [Appwrite Docs MCP server](/docs/tooling/mcp/docs)
- [Appwrite agent skills](/docs/tooling/ai/skills)
- [Plugin source on GitHub](https://github.com/appwrite/claude-plugin)
- [Appwrite Discord](https://appwrite.io/discord)
