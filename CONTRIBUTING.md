# Contributing

We would :heart: you to contribute to Appwrite and help make it better. All contributions are welcome: issues, docs, code, blog posts, workshops, and more.

Coding conventions, architecture, tests, and how to run the stack live in [AGENTS.md](AGENTS.md). That file is the coding contract for every contributor, human or agent. Using AI does not lower the bar: you own the pull request, you must follow those standards, and you must review and oversee any agent-generated work against them. The same rules apply whether you write the code yourself or with an assistant.

The SDK release how-to is in [docs/tutorials/release-sdks.md](docs/tutorials/release-sdks.md). The Console UI is a [separate repository](https://github.com/appwrite/console/blob/main/CONTRIBUTING.md).

## Here for Hacktoberfest?

Appwrite only accepts Hacktoberfest contributions on issues labeled `hacktoberfest`. [Find those issues](https://github.com/search?q=org%3Aappwrite+is%3Aopen+type%3Aissue+label%3Ahacktoberfest&type=issues).

## How to Start

Follow these steps before writing any code:

1. **Find an issue.** Prefer [good first issue](https://github.com/search?q=org%3Aappwrite+is%3Aopen+type%3Aissue+label%3A%22good+first+issue%22&type=issues) or [help wanted](https://github.com/search?q=org%3Aappwrite+is%3Aopen+type%3Aissue+label%3A%22help+wanted%22&type=issues). If you are unsure, ask in the [maintainers channel](https://discord.com/channels/564160730845151244/636852860709240842) on Discord.
2. **Ask to be assigned.** Comment on the GitHub issue, then open a Discord thread in the maintainers channel with a link to it. The team is small and may miss GitHub comments.
3. **Do not submit unsolicited PRs.** If you are not working on an assigned issue, open a GitHub issue first. Large features should be discussed on that issue; some need an [RFC](https://github.com/appwrite/rfc). PRs without context may be closed without review.

We can only review contributions that follow this process.

## Code of Conduct

Please read and follow our [Code of Conduct](https://github.com/appwrite/.github/blob/main/CODE_OF_CONDUCT.md).

## Submit a Pull Request

Branch names follow `TYPE-ISSUE_ID-DESCRIPTION`, for example `doc-548-submit-a-pull-request-section-to-contribution-guide`.

`TYPE` is one of:

- **feat** — a new feature
- **doc** — documentation only
- **cicd** — CI/CD
- **fix** — a bug fix
- **refactor** — neither a fix nor a feature

Every PR needs a commit message that describes the change.

1. Fork the repo and keep your default branch up to date (`git pull`).
2. Create a branch from `master` with the naming convention above.
3. Make commits on that branch. Follow [AGENTS.md](AGENTS.md) for format, tests, and architecture — including when an agent drafted the change. You still review it.
4. Push and open a pull request against this repository.
5. Wait for a core developer to review. After approval, the PR can be merged; GitHub deletes the branch.

## Other Ways to Help

Pull requests are great, but you can also:

- Write or speak about Appwrite, mention [@appwrite](https://twitter.com/appwrite), and add posts or talks to [Awesome Appwrite](https://github.com/appwrite/awesome-appwrite).
- Present at meetups and conferences; we are happy to review talk abstracts.
- Report bugs and share feedback on GitHub or [Discord](https://discord.gg/GSeTUeA).
- Open an issue for a new idea so the community can discuss it.
- Improve documentation (including spelling and grammar).
- Help someone else on Discord, GitHub, or Stack Overflow.
