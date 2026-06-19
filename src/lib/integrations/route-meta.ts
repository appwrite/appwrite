import type { Integration, IntegrationMeta } from './types'
import { pageTitle } from '@/lib/utils/page-title'

type MetaTag = Record<string, string>

function asRouteMetaTags(tags: readonly MetaTag[]): MetaTag[] {
  return [...tags]
}

export function getIntegrationsIndexMetaTags(): MetaTag[] {
  return asRouteMetaTags([
    { title: pageTitle('Integrations') },
    {
      name: 'description',
      content:
        'Connect your favorite apps to Appwrite for a unified tech stack. Explore the Appwrite catalog: a marketplace to find integrations for your projects.',
    },
    { property: 'og:title', content: pageTitle('Integrations') },
    {
      property: 'og:description',
      content:
        'Connect your favorite apps to Appwrite for a unified tech stack. Explore the Appwrite catalog: a marketplace to find integrations for your projects.',
    },
    { name: 'twitter:card', content: 'summary_large_image' },
  ])
}

export function getIntegrationDetailMetaTags(integration: Integration | IntegrationMeta): MetaTag[] {
  return asRouteMetaTags([
    { title: pageTitle(integration.title) },
    { name: 'description', content: integration.description },
    { property: 'og:title', content: integration.title },
    { property: 'og:description', content: integration.description },
    ...(integration.cover
      ? [
          { property: 'og:image', content: integration.cover },
          { name: 'twitter:image', content: integration.cover },
        ]
      : []),
    { name: 'twitter:card', content: 'summary_large_image' },
  ])
}

export function getIntegrationsIndexRouteMetaTags() {
  return getIntegrationsIndexMetaTags()
}

export function getIntegrationDetailRouteMetaTags(
  integration: Integration | IntegrationMeta,
) {
  return getIntegrationDetailMetaTags(integration)
}
