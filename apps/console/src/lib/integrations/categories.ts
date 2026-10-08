import type { IntegrationCategory, IntegrationCategorySlug } from './types'

export const INTEGRATION_CATEGORIES: IntegrationCategory[] = [
  {
    slug: 'mcp',
    heading: 'MCP',
    description: 'Platforms with Model Context Protocol support',
  },
  {
    slug: 'ai',
    heading: 'AI',
    description: 'Machine learning and AI capabilities',
  },
  {
    slug: 'auth',
    heading: 'Auth',
    description: 'User authentication and authorization',
  },
  {
    slug: 'databases',
    heading: 'Databases',
    description: 'Manage database systems',
  },
  {
    slug: 'logging',
    heading: 'Logging',
    description: 'Monitor and analyze application logs',
  },
  {
    slug: 'messaging',
    heading: 'Messaging',
    description: 'Real-time communication platforms',
  },
  {
    slug: 'payments',
    heading: 'Payments',
    description: 'Secure online payment processing',
  },
  {
    slug: 'search',
    heading: 'Search',
    description: 'Implement search functionalities',
  },
  {
    slug: 'sites',
    heading: 'Sites',
    description: 'Deploy and host web applications',
  },
  {
    slug: 'storage',
    heading: 'Storage',
    description: 'Storage for data and media',
  },
  {
    slug: 'deployments',
    heading: 'Deployments',
    description: 'Seamlessly deploy your code',
  },
]

export const INTEGRATION_CATEGORY_ORDER = INTEGRATION_CATEGORIES.map((c) => c.slug)

export function getIntegrationCategory(
  slug: string,
): IntegrationCategory | undefined {
  return INTEGRATION_CATEGORIES.find((category) => category.slug === slug)
}

export function getIntegrationCategoryHeading(
  slug: IntegrationCategorySlug,
): string {
  return getIntegrationCategory(slug)?.heading ?? slug
}

export function isIntegrationCategorySlug(
  value: string,
): value is IntegrationCategorySlug {
  return INTEGRATION_CATEGORY_ORDER.includes(value as IntegrationCategorySlug)
}
