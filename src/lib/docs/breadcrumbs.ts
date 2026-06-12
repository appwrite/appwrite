import { DOCS_PAGE_MAP } from './generated/manifest'
import { DOCS_GLOBAL_NAV } from './navigation/global'
import { isDocsNavGroup } from './navigation/index'
import { DOCS_SECTION_NAVS, type DocsSectionNavConfig } from './navigation/sections'
import type { DocsNavTree } from './types'

type DocsPageLookup = Record<string, { title: string } | undefined>

function slugToHref(slug: string): string {
  return slug ? `/docs/${slug}` : '/docs'
}

function getSectionConfig(slug: string): DocsSectionNavConfig | null {
  let best: DocsSectionNavConfig | null = null
  for (const config of DOCS_SECTION_NAVS) {
    if (slug === config.prefix || slug.startsWith(`${config.prefix}/`)) {
      if (!best || config.prefix.length > best.prefix.length) {
        best = config
      }
    }
  }
  return best
}

function findNavItem(
  navigation: DocsNavTree,
  href: string,
): { groupLabel?: string; itemLabel: string } | null {
  for (const node of navigation) {
    if (isDocsNavGroup(node)) {
      for (const item of node.items) {
        if (item.href === href) {
          return { groupLabel: node.label, itemLabel: item.label }
        }
      }
    } else if (node.href === href) {
      return { itemLabel: node.label }
    }
  }
  return null
}

function getTitleForSlug(slug: string, pageMap: DocsPageLookup): string {
  return (
    pageMap[slug]?.title ??
    slug.split('/').pop()?.replace(/-/g, ' ') ??
    slug
  )
}

function getGlobalNavRootLabel(slug: string): string | null {
  const href = slugToHref(slug)
  for (const group of DOCS_GLOBAL_NAV) {
    if (!('items' in group)) continue
    for (const item of group.items) {
      if (item.href === href) return item.label
      if (item.isParent && href.startsWith(`${item.href}/`)) {
        return item.label
      }
    }
  }
  return null
}

function appendSlugPathTitles(
  crumbs: string[],
  prefix: string,
  slug: string,
  pageMap: DocsPageLookup,
): string[] {
  const relative =
    slug === prefix
      ? ''
      : prefix
        ? slug.slice(prefix.length + 1)
        : slug

  if (!relative) {
    return [...crumbs, getTitleForSlug(slug, pageMap)]
  }

  const next = [...crumbs]
  const segments = relative.split('/')
  let path = prefix
  for (const segment of segments) {
    path = path ? `${path}/${segment}` : segment
    next.push(getTitleForSlug(path, pageMap))
  }
  return next
}

export function getDocsPageBreadcrumbs(
  slug: string,
  pageMap: DocsPageLookup = DOCS_PAGE_MAP,
): string[] {
  if (!slug) return ['Docs']

  const config = getSectionConfig(slug)
  const href = slugToHref(slug)

  if (config) {
    const crumbs = [config.parent.label]
    const navMatch = findNavItem(config.navigation, href)

    if (navMatch) {
      if (navMatch.groupLabel) crumbs.push(navMatch.groupLabel)
      crumbs.push(navMatch.itemLabel)
      return crumbs
    }

    return appendSlugPathTitles(crumbs, config.prefix, slug, pageMap)
  }

  const crumbs: string[] = []
  const globalLabel = getGlobalNavRootLabel(slug)
  if (globalLabel) crumbs.push(globalLabel)

  return appendSlugPathTitles(crumbs, '', slug, pageMap).filter(Boolean)
}

export function formatDocsBreadcrumbs(breadcrumbs: string[]): string {
  return breadcrumbs.join(' / ')
}

export type DocsBreadcrumbItem = {
  label: string
  slug: string | null
}

export const DOCS_HOME_BREADCRUMB: DocsBreadcrumbItem = {
  label: 'Docs',
  slug: '',
}

function hrefToDocsSlug(href: string): string | null {
  if (href === '/docs' || href === '/docs/') return ''
  if (href.startsWith('/docs/')) return href.slice('/docs/'.length)
  return null
}

function getGlobalNavRootItem(slug: string): DocsBreadcrumbItem | null {
  const href = slugToHref(slug)
  for (const group of DOCS_GLOBAL_NAV) {
    if (!('items' in group)) continue
    for (const item of group.items) {
      if (item.href === href) {
        return { label: item.label, slug: hrefToDocsSlug(item.href) }
      }
      if (item.isParent && href.startsWith(`${item.href}/`)) {
        return { label: item.label, slug: hrefToDocsSlug(item.href) }
      }
    }
  }
  return null
}

function appendSlugPathBreadcrumbItems(
  crumbs: DocsBreadcrumbItem[],
  prefix: string,
  slug: string,
  pageMap: DocsPageLookup,
): DocsBreadcrumbItem[] {
  const relative =
    slug === prefix
      ? ''
      : prefix
        ? slug.slice(prefix.length + 1)
        : slug

  if (!relative) {
    const item = { label: getTitleForSlug(slug, pageMap), slug }
    const last = crumbs[crumbs.length - 1]
    if (last?.slug === slug) return crumbs
    return [...crumbs, item]
  }

  const next = [...crumbs]
  const segments = relative.split('/')
  let path = prefix
  for (const segment of segments) {
    path = path ? `${path}/${segment}` : segment
    next.push({ label: getTitleForSlug(path, pageMap), slug: path })
  }
  return next
}

export function getDocsPageBreadcrumbItems(
  slug: string,
  pageMap: DocsPageLookup = DOCS_PAGE_MAP,
): DocsBreadcrumbItem[] {
  if (!slug) return [DOCS_HOME_BREADCRUMB]

  const config = getSectionConfig(slug)
  const href = slugToHref(slug)

  let items: DocsBreadcrumbItem[]

  if (config) {
    const parentSlug = hrefToDocsSlug(config.parent.href)
    const crumbs: DocsBreadcrumbItem[] = [
      {
        label: config.parent.label,
        slug: parentSlug === '' ? config.prefix : parentSlug,
      },
    ]
    const navMatch = findNavItem(config.navigation, href)

    if (navMatch) {
      if (navMatch.groupLabel) {
        crumbs.push({ label: navMatch.groupLabel, slug: null })
      }
      crumbs.push({ label: navMatch.itemLabel, slug })
      items = crumbs
    } else {
      items = appendSlugPathBreadcrumbItems(crumbs, config.prefix, slug, pageMap)
    }
  } else {
    const crumbs: DocsBreadcrumbItem[] = []
    const globalRoot = getGlobalNavRootItem(slug)
    if (globalRoot) crumbs.push(globalRoot)

    items = appendSlugPathBreadcrumbItems(
      crumbs,
      globalRoot?.slug ?? '',
      slug,
      pageMap,
    ).filter((item) => item.label)
  }

  if (items[0]?.label === DOCS_HOME_BREADCRUMB.label) {
    return items
  }

  return [DOCS_HOME_BREADCRUMB, ...items]
}
