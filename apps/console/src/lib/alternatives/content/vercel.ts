import type { AlternativeContent } from '@/lib/alternatives/types'

export const vercelAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Hosting',
      rows: [
        { label: 'Static and SSR hosting', appwrite: true, competitor: true },
        { label: 'Git deploys, previews, and rollbacks', appwrite: true, competitor: true },
        { label: 'Custom domains, TLS, and domain purchase', appwrite: true, competitor: true },
        { label: 'DDoS mitigation', appwrite: true, competitor: true },
        { label: 'Firewall rules on Pro', appwrite: '50 per project', competitor: '40 custom rules' },
        {
          label: 'Open source and self-hostable',
          appwrite: { value: true, note: 'Self-host anywhere' },
          competitor: false,
        },
      ],
    },
    {
      title: 'First-party backend',
      rows: [
        {
          label: 'Authentication',
          appwrite: { value: true, note: '40+ OAuth providers, MFA, teams' },
          competitor: { value: false, note: 'Marketplace or third party' },
        },
        {
          label: 'Databases',
          appwrite: { value: true, note: 'TablesDB, PostgreSQL, MySQL, and more' },
          competitor: { value: 'partial', note: 'Marketplace partners such as Neon and Upstash' },
        },
        {
          label: 'File storage with user permissions',
          appwrite: true,
          competitor: { value: 'partial', note: 'Blob storage without per-user permissions' },
        },
        { label: 'Realtime subscriptions', appwrite: true, competitor: false },
        { label: 'Email, SMS, and push messaging', appwrite: true, competitor: false },
        {
          label: 'Serverless functions',
          appwrite: { value: true, note: '13+ runtimes' },
          competitor: true,
        },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        { label: 'Pro plan', appwrite: 'From $25/mo', competitor: '$20/mo per deploying seat' },
        {
          label: 'Team members',
          appwrite: 'Unlimited, included',
          competitor: { value: '$20/mo each', note: 'Per extra deploying seat, viewers are free' },
        },
        { label: 'Bandwidth included on Pro', appwrite: '2TB', competitor: '1TB' },
        {
          label: 'Spend control covers',
          appwrite: { value: 'Hosting and backend', note: 'One organization-wide budget cap' },
          competitor: { value: 'Vercel usage', note: 'Excludes Marketplace integrations' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Vercel might still fit',
    description:
      'Vercel builds Next.js and runs a polished frontend cloud. It may suit you if these describe your project.',
    points: [
      'You run a large Next.js app and want new framework features the day they ship.',
      'Your routes need long-running functions (up to 800 seconds on Pro) or streaming responses.',
      'You want managed bot protection, an AI gateway, and v0 from the same vendor.',
      'Your backend already lives somewhere else and you only need a frontend host.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite vs Vercel vs Netlify: where does your stack live?',
      description: 'The backend gap behind every frontend cloud.',
      href: '/blog/post/appwrite-vs-vercel-vs-netlify',
    },
    {
      kind: 'blog',
      title: 'Appwrite Sites vs Vercel: Choosing the right web hosting platform',
      description: 'Containers, automatic API keys, and CORS that trusts only your project.',
      href: '/blog/post/open-source-vercel-alternative',
    },
    {
      kind: 'docs',
      title: 'Migrate from Vercel',
      description: 'Build settings, environment variables, and domains, step by step.',
      href: '/docs/products/sites/migrations/vercel',
    },
    {
      kind: 'product',
      title: 'Appwrite Sites',
      description: 'Static, SSR, and CSR deploys from Git.',
      href: '/products/sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Firewall',
      description: 'Deny, rate limit, redirect, and challenge traffic per project.',
      href: '/products/firewall',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Vercel?',
      answer:
        'If your app needs a backend, yes. Appwrite Sites matches the deploy workflow Vercel is known for, with Git deploys, previews, rollbacks, and custom domains, plus Appwrite Network for edge delivery and a global CDN. It also adds first-party auth, databases, storage, functions, realtime, and messaging in the same project. Appwrite Pro has no per-seat pricing, so your whole team is included.',
    },
    {
      question: 'What is the best open-source alternative to Vercel?',
      answer:
        'Appwrite is the best open-source alternative to Vercel. Appwrite Sites is fully open source, hosts Next.js, Nuxt, SvelteKit, Astro, and more, and runs on Appwrite Cloud or your own servers with the same Console, so your hosting is never locked to one vendor.',
      links: [{ label: 'Appwrite Sites', href: '/products/sites' }],
    },
    {
      question: 'Is Appwrite Sites a good Vercel alternative?',
      answer:
        'Yes, especially when you also need a backend. Sites deploys static and server-rendered apps from Git with preview URLs, instant rollbacks, custom domains, and firewall rules, and it runs next to Appwrite Auth, Databases, Storage, Functions, Messaging, and Realtime in the same project.',
    },
    {
      question: 'Does Appwrite Sites support Next.js?',
      answer:
        'Yes. Next.js runs in containers on Sites with SSR, API routes, middleware, and server actions, and standalone output is supported for smaller builds and faster cold starts. Sites also has presets for Nuxt, SvelteKit, Astro, Remix, TanStack Start, Angular, Analog, and more.',
      links: [{ label: 'Frameworks', href: '/docs/products/sites/frameworks' }],
    },
    {
      question: 'How does pricing compare for a team?',
      answer:
        'Vercel Pro is $20/mo per deploying seat, so a team of five starts at $100/mo before usage. Appwrite Pro starts at $25/mo with unlimited members, 2TB of bandwidth, and the backend included.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'How do I move a project from Vercel?',
      answer:
        'Connect your Git repository to a new site, pick the framework preset, copy your environment variables, and point your domain at Appwrite. The migration guide covers build settings, environment variables, and domains.',
      links: [{ label: 'Migrate from Vercel', href: '/docs/products/sites/migrations/vercel' }],
    },
    {
      question: 'Can I keep my frontend on Vercel and use Appwrite as the backend?',
      answer:
        'Yes. Appwrite SDKs work from any host. Many teams start that way and move the frontend to Sites later, so CORS, API keys, and billing live in one place.',
    },
    {
      question: 'Can I self-host Appwrite Sites?',
      answer:
        'Yes. Sites ships with every self-hosted Appwrite install and uses the same Console and deployment flow as Appwrite Cloud.',
      links: [{ label: 'Self-hosting', href: '/docs/advanced/self-hosting' }],
    },
  ],
  sources: [
    { label: 'Vercel pricing', href: 'https://vercel.com/pricing' },
    { label: 'Vercel Pro plan', href: 'https://vercel.com/docs/plans/pro-plan' },
    { label: 'Vercel spend management', href: 'https://vercel.com/docs/spend-management' },
    { label: 'Vercel storage', href: 'https://vercel.com/docs/storage' },
    { label: 'Vercel Flat Rate CDN', href: 'https://vercel.com/docs/pricing/flat-rate-cdn' },
  ],
}
