import type { AlternativeContent } from '@/lib/alternatives/types'

export const netlifyAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Hosting',
      rows: [
        { label: 'Static and SSR hosting', appwrite: true, competitor: true },
        { label: 'Deploy previews and rollbacks', appwrite: true, competitor: true },
        { label: 'Custom domains and TLS', appwrite: true, competitor: true },
        {
          label: 'Firewall rules',
          appwrite: { value: '50 per project on Pro', note: 'Deny, rate limit, redirect, challenge' },
          competitor: { value: 'Traffic rules', note: 'Managed WAF rulesets on Enterprise' },
        },
        {
          label: 'Open source and self-hostable',
          appwrite: { value: true, note: 'Self-host anywhere' },
          competitor: false,
        },
      ],
    },
    {
      title: 'Usage and billing',
      rows: [
        {
          label: 'Pricing model',
          appwrite: { value: 'Allowance per resource', note: 'Pro from $25/mo' },
          competitor: { value: 'One credit balance', note: 'Pro is $20/mo for 3,000 credits' },
        },
        {
          label: 'Bandwidth on Pro',
          appwrite: '2TB included',
          competitor: { value: 'About 150GB', note: 'If all 3,000 credits go to bandwidth' },
        },
        { label: 'Production deploys', appwrite: 'Not metered', competitor: '15 credits each' },
        { label: 'Team members on Pro', appwrite: 'Unlimited', competitor: 'Unlimited' },
        {
          label: 'When included usage runs out',
          appwrite: { value: 'Pay as you go', note: 'Up to your budget cap' },
          competitor: { value: 'Projects pause', note: 'Until credits are added' },
        },
      ],
    },
    {
      title: 'Backend',
      rows: [
        {
          label: 'Authentication',
          appwrite: { value: true, note: 'MFA, phone, anonymous, teams, 40+ OAuth' },
          competitor: { value: 'partial', note: 'Netlify Identity: email and four OAuth providers' },
        },
        {
          label: 'Databases',
          appwrite: { value: true, note: 'TablesDB, PostgreSQL, MySQL, and more' },
          competitor: { value: true, note: 'Netlify Database (Postgres)' },
        },
        {
          label: 'File storage with user permissions',
          appwrite: true,
          competitor: { value: 'partial', note: 'Netlify Blobs without per-user permissions' },
        },
        { label: 'Realtime subscriptions', appwrite: true, competitor: false },
        { label: 'Email, SMS, and push messaging', appwrite: true, competitor: false },
        {
          label: 'Function runtimes',
          appwrite: '13+',
          competitor: 'JavaScript, TypeScript, Go',
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Netlify might still fit',
    description:
      'Netlify pioneered modern web deploys and still hosts many static sites well. It may suit you if these apply.',
    points: [
      'Your site is mostly static, and Netlify Forms handles submissions without any backend.',
      'You rely on the large catalog of build plugins and integrations around the Jamstack.',
      'Your server routes need up to 60 seconds per synchronous request.',
      'Your monthly traffic is small enough to stay inside the free credit allowance.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite Sites vs Netlify: Choosing the right web hosting platform',
      description: 'Containers for SSR, automatic API keys, and built-in CORS trust.',
      href: '/blog/post/open-source-netlify-alternative',
    },
    {
      kind: 'blog',
      title: 'Appwrite vs Vercel vs Netlify: where does your stack live?',
      description: 'Why hosting and backend belong in the same project.',
      href: '/blog/post/appwrite-vs-vercel-vs-netlify',
    },
    {
      kind: 'blog',
      title: 'How we reduced cold start times on Appwrite Sites',
      description: 'Smaller builds and 30 to 50% faster cold starts.',
      href: '/blog/post/reducing-cold-starts-appwrite-sites',
    },
    {
      kind: 'blog',
      title: 'How to host SSR web apps on Appwrite Sites',
      description: 'Adapter settings for SvelteKit, Astro, Remix, Nuxt, and Angular.',
      href: '/blog/post/host-ssr-web-apps-sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Sites',
      description: 'Static, SSR, and CSR deploys from Git.',
      href: '/products/sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Domains',
      description: 'Search, buy, and manage domains next to your sites.',
      href: '/domains',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Netlify?',
      answer:
        'For apps that outgrow a static site, yes. Appwrite Sites gives you the same Git deploy workflow with 2TB of bandwidth on Pro and unmetered deploys instead of a shared credit pool, plus a complete backend with auth, databases, storage, functions, realtime, and messaging in the same project.',
    },
    {
      question: 'What is the best open-source alternative to Netlify?',
      answer:
        'Appwrite is the best open-source alternative to Netlify. Appwrite Sites is fully open source, deploys static and SSR apps from Git, and runs on Appwrite Cloud or your own servers with the same APIs and Console, so you can move your sites whenever you want.',
      links: [{ label: 'Appwrite Sites', href: '/products/sites' }],
    },
    {
      question: 'Is Appwrite Sites a good Netlify alternative?',
      answer:
        'Yes. Sites deploys static and server-rendered apps from Git with previews, rollbacks, custom domains, TLS, and firewall rules. Pro includes 2TB of bandwidth, deploys are not metered, and a full backend lives in the same project.',
    },
    {
      question: 'How do Netlify credits compare to Appwrite pricing?',
      answer:
        'On Netlify credit plans, every product draws from one balance: 20 credits per GB of bandwidth, 15 per production deploy, plus compute and requests. Pro includes 3,000 credits for $20/mo. Appwrite Pro starts at $25/mo with a fixed allowance for each resource, including 2TB of bandwidth, and anything above it is pay as you go up to your budget cap.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'What happens when I reach my limits?',
      answer:
        'On Netlify, when credits run out and auto-recharge is off, projects stop serving until you add credits. On Appwrite Pro, usage above the included amounts is billed per resource, and an optional budget cap stops automatic scaling at the amount you choose.',
      links: [{ label: 'Budget caps', href: '/docs/advanced/billing/pro#budget-cap' }],
    },
    {
      question: 'Which frameworks can I deploy?',
      answer:
        'Sites has presets for Next.js, Nuxt, SvelteKit, Astro, Remix, TanStack Start, Angular, Analog, and more, plus Flutter Web and React Native for web. Any static output can be deployed too.',
      links: [{ label: 'Frameworks', href: '/docs/products/sites/frameworks' }],
    },
    {
      question: 'Does Appwrite replace Netlify Identity and Netlify Database?',
      answer:
        'Yes, and it goes further. Appwrite Auth adds MFA, phone and anonymous sign-in, teams, and 40+ OAuth providers. Databases include TablesDB, DocumentsDB, VectorsDB, and managed PostgreSQL and MySQL, with permissions tied to Auth.',
      links: [
        { label: 'Appwrite Auth', href: '/products/auth' },
        { label: 'Appwrite Databases', href: '/products/databases' },
      ],
    },
  ],
  sources: [
    { label: 'Netlify pricing', href: 'https://www.netlify.com/pricing/' },
    {
      label: 'How Netlify credits work',
      href: 'https://docs.netlify.com/manage/accounts-and-billing/billing/billing-for-credit-based-plans/how-credits-work/',
    },
    {
      label: 'Netlify Identity',
      href: 'https://docs.netlify.com/manage/security/secure-access-to-sites/identity/overview/',
    },
    {
      label: 'Netlify Database',
      href: 'https://www.netlify.com/changelog/2026-04-28-netlify-database/',
    },
  ],
}
