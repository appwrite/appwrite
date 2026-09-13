---
name: writing-blogs
description: Writes, structures, and formats SEO-optimized blog posts for the Appwrite blog following established conventions for voice, tone, frontmatter, and post structure. Use when the user asks to write, draft, create, or outline a blog post, or when working with files in src/content/blog/posts/.
---
## Repository layout (vibes)

This skill targets the `appwrite/vibes` repo. Path mapping from the upstream `appwrite/website` skills:

| Artifact | Path |
| --- | --- |
| Blog post | `src/content/blog/posts/<slug>.markdoc` |
| Docs page | `src/content/docs/{path}/index.markdoc` |
| Changelog entry | `src/content/changelog/entries/<YYYY-MM-DD>.markdoc` |
| Cover image | `public/images/blog/<slug>/cover.avif` (min 1200px wide; run `bun run generate:cover-manifest` after adding) |

Also apply the `unslop` skill to all user-facing prose.



# Blog Writing

## Blog structure

Each blog post is a single file at `src/content/blog/posts/<slug>.markdoc` (e.g. `src/content/blog/posts/my-post-slug.markdoc`). Every file must start with this frontmatter:

```yaml
---
layout: post
title: "Full title as a string"
description: One sentence summary of what the post covers.
date: YYYY-MM-DD
cover: /images/blog/<slug>/cover.avif
timeToRead: <number>
author: aditya-oberai
category: product
featured: false
unlisted: true
faqs:
  - question: "A question a reader would actually ask."
    answer: "A direct answer. Markdown links to docs and inline code blocks are allowed."
  - question: "Another question."
    answer: "Another answer."
---
```

The filename (slug) must match the `cover` image path: e.g. `avoid-backend-overengineering.md` maps to `/images/blog/avoid-backend-overengineering/cover/cover.avif`.

## Voice and tone

- **Direct and opinionated.** State positions clearly. Avoid hedging language like "it depends" without then explaining what it depends on.
- **Developer-first.** Write for engineers making real decisions. No fluff, no marketing speak.
- **Practical over theoretical.** Lead with the concrete problem, then the reasoning, then the solution.
- **No unnecessary superlatives.** Don't call things "amazing" or "powerful" — show why they matter.

## Post structure pattern

1. **Hook** — Open with the real problem or a common misconception. No preamble.
2. **Establish the cost** — Why this problem matters in practice.
3. **Answer directly** — Give a concise answer to the primary search query early, before expanding on the details.
4. **Break it down** — Use useful H1/H2 sections and H3 subsections to cover each dimension clearly.
5. **Show examples** — Include concrete examples that help the reader apply the guidance.
6. **Connect to Appwrite** — Explain a relevant Appwrite use case without forcing one where it does not fit.
7. **Concrete guidance** — What to actually do. Be specific.
8. **Close with a combined CTA and resources section** — Merge "Moving forward" and "Resources" into one section. The heading must be specific to the post's topic (e.g. "Getting started with Appwrite Auth", not "Moving forward"). End with 2-4 relevant doc links found by reading the local docs in `src/content/docs/`. Only link to pages directly relevant to the post. Do not include generic links to the homepage, Discord, or GitHub unless nothing more specific exists.

## Formatting conventions

- Use `**bold**` for key terms and important callouts within paragraphs.
- Use bullet lists for enumerating capabilities, trade-offs, or steps — not for general prose.
- Avoid nested lists unless strictly necessary.
- Use H1 (`#`) for major sections, H2 (`##`) for subsections, and H3 (`###`) where a subsection needs further structure.
- Link to official docs for referenced features: e.g. `[Appwrite Sites](https://appwrite.io/docs/products/sites)`.
- Keep paragraphs short: 2-4 sentences as a rule.
- **No em dashes.** Do not use `—` anywhere. Rewrite the sentence to avoid it using a period, comma, or restructured clause.
- **Section headings must be specific and declarative.** Avoid vague closers like "Moving forward", "What this means", or "Where to go from here". Bad: "Moving forward". Good: "Getting started with Appwrite Storage".

## SEO guidelines

- **Title:** Include the primary keyword naturally. Keep it 50-60 characters. Front-load the keyword when possible (e.g. "JWT Authentication with Appwrite" not "Using Appwrite to Do JWT Authentication").
- **Description frontmatter:** Write 1 sentence, 150-160 characters, that includes the primary keyword and clearly states what the reader will learn or get.
- **Slug:** Short, keyword-rich, hyphen-separated. Match the exact topic (e.g. `appwrite-jwt-auth`, not `how-to-do-authentication-in-your-app-using-appwrite`).
- **Headings:** Use the primary keyword in at least one H1, H2, or H3. Headings should reflect real search queries developers type, not internal jargon.
- **Search intent:** Match the post type to intent. A "how to" post should answer a specific task. A comparison post should help the reader make a decision. Do not write a conceptual post when developers are searching for a tutorial.
- **Internal links:** Link contextually to relevant Appwrite docs, product pages, and related blog posts from within the body, not just the closing section.
- **External links:** Where relevant, cite credible external sources that support accuracy, trust, and E-E-A-T.

