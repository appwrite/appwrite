---
name: create-announcement
description: Coordinates the three artifacts that ship a new Appwrite feature announcement: docs updates under src/content/docs/, an announcement blog post under blog/announcing-<slug>/, and a changelog entry under src/content/changelog/entries/. Use when the user asks to announce, launch, or ship a feature, product, SDK, runtime, integration, or plugin.
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



# Create Announcement

## What this skill produces

A complete Appwrite feature announcement is three coordinated artifacts:

1. **Docs update** in `src/content/docs/...` — the canonical reference for how the feature works.
2. **Announcement blog** at `src/content/blog/posts/announcing-<slug>.markdoc` — narrative framing of why it matters and how to use it.
3. **Changelog entry** at `src/content/changelog/entries/<YYYY-MM-DD>.markdoc` — short summary on the changelog feed linking back to the blog and/or docs.

The docs are the source of truth. The blog cites them. The changelog links into one or both. Ship them together.

This skill builds on `writing-blogs` for voice, tone, SEO, formatting, slug conventions, and cover-image generation. Read that skill before writing the blog and complete both sections of its SEO checklist before publishing. This skill only covers what is specific to announcements.

## When to use each artifact

- **Docs only:** A small reference change. No blog, no changelog.
- **Docs + changelog:** A useful improvement that does not justify a narrative post. The changelog is the front-facing surface.
- **Docs + blog + changelog:** A launch worth telling a story about — new products, new SDKs/runtimes, major capabilities, integrations, plugins, compliance milestones.

If unsure, ask the user which subset to produce before writing anything.

## 1. Updating the docs

The docs live under `src/content/docs/`. The directory map from `writing-blogs` applies: products under `products/{auth,databases,storage,functions,messaging,sites,ai}/`, tooling under `tooling/`, SDKs under `sdks/`, and so on.

**Find the right page first.** New column types belong under `products/databases/`. New runtimes go under `products/functions/develop/`. New CLI features sit under `tooling/command-line/`. Plugins and AI dev tools live under `tooling/ai/`. Read the existing page, mirror its structure, and extend it — do not replace working content.

**Cover at minimum:**

- One paragraph on what the feature is.
- How to enable or configure it, including Console steps when applicable.
- A code example. If the surrounding page already shows examples across SDKs, add the new example to every `{% multicode %}` block on that page so coverage stays even.
- An entry in any reference table on the page (supported column types, supported runtimes, available scopes, etc.).

**Verify accuracy against the live docs.** Read sibling `index.markdoc` files to confirm exact feature names, method signatures, parameter names, and configuration keys. Do not rely on training data for product details.

## 2. Writing the announcement blog

Announcement blogs live at `src/content/blog/posts/announcing-<slug>.markdoc`. Voice, tone, SEO, slug consistency, em-dash rules, and cover-image generation all follow `writing-blogs` — read it before drafting.

### Announcement-specific frontmatter

```yaml
---
layout: post
title: "Announcing <feature>: <one-line value prop>"
description: One sentence, 150-160 chars, on what shipped and why it matters. Include the primary keyword.
date: YYYY-MM-DD
cover: /images/blog/announcing-<slug>/cover.avif
timeToRead: <number>
author: <author-slug>
category: announcement
featured: false
faqs:
  - question: "..."
    answer: "..."
---
```

- `category` must be `announcement`, not `product`.
- Save the cover as `.png` at `public/images/blog/announcing-<slug>/cover.avif`. `bun run optimize` converts it to `.avif` at build time, so authored files stay `.png` and the frontmatter path also stays `.png`.
- `faqs` is optional but recommended. Each FAQ is a question developers actually ask, with an answer that stands alone and links to docs inline where helpful. The FAQs render on the post page and feed structured data for SEO.

### Announcement post structure

1. **Setup the gap** — one short paragraph naming the problem or rough edge that existed before today.
2. **The announcement line** — a single sentence with the feature bolded: "Today, we are announcing **<feature>**." Keep it punchy.
3. **What this gives you** — bulleted list of the concrete capabilities shipped.
4. **How to use it** — Console steps and/or a `{% multicode %}` block. Mirror the SDK coverage of the docs page you updated.
5. **When to reach for it** — a short subsection that helps the reader decide if the feature applies to their use case.
6. **Get started / Resources** — closing section with 2-4 specific doc links. Same rules as `writing-blogs`: no filler links to Discord, GitHub, or the homepage unless nothing more specific exists. The closing heading must be specific to the topic.

