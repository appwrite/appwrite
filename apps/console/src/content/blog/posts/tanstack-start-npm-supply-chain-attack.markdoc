---
layout: post
title: "The TanStack npm attack shows how fragile modern JavaScript supply chains can be"
description: A detailed breakdown of the May 2026 Mini Shai-Hulud attack on TanStack npm packages, how GitHub Actions cache poisoning and OIDC trusted publishing were abused, what the malware tried to steal, and how developers should respond.
date: 2026-05-12
cover: /images/blog/tanstack-start-npm-supply-chain-attack/cover.avif
timeToRead: 16
author: atharva
category: security
featured: false
faqs:
  - question: "What happened in the TanStack npm attack?"
    answer: "On May 11, 2026, 84 malicious versions were published across 42 @tanstack/* npm packages. The attack used GitHub Actions cache poisoning to get malicious code into a trusted release workflow, then abused OIDC trusted publishing to publish compromised package artifacts."
  - question: "Was TanStack Start itself compromised?"
    answer: "TanStack's postmortem listed the TanStack Start meta-package as confirmed clean. However, Router and Start-adjacent packages such as @tanstack/react-router, @tanstack/router-core, @tanstack/router-plugin, @tanstack/start-server-core, and @tanstack/start-client-core were affected, so TanStack Start projects should still audit their lockfiles and CI installs."
  - question: "What did the malware try to steal?"
    answer: "The payload targeted developer and CI/CD credentials, including GitHub Actions environment values, OIDC request variables, npm credentials, AWS credentials, Vault tokens, Kubernetes service account material, GitHub tokens, SSH private keys, and .npmrc files."
  - question: "What should developers do if an affected version ran?"
    answer: "Treat the host or CI runner as compromised. Isolate it, rotate reachable GitHub, npm, cloud, Vault, Kubernetes, and SSH credentials from a known-clean machine, review publish and audit logs, clear package-manager caches, reinstall clean dependency versions, and rebuild runners from clean images."
---

On May 11, 2026, the JavaScript ecosystem got another reminder that the most dangerous dependency attacks do not always look like typosquatting, abandoned packages, or a random install script hiding in a library nobody has heard of.

This time, the name on the package was one developers already trusted: TanStack.

