---
layout: post
title: "Announcing HEIC and AVIF support: modern image formats now in Appwrite"
description:
date: 2025-05-22
cover: /images/blog/new-image-formats.avif
timeToRead: 6
author: damodar-lohani
category: product, announcement
featured: false
faqs:
  - question: "What is HEIC and why does it matter?"
    answer: "HEIC is Apple's default image format on iOS, designed to deliver high quality images at significantly smaller file sizes than JPEG. It is widely used in iPhone photo libraries and apps that need to handle native iOS image capture. Supporting HEIC means images stay smaller without losing quality on Apple devices."
  - question: "What is AVIF and how does it compare to other formats?"
    answer: "AVIF is a modern open image format with excellent compression ratios, often producing files smaller than WebP at similar quality. It is supported by modern browsers like Chrome, Firefox, and Safari (recent versions). For media heavy sites, switching to AVIF can cut bandwidth and improve load times noticeably."
  - question: "How do I use HEIC or AVIF with Appwrite Storage?"
    answer: "Call the image preview endpoint with the output parameter set to ImageFormat.Heic or ImageFormat.Avif using the [Appwrite SDK](/docs/sdks). The source image can be in any supported format, Appwrite converts on the fly. See the [image transformation docs](/docs/products/storage/images) for the full parameter list."
  - question: "Do I need to upload images in HEIC or AVIF to serve them in those formats?"
    answer: "No, you can upload images in any common format and request the preview in HEIC or AVIF at serve time. Appwrite handles the conversion as part of the [Storage](/docs/products/storage) preview pipeline. This means you keep one source file per image and deliver the right format per client."
  - question: "Is HEIC or AVIF support available on self hosted Appwrite?"
    answer: "Yes, HEIC and AVIF output formats are available on both Appwrite Cloud and self hosted installations. Update to a version that includes the feature and you can request previews in either format. No additional external services are required."
  - question: "When should I use AVIF over WebP or JPEG?"
    answer: "Use AVIF when you need the smallest file sizes and your audience uses modern browsers and devices. WebP remains a safe middle ground for broader compatibility, and JPEG still serves legacy clients. For mixed audiences, request AVIF with a JPEG fallback by detecting client support."
---

We’re excited to share we have added support for two new image formats in Appwrite Storage: **HEIC** and **AVIF.** This will give you more tools to manage, manipulate, and serve images the way you need.

You can generate image previews directly in **.heic** and **.avif** formats, which are natively supported in Appwrite.

No converters. No extra steps. Just fast, flexible image handling built in.

# Native support for next-gen formats

Image performance matters. Whether you're building a high-performance photo app or just optimizing media delivery, modern formats like AVIF and HEIC offer massive benefits: smaller sizes, better quality, and native platform support.

With this update, Appwrite now supports outputting previews in **HEIC** (Apple’s proprietary format) and **AVIF** (a modern open format with excellent compression).

# Why it matters

Previously, if you wanted to serve HEIC or AVIF images from Appwrite, you had to download them, convert them manually, and re-upload or host elsewhere.

Now, the Appwrite Storage preview API can do it all automatically.

This means:

- No more external tools
- No extra conversion steps
- No breaking your image pipeline

Just fast, reliable image delivery in the formats you need.

# Built for Apple and beyond

HEIC is Apple’s default image format on iOS. AVIF is increasingly used across modern browsers and devices.

This feature was designed for mobile app developers, especially those targeting iOS and working with native image capture and display. But it’s just as useful for any developer looking to serve modern image formats with better compression and quality.

# How to use it

Use the image preview endpoint as you normally would, just set the desired output format to HEIC or AVIF. It works the same way as existing format conversions like `jpg` or `png`.

You’ll see smaller file sizes, faster load times, and better integration with native apps and devices.

# How to get started

HEIC and AVIF support is available on **Appwrite Cloud** and **self-hosted** installations.

```jsx
import { Client, Storage, ImageFormat } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

const resultHeic = storage.getFilePreview({
    bucketId: 'photos',
    fileId: 'sunset.png',
    width: 1800,
    gravity: 'center',
    quality: '90',
    borderWidth: 5,
    borderColor: 'CDCA30',
    borderRadius: 15,
    background: 'FFFFFF',
    output: ImageFormat.Heic
});
console.log(resultHeic.href);

const resultAvif = storage.getFilePreview({
  bucketId: 'photos',
  fileId: 'sunset.jpeg',
  output: ImageFormat.Avif
})
console.log(resultAvif.href);
```

[Visit the documentation](/docs/products/storage/images) to learn more.

# Smarter storage for better apps

This feature is part of our ongoing commitment to giving you modern tools that work the way you expect. Whether you're building a media-heavy app, targeting Apple platforms, or just caring about performance, you now have more control and better output without extra overhead.

# More resources
Take a look at what else we released this week.

- [Appwrite Sites](/blog/post/announcing-appwrite-sites)
- [Hosting for Flutter web](/blog/post/hosting-flutter-web)
- [Dev Keys](blog/post/announcing-dev-keys)
- [Appwrite compared to Vercel](/blog/post/open-source-vercel-alternative)
- [Appwrite Sites product tour](https://youtu.be/VtDe6hDw91k)
- [Appwrite Sites video announcement](https://youtu.be/0cERQxFjTW4)
- [Appwrite Discord server](https://appwrite.io/discord)
