import type { AlternativeContent } from '@/lib/alternatives/types'

/**
 * CodeRabbit facts checked on 2026-11-27 against its pricing page, plans,
 * platforms, and security docs (see `sources`). Appwrite Reviews facts follow
 * the Reviews module and Cloud's review credits; the reviewer reads the pull
 * request, not deployments or production data.
 */
export const coderabbitAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Review',
      rows: [
        {
          label: 'Pull request summary and walkthrough',
          appwrite: {
            value: true,
            note: 'One comment, updated on every review',
          },
          competitor: { value: true, note: 'With diagrams' },
        },
        {
          label: 'Findings on the changed lines',
          appwrite: {
            value: 'Critical to Info',
            note: 'With a category on each',
          },
          competitor: {
            value: 'Critical to Trivial',
            note: 'Severity on each comment',
          },
        },
        {
          label: 'Suggested fixes',
          appwrite: {
            value: true,
            note: 'Suggestions and prompts for coding agents',
          },
          competitor: { value: true, note: 'One-click fixes' },
        },
        {
          label: 'Chat with the reviewer',
          appwrite: { value: true, note: 'Mention it or reply to a finding' },
          competitor: { value: true, note: 'Mention @coderabbitai' },
        },
        {
          label: 'Learns team preferences',
          appwrite: { value: true, note: 'Learnings saved from conversations' },
          competitor: { value: true, note: 'Editable learnings' },
        },
        {
          label: 'Merge confidence',
          appwrite: {
            value: 'S to F grade',
            note: 'With a verdict decided by code',
          },
          competitor: {
            value: 'partial',
            note: 'Pre-merge checks, merge blocking on Advanced',
          },
        },
        {
          label: 'Reads failed CI checks',
          appwrite: { value: true, note: 'Failed checks on the head commit' },
          competitor: {
            value: true,
            note: 'Logs from GitHub Actions, GitLab CI, and more',
          },
        },
        {
          label: 'Linters and security scanners in the review',
          appwrite: false,
          competitor: { value: true, note: '50+ tools' },
        },
        {
          label: 'Reviews in the IDE and CLI',
          appwrite: false,
          competitor: true,
        },
      ],
    },
    {
      title: 'Git providers',
      rows: [
        { label: 'GitHub', appwrite: true, competitor: true },
        { label: 'GitLab', appwrite: true, competitor: true },
        { label: 'Bitbucket', appwrite: true, competitor: true },
        { label: 'Azure DevOps', appwrite: false, competitor: true },
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
            value: 'Per developer',
            note: 'From $24 a month, billed annually',
          },
        },
        { label: 'No per-seat fees', appwrite: true, competitor: false },
        {
          label: 'Free plan',
          appwrite: {
            value: '100 credits a month',
            note: 'About two Normal reviews',
          },
          competitor: {
            value: 'partial',
            note: 'Pull request summaries; reviews in the IDE and CLI',
          },
        },
        {
          label: 'Usage past the plan',
          appwrite: {
            value: '$1 per 100 credits',
            note: 'Up to your budget cap',
          },
          competitor: {
            value: '$0.25 per file',
            note: 'No spending cap unless an admin sets one',
          },
        },
      ],
    },
    {
      title: 'Platform',
      rows: [
        {
          label: 'Deploys your Sites and Functions',
          appwrite: true,
          competitor: false,
        },
        {
          label: 'Backend for the app you review',
          appwrite: {
            value: true,
            note: 'Auth, databases, storage, messaging, realtime',
          },
          competitor: false,
        },
        {
          label: 'Monitors production',
          appwrite: { value: true, note: 'Usage monitors and endpoint checks' },
          competitor: {
            value: 'partial',
            note: 'An incident agent reads your Datadog or Sentry',
          },
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
    title: 'When CodeRabbit might still fit',
    description:
      'CodeRabbit is a mature, dedicated reviewer with a wide set of review tools. If review depth is all you need from a vendor, it is a strong choice.',
    points: [
      'You want reviews in your editor and terminal as well as on pull requests.',
      'Your repositories are on Azure DevOps.',
      'You want 50+ linters and security scanners run inside every review.',
      'You want generated tests, CI failure fixes, and Jira or Linear context built in.',
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
      question: 'Is Appwrite Reviews a good CodeRabbit alternative?',
      answer:
        'Yes, especially if your code ships on Appwrite. Appwrite Reviews posts a summary, findings on the lines they concern, a verdict, and a merge confidence grade on every pull request, from the same Appwrite project that deploys your Sites and Functions, monitors production, and pages you with Beep. CodeRabbit is a dedicated reviewer you add as another vendor, priced per developer.',
      links: [{ label: 'Appwrite Reviews', href: '/products/reviews' }],
    },
    {
      question: 'How is Appwrite Reviews priced compared with CodeRabbit?',
      answer:
        'Appwrite Reviews uses credits from your Appwrite plan instead of seats. A Basic review uses 10 credits, Normal 50, and Expert 500. Free includes 100 credits a month for your organization, Pro includes 2,000 a month for each project, and extra credits cost $1 per 100 on paid plans. CodeRabbit charges per developer who opens pull requests, from $24 a month billed annually, with usage charges past its limits.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Which Git providers does each one support?',
      answer:
        'Appwrite Reviews works with GitHub, GitLab, and Bitbucket. CodeRabbit supports GitHub, GitLab, Bitbucket, and Azure DevOps, including their self-managed editions.',
    },
    {
      question: 'Can I talk to the reviewer?',
      answer:
        'Yes. Mention it in a comment to ask a question or request a new review, or reply to one of its findings. Answers are free, and when you state a lasting rule it saves a learning that later reviews follow. CodeRabbit also chats through @coderabbitai and keeps editable learnings.',
    },
    {
      question: 'Does Appwrite Reviews read my deployments or logs?',
      answer:
        'No. The reviewer reads the pull request: the diff, the repository at its head, the description, linked issues, failed checks, and rule files such as AGENTS.md. What Appwrite adds is everything around the review: the same Git connection deploys the code, and the same project hosts, monitors, and alerts on it after you merge.',
    },
    {
      question: 'How does it work with Appwrite Sites and Functions?',
      answer:
        'Review pull requests is on by default when you create a site or function from a repository, every site and function has a Reviews tab, and the review comment sits next to the deployment comment from the same Appwrite app on the pull request.',
      links: [
        { label: 'Appwrite Sites', href: '/products/sites' },
        { label: 'Appwrite Functions', href: '/products/functions' },
      ],
    },
    {
      question: 'Is Appwrite open source?',
      answer:
        'Yes. Appwrite and its review service are open source, and you can self-host them. CodeRabbit is closed source, with self-hosting on Enterprise plans for 500 or more seats.',
    },
  ],
  sources: [
    { label: 'CodeRabbit pricing', href: 'https://www.coderabbit.ai/pricing' },
    {
      label: 'CodeRabbit plans',
      href: 'https://docs.coderabbit.ai/management/plans.md',
    },
    {
      label: 'CodeRabbit usage-based add-on',
      href: 'https://docs.coderabbit.ai/management/usage-based-addon.md',
    },
    {
      label: 'CodeRabbit platforms',
      href: 'https://docs.coderabbit.ai/platforms/overview.md',
    },
    {
      label: 'CodeRabbit code review overview',
      href: 'https://docs.coderabbit.ai/guides/code-review-overview.md',
    },
    {
      label: 'CodeRabbit code guidelines',
      href: 'https://docs.coderabbit.ai/knowledge-base/code-guidelines.md',
    },
    {
      label: 'CodeRabbit tools',
      href: 'https://docs.coderabbit.ai/tools/index.md',
    },
    {
      label: 'CodeRabbit IDE',
      href: 'https://docs.coderabbit.ai/ide/index.md',
    },
    {
      label: 'CodeRabbit CI/CD analysis',
      href: 'https://docs.coderabbit.ai/pr-reviews/cicd-pipeline-analysis.md',
    },
    { label: 'CodeRabbit Agent', href: 'https://www.coderabbit.ai/agent' },
    {
      label: 'CodeRabbit self-hosted',
      href: 'https://docs.coderabbit.ai/self-hosted/overview.md',
    },
    {
      label: 'CodeRabbit security',
      href: 'https://www.coderabbit.ai/security',
    },
  ],
}
