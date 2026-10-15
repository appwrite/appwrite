import {
  getMarketingPageUrl,
  isMarketingPageExternal,
  type MarketingPagePath,
} from '@/lib/marketing/urls'

export type PolicySlug = 'terms' | 'privacy' | 'cookies'

export type PolicyLink = {
  slug: PolicySlug
  label: string
  footerLabel: string
  path: Extract<
    MarketingPagePath,
    '/terms' | '/privacy' | '/cookies'
  >
}

export type ResolvedPolicyLink = PolicyLink & {
  href: string
  external: boolean
}

export const POLICY_LINKS: readonly PolicyLink[] = [
  {
    slug: 'terms',
    label: 'Terms and Conditions',
    footerLabel: 'Terms',
    path: '/terms',
  },
  {
    slug: 'privacy',
    label: 'Privacy Policy',
    footerLabel: 'Privacy',
    path: '/privacy',
  },
  {
    slug: 'cookies',
    label: 'Cookies Policy',
    footerLabel: 'Cookies',
    path: '/cookies',
  },
]

export function resolvePolicyLink(
  link: PolicyLink,
  marketingEnabled: boolean,
): ResolvedPolicyLink {
  return {
    ...link,
    href: getMarketingPageUrl(link.path, marketingEnabled),
    external: isMarketingPageExternal(marketingEnabled),
  }
}

export function getFooterPolicyLinks(
  marketingEnabled: boolean,
): readonly { label: string; href: string; external: boolean }[] {
  return POLICY_LINKS.map((link) => {
    const resolved = resolvePolicyLink(link, marketingEnabled)
    return {
      label: link.footerLabel,
      href: resolved.href,
      external: resolved.external,
    }
  })
}

export function getRelatedPolicyLinks(
  current: PolicySlug,
  marketingEnabled: boolean,
): readonly ResolvedPolicyLink[] {
  return POLICY_LINKS.filter((link) => link.slug !== current).map((link) =>
    resolvePolicyLink(link, marketingEnabled),
  )
}
