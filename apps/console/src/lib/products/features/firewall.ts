import type { ProductFeatureContent } from '@/lib/products/features/types'

export const firewallProductFeatures: ProductFeatureContent[] = [
  {
    id: 'actions',
    title: 'Deny, bypass, rate limit, redirect, or challenge',
    description:
      'Every rule applies one action when conditions match. Deny abusive traffic, bypass trusted clients, throttle per IP, redirect to maintenance URLs, or challenge suspicious requests before they continue.',
    docsHref: '/docs/products/firewall/actions',
    docsLabel: 'Actions docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
  },
  {
    id: 'conditions',
    title: 'Match on rich request attributes',
    description:
      'Build rules from the request properties that matter to your app. Combine conditions so a rule only fires when every filter matches.',
    docsHref: '/docs/products/firewall/conditions',
    docsLabel: 'Conditions docs',
  },
  {
    id: 'scopes',
    title: 'Scope rules to API, Functions, or Sites',
    description:
      'Apply policies to the project API, a specific Function, or a specific Site. Keep production APIs locked down while preview sites and health checks stay reachable.',
    docsHref: '/docs/products/firewall/scopes',
    docsLabel: 'Scopes docs',
  },
  {
    id: 'priority',
    title: 'Priority decides the first match',
    description:
      'Lower priority numbers evaluate first. Place bypass allowlists ahead of broader deny, rate limit, or challenge rules so trusted traffic skips the rest of the chain.',
    docsHref: '/docs/products/firewall/priority',
    docsLabel: 'Priority docs',
  },
  {
    id: 'impact',
    title: 'Preview impact before you enable',
    description:
      'Estimate how much recent traffic a draft rule would affect, then refine conditions before you turn it on.',
    docsHref: '/docs/products/firewall/monitor#impact-preview',
    docsLabel: 'Impact preview docs',
  },
  {
    id: 'monitor',
    title: 'Monitor denied, limited, redirected, and challenged traffic',
    description:
      'Track request volume alongside denied, rate-limited, redirected, and challenged outcomes on the Firewall page. Confirm policies after enablement without leaving the Console.',
    docsHref: '/docs/products/firewall/monitor',
    docsLabel: 'Monitor docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    hideDocsLink: true,
    flushBottom: true,
  },
]
