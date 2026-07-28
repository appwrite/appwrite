---
layout: post
title: "25 startup ideas you can build with vibe coding"
description: "25 concrete startup ideas a solo founder can build with vibe coding tools like Cursor, Claude Code, and Lovable, plus the backend primitives each one needs."
date: 2026-05-11
lastUpdated: 2026-06-29
cover: /images/blog/25-startup-ideas-you-can-build-with-vibe-coding/cover.avif
timeToRead: 12
author: aditya-oberai
category: startup
unlisted: true
faqs:
  - question: "What kinds of startup ideas are best for vibe coding?"
    answer: "Anything with a clear user, a narrow scope, and a small set of backend primitives: auth, a few tables, file uploads, a payment hook, maybe a scheduled function. Vibe coding tools like Cursor, Claude Code, Windsurf, Lovable, and Bolt are excellent at scaffolding apps in this shape. The ideas in this post all fit that profile."
  - question: "Do I need to know how to code to build these?"
    answer: "It helps. Tools like Lovable and Bolt let non-developers ship a working version, but every idea here will hit a wall the first time a user reports a real bug. Knowing enough to read a diff, understand permissions, and check that secrets are not in the client bundle is the difference between a demo and a startup."
  - question: "What backend should I use for these ideas?"
    answer: "Pick a backend that covers auth, databases, storage, server-side functions, and hosting under one model. [Appwrite Cloud](https://cloud.appwrite.io) gives you all of that with an MCP server so your AI tool can act on the project. It also reduces the number of vendors a solo founder has to manage in the first six months."
  - question: "How long does it take to build a startup with vibe coding?"
    answer: "A working prototype takes hours. A launched product takes weeks because of the parts vibe coding does not solve: permissions, payments, support, marketing, and the production review every app needs before users touch it. The [backend checklist for vibe-coded apps before launch](/blog/post/backend-checklist-vibe-coded-apps) covers the engineering side of that gap."
  - question: "Are these ideas original?"
    answer: "Some are crowded markets, some are open. The point is not that the idea is unique. The point is that vibe coding has lowered the cost of trying any of them to the point where the experiment is worth running. A founder can ship version one of three of these in a month and let users decide which one is the company."
---

The hard part of starting a software company used to be building software. Vibe coding moved that bar. An idea you would have shelved last year because it was too small to justify the work now fits inside a weekend. The bottleneck has shifted to taste, distribution, and execution.

This post is a list of 25 startup ideas a founder can ship with vibe coding tools like Cursor, Claude Code, Windsurf, Bolt, or Lovable. The first half is the ideas. The second half is what it actually takes to build them and how Appwrite's offerings and AI plugins map to each piece. Pick one, build a version this week, and let users decide whether it is a company.

# 25 startup ideas to build with vibe coding

## 1. AI-powered meal planner from your fridge

Snap a photo of your fridge, the app reads the ingredients, generates a week of meal plans, and writes a grocery list for the gaps. Real examples already exist. The reason this idea keeps showing up is that the unit of value is concrete and the moat is recipe quality, not engineering. The shape is auth, a table for plans, a bucket for fridge photos, and a function that calls a vision model.

## 2. Personalized bedtime stories

Generate a bedtime story adapted to a child's age, name, interests, and mood. Save the favorites, replay them, let parents share a story with grandparents. A small table for kids, another for stories, a function for generation, a bucket for narrated audio. It is one of the most repeatable solo founder wins of the last year.

## 3. AI tutor for a single subject

Pick one subject. SAT math, conversational Spanish, music theory, intro to chemistry. Build a tutor that explains, quizzes, and tracks progress. The market for a general AI tutor is crowded. The market for a great tutor in one specific exam is open in every country. Auth, a progress table, a function that prompts the model with the student's history.

## 4. Health log with weekly AI summary

Users log meals, sleep, mood, workouts. A scheduled function summarizes the week and flags patterns. The hard part is not the AI. It is making logging fast enough that people do it. A single table, a scheduled function on Sunday night, and a clean mobile UI.

## 5. Receipt-to-expense report

Photograph a receipt, the app extracts the vendor, amount, tax, and category, and groups receipts into an expense report you can email or sync to QuickBooks. Buckets for the images, a table for receipts, a function for extraction, a function for export. Small businesses pay for this every month.

## 6. AI study companion that quizzes you from your notes

Drop in a PDF, the app indexes it, then quizzes you with cards, short answers, and timed reviews. The retention engineering is the hard part, not the AI. A single user, a few tables for documents and reviews, and a function that turns a section into questions.

## 7. Personalized workout generator

Generate a daily workout based on goals, equipment, and what the user did yesterday. The product wedge is not the workout. It is how the app handles missed days, plateaus, and injuries. A table for sessions, a function that builds tomorrow's workout from history.

