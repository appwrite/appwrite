import type { Integration, IntegrationMeta } from './types'
import { getSeoSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { asRouteHead } from '@/lib/seo/route-head'
import { pageTitle } from '@/lib/utils/page-title'

type MetaTag = Record<string, string>

function asRouteMetaTags(tags: readonly MetaTag[]): MetaTag[] {
  return [...tags]
}

export function getIntegrationsIndexMetaTags(siteOrigin?: string): MetaTag[] {
  const title = pageTitle('Integrations')
  const description =
    'Connect your favorite apps to Appwrite for a unified tech stack. Explore the Appwrite catalog: a marketplace to find integrations for your projects.'
  const resolvedOrigin = getSeoSiteOrigin(siteOrigin)
  const canonical = `${resolvedOrigin}/integrations`

  return asRouteMetaTags(
    getPageMetaTags({
      title,
      description,
      canonical,
      ogImageParams: {
        title: 'Connect your favorite apps',
        eyebrow: 'Integrations',
        subtitle:
          'Explore the Appwrite catalog: a marketplace to find integrations for your projects.',
      },
      siteOrigin: resolvedOrigin,
    }) as unknown as MetaTag[],
  )
}

export function getIntegrationDetailMetaTags(
  integration: Integration | IntegrationMeta,
  siteOrigin?: string,
): MetaTag[] {
  const title = pageTitle(integration.title)
  const resolvedOrigin = getSeoSiteOrigin(siteOrigin)
  const ogImage = integration.cover
    ? integration.cover.startsWith('/')
      ? resolveSiteAssetUrl(integration.cover, resolvedOrigin)
      : integration.cover
    : undefined
  const integrationDescription = integration.description.trim()
  const integrationTitle = integration.title.trim()
  const canonical = integration.href.startsWith('http')
    ? integration.href
    : `${resolvedOrigin}${integration.href}`

  return asRouteMetaTags(
    getPageMetaTags({
      title,
      description: integration.description,
      canonical,
      ogImage,
      ogImageParams: ogImage
        ? undefined
        : {
            title: integrationTitle,
            eyebrow: 'Integrations',
            subtitle:
              integrationDescription &&
              integrationDescription !== integrationTitle
                ? integrationDescription
                : 'Connect this integration to your Appwrite project.',
          },
      ogType: 'article',
      siteOrigin: resolvedOrigin,
    }) as unknown as MetaTag[],
  )
}

export function getIntegrationsIndexRouteMetaTags() {
  return getIntegrationsIndexMetaTags()
}

export function getIntegrationsIndexRouteHead(siteOrigin?: string) {
  const resolvedOrigin = getSeoSiteOrigin(siteOrigin)
  return asRouteHead(getIntegrationsIndexMetaTags(siteOrigin), {
    canonicalHref: `${resolvedOrigin}/integrations`,
  })
}

export function getIntegrationDetailRouteMetaTags(
  integration: Integration | IntegrationMeta,
) {
  return getIntegrationDetailMetaTags(integration)
}

export function getIntegrationDetailRouteHead(
  integration: Integration | IntegrationMeta,
  siteOrigin?: string,
) {
  const resolvedOrigin = getSeoSiteOrigin(siteOrigin)
  const canonical = integration.href.startsWith('http')
    ? integration.href
    : `${resolvedOrigin}${integration.href}`
  return asRouteHead(getIntegrationDetailMetaTags(integration, siteOrigin), {
    canonicalHref: canonical,
  })
}
