---
layout: post
title: How to use AVIF in Appwrite Storage
description: Learn how to use AVIF, a new image format that offers better compression without sacrificing image quality, in Appwrite Storage.
date: 2025-05-22
cover: /images/blog/avif-in-storage/cover.avif
timeToRead: 6
author: ebenezer-don
category: tutorial
featured: false
faqs:
  - question: "What is AVIF and why use it?"
    answer: "AVIF is an image format built on the AV1 video codec. It typically achieves 50% or smaller file sizes than JPEG at equivalent visual quality, supports transparency and animations, and works in HDR. Smaller images mean faster page loads and lower bandwidth bills."
  - question: "Does Appwrite Storage support AVIF?"
    answer: "Yes. You can upload AVIF files directly to [Appwrite Storage](/docs/products/storage), and you can also convert other formats to AVIF on the fly via getFilePreview by setting the output format to ImageFormat.Avif."
  - question: "Can Appwrite convert my JPEGs and PNGs to AVIF automatically?"
    answer: "Yes. Use getFilePreview with output set to AVIF to convert any uploaded image on demand. You can tune the quality parameter (0 to 100) and crop with the ImageGravity options."
  - question: "How do I handle browsers that don't support AVIF?"
    answer: "Serve AVIF via the HTML picture element with WebP and JPEG fallbacks, and let the browser pick the first supported source. Appwrite's getFilePreview can deliver each format from the same source file, so you don't store multiple copies."
  - question: "Is AVIF supported in all modern browsers?"
    answer: "Chrome, Edge, Firefox, and Safari all support AVIF in current versions. Older browsers may need a fallback (WebP or JPEG), which is straightforward with the picture element."
  - question: "Will AVIF reduce my Appwrite Storage bandwidth costs?"
    answer: "Likely yes, since AVIF files are typically much smaller than JPEG or PNG for the same image quality. Less data shipped to clients means lower egress and faster load times, though exact savings depend on your image types and quality settings."
---

Every web developer knows the struggle with image optimization. You want your images to look good, but large file sizes slow down your sites and hurt the user experience. JPEG and WebP have been popular choices for image compression, but they have limitations when it comes to achieving high compression without sacrificing image quality. AVIF offers a solution to this problem.

