---
layout: tutorial
title: Create app
description: Create a Nuxt app project and integrate with Appwrite
step: 2
---

## Create Nuxt project {% #create-nuxt-project %}

Create a Nuxt app with the `npx init` command.
The command will install all the necessary dependencies for you.

```sh
npx nuxi@latest init ideas-tracker
```

## Add dependencies {% #add-dependencies %}

Once the project is created, change your current working directory and install the JavaScript Appwrite SDK.

```sh
cd ideas-tracker
npm install appwrite
npm install "@appwrite.io/pink"
```

Open `App.vue` and import the relevant style files.

```html
<!-- app.vue -->

<script setup>

import "@appwrite.io/pink";
// optionally, add icons
import "@appwrite.io/pink-icons";

</script>
```

Then update `nuxt.config.ts` to disable SSR for now. SSR support is coming soon to Appwrite, for now, disable SSR.

```ts
// nuxt.config.ts

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  ssr: false,
  devtools: { enabled: true }
})
```

You can start the development server to watch your app update in the browser as you make your changes.

```sh
npm run dev
```

## File structure {% #file-structure %}

Nuxt relies on an opiniated directory structure to automate tasks and help organize the codebase.
To take advantage of this we need to add the following directories:
- `/components/` to keep our UI components in one place.
  We will get back to it in [step 5](/docs/tutorials/nuxt/step-5)
- `/composables/`for storing files handling global states and data fetching.
  We will use it in [step 4](/docs/tutorials/nuxt/step-4)
- `/layouts/` to store the page layouts
- `/pages/` for the content pages.

Run the following command to create these folders

```sh
mkdir components composables layouts pages
```

## Add layout {% #add-layout %}

Go to the `layouts/` directory and add the file `default.vue`.
Add the following code for the default layout.
As you see it's nearly empty but it is needed for the automatic routing to work properly.

```html
<!-- layouts/default.vue -->

<template>
  <div>
    <slot />
  </div>
</template>

<script>
export default {
  layout: "default",
};
</script>
```

## Add home page {% #add-home-page %}

Next, head over to the `pages` directory.
This is where we will keep the content that will render on our pages in the web application.
Nuxt reads all the `.vue` files inside this directory and [automatically renders them as routes](https://v2.nuxt.com/docs/directory-structure/pages/).
Add the file `index.vue` with the following code.

```vue
<!-- pages/index.vue -->

<template>
  <nav class="main-header u-padding-inline-end-0">
    <h3 class="u-stretch eyebrow-heading-1">Hello, Idea Tracker!</h3>
  </nav>
</template>
```

This is what your directory should look like after adding the new directories and files:

```
[repository tree]
├── .nuxt/
├── components/
├── composables/
├── layouts/
│   └── default.vue
├── pages/
│   ├── index.vue
├── public/
│   └── /favicon.ico
├── server/
│   └── /tsconfig.json
├── .gitignore
├── app.vue
├── nuxt.config.ts
├── package-lock.json
├── package.json
├── README.md
└── tsconfig.json
```

## Render page {% #render-page %}

If you run the development server now, it will still render the Nuxt Welcome page.
We need to tell our app to use the files we just created instead.
Open `app.vue` in the root directory and replace the content with the following code.
Your page will now be up and running.

```vue
<!-- app.vue -->

<script setup>
import "@appwrite.io/pink";
import "@appwrite.io/pink-icons";
</script>

<template>
  <div>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>
```
