---
layout: post
title: "Get inspired for Hacktoberfest 2024 with these ideas"
description: Check out what the Appwrite team created during our internal hackathon and get inspired for your own project in the Hacktoberfest 2024 Hackathon!
date: 2024-09-27
lastUpdated: 2026-06-29
cover: /images/blog/get-inspired-for-hackathon/cover.avif
timeToRead: 4
author: snezhanna
category: hackathon
faqs:
  - question: "How should I pick a hackathon project idea?"
    answer: "Start with a problem you personally relate to so motivation stays high. Research how many other people face the same issue to gauge impact. Brainstorm multiple solutions before committing to one, and pick something self-sustaining that does not depend on fragile external resources."
  - question: "What Appwrite features are most useful in a Hacktoberfest project?"
    answer: "The example projects in this post used [Appwrite Auth](/docs/products/auth) for OAuth, [Appwrite Databases](/docs/products/databases) for storing app data, [Appwrite Functions](/docs/products/functions) (including the Go runtime) for backend logic, and [Appwrite Messaging](/docs/products/messaging) for emails. Mix and match based on what your project actually needs."
  - question: "Do I need to build something Appwrite-specific to participate?"
    answer: "No. Hacktoberfest itself accepts contributions to any participating open-source repo. Appwrite hackathons typically reward projects built with Appwrite, but the wider Hacktoberfest program is framework agnostic."
  - question: "How do I scope a project so I can actually finish it?"
    answer: "Cut scope aggressively. Pick one core feature that demonstrates the idea, build that to a working state, and only add extras if you have time. Most hackathon projects fail because the team tried to ship five features instead of one polished one."
  - question: "Should I work solo or in a team for a hackathon?"
    answer: "Teams move faster but coordination overhead is real. Solo lets you make decisions instantly but limits scope. If you team up, divide work along clean boundaries (frontend, backend, design) so people are not blocked on each other."
  - question: "What kind of projects perform well in Appwrite hackathons?"
    answer: "Projects that solve a concrete problem and use Appwrite features meaningfully tend to do well. The Terrazone, MailMemo, and Cord examples each address a specific remote-work pain point and use Appwrite for the parts where it actually saves time."
---
Appwrite has just announced our very own **Hacktoberfest 2024 Hackathon**, and if we know the open source community, you're probably buzzing with ideas! Hacktoberfest is the perfect chance to collaborate, build, and showcase your creativity, whether you’re an experienced developer or just getting started. But with so many possibilities, where do you start?