Built on the [AV1 video codec](https://en.wikipedia.org/wiki/AV1) by the Alliance for Open Media, it gives us much better compression without the quality loss we're used to seeing in highly compressed images.

Now that Appwrite supports [AVIF in Storage](/blog/post/new-image-formats-avif-heic), you can start using it in your projects. This matters because you can reduce image sizes by 50% or more compared to JPEG, while keeping the same visual quality. And for users with browsers that don't support AVIF yet, you can easily fall back to other formats.

Let's learn how AVIF works in Appwrite and look at some real implementation examples.

# What makes AVIF different?

AVIF brings several technical improvements to image compression:

- Achieves [better compression](https://developer.mozilla.org/en-US/docs/Web/Media/Formats/Image_types) than JPEG and WebP while maintaining quality
- Preserves more image detail at similar file sizes
- Includes alpha channel for transparency
- Works with wide color gamut (supports both HDR and standard color ranges)
- Supports animations and image sequences
- Supported by major browsers including Chrome and Firefox

# Implementation in Appwrite

Appwrite's Storage service provides three main ways to work with AVIF:

- Direct AVIF file storage
- On-demand conversion from other formats
- Format-specific delivery based on browser support

Let's examine each approach in detail.

## Direct AVIF upload

You can upload AVIF images to Appwrite Storage just like any other supported image format.

Here's an example using the Web SDK:

```js
import { Client, Storage, ID } from "appwrite";

const client = new Client()
    .setEndpoint('<https://cloud.appwrite.io/v1>')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

// Upload an AVIF file
const promise = storage.createFile({
    bucketId: '<BUCKET_ID>',
    fileId: ID.unique(),
    file: document.getElementById('uploader').files[0]
});

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```

This code handles file upload through a standard form input. The Storage service accepts AVIF files just like any other supported image format, with no special handling required at the upload stage.

## Converting images to AVIF

Appwrite's Storage service can convert your existing images to AVIF format on demand. This means you can transform JPEGs, PNGs, and other formats to AVIF without preprocessing them.

Here's how to implement the conversion:

```js
import { Client, Storage, ImageGravity, ImageFormat } from "appwrite";

const client = new Client()
    .setEndpoint('<https://cloud.appwrite.io/v1>')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

const result = storage.getFilePreview({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    width: 2000,
    gravity: ImageGravity.Center,
    quality: 100,
    output: ImageFormat.Avif
});
```

The quality parameter in getFilePreview ranges from 0-100. AVIF's compression algorithm processes different image types (photographs, illustrations, UI elements) with varying efficiency, so quality settings need to be tested for your specific use case.

The `ImageGravity` enum determines how images are cropped or positioned during resizing - `Center` maintains focus on the middle of the image.

The `ImageFormat` enum specifies the output format, with options including `Avif`, `Webp`, `Jpg`, and `Png`.

## Browser compatibility detection

To ensure the best experience for all users, you should check for AVIF support before serving AVIF images.

Here's a simple way to detect AVIF support:

```js
async function isAvifSupported() {
    const avif = new Image();
    return new Promise((resolve) => {
        avif.onload = () => resolve(true);
        avif.onerror = () => resolve(false);
        avif.src = "data:image/avif;base64,AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADybWV0YQAAAAAAAAAoaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAGxpYmF2aWYAAAAADnBpdG0AAAAAAAEAAAAeaWxvYwAAAABEAAABAAEAAAABAAABGgAAAB0AAAAoaWluZgAAAAAAAQAAABppbmZlAgAAAAABAABhdjAxQ29sb3IAAAAAamlwcnAAAABLaXBjbwAAABRpc3BlAAAAAAAAAAIAAAACAAAAEHBpeGkAAAAAAwgICAAAAAxhdjFDgQ0MAAAAABNjb2xybmNseAACAAIAAYAAAAAXaXBtYQAAAAAAAAABAAEEAQKDBAAAACVtZGF0EgAKCBgANogQEAwgMg8f8D///8WfhwB8+ErK42A=";
    });
}

// Usage
if (await isAvifSupported()) {
    // Serve AVIF images
} else {
    // Fallback to WebP or JPEG
}
```

This code tries to load a tiny base64-encoded AVIF image in the browser. If the image loads successfully (onload), the browser supports AVIF. If it fails to load (onerror), it means the browser can't decode AVIF images and we need to use a fallback format

## Implementing a format fallback

We can use the `isAvifSupported` function to implement progressive enhancement for image loading. This means using a baseline format (in this case, WebP) and using AVIF for browsers that support it.

Here's an example:

```js
const storage = new Storage(client);

async function getOptimizedImageUrl(bucketId, fileId) {

    const format = await isAvifSupported() ? ImageFormat.Avif : ImageFormat.WebP;

    return storage.getFilePreview({
        bucketId,
        fileId,
        width: 1200,
        gravity: ImageGravity.Center,
        quality: 90,
        output: format
    });
}
```

Notice that the format variable falls back to WebP if the browser doesn't support AVIF. I've used WebP because it's now widely supported. However, you can choose to fallback to JPEG for an even wider range of compatibility.

# Best practices for using AVIF in Appwrite Storage

When working with AVIF in Appwrite Storage, consider these best practices:

1. **Progressive enhancement**: Always implement fallbacks for browsers that don't support AVIF.
2. **Quality settings**: AVIF provides good quality even at lower settings. Start with a quality setting of 75-85 and adjust based on your needs.
3. **Responsive images**: Use appropriate image dimensions for different screen sizes to optimize bandwidth usage.
4. **Performance monitoring**: Monitor your application's performance metrics after implementing AVIF to ensure it provides the expected benefits.
5. **Lazy loading**: Consider implementing lazy loading for AVIF images to further optimize page load performance.

# Conclusion

AVIF support in Appwrite Storage is a practical way to make your apps faster without compromising on image quality. The code to implement it is straightforward, and the benefits are real - especially for image-heavy applications.

Start with a few test images, measure the results, and scale up from there. Your users will get faster load times, and you'll cut down on bandwidth costs.

For more details on handling images in Appwrite, check out the [Storage documentation](/docs/products/storage/quick-start).

# Further reading

- [How Appwrite streamlines database operations using hooks](/blog/post/hooks-appwrite-databases?doFollow=true)
- [5 VS Code extensions that replace entire development tools](/blog/post/5-vs-code-extensions-that-replace-entire-dev-tools?doFollow=true)
- [Budget caps: How to stop unexpected cloud bills before they happen](/blog/post/budget-caps-stop-unexpected-cloud-bills?doFollow=true)
