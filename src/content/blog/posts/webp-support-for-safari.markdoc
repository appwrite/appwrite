---
layout: post
title: "WebP support now available for Safari on all devices"
description: With WebP now supported in Safari, Appwrite developers can use this format on any browser and device.
date: 2024-10-02
cover: /images/blog/webp-support-for-safari/cover.avif
timeToRead: 3
author: eldad-fux
category: product
faqs:
  - question: "What is WebP and why does it matter?"
    answer: "WebP is a modern image format that compresses better than JPEG and PNG while keeping similar quality. It typically reduces image size by around 25 to 35 percent, which means faster page loads, lower bandwidth bills, and a better experience on mobile networks."
  - question: "Is WebP supported on Safari now?"
    answer: "Yes. Safari has supported WebP since Safari 14 (macOS Big Sur and iOS 14), so every currently supported Apple OS version can decode WebP. Appwrite Cloud now serves WebP directly to Safari instead of falling back to PNG."
  - question: "Do I have to change anything in my app to get the smaller images?"
    answer: "No. If you use [Appwrite Storage](/docs/products/storage) image previews with output=webp, Safari clients now get WebP automatically. There are no SDK changes, no new parameters, and no extra requests."
  - question: "Will old Safari versions still work?"
    answer: "Versions that pre date Safari 14 are not part of the supported matrix on modern macOS or iOS. For practical purposes, all currently used Safari versions handle WebP, so the PNG fallback path is no longer triggered."
  - question: "How much will my image sizes drop after the switch?"
    answer: "Expect roughly a 25 to 35 percent reduction compared to PNG for typical photos and UI assets, with even larger savings on graphics and screenshots. Real numbers depend on the source image, but the change is automatic so you can measure it on your own dashboards."
  - question: "Does this work for both Cloud and self hosted Appwrite?"
    answer: "WebP delivery is rolled out on Appwrite Cloud first. Self hosted instances inherit the same behavior in the matching release, so check your version's changelog if you run Appwrite on your own infrastructure."
---
We’re happy to share that Appwrite Cloud users now have full WebP support across all versions of Safari browsers and devices.

When we first introduced this feature in the early versions of Appwrite, Safari didn’t support WebP, which created challenges for developers using this format. To maintain application integrity, we automatically fell back to the PNG format, ensuring cross-browser compatibility but at the cost of larger file sizes and slower load times.

However, as outlined on [Can I use WebP?](https://caniuse.com/webp), Safari has supported WebP for quite some time now. With this update, Appwrite has fully embraced WebP across all Safari browsers and devices, eliminating the need for fallback solutions. This update is now available to all Appwrite Cloud users, making image delivery more efficient across all platforms.

# Why WebP matters

WebP is a modern image format offering superior compression while maintaining high image quality. Compared to traditional formats like JPEG and PNG, WebP can reduce image sizes by up to 30%, resulting in faster page load times, lower bandwidth usage, and an overall better user experience. This is especially critical for developers looking to optimize their applications for performance and scalability.

With WebP now fully supported in Safari, Appwrite developers can take full advantage of this format across all browsers and devices. This improvement will lead to reduced latency, less bandwidth consumption, and cost savings - particularly for Cloud accounts, where efficiency matters most.