import type { ProductPageContent } from '@/lib/products/types'

export const sitesProductContent: ProductPageContent = {
  id: 'sites',
  metaDescription:
    'Deploy static, SSR, and CSR web apps with Appwrite Sites. Git-based deploys, previews, custom domains, and Appwrite backends.',
  hero: {
    title: 'Deploy web apps from Git in minutes',
    description:
      'Ship static, SSR, and CSR frontends with automatic builds, preview URLs, and Appwrite services connected behind the scenes.',
    stats: [
      { value: '14', label: 'Framework presets' },
      { value: 'Git', label: 'Push to deploy' },
      { value: 'Preview', label: 'Deployment URLs' },
      { value: '3', label: 'Rendering modes' },
    ],
  },
  faq: [
    {
      question: 'Which frameworks does Sites support?',
      answer:
        'Sites supports popular frameworks including React, Next.js, Nuxt, SvelteKit, Astro, and more. See the docs for build settings per framework.',
    },
    {
      question: 'Can I use custom domains?',
      answer:
        'Yes. Attach custom domains on Appwrite Cloud with automatic TLS for production deployments.',
    },
    {
      question: 'Do preview deployments get their own URLs?',
      answer:
        'Yes. Each deployment can expose a preview URL so you can review changes before promoting to production.',
    },
    {
      question: 'Can Sites connect to my Appwrite backend?',
      answer:
        'Yes. Sites deploys in the same project as Auth, Databases, Storage, and Functions so your frontend and backend stay together.',
    },
  ],
  cta: {
    title: 'Start building with Sites',
    description: 'Connect a repository and deploy your first frontend alongside your Appwrite backend.',
  },
}
