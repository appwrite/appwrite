import {
  COVER_TEMPLATE_IDS,
  isCoverTemplateId,
  type CoverTemplateId,
} from '@/lib/cover-generator/constants'

export const COVER_TEMPLATE_CATEGORY_IDS = [
  'text',
  'code',
  'milestones',
  'releases',
  'logos',
  'product',
  'data',
] as const

export type CoverTemplateCategoryId = (typeof COVER_TEMPLATE_CATEGORY_IDS)[number]

export type CoverTemplateCategory = {
  id: CoverTemplateCategoryId
  label: string
  description: string
  templateIds: readonly CoverTemplateId[]
}

export const COVER_TEMPLATE_CATEGORIES: CoverTemplateCategory[] = [
  {
    id: 'text',
    label: 'Text',
    description: 'Headlines and copy-focused covers.',
    templateIds: ['simple-title'],
  },
  {
    id: 'code',
    label: 'Code',
    description: 'Terminal commands and syntax-highlighted snippets.',
    templateIds: ['cli-code', 'code-snippet'],
  },
  {
    id: 'milestones',
    label: 'Milestones',
    description: 'Share metrics, growth numbers, and launch milestones.',
    templateIds: ['milestone-split', 'milestone-centered'],
  },
  {
    id: 'releases',
    label: 'Releases',
    description: 'Announce new version releases with a large version number.',
    templateIds: ['version-number', 'version-title'],
  },
  {
    id: 'logos',
    label: 'Logos',
    description: 'Single icons, integrations, and partner logos.',
    templateIds: ['integration', 'integration-icon', 'showcase-icon', 'title-icon'],
  },
  {
    id: 'product',
    label: 'Product',
    description: 'Screenshots and 3D product visuals.',
    templateIds: ['screenshot', 'screenshot-side', 'screenshot-angled', 'cards-angled'],
  },
  {
    id: 'data',
    label: 'Data',
    description: 'Tables and charts for metrics and comparisons.',
    templateIds: ['table', 'bar-chart', 'line-chart'],
  },
]

const COVER_TEMPLATE_CATEGORY_BY_ID = new Map(
  COVER_TEMPLATE_CATEGORIES.map((category) => [category.id, category]),
)

const COVER_TEMPLATE_CATEGORY_BY_TEMPLATE = new Map<CoverTemplateId, CoverTemplateCategoryId>(
  COVER_TEMPLATE_CATEGORIES.flatMap((category) =>
    category.templateIds.map((templateId) => [templateId, category.id]),
  ),
)

/** Templates in category order (used for panel listing). */
export const COVER_TEMPLATE_IDS_BY_CATEGORY: CoverTemplateId[] =
  COVER_TEMPLATE_CATEGORIES.flatMap((category) => [...category.templateIds])

export function isCoverTemplateCategoryId(
  value: string,
): value is CoverTemplateCategoryId {
  return (COVER_TEMPLATE_CATEGORY_IDS as readonly string[]).includes(value)
}

export function getCoverTemplateCategory(
  categoryId: CoverTemplateCategoryId,
): CoverTemplateCategory {
  const category = COVER_TEMPLATE_CATEGORY_BY_ID.get(categoryId)
  if (!category) {
    throw new Error(`Unknown cover template category: ${categoryId}`)
  }
  return category
}

export function getCoverTemplateCategoryId(
  templateId: CoverTemplateId,
): CoverTemplateCategoryId {
  return COVER_TEMPLATE_CATEGORY_BY_TEMPLATE.get(templateId) ?? 'text'
}

export function getCoverTemplatesForCategory(
  categoryFilter: CoverTemplateCategoryFilter,
): CoverTemplateId[] {
  if (categoryFilter === 'all') {
    return [...COVER_TEMPLATE_IDS_BY_CATEGORY]
  }

  return [...getCoverTemplateCategory(categoryFilter).templateIds]
}

export function getCoverTemplateCategorySections(
  categoryFilter: CoverTemplateCategoryFilter,
): CoverTemplateCategory[] {
  if (categoryFilter === 'all') {
    return COVER_TEMPLATE_CATEGORIES
  }

  return [getCoverTemplateCategory(categoryFilter)]
}

export type CoverTemplateCategoryFilter = 'all' | CoverTemplateCategoryId

export function parseCoverTemplateCategoryFilter(value: unknown): CoverTemplateCategoryFilter {
  if (value === 'all') return 'all'
  if (typeof value === 'string' && isCoverTemplateCategoryId(value)) return value
  if (typeof value === 'string' && isCoverTemplateId(value)) {
    return getCoverTemplateCategoryId(value)
  }
  return 'all'
}

/** Ensures COVER_TEMPLATE_IDS matches the category grouping. */
export function assertCoverTemplateCategoryCoverage(): void {
  const categorized = new Set(COVER_TEMPLATE_IDS_BY_CATEGORY)
  for (const templateId of COVER_TEMPLATE_IDS) {
    if (!categorized.has(templateId)) {
      throw new Error(`Template "${templateId}" is missing from cover template categories`)
    }
  }
  if (categorized.size !== COVER_TEMPLATE_IDS.length) {
    throw new Error('Cover template categories include unknown template ids')
  }
}

assertCoverTemplateCategoryCoverage()
