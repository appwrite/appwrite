---
layout: post
title: "How agencies are using vibe coding to ship client projects faster"
description: "How agencies use vibe coding tools like Cursor, Claude Code, and Lovable to shorten client cycles, standardize backends, and keep margins on fixed-fee work."
date: 2026-05-11
cover: /images/blog/agencies-vibe-coding-client-projects/cover.avif
timeToRead: 9
author: aditya-oberai
category: ai
unlisted: true
faqs:
  - question: "How does vibe coding change agency economics?"
    answer: "Agencies bill by the hour, the project, or the sprint. Vibe coding compresses the hours that go into the predictable parts of a build: auth, CRUD, file handling, integrations, and frontend scaffolding. Margins on fixed-fee projects improve, time-to-pitch shortens, and the same team can run more projects in parallel. The catch is that the review and hardening work does not compress, so the win only lands for agencies that keep both halves of the workflow in shape."
  - question: "Which vibe coding tools do agencies use?"
    answer: "Most agencies pair an IDE-native agent like Cursor or Claude Code with a hosted prototype builder like Lovable, Bolt, or v0 for the first pass. Browser builders win the discovery and early design phase. IDE agents take over once the project moves to a real repo. The deep comparison lives in [Best vibe coding tools in 2026](/blog/post/comparing-vibe-coding-tools)."
  - question: "Should agencies standardize on one backend across clients?"
    answer: "Yes. Backend fragmentation across clients is the largest hidden cost in agency work: every new project is a new mental model, every fix is in a stack the on-call dev does not know, and every estimate carries unfamiliar risk. Standardizing on one backend like [Appwrite Cloud](https://cloud.appwrite.io) turns the backend layer into solved infrastructure. The full case is in [How agencies standardize backend stacks across clients](/blog/post/agency-backend-standardization)."
  - question: "How do agencies handle client handoff for vibe-coded apps?"
    answer: "Document the prompts and decisions, not just the code. The agent can regenerate the code from intent if the schema, permission model, and architectural decisions are clear. Hand over a clean repo, a real domain, scoped API keys per environment, runbooks for the three most likely incidents, and access to the project under the client's account. The client should be able to keep building with their own agent on day one."
  - question: "What is the biggest mistake agencies make with vibe coding?"
    answer: "Treating the prototype as the launch. The demo flows are not the same as production-ready flows, and the gaps are predictable: leaky permissions, secrets in client bundles, missing indexes, no logs, and a preview URL that never got swapped for a real domain. The [backend checklist for vibe-coded apps before launch](/blog/post/backend-checklist-vibe-coded-apps) is the version most agency teams converge on."
---

The agency model has always been about turning predictable work into repeatable margin. Vibe coding is the largest leverage agencies have had on that math in a decade. The teams that get it right ship more projects per quarter without growing headcount. The teams that get it wrong end up doing the same work twice, once to generate it and once to fix it.

This is a working view of how agencies are using vibe coding to ship client projects faster, where the leverage actually lives, and how to keep margins clean on fixed-fee work.

# The agency math has shifted

For a long time, the bottleneck on agency throughput was developers per project. Three engineers could ship two apps a quarter. Vibe coding moved the bottleneck. The same three engineers can now scaffold five apps in the same quarter, and the constraint becomes review, discovery, and client communication, not typing.

This is more disruptive to agency economics than to product economics. A product company keeps the same product after a fast prototype. An agency starts over every engagement, so the win on the first day of a build compounds across every project the team takes on. A meaningful reduction in time-to-first-working-version can translate into more pitches and faster iteration across the year.

The honest catch is that the time you do not spend on the build is partially eaten by the time you spend on the review. The agencies that hit the leverage cleanly are the ones that built a review habit before vibe coding mattered. The ones that did not are shipping more bugs faster.

# The two-builder pattern

Agencies tend to use vibe coding in two passes that map to two different tools.

**Discovery and demo.** Builders like Lovable, Bolt, v0, and Replit are excellent for the first pass. A salesperson can sit with a client, talk through what they want, and have a working UI in the call. The builder's bundled host is fine for the demo. The output is throwaway in spirit. The point is alignment, not production.

**Build and harden.** Once the project is real, the code moves into a real repo and the workflow moves into Cursor, Claude Code, or Windsurf. The agent has the full project in context, can run tests, can edit across files, and can act on the project's backend through MCP. This is the pass that ships.

Trying to combine both into one tool is a common mistake. A browser builder is too constrained to ship a real product. An IDE-native agent is too heavy for a 20-minute sales conversation. The two-pass workflow keeps the right tool for the right phase.

# Standardize the backend before standardizing the agent

The agent is the visible part of the workflow. The backend is the load-bearing part of the agency's economics.

Agencies that run a different backend per client pay for the choice every time. Different security defaults, different auth flows, different mental models, different debugging tools, different on-call burdens. A developer joining the team has to learn three stacks before they can be productive. An incident at 2am lands on whoever knows the stack, which is often nobody on the rotation.

Standardizing on one backend per agency turns this into solved infrastructure. The same auth flow ships in every project. The same permission model. The same file handling. The same logs and rollback story. The agent gets faster too, because the same prompts produce useful output across every engagement instead of starting from zero.