## SEO checklist

### Create the content

- [ ] The title is clear and includes the primary keyword naturally.
- [ ] The introduction is strong, establishes the problem, and uses the primary keyword naturally.
- [ ] An early direct answer section addresses the primary search query for AEO.
- [ ] Useful H2 and H3 headings organize the post and include the keyword where natural.
- [ ] Concrete examples make the guidance actionable.
- [ ] A relevant Appwrite use case connects the topic to a real developer workflow.
- [ ] Contextual internal links point to relevant Appwrite docs, product pages, and related blogs.
- [ ] The `faqs` frontmatter contains 4-7 direct, standalone answers to real search questions.
- [ ] The `description` frontmatter provides a focused meta description.

### Optimize before publishing

- [ ] The title is 50-60 characters.
- [ ] The meta description is 150-160 characters.
- [ ] The body is 1,500-2,000 words.
- [ ] The primary keyword appears naturally in the title, introduction, headings, and body.
- [ ] Internal links cover relevant docs, product pages, and related blogs.
- [ ] Direct answer sections make key answers easy for answer engines to extract.
- [ ] Clear headings, bullets, short paragraphs, and short sections make the post easy to scan.
- [ ] Credible external links support accuracy, trust, and E-E-A-T where relevant.

## What is Appwrite

Appwrite is an open-source developer infrastructure platform for building web, mobile, and AI apps. It includes both a backend server, providing authentication, databases, file storage, serverless functions, real-time subscriptions, and messaging, and a fully integrated hosting solution for deploying static and server-side rendered frontends. Appwrite can be fully self-hosted on any Docker-compatible infrastructure or used as a managed service through [Appwrite Cloud](https://cloud.appwrite.io).

## Content guidelines

- **Appwrite context:** Posts often compare Appwrite to alternatives (Firebase, Vercel, Netlify, etc.) or explain how Appwrite solves a specific developer problem. Keep comparisons fair and factual.
- **Avoid lock-in anxiety:** When recommending Appwrite, acknowledge trade-offs rather than dismissing alternatives.
- **Use real examples:** Reference actual developer workflows (Git-based deployments, serverless functions, auth flows) rather than hypothetical use cases.
- **timeToRead:** Estimate roughly 200-250 words per minute. A 1,500-word post = ~6-8 min read.

## Appwrite documentation reference

**Always read the relevant local doc files before writing a new post.** The docs live in `src/content/docs/` as `index.markdoc` files. Use the directory structure below to locate the pages relevant to the post's topic, read them, then link only to those. Every resource link in a post must be directly relevant to what the post covers. A post about authentication should link to auth docs; a post about storage should link to storage docs. Do not add links to Discord, GitHub, or the homepage unless nothing more specific exists.

The docs map to public URLs as follows: `src/content/docs/{section}/{subsection}/index.markdoc` becomes `https://appwrite.io/docs/{section}/{subsection}`. Key sections:

- **Products:** `src/content/docs/products/{auth,databases,storage,functions,messaging,sites,ai}/`
- **Quick starts:** `src/content/docs/quick-starts/{react,nextjs,sveltekit,vue,nuxt,angular,astro,solid,deno,node,go,php,ruby,python,kotlin,java,...}/`
- **Tutorials:** `src/content/docs/tutorials/{react,nextjs,sveltekit,nuxt,vue,flutter,android,apple,...}/`
- **Advanced:** `src/content/docs/advanced/{platform,security,self-hosting,migrations,integration}/`
- **APIs:** `src/content/docs/apis/{realtime,rest,graphql}/`
- **Tooling:** `src/content/docs/tooling/{command-line,command-center,assistant,mcp,skills,arena,appwriter}/`
- **SDKs:** `src/content/docs/sdks/`

**Prioritize Appwrite Cloud over self-hosting.** When linking to Appwrite, prefer `https://cloud.appwrite.io` over self-hosting documentation. Include a Cloud signup link in the resources section of every post unless the post is specifically about self-hosting or a compliance use case where self-hosting is the core recommendation (e.g. HIPAA, data residency). In CTAs and inline mentions, refer to Appwrite Cloud first and self-hosting as a secondary option.

## Adding a new blog

Copy this checklist and check off items as you complete them:

```
Blog Creation Progress:
- [ ] Step 1: Read the relevant local doc files in src/content/docs/ to find accurate content and links
- [ ] Step 2: Create src/content/blog/posts/<slug>.markdoc with correct frontmatter
- [ ] Step 3: Write the post following the structure and tone guidelines
- [ ] Step 4: Add an `faqs` block to the frontmatter
- [ ] Step 5: Verify accuracy of all Appwrite-specific claims against the local docs
- [ ] Step 6: Verify slug consistency across filename, cover image path, and internal links
- [ ] Step 7: Complete the SEO checklist
- [ ] Step 8: Generate and save the cover image
```

**Step 1: Read the local docs**

Identify the relevant sections from `src/content/docs/` based on the post's topic. Read those `index.markdoc` files directly to understand exact feature names, API method names, and configuration options. Use the directory structure described in "Appwrite documentation reference" above to navigate to the right files.

**Step 2: Create the file**

Create `src/content/blog/posts/<slug>.markdoc` with the frontmatter template from "Blog structure" above. All blog content must be written in `src/content/blog/posts/<slug>.markdoc`.

**Step 3: Write the post**

Follow the post structure pattern and voice/tone guidelines. Refer to the style reference posts below to calibrate tone and section depth.

**Step 4: Add the FAQs block**

Add an `faqs` list to the frontmatter with 4-7 entries. The FAQ block renders as a structured section on the published post and feeds rich-result eligibility, so each entry must stand on its own without depending on the body copy. Keep questions phrased the way a developer would actually search ("How do I install the Appwrite plugin for Codex?"), and keep answers direct, factual, and free of marketing language. Inline markdown links to relevant docs are encouraged.

See the [Codex plugin announcement](src/content/blog/posts/announcing-appwrite-codex-plugin) for a worked example: each question targets a real install/configuration concern, the answers reference specific commands and docs, and one question is dedicated to a non-obvious decision ("Why isn't the Appwrite API MCP server bundled with the plugin?").

**Step 5: Verify accuracy against Appwrite docs**

Re-read each local doc file linked in the post and cross-check every Appwrite-specific claim: feature names, API method names, configuration options, and behavioral descriptions. Correct any discrepancies before finalizing. Do not rely on training data for product details — always verify against the local docs. This pass must cover both the body and the FAQ answers.

**Step 6: Verify slug consistency**

Confirm the filename, cover image path, and any internal links all use the same slug.

**Step 7: Complete the SEO checklist**

Run every check in "SEO checklist" before publishing. Count the title and meta description characters, count body words without frontmatter, and correct every unmet item or clearly report it to the user.

**Step 8: Generate and save the cover image**

First, fetch the list of currently available templates so you pick from the latest set rather than a hard-coded name:

```bash
curl -s https://cover.appwrite.network/templates
```

The response lists templates across `generic`, `icons`, and `screenshots` categories, each with its `id`, required `params`, and `maxCharLimit`. Pick a template whose params match what you have (text-only posts → a `generic` template; posts with a logo or screenshot → an `icons` or `screenshots` template) and confirm the title fits within `maxCharLimit`. Default to `default` for text-only covers unless the post calls for something different.

Generate the cover by calling the cover API with the chosen `template` and required params:

```
https://cover.appwrite.network/generate?template=<template-id>&text=<URL-encoded post title>
```

Download the response and save it to `public/images/blog/<slug>/cover.avif`. Use `curl` to do this in one step:

```bash
curl -o public/images/blog/<slug>/cover.avif \
  "https://cover.appwrite.network/generate?template=<template-id>&text=<URL-encoded post title>"
```

The `cover` field in the frontmatter must match this path: `/images/blog/<slug>/cover.avif`.

## Style references

These posts represent the target style:

- [Password Protection](src/content/blog/posts/password-protection) — Security-focused with practical developer guidance, clear section structure, and no marketing fluff.
- [Push Notifications Best Practices](src/content/blog/posts/push-notifications-best-practices) — Feature-focused, explaining Appwrite capabilities in context with concrete implementation details.

## Common pitfalls

- Starting with "In this post, we will..." — just start with the argument.
- Over-explaining what the reader already knows — assume developer competence.
- Ending with "Let us know your thoughts!" — this blog doesn't use that style.
- Using passive voice where active voice is clearer.
- Writing section headers as questions — use declarative headers instead.
- Using em dashes (`—`) anywhere in the post. Use commas, parentheses, periods, colons, or semicolons instead as appropriate.
- Generic closing headers like "Moving forward", "Conclusion", or "Next steps". The final section heading must be specific to the post's topic.
- Repeating the same CTA phrasing across posts. The closing paragraph should reflect the post's specific argument, not a boilerplate Appwrite pitch.
- Linking to Discord, GitHub, or the Appwrite homepage as filler resources. Only include links the reader would actually click given the post's topic.