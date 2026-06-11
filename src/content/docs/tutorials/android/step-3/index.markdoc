---
layout: tutorial
title: Set up Appwrite
description: Initialize Appwrite in your Android project.
step: 3
---

## Create project {% #create-project %}

Head to the [Appwrite Console](https://cloud.appwrite.io/console).

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

If this is your first time using Appwrite, create an account and create your first project.

Then, under **Add a platform**, add an **Android app**. The **Package Name** should be the same as the one you used when creating your app.

{% only_dark %}
![Add a platform](/images/docs/quick-starts/dark/add-platform.avif)
{% /only_dark %}
{% only_light %}
![Add a platform](/images/docs/quick-starts/add-platform.avif)
{% /only_light %}

You can skip optional steps.

## Initialize Appwrite SDK {% #init-sdk %}

To use Appwrite in our Android app, we'll need to find our project ID. Find your project's ID in the **Settings** page. 

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Create a new file `services/Appwrite.kt` to hold our Appwrite related code.
Only one instance of the `Client` class should be created per app.
Add the following code to it, replacing `<PROJECT_ID>` with your project ID.

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>.services

import android.content.Context
import io.appwrite.Client

object Appwrite {
    private const val ENDPOINT = "https://<REGION>.cloud.appwrite.io/v1"
    private const val PROJECT_ID = "<PROJECT_ID>"

    private lateinit var client: Client

    fun init(context: Context) {
        client = Client(context)
            .setEndpoint(ENDPOINT)
            .setProject(PROJECT_ID)
    }
}
```