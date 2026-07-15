---
layout: article
title: Develop Appwrite Sites
description: Master site development with Appwrite.
---

# Rendering strategies

Appwrite allows you to host both statically-generated and server-rendered websites. 

[Static sites](/docs/products/sites/rendering/static) are websites that are pre-built and served as-is to clients. They do not execute server-side code on each request. They are ideal for use-cases such as [Single Page Applications (SPAs)](/docs/products/sites/rendering/static#running-spas-on-appwrite-sites), documentation sites, personal blogs, and portfolio websites. 

[Server-side rendered (SSR) sites](/docs/products/sites/rendering/ssr) generate content dynamically on the server and send fully rendered pages for each request. They are ideal for use-cases with substantial dynamic content or server-side processing such as e-commerce platforms, social media applications, content management systems (CMS), and real-time collaboration tools.

You can configure your preferred rendering strategy through the following steps:

1. Navigate to your site on Appwrite Console.
2. Head to the **Settings** tab > **Build settings** section.
3. Select the SSR or the Static checkbox.
4. Confirm that the appropriate install command, build command, and output directory are set.
5. *(For SPAs)* Add a fallback file.
6. Click on the **Update** button and redeploy your site.

{% only_dark %}
![Rendering strategy](/images/docs/sites/dark/build-settings-rendering-ssr.avif)
{% /only_dark %}
{% only_light %}
![Rendering strategy](/images/docs/sites/build-settings-rendering-ssr.avif)
{% /only_light %}

# Timeouts {% #timeouts %}

Each request made to a path on an Appwrite Site has a set time limit, after which the request will timeout. Here are the steps to configure those timeout period:

1. Navigate to your site on Appwrite Console.
2. Head to the **Settings** tab > **Timeout** section.
3. Add an appropriate time limit (in seconds).

{% only_dark %}
![Timeout](/images/docs/sites/dark/timeout.avif)
{% /only_dark %}
{% only_light %}
![Timeout](/images/docs/sites/timeout.avif)
{% /only_light %}

The default timeout is set at `15 seconds` and the maximum value possible is `30 seconds`.

# Resource limits {% #resource-limits %}

Under **Settings** - **Resource limits**, you can set **build** and **runtime** specifications independently. The build spec applies while dependencies are installed and your site is built for deployment; the runtime spec applies when your site serves traffic, including server-side rendering (SSR). Both use the same CPU and memory tiers on Cloud.

On Appwrite Cloud, customizing specifications requires the **Pro** plan. See [Compute](/docs/advanced/billing/compute) for tiers, GB-hours, and pricing.

## Build timeouts {% #build-timeouts %}

On Appwrite Cloud, the **build** phase of each deployment must complete within your plan’s **maximum build duration** (for example, 15 minutes on Free and 45 minutes on Pro and Scale). See [Build timeouts](/docs/advanced/billing/compute#build-timeouts) and the [pricing page](/pricing).

# Project dependencies

To install your dependencies before your site is built, you should add the relevant install command to your site’s build settings. Here are the steps to add the install command:

1. Navigate to your site on Appwrite Console.
2. Head to the **Settings** tab > **Build settings** section.
3. Confirm that the appropriate install command is set.

{% only_dark %}
![Install command](/images/docs/sites/dark/build-settings-install-command.avif)
{% /only_dark %}
{% only_light %}
![Install command](/images/docs/sites/build-settings-install-command.avif)
{% /only_light %}

Make sure to include dependency files like `package.json` in your site's configured root directory. Do not include the dependency folders like `node_modules` in your site’s root directory. The dependencies installed for your local OS may not work in the site's environment.

Your site's dependencies should be managed by the package manager of each language. We include the following package managers and setup commands by default.

{% table %}
- &nbsp; {% width=48 %}
- Framework
- Default package manager
- Install command
- Build command
- Output directory

---

- {% icon icon="nextjs" size="m" /%}
- **Next.js**
- `npm`
- `npm install`
- `npm run build`
- `./.next`

---

- {% icon icon="nuxt" size="m" /%}
- **Nuxt**
- `npm`
- `npm install` or `yarn install`
- `npm run build` or `yarn build`
- `./.output`

---

- {% icon icon="svelte" size="m" /%}
- **SvelteKit**
- `npm`
- `npm install`
- `npm run build`
- `./build`

---

- {% icon icon="angular" size="m" /%}
- **Angular**
- `npm`
- `npm install`
- `npm run build`
- `./dist/angular/browser`

---

- {% icon icon="astro" size="m" /%}
- **Astro**
- `npm`
- `npm install`
- `npm run build`
- `./dist`

---

- {% only_dark %}{% icon_image src="/images/platforms/dark/remix.svg" alt="Remix logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/remix.svg" alt="Remix logo" size="m" /%}{% /only_light %}
- **Remix**
- `npm`
- `npm install`
- `npm run build`
- `./build`

---

- {% only_dark %}{% icon_image src="/images/platforms/dark/tanstack.svg" alt="TanStack Start logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/tanstack.svg" alt="TanStack Start logo" size="m" /%}{% /only_light %}
- **TanStack Start**
- `npm`
- `npm install`
- `npm run build`
- `./dist`

---
- {% icon icon="flutter" size="m" /%}
- **Flutter**
- `pub`
- `flutter pub get`
- `flutter build web --release -t lib/main.dart`
- `./build/web`

---

- {% icon icon="react-native" size="m" /%}
- **React Native**
- `npm`
- `npm install`
- `npm run build`
- `./dist`

---

- {% icon icon="js" size="m" /%}
- **Other JavaScript**
- `npm`
- (Empty)
- (Empty)
- `./`

{% /table %}