**Lift narrative into the blog, not reference material.** Exhaustive parameter tables, full SDK setup, complete API surfaces belong in the docs. The blog motivates, frames, and points readers at the docs.

## 3. Adding the changelog entry

Changelog entries live at `src/content/changelog/entries/<YYYY-MM-DD>.markdoc`. If a same-day entry already exists, suffix the next file with `-2` (for example `2026-05-12-2.markdoc`).

### Frontmatter

```yaml
---
layout: changelog
title: "Specific headline of what shipped"
date: YYYY-MM-DD
cover: /images/blog/announcing-<slug>/cover.avif
---
```

- `title` is a sentence-style headline, not a generic noun phrase. Compare "Store 64-bit integers with BigInt columns" against "BigInt columns".
- `cover` reuses the announcement blog cover whenever one exists. For docs-only or changelog-only changes, point at `/images/changelog/<YYYY-MM-DD>.png` or leave the field empty/omitted (both patterns exist in the repo).

### Body

- 1-3 short paragraphs that summarize what shipped, with enough context for a reader scanning the changelog to decide whether to click through.
- Inline links to the most relevant docs page on first mention of the feature.
- One small code or config snippet only if it makes the change materially easier to grasp at a glance. Leave longer examples for the docs and blog.
- Close with one or two `{% arrow_link %}` blocks:

```
{% arrow_link href="/blog/post/announcing-<slug>" %}
Read the announcement
{% /arrow_link %}
```

When there is no announcement blog, link directly into the docs page that documents the feature:

```
{% arrow_link href="/docs/<section>/<subsection>" %}
<Specific call to action, e.g. "Configure build settings">
{% /arrow_link %}
```

## Cross-artifact consistency checks

Before finalizing:

- The blog slug, blog cover path, and changelog cover path all reference the same `announcing-<slug>` folder.
- The changelog `date` matches the blog `date`.
- Feature names, API method names, parameter names, and configuration keys are identical across all three artifacts and match what the docs say.
- The blog's resources section links into the docs page you updated.
- The changelog's arrow link resolves to a real blog slug or docs path.

## Working through an announcement

Copy this checklist and check items off as you go:

```
Announcement Progress:
- [ ] Step 1: Confirm with the user which artifacts to produce (docs, blog, changelog, or a subset)
- [ ] Step 2: Read the relevant pages under src/content/docs/ to ground every fact and identify the page to update
- [ ] Step 3: Update or create the docs in src/content/docs/ to document the feature
- [ ] Step 4: Write src/content/blog/posts/announcing-<slug>.markdoc following the announcement structure
- [ ] Step 5: Generate and save the cover image to public/images/blog/announcing-<slug>/cover.avif (see writing-blogs Step 8). `bun run optimize` converts it to .avif at build time.
- [ ] Step 6: Create src/content/changelog/entries/<YYYY-MM-DD>.markdoc with an arrow_link to the blog or docs
- [ ] Step 7: Complete both sections of the writing-blogs SEO checklist
- [ ] Step 8: Run the cross-artifact consistency checks above
```

## Style references

Recent announcements that show the target shape:

- [Announcing BigInt columns](https://appwrite.io/blog/post/announcing-bigint-columns) — Feature announcement with multi-SDK code, FAQs, paired docs update to `products/databases/tables`, and a matching changelog entry.
- [Announcing the Appwrite plugin for Codex](https://appwrite.io/blog/post/announcing-appwrite-codex-plugin) — Tooling/integration announcement with setup steps, FAQs, paired docs update under `tooling/ai/ai-dev-tools/codex`, and a short paired changelog entry.

## Common pitfalls

- Shipping the blog before the docs exist. The docs are the source of truth — write them first or in lockstep.
- Using `category: product` on an announcement. Announcements use `category: announcement`.
- Generic changelog titles like "BigInt support". Lead with what the user can now do.
- A changelog entry that paraphrases the whole blog. Keep it short and link out.
- Duplicating exhaustive parameter tables in the blog instead of linking the docs.
- Mismatched dates between blog and changelog. Both reflect the public ship date.
- Forgetting to add the new feature to every reference table or `{% multicode %}` block on the docs page — readers comparing options will miss it.
