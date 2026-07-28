import {
  isFirewallDocsEnabled,
  isFirewallDocsHref,
} from '../firewall-docs-feature'
import type { DocsNavGroup, DocsNavTree } from '../types'
import { DOCS_LOCAL_SECTION_NAVS } from './local-sections'
import { DOCS_SECTION_NAVS, type DocsSectionNavConfig } from './sections'

function isNavGroup(item: DocsNavTree[number]): item is DocsNavGroup {
  return 'items' in item
}

function withoutFirewallDocsLinks(navigation: DocsNavTree): DocsNavTree {
  return navigation.flatMap((entry) => {
    if (isNavGroup(entry)) {
      const items = entry.items.filter((item) => !isFirewallDocsHref(item.href))
      if (items.length === 0) return []
      return [{ ...entry, items }]
    }
    if (isFirewallDocsHref(entry.href)) return []
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
  if (!isFirewallDocsEnabled()) {
    configs = configs
      .filter((config) => config.prefix !== 'products/firewall')
      .map((config) =>
        config.prefix === 'products/network'
          ? { ...config, navigation: withoutFirewallDocsLinks(config.navigation) }
          : config,
      )
  }

  return configs.sort((a, b) => a.prefix.localeCompare(b.prefix))
}
