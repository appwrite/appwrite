---
layout: post
title: Health in ChatGPT connects your medical data to AI
description: Health in ChatGPT lets US users securely connect medical records and Apple Health so ChatGPT can reason over their real data. Here is how it works, the privacy model behind it, and what it means for developers building health apps.
date: 2026-07-24
cover: /images/blog/health-in-chatgpt-connects-your-medical-data-to-ai/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: What is Health in ChatGPT?
    answer: Health in ChatGPT is a feature that lets eligible US users connect medical records and Apple Health data so ChatGPT can provide answers grounded in their personal health information.
  - question: What health data can ChatGPT access?
    answer: With permission, ChatGPT can use connected information such as medications, lab results, medical visits, sleep, activity, and other data shared through Apple Health or supported healthcare providers.
  - question: Is Health in ChatGPT available outside the US?
    answer: Health in ChatGPT is currently rolling out to logged-in users aged 18 and older in the US across the Free, Go, Plus, and Pro plans.
  - question: Can users delete their connected health data?
    answer: Yes. Users can disconnect a health data source at any time. OpenAI says information synced from that source is deleted from its systems within 30 days.
---
More than 300 million people ask ChatGPT a health question every week. They paste in a lab result, describe what a doctor said, or try to make sense of a symptom. The problem is that the context behind those questions lives somewhere else. It is scattered across patient portals, medical records, fitness apps, and wearables, and none of it is in the chat.

