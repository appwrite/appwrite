---
layout: post
title: "Faster and safer Functions development in your local environment"
description: You can now develop and test functions on your local machine.
date: 2024-08-19
cover: /images/blog/init-day0/cover.avif
timeToRead: 5
author: matej-baco
category: init, announcement, product
featured: false
faqs:
  - question: "How do I run an Appwrite Function locally?"
    answer: "Install the [Appwrite CLI](/docs/tooling/command-line), authenticate with `appwrite login`, link your project with `appwrite init project`, and pull or create a function. Then run `appwrite run function` and pick the function to launch. It listens on `http://localhost:3000` by default."
  - question: "What does local development need installed?"
    answer: "You need the latest Appwrite CLI and Docker installed. The CLI uses Docker to replicate the production runtime exactly, which means bugs you see locally also reproduce in production. The CLI prompts you to install Docker if it isn't already available."
  - question: "How does hot reload work for local functions?"
    answer: "While `appwrite run function` is running, the CLI watches your function's folder and performs a hot swap when source files change, usually in under a second. If you change package manager files like `package.json`, the CLI triggers a rebuild automatically. Disable this with `--no-reload` if you prefer manual restarts."
  - question: "Can I impersonate a user when testing locally?"
    answer: "Yes, pass `--user-id=\"<USER_ID>\"` to `appwrite run function` and the CLI injects the same `x-appwrite-user-id` and `x-appwrite-user-jwt` headers your function gets in production. This lets you test user-scoped permissions and JWT flows without touching your code. See the [Appwrite Functions docs](/docs/products/functions) for context details."
  - question: "How do I use local Appwrite Functions in CI/CD?"
    answer: "Install the Appwrite CLI in your pipeline, then run the same `appwrite run function` command with `--function-id` and `&` to background it. Once the function is listening on its port, your tests can hit it like any HTTP endpoint. This gives you integration tests that run inside the real function runtime."
  - question: "Should I pull production variables into a local function?"
    answer: "You can with `--with-variables`, but be careful since this pulls real production secrets. Prefer a local `.env` file with development-only values for most testing, and only pull production variables when you specifically need to reproduce a production-only bug."
---
We are excited to announce a new addition to Appwrite Functions that makes function development faster and more enjoyable. With local development, the entire flow, including coding, testing, and debugging, becomes fast and reliable.

We have always been committed to making your experience with Appwrite the best it can be, and local development aims to address community feature requests and enhancements from the past couple of months.

Let’s examine what’s possible with new local development and how you and your team can benefit from it.

# Differences between production, staging, and development

It’s very common to have two separate Appwrite projects: one for your production application and one for the staging environment. In your staging, you can safely apply your deployment changes to ensure stability after your latest changes.

Whether you work alone or in a team, you need a separate project for each branch of features you work on. Functions' source code and settings are properly version-controlled, but you still need to go through the time-consuming process of project creation each time, leaving you with a lot of clutter.

Having many development projects often leads to increased resource usage, quickly depleting your Cloud plan limits.

Deploying every small change also leaves you with a lot of waiting time as Appwrite builds your function for production use with every deployment. While a few additional minutes on your production isn’t critical, when it comes to development, every second counts.

The new local development feature allows you to run your functions directly on your machine, resulting in a faster and more cost-effective development environment.

# Run your first Appwrite Function locally

Running functions locally has many benefits. Local development allows faster debugging, a quicker feedback loop, and a better overall experience. When running your function locally during development, you are more likely to test it every step of the way instead of shipping multiple big changes all at once.

Let’s take a look at how you can create a new function (or pull an existing one) and start it locally on your machine.

## Project and Function preparations

