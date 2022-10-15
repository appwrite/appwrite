# Adding a new SMS provider 🛡

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting started

SMS providers help users to log in to the apps and websites without the need to provide passwords or any other type of credentials. Appwrite's goal is to have support from as many **major** SMS providers as possible.

## 1. Prerequisites

It's really easy to contribute to an open source project, but when using GitHub, there are a few steps we need to follow. This section will take you step-by-step through the process of preparing your own local version of Appwrite, where you can make any changes without affecting Appwrite right away.

> If you are experienced with GitHub or have made a pull request before, you can skip to [Implement new provider](#2-implement-new-provider).

### 1.1 Fork the Appwrite repository

Before making any changes, you will need to fork Appwrite's repository to keep branches on the official repo clean. To do that, visit the [Appwrite Github repository](https://github.com/appwrite/appwrite) and click on the fork button.

![Fork button](images/fork.png)

This will redirect you from `github.com/appwrite/appwrite` to `github.com/YOUR_USERNAME/appwrite`, meaning all changes you do are only done inside your repository. Once you are there, click the highlighted `Code` button, copy the URL and clone the repository to your computer using `git clone` command:

```shell
$ git clone COPIED_URL
```

> To fork a repository, you will need a basic understanding of CLI and git-cli binaries installed. If you are a beginner, we recommend you to use `Github Desktop`. It is a really clean and simple visual Git client.

Finally, you will need to create a `feat-XXX-YYY-sms` branch based on the `master` branch and switch to it. The `XXX` should represent the issue ID and `YYY` the SMS provider name.

## 2. Implement new provider

### 2.1 Add Provider Class

Create a new file `XXX.php` where `XXX` is the name of the SMS provider in [`PascalCase`](https://stackoverflow.com/a/41769355/7659504) in this location

```bash
src/Appwrite/Auth/SMS/Adapter/XXX.php
```

Inside this file, create a new class that extends the abstract [`Adapter`](/src/Appwrite/SMS/Adapter.php). Note that the class name should start with a capital letter, as PHP FIG standards suggest.

Once a new class is created, you can start to implement your new provider's `send()` method. Refer to the existing Adapters for guidance on how to implement your provider.

### 2.2 Update the Documentation

The `_APP_SMS_PROVIDER` environment variable determines which SMS provider is used. Our documentation should list the available providers. To update this, update the `description` of the `_APP_SMS_PROVIDER` variable in [/app/config/variables.php](/app/config/variables.php).

### 2.3 Update the Worker

The [Messaging Worker's](/app/workers/messaging.php) `init()` method checks the `_APP_SMS_PROVIDER` and instantiates the corresponding SMS Adapter. Update it to include your new provider.

### 2.3 Update Appwrite

Appwrite also has an [`init`](/app/init.php) that does the same thing as the worker. Look for `App::setResource('phone'` and update the callback to include your new provider.

## 3. Test your provider

Ensure you've updated your environment variables to use your new SMS provider and then test it by trying to login using the [Phone method](https://appwrite.io/docs/client/account#accountCreatePhoneSession) when integrating with an Appwrite client SDK in a demo app. Make sure to test a failed and successful login.

If everything goes well, raise a pull request and be ready to respond to any feedback which can arise during our code review.

## 4. Raise a pull request

First of all, commit the changes with the message `Add XXX SMS Provider` and push it. This will publish a new branch to your forked version of Appwrite. If you visit it at `github.com/YOUR_USERNAME/appwrite`, you will see a new alert saying you are ready to submit a pull request. Follow the steps GitHub provides, and at the end, you will have your pull request submitted.

## 🤕 Stuck?

If you need any help with the contribution, feel free to head over to [our Discord channel](https://appwrite.io/discord) and we'll be happy to help you out.
