---
layout: post
title: "Local serverless function development with the new Appwrite CLI"
description: Your guide to creating, testing, and maintaining Appwrite functions locally.
date: 2024-08-19
cover: /images/blog/local-development-guide/localdev-cover.avif
timeToRead: 5
author: ebenezer-don
category: product
featured: false
faqs:
  - question: "Can I develop Appwrite Functions locally without deploying first?"
    answer: "Yes. The Appwrite CLI supports local development for functions, so you can write, run, and debug them on your machine before deploying. This avoids the slow cycle of pushing to the cloud just to see if a small change works. See [Appwrite Functions](/docs/products/functions) for the product overview."
  - question: "What do I need installed to run Appwrite Functions locally?"
    answer: "You need the latest Appwrite CLI and Docker. The CLI uses Docker to run the function in the same runtime that Appwrite Cloud uses, so behavior matches production. Without Docker the `appwrite run function` command will not work."
  - question: "How do I install the Appwrite CLI?"
    answer: "Run `npm install -g appwrite-cli@latest`. After installation, log in with `appwrite login` and verify with `appwrite whoami`. You only need to do this once per machine."
  - question: "Which runtimes does the Appwrite CLI support for local development?"
    answer: "The CLI supports Node.js, Python, PHP, Ruby, Dart, and others. When you run `appwrite init functions`, the CLI prompts you to choose a runtime from the list of supported options. Local execution mirrors the same Docker images used in production."
  - question: "How do I deploy a function after testing it locally?"
    answer: "Run `appwrite push functions` from your project directory to push the function to your Appwrite project. The CLI reads your `appwrite.config.json` to know which function to deploy and where to send it. You can also use Git based deploys if your function lives in a connected repository."
  - question: "Can I use environment variables when developing functions locally?"
    answer: "Yes. The CLI reads variables from your `appwrite.config.json` and any `.env` files you configure, then injects them when the function runs. This lets you use the same variable names locally as in production without code changes."
---
What if you could develop serverless functions quickly and effectively without the need for constant cloud deployment? If you've been stuck waiting for deployments or struggling with limited offline capabilities, there's good news. The new Appwrite CLI provides a seamless local development experience for serverless functions, enabling you to develop, test, and iterate rapidly on your functions directly from your local machine.

In this guide, we'll walk through setting up and developing Appwrite Functions locally using the new Appwrite CLI.

# Why local development?

Local development offers several advantages when working with serverless functions:

1. **Faster iteration:** Developing functions locally allows you to test changes quickly without the need to deploy them to a live environment. This speeds up the development process and enables rapid iteration.
2. **Offline development:** With local development, you can work on your functions even when you're offline. This is especially useful when you're traveling or in areas with limited internet connectivity.
3. **Debugging:** Local development provides better debugging capabilities, allowing you to troubleshoot issues more effectively before deploying your functions to a live environment. This can help you avoid catastrophic errors by identifying and fixing problems early, without the risk of affecting your live application.
4. **Cost efficiency:** Running functions locally helps keep development setup costs low, as you don't need to continuously deploy functions to a cloud environment for testing.

Let's see how to set up and develop Appwrite Functions locally using the new CLI which we’ll reveal more of tomorrow.

# Installing Appwrite CLI

Start by installing the latest version of the Appwrite CLI:

```bash
npm install -g appwrite-cli@latest

```

# Setting up your project

Before developing functions, you need to set up a project. First, log in to your Appwrite account using the CLI:

```bash
appwrite login
```

If you’ve already worked with the Appwrite CLI, you can use `appwrite whoami` to check if you are signed in. This command will display your account details.

Next, initialize your project in the directory where you want to develop your function:

```bash
appwrite init project
```

You will be prompted with options to either create a new project or link to an existing one:

```bash
How would you like to start? (Use arrow keys)
❯ Create new project
  Link directory to an existing project
```

If you choose "Create new project," you'll need to select your organization, name your project, and give it an ID.

# Initializing functions

With your project set up, you can now initialize your functions. Run the following command:

```bash
appwrite init functions
```

You'll be asked to provide details about your function like its name, ID, and runtime:

```bash
? What would you like to name your function? New Awesome Function
? What ID would you like to have for your function? new-awesome-function
? What runtime would you like to use? (Use arrow keys)
❯ Node.js (node-16.0)
  Node.js (node-18.0)
  PHP (php-8.0)
  Ruby (ruby-3.0)
  Python (python-3.9)
  Python (ML) (python-ml-3.11)
  Dart (dart-2.17)

```

The CLI will generate the function's code in a directory within your project. This directory will be named after the function name you provided. You can open it to see the source code and start developing your function.

Appwrite commands need your appwrite.config.json file to work, which is located in the root directory of your project. However, if you run Appwrite commands from the function directory, the CLI will automatically look for the `appwrite.config.json` file in the parent directories. So, commands from the function directory should work without any issues.

This way, you don't have to worry about switching directories when you need to do commands like `npm install` (which needs to be done in the function directory) or `appwrite deploy` (which needs the `appwrite.config.json` file).

# Developing locally

You can start developing and testing your function locally. To do this, ensure you have Docker installed on your system. You can download Docker from [here](https://www.docker.com/products/docker-desktop).

Next, run the following command to start your function locally:

```bash
appwrite run function
```

You'll be prompted to select the function you want to run locally:

```bash
appwrite run function
? Which function would you like to develop locally?

```

To avoid this question repeatedly, you can specify the function id directly:

```bash
appwrite run function --function-id=YOUR_FUNCTION_ID
```

Select the function you want to run, and the CLI will start the local server. You'll see a message like this:

```bash
Build finished.
ℹ Info Starting function using Docker ...
ℹ Info Permissions, events, CRON and timeouts don't apply when running locally.
ℹ Info 💡 Hint: Function automatically restarts when you edit your code.
✓ Success Visit http://localhost:3000/ to execute your function.

```

By default, the server runs on port 3000, but it will use the first available port above that if 3000 is occupied. You can customize the port using the `--port` flag:

```bash
appwrite run function --port=4000
```

The command will keep running, watching for any code changes and restarting the function accordingly. If you want to prevent this behavior, you can run:

```bash
appwrite run function --no-reload
```

# Environment variables

By default, environment variables from your remote instance won't be loaded during local development. This is to prevent any unintended usage of remote configuration. To include these variables, use the `--with-variables` flag:

```bash
appwrite run function --with-variables
```

Additionally, environment variables can also be read from a `.env` file in your function folder during local development.

# Impersonating users

You can impersonate a user during local function executions to automatically set the correct values for `x-appwrite-user-id` and `x-appwrite-user-jwt` headers:

```bash
appwrite run function --user-id="YOUR_USER_ID"
```

# Conclusion

Local development with the new Appwrite CLI provides a seamless experience for developing and testing serverless functions. By following the steps outlined in this guide, you can set up your project, initialize functions, and start developing locally with ease. This approach allows you to iterate quickly, debug effectively, and develop functions offline, enhancing your overall development workflow.

# More resources:

- [Appwrite Functions Documentation](https://appwrite.io/docs/functions)
- [Join the Appwrite Community on Discord](https://appwrite.io/discord)
- [More about Init](https://appwrite.io/init)
