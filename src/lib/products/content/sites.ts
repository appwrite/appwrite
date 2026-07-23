import type { ProductPageContent } from '@/lib/products/types'

export const sitesProductContent: ProductPageContent = {
  id: 'sites',
  metaDescription:
    'Deploy static, SSR, and CSR web apps with Appwrite Sites. Git-based deploys, preview URLs, instant rollbacks, custom domains, and Appwrite backends.',
  hero: {
    title: 'Deploy web apps from Git in minutes',
    description:
      'Ship static, SSR, and client-rendered frontends with automatic builds, branch previews, and Appwrite services connected behind the scenes.',
    stats: [
      { value: '14', label: 'Framework presets' },
      { value: 'Git', label: 'Push to deploy' },
      { value: 'Preview', label: 'Branch URLs' },
      { value: 'Instant', label: 'Rollbacks' },
      { value: 'Edge', label: 'CDN + TLS' },
    ],
  },
  faq: [
    {
      question: 'Which frameworks does Sites support?',
      answer:
        'Sites supports popular frameworks including Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, Remix, Angular, React, and more. Static hosting works with any framework that outputs HTML assets; SSR is available for supported server-rendered stacks. See the frameworks page for build settings per preset.',
      links: [
        { label: 'Frameworks', href: '/docs/products/sites/frameworks' },
        { label: 'Quick start', href: '/docs/products/sites/quick-start' },
      ],
    },
    {
      question: 'Can I deploy without connecting Git?',
      answer:
        'Yes. Push deployments with the Appwrite CLI from CI or your machine, or upload a .tar.gz archive from the Console for manual deploys. Git remains the recommended path for automatic builds on push and branch previews, but every deploy method uses the same build pipeline and settings.',
      links: [
        { label: 'Deploy from CLI', href: '/docs/products/sites/deploy-from-cli' },
        { label: 'Deploy manually', href: '/docs/products/sites/deploy-manually' },
      ],
    },
    {
      question: 'Can I inspect site traffic and debug SSR output?',
      answer:
        'Yes. Usage charts show requests, bandwidth, builds, and compute over selectable ranges, with breakdowns to see where traffic comes from. The Logs tab records every request with status code, method, path, and duration. Open a log entry for request and response headers. For SSR sites, console.log and console.error output appears in response logs.',
      links: [{ label: 'Site logs', href: '/docs/products/sites/logs' }],
    },
    {
      question: 'Can I use separate domains for staging and production?',
      answer:
        'Yes. Add multiple domain rules on a site: point one hostname to the active production deployment, map another to a specific Git branch for staging, or configure redirects. Branch and commit preview URLs are also generated automatically for Git deployments.',
      links: [{ label: 'Site domains', href: '/docs/products/sites/domains' }],
    },
    {
      question: 'How do preview deployments work?',
      answer:
        'When you push to a branch other than your production branch, Appwrite builds a deployment but does not activate it on your primary domain. Instead, a preview URL is generated for org members to review. Pull requests can also receive preview links and optional PR comments unless silent mode is enabled.',
      links: [
        { label: 'Previews', href: '/docs/products/sites/previews' },
        { label: 'Deploy from Git', href: '/docs/products/sites/deploy-from-git' },
      ],
    },
    {
      question: 'What is the difference between static and SSR hosting?',
      answer:
        'Static and SPA hosting serves pre-built assets at the edge with fast cold starts. SSR runs your framework on each request, which suits dynamic or user-specific pages and gives you runtime access to environment variables. Many frameworks support both modes in the same app.',
      links: [
        { label: 'Rendering', href: '/docs/products/sites/rendering' },
        { label: 'Static hosting', href: '/docs/products/sites/rendering/static' },
        { label: 'SSR hosting', href: '/docs/products/sites/rendering/ssr' },
      ],
    },
    {
      question: 'How does Appwrite Sites keep builds and deployments efficient?',
      answer:
        'Build workers restore a dependency cache at the start of each deployment, so package installs on unchanged lockfiles finish in seconds instead of minutes. Path filters and root directory settings let Turborepo monorepos skip builds when unrelated packages change. Deployment retention automatically deletes inactive deployments after a period you choose, so preview builds do not pile up and consume storage. You can also tune build and runtime CPU and memory in site settings when compilation or SSR needs more headroom.',
      links: [
        { label: 'Deploy from Git', href: '/docs/products/sites/deploy-from-git' },
        {
          label: 'Deployment retention',
          href: '/docs/products/sites/deployments#deployment-retention',
        },
      ],
    },
    {
      question: 'How do instant rollbacks work?',
      answer:
        'Instant rollbacks change which ready deployment is served to visitors. They do not delete, modify, or rebuild your code, so recovery is near-instant with zero downtime. Open your site Overview in the Console, click Instant Rollback, and promote a previous deployment.',
      links: [{ label: 'Instant rollbacks', href: '/docs/products/sites/instant-rollbacks' }],
    },
    {
      question: 'Can I buy a domain and manage DNS in Appwrite?',
      answer:
        'Yes. Purchase domains from your organization Domains tab and manage records with Appwrite DNS in the same Console. Connect the domain to a site for automatic TLS, or use the generated .appwrite.network URL while you set up DNS. Apex domains can delegate to Appwrite nameservers; subdomains use CNAME records. Sites traffic is delivered through Appwrite Network with CDN, DDoS protection, and Firewall.',
      links: [
        { label: 'Site domains', href: '/docs/products/sites/domains' },
        { label: 'Appwrite DNS', href: '/docs/products/network/dns' },
        { label: 'Firewall', href: '/products/firewall' },
      ],
    },
    {
      question: 'What edge network and security features are included?',
      answer:
        'Sites run on Appwrite Network with global CDN delivery, DDoS protection, Firewall, and TLS encryption. SSR workloads can execute closer to users at the edge while Auth, Databases, Storage, and other project services stay in your selected region.',
      links: [
        { label: 'Sites overview', href: '/docs/products/sites' },
        { label: 'Appwrite Network', href: '/docs/products/network' },
        { label: 'Firewall', href: '/products/firewall' },
      ],
    },
    {
      question: 'Can I start from templates or quick-starts?',
      answer:
        'Yes. Browse templates from Sites > Templates in the Console and filter by framework or use case. The create wizard walks you through GitHub setup, production branch, environment variables, and domain configuration. Official quick-starts cover Next.js, Nuxt, SvelteKit, Astro, Vue, TanStack Start, and more.',
      links: [
        { label: 'Templates', href: '/docs/products/sites/templates' },
        { label: 'Quick start', href: '/docs/products/sites/quick-start' },
      ],
    },
    {
      question: 'Can Sites connect to my Appwrite backend?',
      answer:
        'Yes. Sites deploy in the same project as Auth, Databases, Storage, Functions, and Messaging. Use environment variables for API keys and endpoints, then call Appwrite SDKs from your frontend or SSR routes without managing separate infrastructure.',
      links: [
        { label: 'Environment variables', href: '/docs/products/sites/environment-variables' },
        { label: 'Develop locally', href: '/docs/products/sites/develop' },
      ],
    },
  ],
  cta: {
    title: 'Start building with Sites',
    description: 'Connect a repository and deploy your first frontend alongside your Appwrite backend.',
  },
}