[Appwrite Cloud](https://cloud.appwrite.io) is shaped for this. [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), [Sites](/docs/products/sites), and [Realtime](/docs/apis/realtime) under one model, with per-project isolation so every client gets a clean environment. Self-hosting is there for clients who need data residency or compliance. The full case for agency standardization is in [How agencies standardize backend stacks across clients](/blog/post/agency-backend-standardization).

# Build an internal playbook, not a tooling list

The agencies pulling the most leverage from vibe coding all converged on the same artifact: an internal playbook the team uses on every project. The playbook is not a tools list. It is a set of decisions made once, encoded so the agent and the team apply them automatically.

A practical playbook covers:

- **The default backend.** One platform, one set of primitives, one mental model.
- **The starter repo.** A template with auth, a base layout, lint rules, and the agent's context files already in place.
- **The prompt library.** The prompts the team has refined for common features: signup, file uploads, payments, dashboards. Reusable across projects.
- **The review checklist.** What every engineer reviews before merging an agent-generated diff and before shipping a launch.
- **The handoff package.** What every client receives at the end of an engagement.

The first project that uses the playbook costs the agency the playbook. Every project after pays it back.

# Reusable agent context across clients

The other piece of leverage is agent context. Most agencies start over on every engagement: a new repo, a new prompt history, a new set of constraints the engineer has to re-explain to the agent on day one. The agent has to learn the agency's conventions five times a year.

The fix is to keep the conventions outside any single project. An `AGENTS.md` or `CLAUDE.md` file in every starter repo. A shared set of [Agent Skills](/docs/tooling/ai/skills) for the backend the agency standardizes on. Editor plugins like the [Claude Code plugin](/docs/tooling/ai/ai-dev-tools/claude-code) or [Cursor plugin](/docs/tooling/ai/ai-dev-tools/cursor) install the [API MCP server](/docs/tooling/ai/mcp-servers/api), the [Docs MCP server](/docs/tooling/ai/mcp-servers/docs), and the SDK skills in one command, so every developer on every project is on the same baseline.

# Estimating fixed-fee work in the vibe coding era

Fixed-fee work is where agency margin lives, and the place where vibe coding is most visibly mispriced. The dangerous failure mode is to scope a project based on how fast the prototype came together. The hours you save on the build are partially absorbed by the review, the hardening, the launch checklist, and the client communication. Pricing the project as if the demo were the deliverable produces an estimate the team cannot hit.

The honest estimating pattern looks like this:

- Scope the build in two phases: prototype and harden. Price both.
- Add a fixed line for the pre-launch review.
- Add a fixed line for a handoff package.
- Include a clearly defined revision budget. Vibe coding makes revisions cheap, which means clients ask for more of them. Bound the budget or watch the margin.
- Include a maintenance window post-launch for the first incidents. They will come, and the only question is whether they are billed or eaten.

This estimating pattern gives an agency the upside of vibe coding without the margin compression that comes from underpricing review.

# Handoff is where vibe-coded projects go to die

The agency-specific failure mode for vibe-coded apps is handoff. The team shipped a working app, the client signed off, and six months later the same client comes back because something broke and no one knows the code. The agent that wrote the code is gone. The prompts that produced it were never saved. The conventions are buried in chat history.

The fix is to treat the handoff package as a real deliverable, not an afterthought. A typical package contains:

- A clean git repo with a real branch model and protected `main`.
- Real environment variables per environment, with all keys rotated post-handoff.
- A custom domain on the client's account.
- A README that explains the architecture, the schema, and the prompts that produced it.
- An `AGENTS.md` or `CLAUDE.md` that any agent can pick up.
- Runbooks for the three most likely incidents.
- A short loom or written walkthrough.

Done well, the client picks up where the agency left off, with their own agent in Cursor or Claude Code, against the same standardized backend. The agency stays in scope. The client gets a working product they own.

# Where Appwrite fits in agency workflows

The simplest path for an agency starting on vibe coding is to pick one backend, one IDE agent, and one prototype builder, then encode the choices in a playbook.

[Appwrite Cloud](https://cloud.appwrite.io) covers Auth, Databases, Storage, Functions, and Sites under one platform, with per-project isolation, scoped API keys, and a self-host option for clients with stricter requirements. The [API MCP server](/docs/tooling/ai/mcp-servers/api), the [Docs MCP server](/docs/tooling/ai/mcp-servers/docs), and [Agent Skills](/docs/tooling/ai/skills) install in one step through the [Claude Code](/docs/tooling/ai/ai-dev-tools/claude-code) or [Cursor](/docs/tooling/ai/ai-dev-tools/cursor) plugins.

For the broader agency tooling stack, [30 dev tools for agencies](/blog/post/30-dev-tools-for-agencies) covers the surface beyond the backend and the agent.

# Running an agency that ships with vibe coding

The agencies that turn vibe coding into a long-term advantage do it the same way they turn any tool into one: standardize, document, and review. The first project that uses the playbook costs the agency time. Every project after that is the win.

If you are building one of these workflows, start with one backend, one IDE agent, one builder, and a written handoff package. [Sign up for Appwrite Cloud](https://cloud.appwrite.io), install the [Claude Code](/docs/tooling/ai/ai-dev-tools/claude-code) or [Cursor](/docs/tooling/ai/ai-dev-tools/cursor) plugin, and the next client engagement starts from the same baseline as the last one.

- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)
- [How agencies standardize backend stacks across clients](/blog/post/agency-backend-standardization)
- [Appwrite AI tooling](/docs/tooling/ai)
- [Backend checklist for vibe-coded apps before launch](/blog/post/backend-checklist-vibe-coded-apps)