A few months ago, we hosted our own internal hackathon to foster collaboration, spark creativity, and put the new 1.6 features to the test before [their official release](https://appwrite.io/changelog/entry/2024-09-18). The Appwrite team rose to the challenge and delivered some amazing projects.

In this blog, we’ll highlight a few of those projects to inspire your Hacktoberfest 2024 journey!

# How do you come up with a winning project for Hacktoberfest 2024?

Seasoned hackathon contributor Aditya Oberai [shared his 4-step approach](https://appwrite.io/blog/post/the-subtle-art-of-hackathon%20ideation) to help you brainstorm and ideate for any hackathon:

- **Pick a problem you relate to**: Choose a problem that personally impacts you, as emotional connections will keep you motivated and focused throughout the hackathon.
- **Understand the problem's scale**: Research how many others are affected by the problem, increasing the value of your solution.
- **Evaluate potential solutions**: Brainstorm multiple ways to solve the problem and choose the most practical one, avoiding wasted time later on.
- **Choose a self-sustainable solution**: Pick a solution that can run independently without relying on external systems or resources for long-term success.

# Appwrite Hackathon projects

We have 3 projects we'd like to share that demonstrate a variety of ideas and approaches. We hope these examples provide some inspiration as you start thinking about your own Hacktoberfest projects.



![Terrazone](/images/blog/get-inspired-for-hackathon/1.avif)

# Terrazone: Revolutionizing global workforce management

Terrazone is a platform designed to help remote teams work better together across different time zones through a simple, user-friendly interface. 

## What problem does it solve?

Working remotely can come with challenges, such as scheduling meetings, dealing with communication delays, and building a strong team culture. Terrazone helps everyone on the team stay connected by keeping track of where they are and when they're available.

## Key features

- **Global workforce visualization**: A cool globe that shows where your team members are located and their time zones at a glance.
- **Easy access**: Simple login and registration keep your data secure and accessible.
- **User-friendly management**: Team members can easily manage their time zones, availability, and external access to their schedules.
- **Flexible scheduling**: Users can set their working hours and choose who can view their schedules for more privacy.

## Appwrite features used

- [Go runtime and SDK](https://appwrite.io/blog/post/announcing-go-support) for Functions
- [New Appwrite CLI](https://appwrite.io/blog/post/introducing-new-appwrite-cli) for Functions
- [Local development](https://appwrite.io/blog/post/announcing-local-development) to test functions
- [Appwrite Authentication](https://appwrite.io/docs/products/auth/oauth2) for authentication



![MailMemo](/images/blog/get-inspired-for-hackathon/2.avif)

# MailMemo: Simplifying calendar notifications

## What problem does it solve?

Managing multiple personal and work calendars can be overwhelming, especially with last-minute event notifications. MailMemo simplifies this by consolidating all your calendar events and sending personalized email reminders, helping you stay organized and on top of your schedule.

## Key features

- **Calendar consolidation**: Connects all your calendars in one place.
- **Customizable reminders**: Choose when and how often to receive notifications.
- **Email summaries**: Get organized updates directly in your inbox.

## Appwrite features used

- [GitHub OAuth](https://appwrite.io/docs/products/auth/oauth2) in Appwrite Authentication
- [Appwrite Databases](https://appwrite.io/docs/products/databases) and Tables
- [Mailgun provider](https://appwrite.io/docs/references/cloud/server-nodejs/messaging) on Messaging
- [New Go runtime and SDK](https://appwrite.io/blog/post/announcing-go-support) for Functions
- [New Functions ecosystem](https://appwrite.io/blog/post/introducing-functions-ecosystem) to sync calendars and schedule emails



![Cord](/images/blog/get-inspired-for-hackathon/3.avif)

# Cord: A new way to do async

Cord is a Discord bot designed to help teams be more effective when communicating through async. It comes with a few handy features like timezone and location in bio, automatic starting messages, and standup summaries.

## What problem does it solve?

Even though we've mastered the art of async communication, misunderstandings can still happen. With a global team, it’s not always clear when others are online. That’s where Cord comes in: it provides a simple way to see when your coworkers are available, making communication smoother and more efficient.

## Key features

- **/location:** Display your current location and timezone in your bio
- **/daily**: Write down your progress, and the command will organize it into a daily standup format automatically.
- **/schedule:** Schedule your message at a later time.
- **/start:** This command will notify your team you began your day, displaying local time.

## Appwrite features used

- [Bun runtime](https://appwrite.io/blog/post/why-you-need-to-try-the-new-bun-runtime) for Functions
- [Email OTP feature](https://appwrite.io/docs/products/auth/email-otp) in Appwrite Authentication
- [Appwrite Databases](https://appwrite.io/docs/products/databases) for user and project settings
- [Delayed executions](https://appwrite.io/docs/products/functions/execute)

# Final thoughts

What do all these projects have in common? Beyond being built with Appwrite 1.6, they all tackle challenges that our team faces ourselves. Our top advice is simple: create a project that solves your own pain points (or dive into Reddit or Twitter to discover common developer issues). Remember, the sky’s the limit!

If any of these projects have inspired you and sparked an idea for our Hacktoberfest 2024 Hackathon, don’t forget to [register your team and project here](https://apwr.dev/htf24-hackathon) for a chance to win a prize. Happy coding!
