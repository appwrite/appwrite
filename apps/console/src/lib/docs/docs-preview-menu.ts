import { getAllDocsSectionNavs } from './navigation/section-navs'

export type DocsPreviewView = 'article' | 'menu'

/** Hubs with no standalone article; always render the menu. */
const HUB_ONLY_MENU_SLUGS = new Set(['quick-starts', 'tutorials'])

export function canShowDocsPreviewMenu(slug: string): boolean {
  if (!slug) return false
  if (HUB_ONLY_MENU_SLUGS.has(slug)) return true
  return getAllDocsSectionNavs().some((config) => config.prefix === slug)
}

export function resolveDocsPreviewView(
  slug: string,
  requested: DocsPreviewView = 'article',
): DocsPreviewView {
  if (requested === 'menu') return 'menu'
  if (HUB_ONLY_MENU_SLUGS.has(slug)) return 'menu'
  return 'article'
}

export type DocsPreviewMenuMeta = {
  title: string
  description?: string
}

const PREVIEW_HUB_META: Record<string, DocsPreviewMenuMeta> = {
  'quick-starts': {
    title: 'Quick start',
    description:
      'Get started with your favorite framework and language in just a few clicks.',
  },
  tutorials: {
    title: 'Tutorials',
    description:
      'Follow a simple tutorial to get started with Appwrite in your preferred framework quickly and easily.',
  },
}

export function getDocsPreviewMenuMeta(slug: string): DocsPreviewMenuMeta | null {
  if (!canShowDocsPreviewMenu(slug)) return null

  const hubMeta = PREVIEW_HUB_META[slug]
  if (hubMeta) return hubMeta

  const config = getAllDocsSectionNavs().find((entry) => entry.prefix === slug)
  if (!config) return null

  return { title: config.parent.label }
}