According to [TanStack's postmortem](https://tanstack.com/blog/npm-supply-chain-compromise-postmortem), **84 malicious versions were published across 42 `@tanstack/*` npm packages** between 19:20 and 19:26 UTC on May 11. The affected set included widely used Router and Start-adjacent packages such as `@tanstack/react-router`, `@tanstack/router-core`, `@tanstack/router-plugin`, `@tanstack/start-server-core`, and `@tanstack/start-client-core`.

TanStack Start itself, the meta-package, was listed by TanStack as confirmed clean. But parts of the Router and Start dependency surface were affected, which means a TanStack Start app could still have pulled a compromised package depending on the dependency tree and install timing.

The attack was publicly spotted quickly. Socket says its AI scanner flagged every malicious TanStack version within **six minutes** of publication, and TanStack's public incident thread was opened roughly 20 minutes after the first malicious publish. Still, a few minutes is enough time for npm installs, CI jobs, preview deployments, and automated builds to run.

*A supply-chain attack does not need to sit undetected for weeks to matter.* It only needs to execute once in the wrong environment.

# What happened

Attacker-controlled code reached a TanStack GitHub Actions release workflow without directly compromising npm credentials, then used that trusted workflow context to publish malicious package versions to npm.

The chain combined multiple individually familiar risks:

- A `pull_request_target` workflow ran against code from an external fork.
- The workflow restored and later saved GitHub Actions cache entries in the base repository's cache scope.
- The attacker poisoned the pnpm store cache with malicious code.
- A later release workflow restored that poisoned cache on `main`.
- The release workflow had `id-token: write`, which is commonly used for npm trusted publishing.
- The malicious code extracted the OIDC token from the GitHub Actions runner process memory.
- It used that token to authenticate directly to npm and publish compromised package artifacts.

TanStack says no npm tokens were stolen and the intended npm publish step itself was not compromised. The attacker did not need a classic npm token because code execution inside the release workflow was enough to mint an OIDC-backed publish token.

# Why this was not a simple npm token theft

In older npm compromises, the story often starts with a maintainer token: someone phishes it, steals it from a workstation, or grabs it from CI logs, then publishes malicious versions under the maintainer's authority.

The TanStack incident did not follow that path.

TanStack's postmortem says the malicious publish was authenticated through the project's OIDC trusted-publisher binding for the release workflow. The attacker-controlled code ran during the workflow's test or cleanup phase, requested an OIDC token, and posted directly to the npm registry. The package registry saw a valid trusted-publisher flow, but the code path that triggered it was not the legitimate publish step the maintainers intended.

OIDC removes long-lived registry tokens and reduces one class of secret sprawl. But it does not automatically prove that the code running inside the workflow is trustworthy.

If arbitrary code can run in a job with `id-token: write`, that job can become a publisher.

# The cache-poisoning bridge

The first bridge in the attack was GitHub Actions cache poisoning.

TanStack's public timeline says the attacker opened a pull request from a fork and used force-pushes to land a malicious commit on the PR head. A `pull_request_target` workflow ran in the base repository context and checked out the PR merge ref. That workflow then ran package build logic.

This matches the classic "pwn request" pattern: `pull_request_target` gives access to the base repository context, but the workflow still executes attacker-controlled code from the fork.

The attacker did not need the PR to be merged. The goal was to influence a cache entry that a later trusted workflow would restore. TanStack says a poisoned pnpm store cache was saved under a key the release workflow would later compute and use. When an unrelated legitimate PR was merged to `main`, the release workflow restored the poisoned cache, and the malicious code was now inside a trusted release run.

Caches feel like performance infrastructure, not security boundaries. But in CI/CD, cache contents are code-adjacent. A poisoned dependency cache can be as dangerous as a poisoned dependency.

# The OIDC memory extraction step

Once the poisoned cache reached the release workflow, the attacker needed a way to publish.

TanStack's postmortem says the malicious code located the GitHub Actions `Runner.Worker` process, read `/proc/<pid>/maps` and `/proc/<pid>/mem`, extracted the OIDC token from memory, and used it to authenticate direct requests to npm. TanStack also notes this technique resembles the memory-extraction method used in the March 2025 `tj-actions/changed-files` compromise.

It was *published tradecraft applied in a new context*.

Attackers do not need a brand-new zero-day when enough small assumptions line up:

- Fork PR code is treated as safe enough to build,
- Cache writes are treated as harmless,
- Restored caches are treated as trusted,
- OIDC permission is available to more of the job than strictly necessary,
- Package provenance is treated as proof that the release is good.

Each assumption is understandable. Together, they made the release system publish malware.

# What the compromised packages contained

Socket's [TanStack analysis](https://socket.dev/blog/tanstack-npm-packages-compromised-mini-shai-hulud-supply-chain-attack) identified a new `router_init.js` file added to the malicious package versions. Socket described it as a heavily obfuscated, roughly **2.3 MB JavaScript payload** using common JavaScript-obfuscator patterns such as string-array rotation, hex-encoded lookups, control-flow flattening, dead-code injection, and dispatcher functions.

The malicious versions also included this suspicious `optionalDependencies` entry:

```json
{
    "optionalDependencies": {
        "@tanstack/setup": "github:tanstack/router#79ac49eedf774dd4b0cfa308722bc463cfe5885c"
    }
}
```

That dependency pointed to an orphan commit in the TanStack Router fork network. Socket says the commit introduced a package named `@tanstack/setup` with a `prepare` lifecycle hook that ran a bundled payload. Because npm lifecycle hooks run automatically for git dependencies, installing the affected package could execute attacker code during dependency installation.

The malicious dependency was not a normal registry package with an obvious npm page to inspect. It was a git dependency pointing to a specific commit. That made it easier to smuggle code through package installation while still looking like something related to the TanStack namespace.

# What the malware tried to steal

The confirmed target was not browser users. It was developer and CI/CD infrastructure. Socket's technical analysis and TanStack's postmortem point to these credential targets:

- GitHub Actions environment values and OIDC request variables
- npm credentials and publishing capability
- AWS environment variables and metadata-service credentials
- AWS Secrets Manager and SSM Parameter Store access
- Vault tokens and Vault service endpoints
- Kubernetes service account material under `/var/run/secrets/kubernetes.io/serviceaccount`
- GitHub tokens from environment variables, CLI state, or local credential files
- SSH private keys
- `.npmrc` files

It was not optimized for stealing a consumer's browser cookies. It was optimized for the places modern teams concentrate power: CI runners, developer laptops, build systems, release workflows, cloud metadata endpoints, and internal secret stores.

The attack was supply-chain malware, but the real blast radius was infrastructure identity.

# The weirdest parts of the attack

Beyond the headline number of packages, the attack had several unusual traits.

First, the malicious packages could still appear to come from a legitimate trusted-publishing workflow. npm provenance and OIDC publishing are supposed to improve package integrity. They still do, compared to long-lived npm tokens sitting in random CI variables. But if malicious code is already executing inside the trusted workflow, provenance can become a polished badge on a compromised artifact. Socket explicitly warns against treating provenance alone as a sufficient security signal.

Second, the payload tried to blend into developer tooling. Socket's analysis says the implant wrote persistence files under `.claude/` and `.vscode/`, including Claude Code hook configuration and VS Code task configuration. That means removing the package is not necessarily enough if the payload already executed. Developer tools are now part of the runtime environment attackers care about.

Third, the exfiltration path was not a simple webhook to an obvious attacker server. Socket says exfiltration used Session infrastructure such as `filev2.getsession.org` and related service-node domains.

Fourth, the attacker attempted social camouflage. TanStack lists a forged commit identity using `claude@users.noreply.github.com`. Socket also notes repository and campaign markers such as "A Mini Shai-Hulud has Appeared." The intent seems clear: make malicious commits and files feel like they belong to the growing universe of AI-assisted developer workflows, where unfamiliar automation-generated changes are already becoming common.

Finally, there is the dead-man-switch discussion. Screenshots and community posts circulated claims that the malware installed a GitHub-token monitor that could wipe a user's home directory if a stolen token was revoked. Older Shai-Hulud waves have had destructive behavior reported by multiple security writeups. For this TanStack wave, the strongest current TanStack and Socket writeups confirm credential theft, propagation, obfuscation, exfiltration, and persistence; they do not make the wipe behavior the central confirmed IOC. **Do not casually "clean up" an already-executed infection on a live developer machine.** Isolate first, preserve evidence if you need incident response, rotate credentials from a known-clean system, then rebuild affected environments.

# How big did this get?

The numbers moved quickly.

At the TanStack layer, the confirmed scope was **42 packages and 84 malicious versions**. Socket's campaign tracker later showed Mini Shai-Hulud as an ongoing cross-ecosystem campaign affecting both npm and PyPI, with hundreds of affected artifacts by May 12, 2026. Aikido's May 12 writeup reported **373 malicious npm package-version entries across 169 npm package names**, with large clusters in `@tanstack`, `@uipath`, `@squawk`, `@tallyui`, and other scopes.

Socket's campaign page also connected Mini Shai-Hulud activity to earlier compromises involving SAP CAP packages, Intercom's npm client, PyTorch Lightning on PyPI, Mistral-related packages, Guardrails AI, OpenSearch packages, and more.

TanStack is visible because the packages are popular and the postmortem is unusually transparent. Modern release systems are now a primary attack surface.

# How to check your projects

Start with the lockfile. Look for affected `@tanstack/*` package versions, especially around Router and Start-related packages.

Socket and Aikido list many affected versions, but some of the notable TanStack packages include:

- `@tanstack/history`
- `@tanstack/react-router`
- `@tanstack/router-core`
- `@tanstack/router-utils`
- `@tanstack/router-plugin`
- `@tanstack/virtual-file-routes`
- `@tanstack/router-generator`
- `@tanstack/start-server-core`
- `@tanstack/start-client-core`

Then search installed dependencies for the strongest IOCs:

```bash
find . -name router_init.js -print
rg "@tanstack/setup|79ac49eedf774dd4b0cfa308722bc463cfe5885c|router_runtime.js|setup.mjs" .
```

Socket recommends hashing any `router_init.js` file you find and comparing it with the malicious SHA-256:

```bash
shasum -a 256 path/to/router_init.js
```

The hash Socket published for the TanStack `router_init.js` payload is:

```text
ab4fcadaec49c03278063dd269ea5eef82d24f2124a8e15d7b90f2fa8601266c
```

Also check developer-tool persistence paths:

```bash
find . -path "*/.claude/*" -o -path "*/.vscode/tasks.json"
```

Pay special attention to unfamiliar Claude hooks, VS Code tasks, files named `router_runtime.js`, and loader files named `setup.mjs`.

For GitHub repositories, review recent commits for suspicious author identities:

```bash
git log --all --author=claude@users.noreply.github.com --stat
```

That author string alone is not proof of compromise, especially if your team genuinely uses Claude Code integrations. But it should be reviewed carefully against expected automation activity.

# What to do if a compromised package executed

If an affected version merely appears in a lockfile but was never installed or executed, the response is mostly dependency cleanup.

If it ran on a machine or CI runner, treat that environment as compromised.

The minimum response should include:

- Isolate the host or runner before further cleanup.
- Rotate GitHub personal access tokens, npm tokens, SSH keys, cloud keys, Vault tokens, Kubernetes service account tokens, and any secrets reachable from that environment.
- Rotate from a known-clean machine, not the potentially infected one.
- Review npm publish logs for unexpected package versions.
- Review GitHub audit logs, workflow runs, branch changes, and newly created commits.
- Check for unexpected repositories or files connected to Mini Shai-Hulud markers.
- Remove `node_modules`, clear package-manager caches, and reinstall only after pinning to clean versions.
- Rebuild CI runners from clean images rather than trying to disinfect them in place.

Once a credential stealer has executed, you cannot reason only about the file that started it. You have to reason about everything the process could reach.

# How to reduce risk next time

There is no single setting that prevents every supply-chain attack. These controls would have reduced the blast radius here:

- **Use minimum release age for dependency installs.** pnpm supports `minimumReleaseAge`, and Bun supports `minimumReleaseAge` in install configuration. Do not install packages published in the last few minutes or hours unless they are explicitly allowed. Many npm attacks are detected quickly, so a short cooling-off window can stop fresh malicious versions from reaching CI automatically.

- **Pin dependencies and commit lockfiles.** Floating semver ranges are convenient until a newly published malicious version becomes the version your CI decides to trust. Lockfiles with integrity fields are not perfect, but they make surprise resolution changes visible.

- **Use `--ignore-scripts` where possible for audit and recovery installs.** You cannot always disable lifecycle scripts in normal JavaScript projects, but you can use it during investigation and for workflows that only need static dependency inspection.

- **Treat CI caches as untrusted unless proven otherwise.** Do not let untrusted PR workflows save caches that trusted release workflows later restore. Separate cache scopes, avoid restoring fork-controlled caches in release jobs, and purge caches after suspicious workflow behavior.

- **Audit `pull_request_target`.** This trigger is useful for labeling, commenting, and other base-repo tasks, but dangerous when it checks out and executes fork code. If a workflow needs to run untrusted code, use `pull_request` with restricted permissions and no secrets.

- **Constrain `id-token: write`.** OIDC should be available only to the exact job and step that needs it. Most workflows should explicitly set `id-token: none`. Publishing jobs should be small, isolated, and easy to review.

- **Pin GitHub Actions to immutable SHAs.** Floating action refs such as `@main` or broad version tags create standing supply-chain risk. Pinning does not solve every problem, but it removes one moving part.

- **Monitor your own package publishes.** TanStack learned about the compromise from external researchers. Every organization that publishes packages should alert on unexpected versions, unexpected dist-tag changes, provenance anomalies, and publish events outside normal release windows.

- **Prefer short-lived, narrowly scoped credentials.** Long-lived tokens turn a one-time install into a long-term incident. Short-lived job credentials and scoped permissions do not stop theft, but they reduce the time and surface attackers can use.

# Sources

- [TanStack postmortem: npm supply-chain compromise](https://tanstack.com/blog/npm-supply-chain-compromise-postmortem)
- [Socket: TanStack npm packages compromised in Mini Shai-Hulud attack](https://socket.dev/blog/tanstack-npm-packages-compromised-mini-shai-hulud-supply-chain-attack)
- [Socket campaign tracker: Mini Shai-Hulud](https://socket.dev/supply-chain-attacks/mini-shai-hulud)
- [Aikido: Mini Shai-Hulud is back, npm worm hits 160+ packages](https://www.aikido.dev/blog/mini-shai-hulud-is-back-tanstack-compromised)
- [GitHub Advisory: GHSA-g7cv-rxg3-hmpx](https://github.com/TanStack/router/security/advisories/GHSA-g7cv-rxg3-hmpx)
- [Appwrite: Security is a revolving door, and here's how Appwrite keeps you safe](/blog/post/security-is-a-revolving-door)
