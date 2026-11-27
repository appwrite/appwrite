import type { AlternativeContent } from '@/lib/alternatives/types'

/**
 * Greptile facts checked on 2026-11-27 against its pricing, billing, code
 * provider, features, TREX, and security pages (see `sources`).
 */
export const greptileAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Review',
      rows: [
        {
          label: 'Pull request summary',
          appwrite: { value: true, note: 'With a walkthrough' },
          competitor: { value: true, note: 'With diagrams' },
        },
        {
          label: 'Findings on the changed lines',
          appwrite: {
            value: 'Critical to Info',
            note: 'With a category on each',
          },
          competitor: { value: 'P0 to P2', note: 'Severity on each finding' },
        },
        {
          label: 'Merge confidence',
          appwrite: {
            value: 'S to F grade',
            note: 'With a verdict decided by code',
          },
          competitor: {
            value: '0 to 5 score',
            note: 'A confidence score on each review',
          },
        },
        { label: 'Suggested fixes', appwrite: true, competitor: true },
        {
          label: 'Chat with the reviewer',
          appwrite: { value: true, note: 'Mention it or reply to a finding' },
          competitor: { value: true, note: 'Mention @greptileai' },
        },
        {
          label: 'Learns team preferences',
          appwrite: { value: true, note: 'From pull request conversations' },
          competitor: { value: true, note: 'From reactions and replies' },
        },
        {
          label: 'Hands findings to coding agents',
          appwrite: { value: true, note: 'A fix prompt on every finding' },
          competitor: { value: true, note: 'Fix with your agent' },
        },
        {
          label: 'Runs code while reviewing',
          appwrite: {
            value: 'partial',
            note: 'Targeted commands in a sandbox',
          },
          competitor: {
            value: true,
            note: 'TREX clicks through the app, +3 credits',
          },
        },
        {
          label: 'Cross-repository context',
          appwrite: false,
          competitor: { value: true, note: 'On Pro' },
        },
        {
          label: 'Jira, Linear, and Notion',
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
        {
          label: 'Gitea',
          appwrite: false,
          competitor: { value: 'partial', note: 'Enterprise only' },
        },
        { label: 'Azure DevOps', appwrite: false, competitor: false },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        {
          label: 'Pricing model',
          appwrite: {
            value: 'Credits from your plan',
            note: 'Included every month',
          },
          competitor: {
            value: 'Prepaid credits',
            note: '$1 each, shared by the organization',
          },
        },
        {
          label: 'Credits per review',
          appwrite: {
            value: '10, 50, or 500',
            note: 'Basic, Normal, or Expert',
          },
          competitor: { value: '1, 3, or 10', note: 'Base, Plus, or Apex' },
        },
        {
          label: 'Free tier',
          appwrite: {
            value: '100 credits a month',
            note: 'For the organization',
          },
          competitor: {
            value: '50 credits a month',
            note: 'One active developer',
          },
        },
        {
          label: 'When credits run out',
          appwrite: {
            value: 'Extra credits',
            note: '$1 per 100, up to your budget cap',
          },
          competitor: { value: 'Reviews pause', note: 'Until you add credits' },
        },
      ],
    },
    {
      title: 'Platform',
      rows: [
        {
          label: 'Deploys and hosts the app',
          appwrite: true,
          competitor: false,
        },
        {
          label: 'Backend for the app',
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
    title: 'When Greptile might still fit',
    description:
      'Greptile is a focused reviewer with deep codebase context and an agent that tests the app. If you want the reviewer to click through your app before merge, it is built for that.',
    points: [
      'You want TREX to run the pull request’s app in a sandbox and attach logs and video.',
      'You want cross-repository context in every review.',
      'You track work in Jira, Linear, or Notion and want reviews linked to it.',
      'You need an air-gapped, self-hosted reviewer with SSO on an Enterprise contract.',
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
      question: 'Is Appwrite Reviews a good Greptile alternative?',
      answer:
        'Yes, especially if your app runs on Appwrite. Appwrite Reviews posts a summary, verified findings, a verdict, and a merge confidence grade on every pull request, from the same project that deploys, hosts, and monitors your app. Greptile is a dedicated reviewer billed in prepaid credits.',
      links: [{ label: 'Appwrite Reviews', href: '/products/reviews' }],
    },
    {
      question: 'How do the credits compare?',
      answer:
        'Both bill reviews in credits. Greptile credits cost $1: a Base review uses 1, Plus 3, and Apex 10, and reviews pause when credits run out. Appwrite review credits come with your plan: 100 a month on Free, 2,000 a month for each project on Pro, and 20,000 on Scale. A review uses 10, 50, or 500 credits, and extra credits cost $1 per 100.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Greptile runs my app in a sandbox. Does Appwrite?',
      answer:
        'Greptile’s TREX runs the pull request’s app in a sandbox and clicks through its flows. Appwrite Reviews can run targeted commands in a sandbox to confirm a suspected bug on Normal and Expert strengths, and Appwrite hosts the app itself: a preview deployment on Sites for each pull request, then Monitors and Beep in production after you merge.',
    },
    {
      question: 'What does the merge grade mean?',
      answer:
        'Every review grades the pull request from S, ready to merge, to F, do not merge. Open findings cap the grade, and the verdict to approve, request changes, or comment is decided by code from the findings, not by the model.',
    },
    {
      question: 'Which Git providers are supported?',
      answer:
        'Appwrite Reviews works with GitHub, GitLab, and Bitbucket. Greptile supports GitHub, GitLab, and Bitbucket on every plan, and Gitea on Enterprise.',
    },
    {
      question: 'Does Appwrite Reviews read my production data?',
      answer:
        'No. The reviewer reads the pull request: the diff, the repository at its head, the description, linked issues, failed checks, and rule files such as AGENTS.md. What Appwrite adds is everything around the review: the same Git connection deploys the code, and the same project hosts, monitors, and alerts on it after you merge.',
    },
    {
      question: 'Is Appwrite open source?',
      answer:
        'Yes. Appwrite and its review service are open source, and you can self-host them. Greptile is closed source, with self-hosting on Enterprise contracts.',
    },
  ],
  sources: [
    { label: 'Greptile pricing', href: 'https://www.greptile.com/pricing' },
    {
      label: 'Greptile billing and seats',
      href: 'https://www.greptile.com/docs/account/billing-seats.md',
    },
    {
      label: 'Greptile code providers',
      href: 'https://www.greptile.com/docs/integrations/code-providers.md',
    },
    {
      label: 'Greptile first review',
      href: 'https://www.greptile.com/docs/code-review/first-pr-review.md',
    },
    {
      label: 'Greptile key features',
      href: 'https://www.greptile.com/docs/code-review/key-features.md',
    },
    {
      label: 'Greptile TREX',
      href: 'https://www.greptile.com/docs/trex/overview.md',
    },
    { label: 'Greptile security', href: 'https://www.greptile.com/security' },
    {
      label: 'Greptile Series B',
      href: 'https://www.greptile.com/blog/series-b',
    },
  ],
}
