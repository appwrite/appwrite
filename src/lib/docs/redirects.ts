import { getLegacyRedirectTarget } from '@/lib/seo/legacy-redirects'

export type DocsRedirectTarget = {
  pathname: string
  hash?: string
}

/**
 * Legacy and convenience redirects for docs paths that do not have index pages.
 */
const DOCS_REDIRECTS: Record<string, string> = {
  references: 'references/cloud/client-web/account',
  'references/quick-start': 'references/cloud/client-web/account',
  'tooling/command-line': 'tooling/command-line/installation',
  'tooling/assistant': 'products/agent',
  'tooling/ai/assistant': 'products/agent',
  'tooling/skills': 'tooling/ai/skills',
  'products/databases/spatial': 'products/databases/geo-queries#spatial-columns',
  'tutorials/android': 'tutorials/android/step-1',
  'tutorials/apple': 'tutorials/apple/step-1',
  'tutorials/astro-ssr-auth': 'tutorials/astro-ssr-auth/step-1',
  'tutorials/flutter': 'tutorials/flutter/step-1',
  'tutorials/nextjs': 'tutorials/nextjs/step-1',
  'tutorials/nextjs-ssr-auth': 'tutorials/nextjs-ssr-auth/step-1',
  'tutorials/nuxt': 'tutorials/nuxt/step-1',
  'tutorials/nuxt-ssr-auth': 'tutorials/nuxt-ssr-auth/step-1',
  'tutorials/react': 'tutorials/react/step-1',
  'tutorials/react-native': 'tutorials/react-native/step-1',
  'tutorials/refine': 'tutorials/refine/step-1',
  'tutorials/subscriptions-with-stripe': 'tutorials/subscriptions-with-stripe/step-1',
  'tutorials/sveltekit': 'tutorials/sveltekit/step-1',
  'tutorials/sveltekit-csr-auth': 'tutorials/sveltekit-csr-auth/step-1',
  'tutorials/sveltekit-ssr-auth': 'tutorials/sveltekit-ssr-auth/step-1',
  'tutorials/vue': 'tutorials/vue/step-1',
  'partners/guides/manage-domains': 'partners/domains',
  'partners/guides/provision-projects': 'partners/guides/provisioning',
  'partners/guides/marketplace': 'partners/guides/marketplaces',
  'partners/guides/multi-tenant': 'partners/guides/multi-tenancy',
  'products/network/waf': 'products/firewall',
}

function parseFullPathTarget(fullPath: string): DocsRedirectTarget {
  const hashIndex = fullPath.indexOf('#')
  if (hashIndex === -1) {
    return { pathname: fullPath }
  }

  return {
    pathname: fullPath.slice(0, hashIndex),
    hash: fullPath.slice(hashIndex + 1),
  }
}

export function getDocsRedirectTarget(slug: string): DocsRedirectTarget | null {
  const normalized = slug.replace(/^\/+|\/+$/g, '')
  const target = DOCS_REDIRECTS[normalized]
  if (target) {
    return parseFullPathTarget(`/docs/${target.replace(/\/+$/, '')}`)
  }

  // Legacy website redirects for /docs paths (client-side navigation; the
  // server middleware already 301s full page loads).
  const legacyTarget = getLegacyRedirectTarget(`/docs/${normalized}`)
  if (legacyTarget?.startsWith('/docs')) {
    return parseFullPathTarget(legacyTarget)
  }

  return null
}
