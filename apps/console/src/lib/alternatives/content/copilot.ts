import type { AlternativeContent } from '@/lib/alternatives/types'

/**
 * GitHub Copilot code review facts checked on 2026-11-27 against GitHub's
 * plans, code review docs, billing changes, and changelog (see `sources`).
 */
export const copilotAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Review',
      rows: [
        {
          label: 'Findings on the changed lines',
          appwrite: {
            value: 'Critical to Info',
            note: 'With a category on each',
          },
          competitor: {
            value: 'High to Low',
            note: 'Severity on each comment',
          },
        },
        { label: 'Suggested changes', appwrite: true, competitor: true },
        {
          label: 'Answers replies to its comments',
          appwrite: { value: true, note: 'Mention it or reply to a finding' },
          competitor: {
            value: false,
            note: 'Fix with Copilot hands off to the cloud agent',
          },
        },
        {
          label: 'Learns team preferences',
          appwrite: { value: true, note: 'Learnings saved from conversations' },
          competitor: {
            value: 'partial',
            note: 'Copilot Memory keeps repository facts',
          },
        },
        {
          label: 'Verdict',
          appwrite: {
            value: 'Approve, request changes, or comment',
            note: 'Decided by code from the findings',
          },
          competitor: {
            value: 'Comment by default',
            note: 'Approvals are optional',
          },
        },
        {
          label: 'Merge confidence grade',
          appwrite: { value: 'S to F', note: 'On every review' },
          competitor: { value: 'partial', note: 'An approval assessment' },
        },
        {
          label: 'Follows AGENTS.md and instruction files',
          appwrite: true,
          competitor: true,
        },
        {
          label: 'Pulls context from MCP tools',
          appwrite: false,
          competitor: {
            value: true,
            note: 'Issue trackers, docs, and incident tools',
          },
        },
        {
          label: 'Reviews in the IDE',
          appwrite: false,
          competitor: {
            value: true,
            note: 'VS Code, Visual Studio, JetBrains, Xcode',
          },
        },
      ],
    },
    {
      title: 'Git providers',
      rows: [
        { label: 'GitHub', appwrite: true, competitor: true },
        { label: 'GitLab', appwrite: true, competitor: false },
        { label: 'Bitbucket', appwrite: true, competitor: false },
        {
          label: 'Azure DevOps',
          appwrite: false,
          competitor: { value: true, note: 'Since November 2026' },
        },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        {
          label: 'Pricing model',
          appwrite: {
            value: 'Credits per review',
            note: 'From your Appwrite plan',
          },
          competitor: {
            value: 'AI Credits per review',
            note: 'Plus GitHub Actions minutes',
          },
        },
        {
          label: 'Typical review',
          appwrite: {
            value: '50 credits',
            note: '$0.50 past your plan’s included credits',
          },
          competitor: {
            value: 'About $1.50',
            note: 'Average at the default effort',
          },
        },
        {
          label: 'Free plan',
          appwrite: {
            value: '100 credits a month',
            note: 'About two Normal reviews',
          },
          competitor: {
            value: false,
            note: 'No pull request reviews on Copilot Free',
          },
        },
        {
          label: 'Included with a paid plan',
          appwrite: {
            value: '2,000 credits a month',
            note: 'For each project on Pro',
          },
          competitor: {
            value: '1,500 credits a month',
            note: 'On Copilot Pro, $10 a month',
          },
        },
      ],
    },
    {
      title: 'Platform',
      rows: [
        {
          label: 'Hosts your Git repositories and CI',
          appwrite: false,
          competitor: true,
        },
        {
          label: 'Deploys and hosts your app',
          appwrite: { value: true, note: 'Sites and Functions' },
          competitor: {
            value: 'partial',
            note: 'GitHub Pages for static sites',
          },
        },
        {
          label: 'Backend for your app',
          appwrite: {
            value: true,
            note: 'Auth, databases, storage, messaging, realtime',
          },
          competitor: false,
        },
        {
          label: 'Monitors production',
          appwrite: { value: true, note: 'Usage monitors and endpoint checks' },
          competitor: false,
        },
        {
          label: 'On-call pushes to your phone',
          appwrite: { value: true, note: 'Appwrite Beep, with escalations' },
          competitor: false,
        },
        { label: 'Open source', appwrite: true, competitor: false },
      ],
    },
  ],
  fairPlay: {
    title: 'When GitHub Copilot might still fit',
    description:
      'Copilot code review is built into GitHub and the editors your team already uses. If you live in GitHub and Copilot, it is the shortest path to a reviewer.',
    points: [
      'Your team already pays for Copilot Business or Enterprise and wants reviews on the same bill.',
      'You want reviews in VS Code, JetBrains, Xcode, and the GitHub CLI before you open a pull request.',
      'Your repositories are on Azure DevOps.',
      'You want review context pulled from issue trackers and incident tools through MCP.',
    ],
  },
  related: [
    {
      kind: 'product',
      title: 'Appwrite Reviews',
      description: 'Code review on every pull request, next to your deploys.',
      href: '/products/reviews',
    },
    {
      kind: 'product',
      title: 'Appwrite Sites',
      description: 'Static, SSR, and CSR deploys from Git.',
      href: '/products/sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Functions',
      description: 'APIs, cron jobs, and event handlers at scale.',
      href: '/products/functions',
    },
    {
      kind: 'product',
      title: 'Appwrite Monitors',
      description: 'Alerts when your project crosses a threshold you set.',
      href: '/products/monitors',
    },
    {
      kind: 'product',
      title: 'Appwrite Beep',
      description: 'Your project updates on your phone, on your terms.',
      href: '/products/beep',
    },
    {
      kind: 'product',
      title: 'Appwrite pricing',
      description: 'One plan for your backend, hosting, and reviews.',
      href: '/pricing',
    },
  ],
  faq: [
    {
      question:
        'Is Appwrite Reviews an alternative to GitHub Copilot code review?',
      answer:
        'Yes. Appwrite Reviews posts a summary, findings with severity, a verdict, and a merge confidence grade on every pull request, on GitHub, GitLab, and Bitbucket. It runs from the Appwrite project that deploys, hosts, and monitors your app, so reviews and production share one Console and one bill.',
      links: [{ label: 'Appwrite Reviews', href: '/products/reviews' }],
    },
    {
      question: 'Does the reviewer answer my replies?',
      answer:
        'Yes. Reply to a finding or mention the reviewer and it reads the code to answer, can resolve or dismiss the finding, and saves lasting rules as learnings. Answers are free. GitHub documents that Copilot code review does not see or answer replies to its comments.',
    },
    {
      question: 'How do the costs compare?',
      answer:
        'Appwrite Reviews uses credits from your Appwrite plan: 10, 50, or 500 credits per review, with 100 a month on Free, 2,000 a month for each project on Pro, and extra credits at $1 per 100. Copilot code review uses GitHub AI Credits, about $1.50 for a review at the default effort, plus GitHub Actions minutes.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Do I need GitHub?',
      answer:
        'No. Appwrite Reviews works with GitHub, GitLab, and Bitbucket. Copilot code review works on GitHub.com and, since November 2026, Azure DevOps.',
    },
    {
      question: 'Can I use both on the same pull request?',
      answer:
        'Yes. Each posts its own review. Appwrite Reviews is on by default for the repositories behind new Appwrite Sites and Functions, and you choose any other repositories in the Console.',
    },
    {
      question: 'Does Appwrite Reviews read my production data?',
      answer:
        'No. The reviewer reads the pull request: the diff, the repository at its head, the description, linked issues, failed checks, and rule files such as AGENTS.md. What Appwrite adds is everything around the review: the same Git connection deploys the code, and the same project hosts, monitors, and alerts on it after you merge.',
    },
    {
      question: 'Is Appwrite open source?',
      answer:
        'Yes. Appwrite and its review service are open source, and you can self-host them. Copilot is closed source and is not available on GitHub Enterprise Server.',
    },
  ],
  sources: [
    {
      label: 'Copilot plans',
      href: 'https://docs.github.com/en/copilot/get-started/plans',
    },
    {
      label: 'Copilot plans and pricing',
      href: 'https://github.com/features/copilot/plans',
    },
    {
      label: 'Copilot code review',
      href: 'https://docs.github.com/en/copilot/concepts/agents/code-review',
    },
    {
      label: 'Using Copilot code review',
      href: 'https://docs.github.com/en/copilot/how-tos/use-copilot-agents/request-a-code-review/use-code-review',
    },
    {
      label: 'Copilot usage-based billing',
      href: 'https://github.blog/news-insights/company-news/github-copilot-is-moving-to-usage-based-billing/',
    },
    {
      label: 'Copilot code review on Azure DevOps',
      href: 'https://github.blog/changelog/2026-11-17-copilot-code-review-adaptive-effort-secret-detection-and-more',
    },
    {
      label: 'Copilot code review billing defaults',
      href: 'https://github.blog/changelog/2026-11-25-copilot-code-review-billing-will-default-to-the-organization',
    },
    {
      label: 'GitHub Spark deprecation',
      href: 'https://github.blog/changelog/2026-08-04-upcoming-deprecation-of-github-spark-on-github-com/',
    },
    {
      label: 'Copilot trust center',
      href: 'https://copilot.github.trust.page/',
    },
  ],
}
