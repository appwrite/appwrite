---
layout: article
title: Deploy a Flutter Web app to Appwrite Sites
description: Learn how to setup and deploy Flutter Web apps on Appwrite Sites.
---

{% section #step-1 step=1 title="Create Flutter Web app" %}

First, you must either create a Flutter Web app or setup the [Flutter Web starter template](https://github.com/appwrite/templates-for-sites).

Open your terminal, and run the following command.

```bash
flutter create my_app
```

In case you have an existing Flutter app and want to add web support to it, you must run the following command in your project directory:

```bash
flutter create . --platforms web
```

Push this project to a [GitHub repository](https://github.com/new).
{% /section %}

{% section #step-2 step=2 title="Create Appwrite project" %}
Head to the [Appwrite Console](https://cloud.appwrite.io).

{% only_dark %}
![Create Appwrite project](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}
{% only_light %}
![Create Appwrite project](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

If this is your first time using Appwrite, create an account and create your first project.
{% /section %}

{% section #step-3 step=3 title="Create site" %}
Head to the **Sites** page in your Appwrite project, click on the **Create site** button, and select **Connect a repository**.

Connect your GitHub account and select the repository you intend to deploy (or allow all repositories, for future ease).

1. Select the **production branch** and **root directory** from your repo.
2. Verify that the **correct framework** is selected. In case an incorrect framework is visible, pick **Flutter Web** from the drop-down list. 
3. Confirm the **install command**, **build command**, and **output directory** in the build settings. The default build settings for Flutter Web are:
    - **Install command:** `flutter pub get`
    - **Build command:** `flutter build web --release -t lib/main.dart`
    - **Output directory:** `./build/web`
4. Add any **environment variables** required by the site. This is not necessary if you're deploying the starter app.

Click on the **Deploy** button.
{% /section %}

{% section #step-4 step=4 title="Visit site" %}
After successful deployment, click on the **Visit site** button.

{% /section %}