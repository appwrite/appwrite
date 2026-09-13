---
name: auditing-seo
description: Audits Appwrite blog posts for SEO, AEO, content structure, links, metadata, and search intent. Use when the user asks for an SEO audit, SEO review, search optimization check, or pre-publishing SEO assessment.
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



# SEO Audit

## How to run an audit

When asked to audit a blog post, read `src/content/blog/posts/<slug>.markdoc` and evaluate every item below. Identify the primary keyword from the title, slug, headings, and content. If the target keyword remains ambiguous, state the keyword you inferred and ask the user to confirm it.

Before scoring:

1. Count title and `description` characters, including spaces.
2. Count body words, excluding frontmatter.
3. Read the relevant local files under `src/content/docs/` to verify Appwrite claims and internal doc links.
4. Check that internal and external links resolve when tools and network access allow it. Clearly mark any link you could not verify.

Do not rewrite the post unless the user asks. Report specific problems and exact fixes instead.

## SEO audit checklist

### Content essentials

- [ ] The title clearly describes the topic.
- [ ] The introduction is strong, establishes the problem, and uses the primary keyword naturally.
- [ ] An early direct answer section addresses the primary search query for AEO.
- [ ] Useful H2 and H3 headings organize the content.
- [ ] Concrete examples make the guidance actionable.
- [ ] A relevant Appwrite use case connects the topic to a real developer workflow.
- [ ] Contextual internal links point to relevant Appwrite docs, product pages, and related blogs.
- [ ] The `faqs` frontmatter contains 4-7 direct, standalone answers to real search questions.
- [ ] The `description` frontmatter provides a focused meta description.
### Heading optimization 
- [ ] Every H2 and H3 includes the primary keyword, a natural variation, or a relevant secondary keyword. 
- [ ] Headings reflect queries and subtopics developers actually search for.
 - [ ] Question-based headings are followed by a concise, direct answer. 
- [ ] Keywords fit naturally and are not forced or repeated unnecessarily.
### Title and meta description

- [ ] The title is 50-60 characters.
- [ ] The title includes the primary keyword naturally, front-loaded where possible.
- [ ] The meta description is 150-160 characters.
- [ ] The meta description includes the primary keyword and accurately states what the reader will learn.

### Content length and keyword placement

- [ ] The body is 1,500-2,000 words.
- [ ] The primary keyword appears naturally in the title, introduction, headings, and body.
- [ ] Related terms appear where useful without forced repetition.
- [ ] The content avoids keyword stuffing.

### Search intent, AEO, and structure

- [ ] The post format matches search intent, such as tutorial, comparison, or conceptual guide.
- [ ] The post satisfies that intent with actionable guidance.
- [ ] Direct answer sections give concise, self-contained answers that answer engines can extract.
- [ ] Clear headings, bullets, short paragraphs, and short sections make the post easy to scan.
- [ ] Headings describe their sections and reflect queries developers use.

### Internal links

- [ ] The body links contextually to relevant Appwrite docs.
- [ ] The body links contextually to relevant Appwrite product pages.
- [ ] The body links contextually to related Appwrite blog posts.
- [ ] Internal links are not confined to the closing resources section.
- [ ] Anchor text describes the destination rather than using phrases such as "click here."
- [ ] Internal links resolve to existing pages.

### External links and E-E-A-T

- [ ] Credible external links support factual claims where relevant.
- [ ] Sources are authoritative, current, and directly related to the claim they support.
- [ ] External citations strengthen accuracy, trust, and E-E-A-T rather than acting as filler.
- [ ] External links resolve.

### Frontmatter and presentation

- [ ] `layout`, `title`, `description`, `date`, `cover`, `timeToRead`, `author`, `category`, `featured`, and `unlisted` are present.
- [ ] The folder slug is short, keyword-rich, hyphen-separated, and matches the cover path.
- [ ] `timeToRead` is plausible at roughly 200-250 words per minute.
- [ ] Code and configuration examples are accurate, relevant, and usable where the topic calls for them.

## Scoring

Score each report section as:

- **Pass:** Every applicable check passes.
- **Needs improvement:** One or more checks fail but the section still serves its purpose.
- **Missing:** A required content element is absent.
- **Not applicable:** The check does not fit the topic. Explain why instead of penalizing it.

Do not invent a numerical score. Character counts, word count, failed checks, and prioritized fixes provide a more reproducible audit.

## SEO audit report format

```markdown
## SEO Audit: <title>

### Summary
<Overall SEO health, inferred primary keyword, and highest-impact opportunity>

### Measurements
- Title: <count> characters (target: 50-60)
- Meta description: <count> characters (target: 150-160)
- Body: <count> words (target: 1,500-2,000)

### Content essentials
<Pass / Needs improvement / Missing, with evidence>

### Title and meta description
<Status, with exact counts and suggested rewrites>

### Content length and keyword placement
<Status, with keyword locations and any unnatural usage>

### Search intent, AEO, and structure
<Status, with missing direct answers or scannability issues>

### Internal links
<Status, grouped by docs, product pages, and related blogs>

### External links and E-E-A-T
<Status, including unsupported claims and unverified links>

### Frontmatter and presentation
<Status, including FAQ and Appwrite example coverage>

### Priority fixes
1. <Highest-impact fix>
2. <Second fix>
3. <Third fix>

### Suggested rewrites
<Quote each problem and provide an exact replacement where possible>
```

Be specific. Distinguish required fixes from optional improvements, and do not claim a link or Appwrite detail is valid unless you verified it.

## Common pitfalls

- Treating a keyword mention count as more important than natural language.
- Counting frontmatter as part of the 1,500-2,000-word body.
- Hiding the direct answer after a long introduction.
- Linking only in the final resources section.
- Omitting docs, product, or related-blog links from the internal-link mix.
- Citing weak or irrelevant external sources merely to add links.
- Recommending examples or an Appwrite use case that do not fit the search intent.
- Reporting an inferred keyword as user-provided.
