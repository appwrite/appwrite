---
layout: post
title: How Pink Design helped us improve web accessibility
description: Appwrite’s open-source UI library drastically improved our web accessibility.
date: 2022-11-14
cover: /images/blog/accessibility-in-pink-design/cover.avif
timeToRead: 3
author: arman
category: accessibility, design
faqs:
  - question: "What is Pink Design?"
    answer: "Pink Design is Appwrite's open-source UI library and design system, used to build the Appwrite Console and available for anyone to use in their own products. It targets WCAG AA accessibility, the recommended baseline for most products."
  - question: "What WCAG conformance level should I aim for?"
    answer: "WCAG AA is the standard most teams target and what regulators (like the European Accessibility Act) typically expect. AAA is stricter but harder to maintain. Aim for AA across your product, then push toward AAA for high-impact flows like signup and checkout."
  - question: "Why use REM instead of pixels for font size?"
    answer: "Pixels are absolute and ignore the user's browser font-size preference, which users with low vision often increase. REM is relative to the root font size, so increasing the browser default scales your whole app proportionally. It is a one-line change with a meaningful accessibility impact."
  - question: "How do I respect a user's reduce-motion preference?"
    answer: "Check the `prefers-reduced-motion` media query in CSS or JavaScript and skip or shorten animations when it is set. Some users get vertigo or seizures from motion, so this is not optional for accessible apps. The setting is exposed by the OS, so your app inherits it automatically once you support the query."
  - question: "Why should I avoid relying on color alone to convey information?"
    answer: "Around 1 in 12 men have some form of red-green color blindness, and others have low contrast vision. Use icons, labels, or shape changes alongside color so the meaning still comes through. Appwrite uses red/orange/green/blue for error, warning, success, and info, but always pairs them with text or icons."
  - question: "Is the Appwrite Console accessible?"
    answer: "Appwrite's Console is built on Pink Design, which targets WCAG AA, including high-contrast colors, full keyboard navigation, REM-based font sizes, and reduce-motion support. The team continues to improve coverage in line with the underlying design system."
---

When creating products, accessibility can be an afterthought. Understandably, we want to ship our products fast and deliver value to our users. We might think that accessibility is needed for edge cases and therefore not prioritize it. It's good to be reminded that the World Health Organization (WHO) estimates that 16% of the global population has some form of disability (Dec 2022).Ignoring such a significant part of your user base simply doesn't create a good user experience.Creating accessible products is everyone's responsibility. Designers, developers, content authors, and whoever else is involved in creating products should do their part and strive towards achieving a better experience for everyone.

It's not always easy to maintain a high level of accessibility, but it's definitely easier with a design system. The components we created in Pink Design, Appwrite's fully open source UI library, have an accessibility level of AA. This is the recommended level of accessibility for most products.

To ensure our products will maintain a high accessibility level, we did the following:
- Use high color contrast
- Not relying on color
- Allow keyboard navigation
- Define font size in REM
- Allow users to reduce motion

# Use high color contrast

Color contrast might be the first thing that comes to mind when thinking about accessibility. A lack of contrast between the text and background might mean some people would be unable or have difficulty reading the text. Similarly, bright colors with high luminance are not readable for others. W3C recommends a contrast ratio between text and background of 4.5 to 1 for conformance level AA.

| Item         | Price     | # In stock |
|--------------|-----------|------------|
| Apples       | 1.99      | *7*        |
| Bananas      | **1.89**  | 5234       |

# Not relying on color

The term “color blindness” is often used to describe people who have trouble identifying and distinguishing between certain colors, but color blindness, the inability to see any color, is extremely rare. According to the United Kingdom National Health Service (NHS), red-green color blindness affects 1 out of 12 men and 1 out of 200 women. People with this color vision deficiency may have difficulty differentiating between reds, oranges, yellows, browns, and greens. They also might find it hard to distinguish between shades of purple and may confuse red with black.Similarly, people with “blue-yellow” color vision deficiency may have difficulty differentiating between blues, greens, and yellows.

We use four system colors in Pink Design: red, orange, green, and blue. Each of these colors represents a state in the Appwrite Console: red indicates an error or danger, orange indicates a warning, green indicates success, and blue indicates information. Knowing the difficulties our users might face while seeing these colors, we don't rely on color to make critical information understandable.

# Allow keyboard navigation

People with fine motor control restrictions or disabled hands or arms will be unable to use a mouse. In Pink Design, we provide distinct states for interactive elements. By designing states like focus, hover, and active, we provide the ability to navigate all interactive elements with a keyboard. This is not only an accessible experience but also a better experience for all users who prefer keyboard navigation, including Appwrite's developer community.

It is possible to enhance accessibility through development as well. In collaboration with our engineering team, we decided to incorporate the following into Pink Design:

![appwrite dashboard](/images/blog/accessibility-in-pink-design/cover.avif)

# Define font size in REM

Browsers have a default font size that users can change via the browser setting. A pixel is an absolute unit for fixed sizes and spaces that ignores browser settings. This means that if we are using pixels and a user (with or without vision impairment) changes the font size in their browser settings, their setting won't affect our product. That being said, pixels should not cause any problems if the user zooms in, but we make no assumptions about users' preferences. This is why we decided to define the font size in REM, which is a relative unit.

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID

const account = new Account(client);

const promise = account.createVerification({
    url: 'https://example.com'
});

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});

```

# Allow users to reduce motion

There is no doubt that animations are a nice addition to every product, but animations can also distract people. In some cases, animations can cause dizziness, vertigo, or epileptic seizures. Users that are sensitive to motion might choose to reduce motion in their operating system settings. In this case, we should skip the animation for them. In Pink Design, we decided to create a big animation to show the functionality of the library on the landing page. The animation is 10 seconds long and is the first thing you see on the page. It starts immediately when the page is loaded, but if “reduce motion” is enabled in the operating system, the animation skips to the end.

