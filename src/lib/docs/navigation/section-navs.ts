import {
  isAgentDocsEnabled,
  isAgentDocsHref,
} from '../agent-docs-feature'
import {
  isDomainsDocsEnabled,
} from '../domains-docs-feature'
import {
  isFirewallDocsEnabled,
  isFirewallDocsHref,
} from '../firewall-docs-feature'
import {
  isStorageS3DocsEnabled,
  isStorageS3DocsHref,
} from '../storage-s3-docs-feature'
import type { DocsNavGroup, DocsNavTree } from '../types'
import { DOCS_LOCAL_SECTION_NAVS } from './local-sections'
import { DOCS_SECTION_NAVS, type DocsSectionNavConfig } from './sections'

function isNavGroup(item: DocsNavTree[number]): item is DocsNavGroup {
  return 'items' in item
}

function withoutHref(
  navigation: DocsNavTree,
  isHiddenHref: (href: string) => boolean,
): DocsNavTree {
  return navigation.flatMap((entry) => {
    if (isNavGroup(entry)) {
      const items = entry.items.filter((item) => !isHiddenHref(item.href))
      if (items.length === 0) return []
      return [{ ...entry, items }]
    }
    if (isHiddenHref(entry.href)) return []
    return [entry]
  })
}

/** Imported website docs plus vibes-native sections (local wins on duplicate prefix). */
export function getAllDocsSectionNavs(): DocsSectionNavConfig[] {
  const byPrefix = new Map<string, DocsSectionNavConfig>()
  for (const config of DOCS_SECTION_NAVS) {
    byPrefix.set(config.prefix, config)
  }
  for (const config of DOCS_LOCAL_SECTION_NAVS) {
    byPrefix.set(config.prefix, config)
  }

  let configs = Array.from(byPrefix.values())
  if (!isDomainsDocsEnabled()) {
    configs = configs.filter((config) => config.prefix !== 'products/domains')
  }
  if (!isFirewallDocsEnabled()) {
    configs = configs
      .filter((config) => config.prefix !== 'products/firewall')
      .map((config) =>
        config.prefix === 'products/network'
          ? {
              ...config,
              navigation: withoutHref(config.navigation, isFirewallDocsHref),
            }
          : config,
      )
  }
  if (!isAgentDocsEnabled()) {
    configs = configs
      .filter((config) => config.prefix !== 'products/agent')
      .map((config) =>
        config.prefix === 'tooling/ai' || config.prefix === 'tooling'
          ? {
              ...config,
              navigation: withoutHref(config.navigation, isAgentDocsHref),
            }
          : config,
      )
  }
  if (!isStorageS3DocsEnabled()) {
    configs = configs.map((config) =>
      config.prefix === 'products/storage'
        ? {
            ...config,
            navigation: withoutHref(config.navigation, isStorageS3DocsHref),
          }
        : config,
    )
  }

  return configs
    .map((config) =>
      config.prefix.startsWith('tooling/')
        ? {
            ...config,
            parent: { href: '/docs/tooling', label: 'Tooling' },
          }
        : config,
    )
    .sort((a, b) => a.prefix.localeCompare(b.prefix))
}