To run your Functions locally, you will need the latest version of [Appwrite CLI](https://appwrite.io/docs/tooling/command-line/installation) installed on your machine. If this is your first time using Appwrite CLI, you also need to authenticate with the following command.

```bash
appwrite login
```

You also need to link to a project with the below command.

```bash
appwrite init project
```

If any of that isn’t familiar to you, you can follow our [CLI documentation](https://appwrite.io/docs/tooling/command-line/deployment) to learn more.

Before running Functions locally, you need the function’s source code pulled to your directory. To achieve that, you can create a new function locally.

```bash
appwrite init function
```

Alternatively, if you want to develop your existing function locally, you can pull its code by running the following command.

```bash
appwrite pull functions
```

## Local development using CLI

Let's run the function locally. To locally develop your Function, you can use the following command.

```bash
appwrite run function
```

You will be prompted to select which function you would like to run. Keep in mind that you can run multiple at the same time from different terminal windows, and each will start on a different port.

## Environment variables during local development

When local development starts, it looks for a `.env` file in your functions directory. If it’s present, all variables from this file will be loaded, and you can access them in your code. You can additionally use the `--with-variables` parameter to also pull variables from your function settings on Cloud, but use this parameter with caution as you might be giving production secrets to your function running locally.

## Build step to ensure dependencies

If this is your first time running functions locally, CLI will ensure you have [Docker](https://www.docker.com/) installed and provide you with instructions if not. The first run will also pull the image necessary to run the function in the correct runtime. Local development uses Docker to precisely replicate the production environment and let you investigate bugs that might not be able to replicate otherwise.

Next, your function will be built to ensure all its dependencies are installed properly and with the right versions. If you are developing in a compiled language such as Dart, this step also compiles the code.

## Start the function and listen on a port

Finally, the command starts your function, which will listen on `http://localhost:3000`. If this port is occupied by another function or a fronted app you might also be running locally, Appwrite CLI will look for the nearest available port and spin up the function on ports `3001`, `3002`, and so forth. If you don't like this behavior, you can provide parameter `--port=8080` to specify any port for the function to listen on.

Opening the URL in a browser or HTTP client will execute your function. If you started a brand new function, you should see a sample JSON response.

![Local-host](/images/blog/init-day0/1.avif)

## Make changes to your Appwrite Function

From the previous section, you have a Function up and running locally. It’s actively watching your function’s folder and will react to any changes you make. To see that in action, you can change the code in the entrypoint of your function. If you started a brand new Node.js function, you can find the `src/index.js` file in your function’s directory. To apply some changes, you could add `log('A brand new log')` in the function’s code.

As you save your changes, you can notice in your terminal that a locally running function reacts to those changes and performs a hot swap of affected files. This action usually takes less than a second, and the function is restarted to run the latest version of your code. This experience may differ in compiled languages like Dart, as the compilation step re-runs as well.

In some scenarios, such as adding a new dependency to your code, you need to rebuild your function. You could stop your function by pressing CTRL+C (or Command+C on MacOS) and start it again, but that can be time-consuming. Local development automatically detects changes to files used by the package manager (such as `package.json` in Node), and any change to those files will perform a rebuild step automatically.

If automatic change detection doesn't fit your typical development behavior, you can easily disable it by using the `--no-reload` parameter when starting your function.

Once you are satisfied with your function and the changes you made, you can run the `appwrite push function` to push your code to the Cloud. This will deploy your function to production, and you can use it in your application.

## Test user-specific features in Appwrite Functions

When a specific Appwrite User executes your Appwrite Function, you are automatically provided with headers `x-appwrite-user-id` and `x-appwrite-user-jwt`. Using those, you can authorize as a user inside the Function and have all permission checks and abuse protection in place when talking to other services such as Databases or Storage.

Since the user’s JWT token is short-lived and needs to be generated with every execution, there isn’t any easy way to impersonate a specific user in your code when testing or developing your function. New local development in Appwrite CLI allows you to do exactly that without having to change a single line of your code.

You can pass the `--user-id="[YOUR_ID]"` parameter when running the `appwrite run function`, and Appwrite will do all the necessary logic to provide your function with the very same header you get when running your function on production. This not only makes it easy to run functions that rely on the user’s JWT locally, but it also makes it very easy to switch between different users to test all the flows your function may have.

# Test your Appwrite Functions in CI/CD

Once you publish your application to production, you are likely to set up continuous integration to ensure new updates don’t break existing logic. With just a few additions, Appwrite Functions can also be tested in your CI/CD process.

After installing Appwrite CLI in the preparation step of your test, you can run the exact same command you use for the local development, `appwrite run function`. Make sure to provide the ID of the function you want to test by using the `--function-id="[YOUR_ID]"` parameter. Also ensure you add `&` at the end of your command, to run it in the background. If you don't, your CI/CD step may get frozen. Following is an example of a command used in your CI/CD.

```jsx
appwrite run function --port 3000 --function-id="invoices-generator" &
```

If you noticed, we did not authenticate with `appwrite login` command. This step is not required in your test step, but you might need to do it in your deployment step if you automated that too. All commands are compatible with CI/CD and can run non-interactively. You can always add `--help` at the end of the command to see all possible parameters you can provide to prevent interaction.

Before your test can continue, it needs to wait a little while for the function to build and start. You can achieve this with a small bash script that loops until the port becomes open. Alternatively, a very simple `sleep` could do the job. To prevent flaky tests, make sure the sleep time is high enough that your function is always ready.

Once the Appwrite Function is running in your test step, you can use any test framework to run tests against the endpoints of your Function. Some of the most famous test frameworks are [Jest](https://jestjs.io/), [Mocha](https://mochajs.org/), [PHPUnit](https://www.notion.so/0ede37ea641048cca1a623c322604c9e?pvs=21), and others. It’s usually recommended to pick a test framework written in the same language as your Function to prevent a knowledge gap in your team.

In case you need to mock a behavior in the test environment, you can define your own variable `APP_ENV=testing` in the `.env` file, and in your code, skip some logic based on the value in this environment variable. If your function is adapter-driven, you could define a mock adapter as well to achieve the same.

If you need to look at the logs of your Appwrite Function in your CI/CD environment, you can print the content of the `.appwrite/logs.txt` and `.appwrite/errors.txt` files, which can be found inside your function’s directory. You may or may not see those when running locally, as they get cleaned up when you stop the local development command.

# Conclusion

Local development of Appwrite Functions brings many benefits. A simpler and less time-consuming setup ensures proper development experience. Local development with hot-swapping capabilities makes development quick, and CI/CD support makes your Appwrite Function reliable. All the tiny features and parameters make the local development customizable to fit your style.

Try out the local development and improve your experience with Appwrite. To learn more, check out these resources:

- [Functions docs](https://appwrite.io/docs/products/functions)
- [Appwrite CLI docs](https://appwrite.io/docs/tooling/command-line/installation)
- [Discord server](https://appwrite.io/discord)
- [More about Init](https://appwrite.io/init)
