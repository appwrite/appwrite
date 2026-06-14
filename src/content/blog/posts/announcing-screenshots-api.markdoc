---
layout: post
title: "Announcing Screenshots API: Generate pixel-perfect webpage screenshots on demand"
description: Appwrite's new Screenshots API lets you generate screenshots of any public webpage with a single API call. Control browser environment, device settings, locale, and more without needing to configure your own headless browser infrastructure.
date: 2026-01-21
cover: /images/blog/announcing-screenshots-api/cover.avif
timeToRead: 5
author: aditya-oberai
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is the Appwrite Screenshots API?"
    answer: "The Screenshots API is part of [Appwrite Avatars](/docs/products/network) and lets you generate webpage screenshots with a single API call. You provide a URL and optional parameters like viewport, locale, and theme, and Appwrite renders the page in a managed headless browser and returns the image."
  - question: "Do I need to run my own headless browser to use the Screenshots API?"
    answer: "No. Appwrite manages the underlying headless browser infrastructure. You make an SDK or REST call with the URL and rendering options you want, and Appwrite handles browser launch, rendering, and scaling, so you do not maintain Playwright or Puppeteer pipelines."
  - question: "Can I capture full-page screenshots or only the viewport?"
    answer: "Both. The Screenshots API supports viewport-only and full-page screenshots. Full-page mode scrolls the entire document and stitches the result into a single image, which is useful for visual documentation and link previews of long pages."
  - question: "Can the Screenshots API simulate mobile devices and dark mode?"
    answer: "Yes. You can set the viewport size, device scale, user agent, locale, timezone, and geolocation, and toggle dark mode rendering. This is useful for testing responsive layouts, validating localized content, and capturing region-specific behavior without spinning up real devices."
  - question: "What are common use cases for a screenshot API?"
    answer: "Typical uses include generating link preview images for social sharing, building visual documentation and changelogs, archiving pages for compliance, running visual QA across breakpoints, and powering dashboard widgets. Anywhere you need a consistent image of a web page on demand, a screenshot API simplifies the pipeline."
  - question: "Is the Appwrite Screenshots API available on self-hosted Appwrite?"
    answer: "The Screenshots API is part of Appwrite Avatars and ships in Appwrite. Make sure you are running a version that includes it and your network configuration allows the rendering service to reach the URLs you want to capture."
---

Capturing consistent, high-quality screenshots of web pages is harder than it should be.

What starts as a simple requirement, “render this page as an image,” quickly turns into a fragile setup of headless browsers, custom scripts, and environment-specific workarounds. Fonts render differently across machines. Animations race your screenshot timer. Cookie banners and permission prompts block content. Pages behave one way locally and another in CI. Keeping screenshots reliable across devices, locales, and time zones often means babysitting a complex Playwright or Puppeteer pipeline that breaks the moment a browser update lands.

Most teams do not want to run their own headless browser infrastructure. They just want the result.

Today, we are excited to announce the **Screenshots API**, a new addition to **Appwrite Avatars**, that lets you generate fully customizable webpage screenshots with a single API call, without maintaining any headless browser infrastructure.

# Capture webpages exactly as users see them

The Screenshots API allows you to capture screenshots of any public webpage while controlling the browser environment in which it is rendered.

With one request, you can customize:

- Browser viewport size and device scale
- Light or dark theme rendering
- Full-page or viewport-only screenshots
- Custom headers, user agents and locales
- Timezone and geolocation simulation
- Touch support and browser permissions
- Output format, dimensions, and quality

This means your screenshots are no longer best guesses. They are accurate representations of real user experiences.

# Built for real-world developer workflows

Screenshots are not just images. They are an essential building block for modern developer workflows.

The Screenshots API is designed for use cases like:

- Generating visual documentation automatically
- Creating rich link previews for apps and dashboards
- Running QA checks across devices and browsers
- Validating responsive and localized layouts
- Archiving web pages for compliance and records
- Powering automated reports and visual dashboards

Instead of maintaining your own headless browser infrastructure, you can rely on a managed, consistent API that scales with your application.

# Simple API, powerful customization

Getting started with the Screenshots API is as simple as providing a URL.

```js
import { Client, Avatars } from "appwrite";

const client = new Client()
  .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
  .setProject("<PROJECT_ID>");

const avatars = new Avatars(client);

const screenshot = avatars.getScreenshot({
  url: "https://appwrite.io"
});

console.log(screenshot);
```

From there, you can dial in exactly how the page should be rendered.

Capture mobile, tablet, or desktop views. Enable full-page scrolling. Render pages in dark mode. Simulate a specific country, language, or timezone. Even pre-grant browser permissions to avoid blocked or degraded states.

# Simulate browsers, devices, and locations

Modern web apps behave differently depending on where and how they are accessed. The Screenshots API lets you reflect that reality.

You can:

- Test responsive layouts across viewport sizes
- Verify localized content using different locales and timezones
- Capture region-specific content with geolocation simulation
- Render pages as touch devices or desktop browsers
- Validate pages that depend on browser permissions

This makes it easier to test, document, and ship with confidence.

# Part of Appwrite Avatars

The Screenshots API lives alongside other Avatars endpoints, making it easy to integrate into existing Appwrite projects. Whether you are generating previews, reports, or automated documentation, screenshots become just another API resource you can depend on.

# Available now

The Screenshots API is available today as part of Appwrite Avatars. You can start generating screenshots immediately using the Appwrite SDKs and REST API.

This API is already being used in production by [Imagine](https://imagine.dev) to generate real-time preview screenshots for projects built on the platform, validating it against real user traffic.

To get started, head over to the [documentation](/docs/products/avatars/screenshots) and try it out in your next project.

As always, we would love to see what you build with it.