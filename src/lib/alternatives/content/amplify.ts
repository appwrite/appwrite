import type { AlternativeContent } from '@/lib/alternatives/types'

export const amplifyAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Platform',
      rows: [
        {
          label: 'Where it runs',
          appwrite: { value: 'Appwrite Cloud or any server', note: 'Same APIs, SDKs, and Console' },
          competitor: { value: 'AWS only', note: 'Libraries are open source, services are not' },
        },
        {
          label: 'Consoles to manage',
          appwrite: { value: 'One', note: 'Every product in one project' },
          competitor: { value: 'Several', note: 'Amplify, Cognito, AppSync, DynamoDB, Lambda, S3, IAM' },
        },
        {
          label: 'Backend definition',
          appwrite: { value: 'Console, CLI, or Terraform', note: 'Plus SDKs in 13+ languages' },
          competitor: { value: 'TypeScript with AWS CDK', note: 'Deployed as CloudFormation stacks' },
        },
        { label: 'Per-seat pricing', appwrite: false, competitor: false },
      ],
    },
    {
      title: 'Backend',
      rows: [
        {
          label: 'Authentication',
          appwrite: { value: true, note: 'Appwrite Auth, 40+ OAuth providers, MFA, teams' },
          competitor: { value: true, note: 'Amazon Cognito' },
        },
        {
          label: 'Database models',
          appwrite: { value: '5', note: 'Tables, documents, vectors, PostgreSQL, MySQL' },
          competitor: { value: '1', note: 'DynamoDB through AppSync GraphQL' },
        },
        {
          label: 'Managed PostgreSQL and MySQL',
          appwrite: true,
          competitor: { value: 'partial', note: 'Bring a database you run yourself' },
        },
        {
          label: 'Function runtimes',
          appwrite: '13+',
          competitor: { value: 'Node.js', note: 'Other languages through custom CDK code' },
        },
        {
          label: 'Realtime subscriptions',
          appwrite: { value: true, note: 'Every service, one socket' },
          competitor: { value: true, note: 'GraphQL subscriptions on AppSync' },
        },
        {
          label: 'Email, SMS, and push messaging',
          appwrite: { value: true, note: 'One API, 12 providers' },
          competitor: { value: 'partial', note: 'Pinpoint-backed features end on October 30, 2026' },
        },
      ],
    },
    {
      title: 'Hosting',
      rows: [
        { label: 'Git deploys, previews, and SSR', appwrite: true, competitor: true },
        {
          label: 'Bandwidth on the base plan',
          appwrite: { value: '2TB included', note: 'On Pro' },
          competitor: { value: '$0.15 per GB', note: 'After 15GB a month' },
        },
        {
          label: 'Web application firewall',
          appwrite: { value: true, note: 'Rules per project, included' },
          competitor: { value: '$15/mo per app', note: 'Plus AWS WAF usage' },
        },
        {
          label: 'Buy and manage domains',
          appwrite: true,
          competitor: { value: 'partial', note: 'Through Route 53' },
        },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        {
          label: 'Billing model',
          appwrite: { value: 'One plan with allowances', note: 'From $25/mo, budget caps' },
          competitor: { value: 'Per service, per request', note: 'Each AWS service is metered separately' },
        },
        {
          label: 'Hard budget cap',
          appwrite: true,
          competitor: { value: false, note: 'AWS Budgets sends alerts' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Amplify might still fit',
    description:
      'Amplify is a capable way into AWS. It may still suit you if these describe your project.',
    points: [
      'Your company already runs on AWS and wants every resource inside its own accounts and IAM policies.',
      'You want to define your backend in TypeScript and extend it with any AWS service through CDK.',
      'You rely on AWS credits, enterprise agreements, or compliance programs tied to AWS.',
      'Your data model fits DynamoDB access patterns and GraphQL through AppSync.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Choosing the right platform to deploy your web apps: Vercel, Netlify, Amplify, and Appwrite Sites compared',
      description: 'Hosting, previews, and pricing across four platforms.',
      href: '/blog/post/netlify-vs-vercel-vs-amplify-vs-appwrite-sites',
    },
    {
      kind: 'blog',
      title: 'How to evaluate backend tools without locking yourself in',
      description: 'A practical framework for keeping your options open.',
      href: '/blog/post/evaluate-backend-tools-no-lock-in',
    },
    {
      kind: 'blog',
      title: 'Native databases vs Appwrite databases: which one should you pick?',
      description: 'Tables, documents, vectors, and native SQL compared.',
      href: '/blog/post/native-databases-vs-appwrite-databases',
    },
    {
      kind: 'product',
      title: 'Appwrite Sites',
      description: 'Static, SSR, and CSR deploys from Git.',
      href: '/products/sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Messaging',
      description: 'Email, SMS, and push from one API.',
      href: '/products/messaging',
    },
    {
      kind: 'docs',
      title: 'Self-hosting',
      description: 'Run the whole platform on your own servers.',
      href: '/docs/advanced/self-hosting',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than AWS Amplify?',
      answer:
        'For most teams that want to ship rather than operate AWS, yes. Appwrite gives you auth, five database models, storage, functions in 13+ runtimes, realtime, messaging, and hosting in one open-source project, with one Console and one bill. Amplify wires the same features together from Cognito, AppSync, DynamoDB, Lambda, and S3, each with its own console, limits, and pricing.',
    },
    {
      question: 'What is the best open-source alternative to AWS Amplify?',
      answer:
        'Appwrite is the best open-source alternative to AWS Amplify. The whole platform is open source, so it runs on Appwrite Cloud, on any cloud provider, or on your own servers with the same APIs and Console. Amplify libraries are open source, but the services behind them run only on AWS.',
      links: [{ label: 'Self-hosting', href: '/docs/advanced/self-hosting' }],
    },
    {
      question: 'What replaces Amplify push notifications and analytics?',
      answer:
        'Amplify push notifications and analytics are built on Amazon Pinpoint, which reaches end of support on October 30, 2026. On Appwrite, Messaging sends push, email, and SMS from one API with 12 providers, and it lives in the same project as your users and data.',
      links: [{ label: 'Appwrite Messaging', href: '/products/messaging' }],
    },
    {
      question: 'Does Amplify support SQL databases?',
      answer:
        'Amplify Data stores models in DynamoDB. It can connect to an existing PostgreSQL or MySQL database, but you provision, scale, and back up that database yourself. Appwrite runs managed PostgreSQL and MySQL for you, next to TablesDB, DocumentsDB, and VectorsDB in the same project.',
      links: [{ label: 'Appwrite Databases', href: '/products/databases' }],
    },
    {
      question: 'Can I write functions in Python or Go?',
      answer:
        'On Appwrite, yes. Functions run in 13+ runtimes, including Python, Go, Dart, PHP, Ruby, Java, Kotlin, Swift, .NET, and Node.js. Amplify Gen 2 functions use Node.js, and other languages need custom CDK code.',
      links: [{ label: 'Runtimes', href: '/docs/products/functions/runtimes' }],
    },
    {
      question: 'How does hosting cost compare?',
      answer:
        'Amplify Hosting charges $0.15 per GB served after 15GB a month, plus build minutes, storage, and SSR requests. Serving 2TB comes to about $300 a month in bandwidth alone. Appwrite Pro starts at $25/mo and includes 2TB of bandwidth, with the backend in the same plan.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
  ],
  sources: [
    { label: 'AWS Amplify pricing', href: 'https://aws.amazon.com/amplify/pricing/' },
    { label: 'Amplify Gen 2 functions', href: 'https://docs.amplify.aws/react/build-a-backend/functions/' },
    {
      label: 'Amplify Data with existing PostgreSQL and MySQL',
      href: 'https://docs.amplify.aws/react/build-a-backend/data/connect-to-existing-data-sources/connect-postgres-mysql-database/',
    },
    { label: 'Amazon Pinpoint end of support', href: 'https://docs.aws.amazon.com/pinpoint/latest/userguide/migrate.html' },
  ],
}