## 8. AI-powered customer support inbox

A small business pastes their FAQ and past tickets. The app drafts replies, the human reviews and sends. The price stays under what a part-time support hire costs and the AI handles the boring 60 percent. Team-based auth, tables for tickets, a function that drafts a reply.

## 9. Niche newsletter that writes itself

Pick a topic with a few feeds. A scheduled function reads the feeds every morning, summarizes the relevant items, and drafts a newsletter for the human to edit and send. Substack-level UX is not the moat. Topic selection is.

## 10. Personal CRM for solo founders

Track everyone you talk to: investors, customers, collaborators. Set follow-up reminders, log conversations, ask an AI to draft the next email. The product is simple. The behavior change is the hard part. A few tables, a scheduled function for reminders, a function that drafts follow-ups.

## 11. AI-generated postcards from playlists

Turn a Spotify playlist into a physical postcard with a QR code. Real examples already exist. The category is wide open for variations: poetry from photos, art prints from journal entries, custom posters from saved tweets. A bucket for art, a function for generation, a Shopify or Lemon Squeezy integration for fulfillment.

## 12. Audio transcription with shareable clips

Drop in a meeting recording, the app transcribes it, the user marks the interesting parts, and the product turns those into shareable clips. Founders, podcasters, and students all pay for the same workflow. A bucket for audio, a function for transcription, a table for clips.

## 13. Resume tailoring for a specific job description

Paste a resume, paste a job description, the app tailors the resume to the job, drafts a cover letter, and tracks every application. The job market is large and the UX bar is low. A table for applications, a function per draft, a clean export.

## 14. Tiny SaaS that wraps an API your customers should not have to wrap

Pick a public API your audience uses awkwardly: a translation service, a calendar invite generator, a screenshot tool, a tax lookup, a courier rates aggregator. Build the UX their workflow deserves. Vibe coding gives you the building speed. Distribution decides the company.

## 15. AI-assisted journaling app

Daily prompt, the user writes, the app summarizes and reflects back patterns over weeks and months. The retention play is making the user feel seen. A table for entries, a scheduled function for the weekly summary.

## 16. Couple's shared planner

Shared grocery list, shared calendar, shared notes, with an AI that nudges the easy stuff (rent due, anniversaries, who is picking up what). A two-person account model, a handful of tables, live updates for shared edits, a scheduled function for reminders.

## 17. AI-generated quiz from a YouTube video

Paste a video URL, the app pulls the transcript, generates a quiz, and lets a teacher assign it. The vertical is teachers who use video in class. Team-based auth, a table for quizzes, a function per generation.

## 18. AI-curated job board for a niche

Pick a niche: AI engineers in Berlin, designer-engineers, technical PMs in fintech. A scheduled function scrapes a few sources, the AI filters and ranks, the board ships every Monday. Distribution is the hard part. Vibe coding makes the curation engine cheap.

## 19. Habit tracker with peer accountability

Small groups of three to five users commit to a habit. The app posts daily check-ins to the group. An AI summarizes the week. A group account model covers the social layer. Live updates keep the check-ins flowing.

## 20. Local business AI website builder

A laundromat, a dentist, a wedding venue gets a working site from a phone-call-length intake. The agent generates content, picks a template, and deploys the site to a custom domain. The market is enormous and underserved. Vibe coding is the only way a solo founder can serve it cheaply.

## 21. AI-powered code review for vibe-coded projects

A meta play. Solo founders shipping with Cursor, Claude Code, or Lovable still need a second pair of eyes. The app reviews a PR or a generated diff for the classic vibe coding failure modes: missing permission checks, leaked secrets, unvalidated inputs, missing indexes. A function per review, a small dashboard, a Stripe subscription.

## 22. AI-narrated audio version of any newsletter

User pastes a newsletter URL. The app generates a clean, listenable audio version and adds it to a personal podcast feed. The unit economics depend on TTS pricing, which keeps dropping. A bucket for audio, a function for narration, a hosted feed endpoint.

## 23. AI bookkeeper for solo operators

Connect a bank account, the app categorizes transactions, drafts a P&L, and answers questions about your finances. The market is large. The compliance work is real, which is why vibe coding lets you build a v1, but launch is when the real engineering starts. The [backend checklist for vibe-coded apps before launch](/blog/post/backend-checklist-vibe-coded-apps) is non-optional here.

## 24. Photo organizer with smart albums

Upload photos, the app groups them by event, person, and location, then suggests photo books, video recaps, and shared albums for trips. A bucket for photos, a function for grouping, a table for albums, live updates for shared views.

## 25. AI assistant for a single profession

