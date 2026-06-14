---
layout: post
title: "Storage previews vs SSR image optimization: when to use which"
description: More frameworks are adding server-side image manipulation. Learn when to use service-based image transformations like Appwrite Storage previews versus SSR functions for image optimization.
date: 2025-11-06
lastUpdated: 2026-05-22
cover: /images/blog/storage-previews-vs-ssr-image-optimization/cover.avif
timeToRead: 8
author: ebenezer-don
category: product
featured: false
faqs:
  - question: "What is the difference between Storage previews and SSR image optimization?"
    answer: "Storage previews handle image transformations through a dedicated service API, separate from your app server, with built-in CDN caching. SSR image optimization processes images on your application server using libraries like Sharp or IPX. Service-based approaches reduce server load, while SSR gives you more direct control over the pipeline."
  - question: "When should I use Appwrite Storage previews instead of framework SSR utilities?"
    answer: "Use [Appwrite Storage](/docs/products/storage) previews when you want centralized image storage that works across web, mobile, and other clients, or when you do not want to manage Sharp binaries and caching. SSR utilities make sense if your images are tightly tied to a single framework deployment and you need custom transformation logic."
  - question: "Do Appwrite Storage previews support modern formats like WebP and AVIF?"
    answer: "Yes. You can request transformed images in formats like WebP by passing an output parameter to the file preview endpoint. This lets you serve smaller, modern formats without changing the underlying file."
  - question: "Are transformed images cached automatically?"
    answer: "Appwrite Storage previews cache transformed images on first request and serve subsequent requests from cache. This avoids re-processing the same image repeatedly and improves response times. SSR-based optimization usually requires you to configure caching yourself."
  - question: "Does using Storage previews mean I cannot also use SSR image utilities?"
    answer: "No. The two approaches can coexist. You might use Appwrite Storage previews for user uploads and dynamic content, then use SSR image utilities for static marketing assets that ship with your codebase."
  - question: "How does Appwrite Storage compare to Cloudinary or other media platforms?"
    answer: "Appwrite Storage is part of a broader backend platform, so you get image handling alongside auth, databases, functions, and messaging. Dedicated media platforms like Cloudinary often have richer media-specific features but require integrating an extra service. We have a deeper comparison in the [Appwrite vs Cloudinary post](/blog/post/appwrite-vs-cloudinary)."
---

