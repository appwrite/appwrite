---
layout: post
title: Announcing image transformation pricing
description: To keep Appwrite Cloud sustainable, we are introducing pricing for image transformations.
date: 2025-03-03
lastUpdated: 2026-05-22
cover: /images/blog/announcing-image-transformations-pricing/cover.avif
timeToRead: 4
author: eldad-fux
category: product
featured: false
callToAction: true
faqs:
  - question: "What is an origin image in Appwrite?"
    answer: "An origin image is the original, unmodified image file stored in [Appwrite Storage](/docs/products/storage). It's the source for any transformation (resize, crop, quality change, filter). Each origin image supports unlimited transformations, and you're only billed once per origin image regardless of how many variants you generate."
  - question: "How are image transformations priced on Appwrite Cloud?"
    answer: "The Pro plan includes 100 origin images per month at no extra charge, and Enterprise has custom limits. Beyond the quota, additional origin images cost $5 per 1,000 images. There's no per-transformation fee, so applying 200 transformations across 50 origin images counts as 50 origin images, not 200. See the [pricing page](/pricing) for details."
  - question: "How do I transform an image with Appwrite?"
    answer: "Upload the image to [Appwrite Storage](/docs/products/storage), then use the `getFilePreview` endpoint with parameters like width, height, quality, opacity, border, and format. The transformed image is returned by the preview endpoint. Cache the result on the client or CDN to avoid regenerating the same variant repeatedly."
  - question: "Does counting unique origin images mean variants are free?"
    answer: "Generating multiple variants from the same source image doesn't add to the origin image count, so resizing a single image into thumbnail, mobile, and desktop sizes still counts as one origin image. This makes the pricing predictable when you serve responsive images at multiple breakpoints."
  - question: "Is image transformation pricing applied to self-hosted Appwrite?"
    answer: "No, the pricing applies only to [Appwrite Cloud](/pricing) on Pro and Enterprise plans. Self-hosted Appwrite has no usage charges because you run the infrastructure. The same `getFilePreview` API works the same way in both."
  - question: "How can I keep image transformation costs predictable?"
    answer: "Cache transformed images on a CDN or your client so the same variant isn't regenerated on every request. Use consistent image dimensions across breakpoints so you don't accidentally bloat the number of unique origin images. Monitor usage from your organization's usage page to spot unexpected spikes early."
---

Image transformations are essential for modern applications, allowing developers to dynamically resize, crop, and modify images to suit different devices and use cases. With features like width and height adjustment, quality control, and various filters including opacity and border modifications, image transformations help deliver the perfect image for every situation while optimizing performance and user experience. This service has become a fundamental part of many applications, and we've worked hard to make it powerful yet simple to implement.

When we launched Appwrite Cloud in beta, our focus was on building a robust platform that provided maximum value to our users. We intentionally offered many services without charge, even when they required significant computational resources - like image transformations, which demand substantial processing power and storage capacity to handle various transformations efficiently.

In recent months, we've invested heavily in our infrastructure, optimizing our image processing pipeline and implementing advanced caching mechanisms to ensure fast and reliable image delivery. Our goal has always been to provide powerful image transformation capabilities while maintaining the simplicity that Appwrite is known for.

It's been incredible to see teams leverage our image transformation features, processing millions of images monthly for their applications. This widespread adoption reinforces our commitment to building a powerful, accessible, and sustainable platform.

# Pricing update

As Appwrite continues to grow, we need to ensure that our platform remains sustainable while providing the best possible service to all users. The new pricing will be effective __starting April 1st, 2025__, and will only be available on paid plans.

## What will change

We will begin charging for origin images used in transformations. To use this functionality, you will need to be on the Pro or Enterprise plan. The Pro plan includes 100 origin images per month at no additional charge, with additional origin images at $5 per 1,000 images. Enterprise has custom limits.

This change will help support the computational resources required for this valuable service and enable us to continue enhancing our platform.

Please refer to our [Pricing Page](/pricing) for detailed pricing information, including origin image costs and included quotas for different plans.

![console image](/images/blog/announcing-image-transformations-pricing/usage-component.avif)

# How it works

Appwrite enables the transformation of images before retrieval using the [getFilePreview](/docs/references/cloud/client-web/storage#getFilePreview) endpoint. This functionality supports resizing images by width and height, adjusting quality, and applying filters such as opacity, border color, border radius, and more.

## Origin image transformations

An "origin image" represents the original, unmodified image file in Appwrite Storage. Each origin image serves as the base for unlimited transformations, allowing the creation of multiple variants without incurring additional origin image charges.

**How it works:**
1. Upload an image to Appwrite Storage
2. Use the `getFilePreview` method to apply transformations to the image
3. Retrieve transformed images through the preview endpoint
4. Pay only for unique origin images, regardless of transformation count

For example, suppose there are around 100 images in storage. If only 50 of these images undergo transformations, but transformations are applied around 200 times, the origin image transformations are only 50 and **not 200**.

# Your usage

To help prepare for these changes, teams can review their current image transformation usage through their organization's usage page or the usage section of specific buckets. This will help you understand your usage patterns and plan accordingly.

# We're here to help

If you have questions about this pricing change or need assistance optimizing your image transformation usage, please don't hesitate to reach out to us at [billing@appwrite.io](mailto:billing@appwrite.io).

Your success is our priority, and we're committed to helping you make the most of Appwrite's image transformation capabilities while keeping costs predictable and manageable.
