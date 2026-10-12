---
layout: article
title: Deploy a Vanilla JS app to Appwrite Sites
description: Learn how to setup and deploy Vanilla JS apps on Appwrite Sites.
---

{% section #step-1 step=1 title="Create web app" %}

Open your terminal, and run the following command.

```bash
mkdir my-app
cd my-app
```

In this directory, create two files with the following code:

- `index.html`

```html
<html>
    <head>
        <script type="module" src="/app.js"></script>
    </head>
    <body>
        <h1>Demo App</h1>
        <button>Click Me!</button>
    </body>
</html>
```

- `app.js`

```js
row.querySelector("button").addEventListener("click", () => {
    alert("Hello World!");
});
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
2. Verify that the **correct framework** is selected. In case an incorrect framework is visible, pick **Other** from the drop-down list. 
3. Confirm the **install command**, **build command**, and **output directory** in the build settings. The build settings for this app will be:
    - **Install command:** `N/A` (leave empty, unless you have installed any external packages)
    - **Build command:** `N/A` (leave empty, unless you have installed any external packages)
    - **Output directory:** `./`
4. Add any **environment variables** required by the site. This is not necessary if you're deploying the starter app.

Click on the **Deploy** button.
{% /section %}

{% section #step-4 step=4 title="Visit site" %}
After successful deployment, click on the **Visit site** button.

{% /section %}