Pick a profession with paperwork: real estate agents, electricians, paralegals, fitness coaches, daycares. Build the one workflow that takes them an hour a day and let an AI shorten it to ten minutes. Auth, a few tables, a function for the workflow, an integration with the one tool that profession actually uses.

# What it takes to build these and how Appwrite fits

Every idea on this list runs on the same primitives. Auth, a handful of tables, file storage, a function or two, sometimes live updates, and a hosted frontend. The work is in the product, not in re-inventing the backend each time. The shorter the path from prompt to production, the more weekends you get to spend on the parts only you can build.

## The backend primitives every idea needs

Read through the 25 ideas above and the same shapes keep showing up.

- **Auth.** Every idea has users. Most need email and OAuth, a few need MFA, and the multi-user ideas (8, 16, 17, 19) need a group or team account model.
- **Databases.** Tables for users, content, sessions, applications, entries, reviews, transactions. Typed columns, relationships, queries, and a permission model that does not require a custom RBAC layer.
- **Storage.** Buckets for fridge photos, receipts, audio recordings, generated art, narrated stories, photo libraries. Size limits, MIME limits, antivirus, encryption.
- **Functions.** Server-side logic for every AI call, every third-party integration, every payment hook, every export. Anything touching money, secrets, or third-party APIs belongs here.
- **Scheduled jobs.** Weekly summaries, daily curation, reminders, newsletter generation. A handful of these ideas only exist because a CRON trigger does the work while the user sleeps.
- **Realtime.** Live updates for shared planners, group check-ins, multi-user dashboards.
- **Hosting.** A custom domain, env vars, rollbacks, logs, and a deployment story that does not require manual ops on every push.

A backend that ships all seven under one model is the difference between a weekend launch and a month of wiring.

## How Appwrite covers all of it

[Appwrite Cloud](https://cloud.appwrite.io) is shaped for this exact list.

- [Auth](/docs/products/auth) handles email and password, OAuth, magic URL, email and phone OTP, MFA, sessions, and [Teams](/docs/products/auth/teams) for the multi-user ideas.
- [Databases](/docs/products/databases) cover tables, typed columns, rows, relationships, queries, transactions, and row-level permissions.
- [Storage](/docs/products/storage) gives you buckets with size and MIME limits, antivirus, encryption, image transformations, and signed URLs.
- [Functions](/docs/products/functions) cover server-side logic in Node, Python, Go, PHP, Dart, Ruby, Deno, Rust, and more, with execution logs, env vars, CRON triggers, and event triggers.
- [Sites](/docs/products/sites) hosts the frontend with custom domains, env vars, rollbacks, and build logs.
- [Realtime](/docs/apis/realtime) covers live updates for any idea that needs them.

One project, one set of primitives, one vendor to manage in the first six months.

## The AI plugins that close the loop

The backend is half the story. The other half is how your AI tool talks to it. Appwrite ships the pieces that turn a vibe coding session into something an agent can actually execute against your project.

- **[API MCP server](/docs/tooling/ai/mcp-servers/api).** Your agent in Cursor, Claude Code, or Windsurf can create users, manage tables, run queries, invoke functions, and operate on storage through tool calls instead of synthesized HTTP requests. Scope the API key to the resources the agent should manage, and the blast radius shrinks with it.
- **[Docs MCP server](/docs/tooling/ai/mcp-servers/docs).** The agent reads current Appwrite docs at call time instead of relying on whatever was in its training cutoff. Method names stop hallucinating.
- **[Agent Skills](/docs/tooling/ai/skills).** Curated skills for the Appwrite CLI and every major SDK, including TypeScript, Dart, .NET, Go, Kotlin, PHP, Python, Ruby, Rust, and Swift. The agent picks the right SDK pattern instead of guessing.
- **Editor plugins for [Claude Code](/docs/tooling/ai/ai-dev-tools/claude-code), [Cursor](/docs/tooling/ai/ai-dev-tools/cursor), and [Codex](/docs/tooling/ai/ai-dev-tools/codex).** One-step install for MCP, docs MCP, and skills. Open the editor, start prompting, the agent can already act on your project.

If you are still picking the AI tool to build with, [Best vibe coding tools in 2026](/blog/post/comparing-vibe-coding-tools) walks through the trade-offs.

# Shipping your first vibe-coded startup with Appwrite

Pick one idea from this list. Spend a weekend on a working version. Send it to ten people who fit the user profile. The first signal will tell you whether the company is real, and the second will tell you what to build next.

The leverage from vibe coding compounds only if you ship. The backend is what stops your prototype from becoming the bottleneck the moment the first user shows up.

- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)
- [Appwrite AI tooling](/docs/tooling/ai)
- [Appwrite Functions](/docs/products/functions)
- [Appwrite Sites](/docs/products/sites)
