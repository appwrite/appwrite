import { extractDocsToc } from '@/lib/docs/toc'
import { preprocessBlogMarkdocContent } from '@/lib/blog/preprocess'
import { markdocToMarkdown } from '@/lib/seo/markdoc-to-markdown'
import {
  INTEGRATION_CATEGORIES,
  INTEGRATION_CATEGORY_ORDER,
  getIntegrationCategory,
  isIntegrationCategorySlug,
} from './categories'
import {
  getFrontmatterDate,
  getFrontmatterString,
  getFrontmatterStringArray,
  parseIntegrationFrontmatter,
} from './frontmatter'
import type {
  Integration,
  IntegrationCategoryGroup,
  IntegrationCategorySlug,
  IntegrationMeta,
  IntegrationPlatform,
  IntegrationsCatalog,
  IntegrationsSearch,
} from './types'

const integrationLoaders = import.meta.glob('/src/content/integrations/*.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

function slugFromModulePath(modulePath: string): string {
  const match = modulePath.match(/\/src\/content\/integrations\/(.+)\.markdoc$/)
  return match?.[1] ?? ''
}

function parseBoolean(value: unknown): boolean | undefined {
  if (typeof value === 'boolean') return value
  if (value === 'true') return true
  if (value === 'false') return false
  return undefined
}

function parseProduct(
  frontmatter: Record<string, unknown>,
): IntegrationMeta['product'] {
  const product = frontmatter.product
  if (!product || typeof product !== 'object' || Array.isArray(product)) {
    return { avatar: '', vendor: '', description: '' }
  }

  const record = product as Record<string, unknown>
  return {
    avatar: getFrontmatterString(record, 'avatar') ?? '',
    vendor: getFrontmatterString(record, 'vendor') ?? '',
    description: getFrontmatterString(record, 'description') ?? '',
  }
}

function parsePlatform(value: unknown): IntegrationPlatform[] {
  const items = getFrontmatterStringArray({ platform: value }, 'platform') ?? []
  return items.filter(
    (item): item is IntegrationPlatform =>
      item === 'Cloud' || item === 'Self-hosted',
  )
}

function parseCategory(value: string | undefined): IntegrationCategorySlug {
  if (value && isIntegrationCategorySlug(value)) return value
  return 'auth'
}

function buildIntegrationMeta(modulePath: string, raw: string): IntegrationMeta {
  const slug = slugFromModulePath(modulePath)
  const { frontmatter, body } = parseIntegrationFrontmatter(raw)
  void body

  const category = parseCategory(getFrontmatterString(frontmatter, 'category'))

  return {
    slug,
    href: `/integrations/${slug}`,
    title: getFrontmatterString(frontmatter, 'title') ?? slug,
    description: getFrontmatterString(frontmatter, 'description') ?? '',
    date: getFrontmatterDate(frontmatter, 'date'),
    category,
    cover: getFrontmatterString(frontmatter, 'cover') ?? '',
    featured: parseBoolean(frontmatter.featured),
    isPartner: parseBoolean(frontmatter.isPartner),
    isNew: parseBoolean(frontmatter.isNew),
    platform: parsePlatform(frontmatter.platform),
    product: parseProduct(frontmatter),
    images:
      getFrontmatterStringArray(frontmatter, 'images') ??
      (getFrontmatterString(frontmatter, 'cover')
        ? [getFrontmatterString(frontmatter, 'cover')!]
        : []),
  }
}

function buildIntegration(modulePath: string, raw: string): Integration {
  const meta = buildIntegrationMeta(modulePath, raw)
  const { body } = parseIntegrationFrontmatter(raw)
  const content = preprocessBlogMarkdocContent(body.trim())

  return {
    ...meta,
    content,
    toc: extractDocsToc(content),
  }
}

const allIntegrations = Object.entries(integrationLoaders)
  .map(([modulePath, raw]) => buildIntegration(modulePath, raw))
  .sort((a, b) => a.title.localeCompare(b.title))

const allIntegrationMeta = allIntegrations.map(
  ({ content: _content, toc: _toc, ...meta }) => meta,
)

export function getAllIntegrations(): Integration[] {
  return allIntegrations
}

export function getAllIntegrationMeta(): IntegrationMeta[] {
  return allIntegrationMeta
}

export function getIntegration(slug: string): Integration | undefined {
  return allIntegrations.find((integration) => integration.slug === slug)
}

/** Plain-markdown source (including frontmatter) for the .md export endpoint. */
export function getIntegrationMarkdownExport(slug: string): string | null {
  if (!getIntegration(slug)) return null

  const modulePath = Object.keys(integrationLoaders).find(
    (path) => slugFromModulePath(path) === slug,
  )
  if (!modulePath) return null
  const raw = integrationLoaders[modulePath]
  return raw ? markdocToMarkdown(raw) : null
}

export function getIntegrationsCatalog(): IntegrationsCatalog {
  const platforms = [
    ...new Set(allIntegrationMeta.flatMap((integration) => integration.platform)),
  ].sort() as IntegrationPlatform[]

  const grouped: IntegrationCategoryGroup[] = INTEGRATION_CATEGORY_ORDER.map(
    (categorySlug) => {
      const category = getIntegrationCategory(categorySlug)
      const integrations = allIntegrationMeta.filter(
        (integration) => integration.category === categorySlug,
      )

      return {
        category: categorySlug,
        heading: category?.heading ?? categorySlug,
        description: category?.description ?? '',
        integrations,
      }
    },
  ).filter((group) => group.integrations.length > 0)

  return {
    list: allIntegrationMeta,
    featured: allIntegrationMeta.filter((integration) => integration.featured),
    categories: INTEGRATION_CATEGORIES.filter((category) =>
      allIntegrationMeta.some((integration) => integration.category === category.slug),
    ),
    platforms,
    grouped,
  }
}

function matchesSearch(integration: IntegrationMeta, query: string): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true

  return (
    integration.title.toLowerCase().includes(q) ||
    integration.description.toLowerCase().includes(q) ||
    integration.product.vendor.toLowerCase().includes(q) ||
    (getIntegrationCategory(integration.category)?.heading.toLowerCase().includes(q) ??
      false)
  )
}

export function filterIntegrations(
  integrations: IntegrationMeta[],
  search: IntegrationsSearch,
): IntegrationMeta[] {
  const category = search.category?.trim()
  const platform = search.platform?.trim()

  return integrations.filter((integration) => {
    if (category && category !== 'all' && integration.category !== category) {
      return false
    }

    if (
      platform &&
      platform !== 'all' &&
      !integration.platform.includes(platform as IntegrationPlatform)
    ) {
      return false
    }

    return matchesSearch(integration, search.search ?? '')
  })
}

export function getFilteredIntegrationsCatalog(
  search: IntegrationsSearch,
): IntegrationsCatalog & { filtered: IntegrationMeta[] } {
  const catalog = getIntegrationsCatalog()
  const filtered = filterIntegrations(catalog.list, search)

  return {
    ...catalog,
    featured: filterIntegrations(catalog.featured, search),
    grouped: catalog.grouped
      .map((group) => ({
        ...group,
        integrations: filterIntegrations(group.integrations, search),
      }))
      .filter((group) => group.integrations.length > 0),
    filtered,
  }
}

export function getRelatedIntegrations(
  slug: string,
  limit = 3,
): IntegrationMeta[] {
  const current = getIntegration(slug)
  if (!current) return []

  return allIntegrationMeta
    .filter(
      (integration) =>
        integration.slug !== slug &&
        integration.category === current.category,
    )
    .slice(0, limit)
}