[Health in ChatGPT](https://openai.com/index/health-in-chatgpt/) is OpenAI's answer to that gap. It lets logged-in US users securely connect Apple Health and supported medical records so ChatGPT can use relevant personal context to provide more informed, personalized responses. This post covers what shipped, the privacy model behind it, and what it means if you are building health apps of your own.

# What is Health in ChatGPT?

**Health in ChatGPT is a feature that lets logged-in US users connect Apple Health and supported medical records so ChatGPT can draw on relevant personal information when responding.** With your permission, ChatGPT can draw on connected information like medications, lab results, recent visits, sleep, and activity when it responds. You can compare a new result against prior tests, summarize what changed since your last appointment, or explore how sleep and workouts relate to your routine.

It is rolling out to logged-in ChatGPT users 18 and older in the US, on web and iOS, across the Free, Go, Plus, and Pro plans. You open **Health** from the sidebar to get started. It is not yet available in Codex.

The point is to reduce repetitive work. Once information is connected and synced, ChatGPT may use relevant Health context in conversations when the user permits it, reducing the need to repeatedly gather, upload, or explain the same details.

# What data can you connect?

You can ask health questions in ChatGPT without connecting anything at all. To get answers grounded in your own information, you choose what to connect from a short list of supported sources:

* **Apple Health**, including data that wearable, fitness, and nutrition apps share into it.
* **Supported medical records** from US hospital systems.
* **One Medical** and **Function Health**.

If you use a wearable or nutrition app, the data flows through Apple Health. Turn on sharing in that app, connect Apple Health to ChatGPT, and ChatGPT can use what the app exposes when you allow it. Available metrics vary by app, and some proprietary scores may not transfer.

After connecting, you can review conditions and medications, remove anything that is out of date, and add missing detail like family history. Connected records are not always current. A medication can stay listed after you stop taking it, so OpenAI recommends telling ChatGPT when something changes and checking important details against the original source.

# Why OpenAI moved health out of a separate space

Earlier in 2026, OpenAI tested a dedicated health experience that lived in its own space inside ChatGPT. The data showed people did not want the detour. **More than 70% of health-related conversations happened outside that dedicated space**, because health questions come up mid-task.

Someone planning meals wants ChatGPT to account for a food allergy. Someone choosing a weekend activity wants it to remember a recent injury. Forcing them into a separate tab added a step for no reason.

Once your information is synced, you can ask questions anywhere in ChatGPT. When relevant, ChatGPT may use connected Health information based on your permissions. You can also type @Health to explicitly ask ChatGPT to include that context. The Health section in the sidebar remains the home base for connecting and managing information, viewing recent data and trends, browsing synced records, and returning to past Health conversations.

# The models behind health conversations

OpenAI uses dedicated training to improve how its models handle health. Two are in play here.

**GPT-5.5 Instant** is available on the Free plan and made gains in recognizing when urgent care may be needed, asking for missing context, explaining uncertainty, and making complex information readable. On OpenAI's hardest health evaluations, it performed at a level comparable to the frontier Thinking models available at the time.

**GPT-5.6 Sol** is available to paid users and is OpenAI's strongest health model yet. It is tuned for tasks that require reasoning across complex details, such as explaining a visit note in plain language, tracking how lab results change over time, or preparing questions for a follow-up appointment.

To measure this, OpenAI works with hundreds of physicians to build realistic scenarios and detailed rubrics that score accuracy, safety, communication, context awareness, completeness, and appropriate escalation. Every GPT-5.6 model outperformed GPT-5.5 on [HealthBench Professional](https://openai.com/index/healthbench/), OpenAI's benchmark for challenging health tasks. Physicians also tested Health in ChatGPT before release to check real-world performance with connected data.

None of this makes the output infallible. OpenAI states plainly that ChatGPT can still make mistakes and does not replace the judgment of qualified medical professionals, and it encourages users to verify important information with their provider.

# How does Health in ChatGPT protect your data?

**Health in ChatGPT is built with layered safeguards, and connected data is never used to train foundation models or target ads.** This is the part developers should study closely, because it is a working example of how to handle sensitive data with the right defaults. The model is built on four ideas.

* **Extra encryption on top of the baseline.** All ChatGPT conversations are encrypted at rest and in transit. Connected medical records and Apple Health data get additional encryption on top of that.
* **No training, no ads.** Connected records, Apple Health data, and any conversation that uses them are excluded from foundation model training and ad targeting, regardless of your general model-training setting.
* **Permission before use.** By default, ChatGPT asks before it uses connected data to personalize a response. You can approve each request or choose to always allow access. You control it in Settings.
* **Disconnect and delete.** OpenAI says data synced from that source is deleted from its systems within 30 days. Information already included in existing ChatGPT conversations remains until the user deletes those conversations.

There are extra checks on sharing too. Before ChatGPT takes an action in another plugin that could expose health information, like sending a training plan built from your Apple Health data, safeguards verify the sharing matches your request, and some sensitive actions require confirmation. Memories can be created from health conversations to personalize future chats, but never directly from connected records, and you can turn memory off or use Temporary Chat.

# What Health in ChatGPT means for developers building health apps

The pattern here is bigger than one feature. Users now expect health tools to be personalized, private by default, and under their control. If you are building anything that touches health data, the bar OpenAI just set is the one your users will hold you to.

That bar is largely a compliance and infrastructure problem. Whether HIPAA applies depends on the app’s role and relationships. It generally applies to covered entities and business associates that create, receive, maintain, or transmit Protected Health Information. Other consumer health apps may be governed by different federal or state privacy requirements. For regulated applications, compliance requires appropriate administrative, physical, and technical safeguards, supported by documented policies and risk management. That is a lot to run yourself.

This is where a backend that handles the platform layer for you matters. [Appwrite Cloud is HIPAA compliant](/blog/post/announcing-appwrite-is-hipaa-compliant), so the technical and organizational safeguards a health app needs are already in place. A few primitives map directly onto the model OpenAI shipped:

Encryption can be enabled for supported database text columns and Storage buckets. When bucket encryption is enabled, Storage files smaller than 20 MB are encrypted, while files above 20 MB are not. Encrypted database columns also cannot be queried. Review these limits and use an appropriate additional encryption approach for PHI that falls outside them. See the [encryption docs](/docs/advanced/security/encryption).
* **Permissions** let you scope read and write access down to the individual document, so a patient's records are only ever readable by that patient and the roles you explicitly grant. Read the [permissions docs](/docs/products/databases/permissions).
* **Authentication** gives you MFA, session management, and OAuth out of the box, so you are not hand-rolling the front door to sensitive data. Start with [Appwrite Auth](/docs/products/auth).
* **Audit logs** track who accessed what, which is a requirement rather than a nice-to-have under HIPAA.

You still own your side of the line. Appwrite covers the platform, but you remain responsible for how your app collects, displays, and shares Protected Health Information, including correct permission configuration and obtaining consent. If you are shipping a HIPAA-regulated app on Appwrite Cloud, you also need a Business Associate Agreement in place before going live with real patient data. The full picture is in the [Appwrite security documentation](/docs/advanced/security).

# Building a HIPAA-compliant health app on Appwrite

Health in ChatGPT connects medical data to AI for consumers. If you are building the health app behind that kind of experience, you need a backend that treats sensitive data with the same seriousness: encrypted, permissioned, auditable, and compliant from day one.

[Start for free on Appwrite Cloud](https://cloud.appwrite.io/) and let Appwrite handle Auth, Databases, Storage, and Functions while you focus on the product. Before you go live with Protected Health Information, reach out through the [contact form](/contact-us) to set up a Business Associate Agreement.

# Resources

* [Appwrite is now HIPAA compliant](/blog/post/announcing-appwrite-is-hipaa-compliant)
* [Appwrite security documentation](/docs/advanced/security)
* [Encryption in Appwrite](/docs/advanced/security/encryption)
* [Permissions in Appwrite](/docs/products/databases/permissions)
* [Appwrite Auth](/docs/products/auth)
* [Join the Appwrite Discord](https://appwrite.io/discord)
