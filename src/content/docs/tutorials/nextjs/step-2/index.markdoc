---
layout: tutorial
title: Create app
description: Create a Next.js app project and integrate with Appwrite
step: 2
---

## Create Next.js project {% #create-nextjs-project %}

Create a Next.js app with the `npx create-next-app` command.
The command will install all the necessary dependencies for you.

```sh
npx create-next-app@latest ideas-tracker --typescript --eslint --app --src-dir --import-alias "@/*"
```

## Add dependencies {% #add-dependencies %}

Once the project is created, change your current working directory and install the JavaScript Appwrite SDK.

```sh
cd ideas-tracker
npm install appwrite
npm install "@appwrite.io/pink"
```

Open `src/app/globals.css` and replace the content with the following to import the relevant style files.

```css
/* src/app/globals.css */
@import "@appwrite.io/pink";
@import "@appwrite.io/pink-icons";

* {
  box-sizing: border-box;
}

html,
body {
  overflow-x: hidden;
}

body {
  background: hsl(var(--color-neutral-50));
}

.dark body {
  background: hsl(var(--color-neutral-900));
}
```

You can start your development server to see your app in the browser.

```sh
npm run dev
```

This will start a server at `http://localhost:3000/`.