Image optimization has become a standard feature in modern web frameworks. From Next.js to Nuxt, frameworks now include built-in tools for manipulating images at the server level. The [recent release of Nuxt Image v2](https://nuxt.com/blog/nuxt-image-v2#server-side-utilities) highlights this trend with new server-side utilities that let you transform images directly in your server endpoints.

This raises an important question: if frameworks can now handle image processing, when should you use a service like Appwrite Storage previews instead? Both approaches have their place, but understanding when to use each can save you time, reduce costs, and improve your application's performance.

In this post, we'll compare service-based image transformations with SSR image functions, examine their tradeoffs, and help you decide which approach fits your needs.

For a separate comparison of Appwrite Storage against dedicated media platforms (not framework SSR), read [Appwrite vs Cloudinary](/blog/post/appwrite-vs-cloudinary).

# What are service-based image transformations?

Service-based image transformations handle image processing through a dedicated API, completely separate from your application server. With Appwrite Storage previews, you upload an image once, and the service generates transformed versions on demand using URL parameters.

Here's how it works:

```js
import { Client, Storage } from 'appwrite'

const client = new Client()
const storage = new Storage(client)

client
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>')

// Get a transformed image
const result = storage.getFilePreview({
  bucketId: 'user-photos',
  fileId: 'profile-pic.jpg',
  width: 400,
  height: 400,
  gravity: 'center',
  quality: 90,
  borderRadius: 200,
  output: 'webp'
})
```

The original image remains unchanged in storage. When you request a preview with specific transformations, Appwrite processes the image and caches the result. Subsequent requests for the same transformation are served from cache.

## Key characteristics

- **Centralized storage**: All images live in one place, accessible from any platform
- **Built-in CDN**: Images are distributed globally and cached at the edge
- **No server compute**: Your application server doesn't handle image processing
- **Framework agnostic**: Works the same way in React, Vue, React Native, Flutter, or any other framework
- **Automatic caching**: Transformed images are cached without additional configuration

# What is SSR image optimization?

SSR image optimization processes images on your application server at runtime. Frameworks like Nuxt, Next.js, and SvelteKit include utilities that let you manipulate images in server-side code.

With Nuxt Image v2's new server utilities, you can transform images directly in your API routes:

```ts
// Server endpoint example
export default defineEventHandler(() => {
  const img = useImage()

  return {
    url: img('/hero.jpg', {
      width: 1200,
      height: 630,
      fit: 'cover'
    })
  }
})
```

This approach processes images where your application runs, using libraries like Sharp or IPX. The processed images can be cached at the CDN or browser level.

## Key characteristics

- **Co-located processing**: Images are processed on the same infrastructure as your app
- **Full control**: You can implement custom transformation logic
- **Framework integration**: Deep integration with SSR frameworks and type systems
- **Compute overhead**: Uses your server resources for image processing
- **Setup required**: Need to configure caching and manage dependencies

# How do they compare?

Let's look at the key differences between these approaches:

## Infrastructure and maintenance

**Service-based** requires minimal setup. You connect to the API, upload images, and request transformations. The service handles processing, caching, and distribution. You don't manage image processing libraries or worry about binary dependencies across different deployment platforms.

**SSR functions** require you to manage the image processing pipeline. This means handling Sharp binary installations, configuring caching strategies, and ensuring your server can handle the processing load. The tradeoff is complete control over the transformation logic.

## Performance and scaling

**Service-based** offloads all image processing to dedicated infrastructure. Your application server just sends API requests. This means image processing never impacts your application's response time, even during traffic spikes. The service's global CDN ensures fast delivery worldwide.

**SSR functions** use your server's CPU and memory to process images. For small-scale applications, this might not matter. But as traffic grows, image processing can consume significant resources. You'll need to scale your infrastructure accordingly or implement caching strategies.

## Flexibility and customization

**Service-based** provides a fixed set of transformations. Appwrite Storage offers options like resize, crop, quality adjustment, borders, opacity, rotation, background color, and format conversion. These cover most use cases, but you can't implement custom transformation logic.

**SSR functions** give you complete freedom. Need to add watermarks? Apply custom filters? Generate dynamic compositions? You can implement any transformation logic your application needs. This flexibility comes with the responsibility of maintaining that code.

## Cost considerations

**Service-based** has predictable costs. Appwrite's [pricing](/pricing) includes 100 origin images per month on the Pro plan (Enterprise has custom limits), with additional images at $5 per 1,000. You pay for unique images, not transformations. So one image with 20 different variations only counts as one origin image.

**SSR functions** cost depends on your infrastructure. You pay for compute time, bandwidth, and storage. Low traffic might mean minimal costs, but high traffic requires more server capacity. The actual cost varies based on your cloud provider and configuration.

## Multi-platform support

**Service-based** shines in multi-platform scenarios. Whether you're building a web app, mobile app, or desktop application, the same API works everywhere. Your React Native app, Flutter app, and Next.js site all use the same image URLs with the same transformation parameters.

**SSR functions** are web-focused. While your web app benefits from SSR image optimization, your mobile apps need a different solution. This often means implementing separate image handling logic for different platforms.

# When to use service-based transformations

Choose Appwrite Storage previews when you need:

## Consistent cross-platform image delivery

If you're building for web and mobile, using a service means the same image API works everywhere. Your Flutter app and your React website both fetch images from the same source with the same transformation syntax.

## Minimal infrastructure management

When you don't want to manage image processing infrastructure, a service handles everything. No Sharp binaries to worry about, no caching configuration to debug, no scaling concerns for image processing workloads.

## User-generated content at scale

For applications where users upload photos, profile pictures, or other media, a dedicated storage service simplifies permissions, transformations, and delivery. Appwrite handles the complexity of secure storage and on-demand transformations.

## Example: Social media application

```js
// In your React Native app
const ProfilePicture = ({ userId, fileId }) => {
  const storage = new Storage(client)

  const avatarUrl = storage.getFilePreview({
    bucketId: 'avatars',
    fileId: fileId,
    width: 150,
    height: 150,
    gravity: 'center',
    borderRadius: 75,
    output: 'webp'
  })

  return <Image source={{ uri: avatarUrl }} />
}

// The same API works in your Next.js web app
const ProfilePicture = ({ userId, fileId }) => {
  const storage = new Storage(client)

  const avatarUrl = storage.getFilePreview({
    bucketId: 'avatars',
    fileId: fileId,
    width: 150,
    height: 150,
    gravity: 'center',
    borderRadius: 75,
    output: 'webp'
  })

  return <img src={avatarUrl} alt="Profile" />
}
```

# When to use SSR image functions

Choose SSR image optimization when you need:

## Custom transformation logic

If you need transformations beyond standard resize, crop, and format conversion, SSR functions give you full control. Add watermarks, apply brand-specific filters, or implement complex image compositions.

## Dynamic social preview generation

For generating Open Graph images or social media previews with dynamic text and layouts, SSR functions excel. You can use libraries to compose images programmatically in your server endpoints.

## Processing images from multiple sources

When your images come from various sources (local files, external URLs, databases), SSR functions can normalize processing. You're not limited to images stored in a specific service.

## Example: Dynamic OG image generation

```ts
// Nuxt server endpoint
export default defineEventHandler(async (event) => {
  const { title, author } = getQuery(event)
  const img = useImage()

  // Generate dynamic OG image with custom layout
  // This is simplified - actual implementation would use
  // something like Satori or canvas-based composition
  const ogImageUrl = await generateOgImage({
    title,
    author,
    backgroundImage: img('/og-background.jpg', {
      width: 1200,
      height: 630
    })
  })

  return ogImageUrl
})
```

# Can you use both?

Yes, and this hybrid approach often makes the most sense. Use each tool for what it does best.

Consider this setup:

- **Appwrite Storage previews** for user-generated content (profile pictures, uploaded photos, documents)
- **SSR functions** for dynamic, generated images (OG images, certificates, custom graphics)

This gives you the benefits of both approaches:

```tsx
// Next.js example combining both approaches

// User avatar from Appwrite Storage
export function UserAvatar({ fileId }: { fileId: string }) {
  const storage = new Storage(client)

  const avatarUrl = storage.getFilePreview({
    bucketId: 'avatars',
    fileId,
    width: 200,
    height: 200,
    borderRadius: 100,
    output: 'webp'
  })

  return <img src={avatarUrl} alt="User avatar" />
}

// Dynamic OG image from SSR function
export async function generateMetadata({ params }: { params: { slug: string } }) {
  const post = await getPost(params.slug)

  return {
    openGraph: {
      images: [
        {
          url: `/api/og?title=${encodeURIComponent(post.title)}&author=${post.author}`,
          width: 1200,
          height: 630
        }
      ]
    }
  }
}
```

The user avatar comes from Appwrite Storage because it's user-uploaded content that needs to be displayed consistently across platforms. The OG image uses an SSR function because it requires custom composition with dynamic text.

# Making the decision

Ask yourself these questions:

1. **Are you building for multiple platforms?**
   - Yes → Service-based handles cross-platform better
   - No → Either approach works

2. **Do you need custom transformation logic?**
   - Yes → SSR functions give you full control
   - No → Service-based covers standard transformations

3. **How much infrastructure do you want to manage?**
   - Minimal → Service-based requires less maintenance
   - Full control → SSR functions let you optimize everything

4. **What's your scaling strategy?**
   - Focus on application logic → Service-based handles image scaling separately
   - Unified infrastructure → SSR functions scale with your servers

5. **Are images user-generated or static?**
   - User-generated → Service-based simplifies storage and permissions
   - Static or generated → Either approach works, depending on other factors

# Final thoughts

The trend of frameworks adding image manipulation features is a response to real developer needs. SSR image functions make sense when you're building primarily for web, need custom processing logic, or want complete control over your image pipeline.

But service-based transformations still have a strong place in modern web development. They excel at multi-platform delivery, reduce infrastructure complexity, and provide predictable scaling and costs.

The question is not which approach is better, it's which approach fits your specific needs. For many applications, using both in a hybrid setup makes the most sense. Let Appwrite Storage handle user-generated content and cross-platform image delivery, while using SSR functions for dynamic, custom-generated images.

Start with understanding your requirements: platform coverage, transformation needs, infrastructure preferences, and scaling strategy. Then choose the tool that matches those needs.

And remember, you're not locked into one approach. Start with what makes sense now, and adjust as your application grows and your needs change.

# Resources

- [Appwrite Storage documentation](/docs/products/storage)
- [Image transformations guide](/docs/products/storage/images)
- [How to use AVIF in Appwrite Storage](/blog/post/avif-in-storage)
- [Image transformation with Appwrite Storage](/blog/post/image-transformation-with-appwrite-storage)
