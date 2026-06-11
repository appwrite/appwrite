---
layout: article
title: Frameworks
description: Discover which frameworks are supported out-of-the-box by Appwrite Sites.
---

Appwrite Sites allows web apps developed with a variety of frameworks to be hosted and served to your users. Appwrite Sites allows web apps developed with a variety of frameworks to be hosted and served to your users. When we say a framework is "supported," it means Appwrite can automatically detect, build, and optimize deployments for that framework with minimal configuration from you.

# Zero-configuration approach

Appwrite Sites uses a zero-config approach to make deployments as frictionless as possible. When you deploy a project, Appwrite:

1. Automatically detects your framework based on your package dependencies and configuration files (like `next.config.js`, `nuxt.config.js`, etc.)
2. Selects one of the [SSR](/docs/products/sites/rendering/ssr) or [Static](/docs/products/sites/rendering/static) rendering strategies
3. Sets up the appropriate install command, build command, and output directory

This means you can focus on building your application while Appwrite handles the deployment complexities.

# Supported frameworks

{% table %}
- &nbsp; {% width=48 %}
- Framework
- Rendering strategy

---

- {% icon icon="nextjs" size="m" /%}
- [**Next.js**](/docs/products/sites/quick-start/nextjs)
- `SSR` `Static`

---

- {% icon icon="nuxt" size="m" /%}
- [**Nuxt**](/docs/products/sites/quick-start/nuxt)
- `SSR` `Static`

---

- {% icon icon="svelte" size="m" /%}
- [**SvelteKit**](/docs/products/sites/quick-start/sveltekit)
- `SSR` `Static`

---

- {% icon icon="angular" size="m" /%}
- [**Angular**](/docs/products/sites/quick-start/angular)
- `SSR` `Static`

---

- {% icon icon="astro" size="m" /%}
- [**Astro**](/docs/products/sites/quick-start/astro)
- `SSR` `Static`

---

- {% only_dark %}{% icon_image src="/images/platforms/dark/remix.svg" alt="Remix logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/remix.svg" alt="Remix logo" size="m" /%}{% /only_light %}
- [**Remix**](/docs/products/sites/quick-start/remix)
- `SSR` `Static`

---

- {% only_dark %}{% icon_image src="/images/platforms/dark/tanstack.svg" alt="TanStack Start logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/tanstack.svg" alt="TanStack Start logo" size="m" /%}{% /only_light %}
- [**TanStack Start**](/docs/products/sites/quick-start/tanstack-start)
- `SSR` `Static`

---
- {% icon icon="flutter" size="m" /%}
- [**Flutter Web**](/docs/products/sites/quick-start/flutter)
- `Static`

---

- {% icon icon="react-native" size="m" /%}
- [**React Native**](/docs/products/sites/quick-start/react-native)
- `Static`

---

- {% icon icon="js" size="m" /%}
- [**Other JavaScript**](/docs/products/sites/quick-start/vanilla)
- `Static`

---
{% /table %}

## Using unsupported frameworks

Even with "unsupported" frameworks, Appwrite will attempt to detect the correct build configuration. If your preferred framework isn't officially supported, you can still deploy it to Appwrite Sites using:

1. **Manual configuration**: You can specify a custom install command, build command, and output directory in your build setting by selecting the **Other** framework option.
2. **Static builds**: Most JavaScript frameworks (and some non-JavaScript ones) can generate static builds that work with Appwrite Sites.