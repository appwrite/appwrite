import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'
import { buildOgImageUrl } from '@/lib/seo/og-image'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { pageTitle } from '@/lib/utils/page-title'

export const MARKETING_HOMEPAGE_OG_DESCRIPTION =
  'The open-source developer platform with Auth, Databases, Storage, Functions, Messaging, and Sites. Build like a team of hundreds.'

type MetaTag = Record<string, string>

type MarketingPageMetaInput = {
  pageName: string
  description: string
  ogImage?: string
  ogImageTitle?: string
  ogImageSubtitle?: string
  ogImageEyebrow?: string
  ogImageCta?: string
  canonical?: string
  ogType?: string
  siteOrigin?: string
}

function asRouteMetaTags(tags: readonly MetaTag[]): MetaTag[] {
  return [...tags]
}

export function getMarketingHomeOgImage(siteOrigin?: string): string {
  return buildOgImageUrl(
    {
      title: 'Appwrite',
      subtitle: MARKETING_HOMEPAGE_OG_DESCRIPTION,
    },
    siteOrigin,
  )
}

export function getMarketingPageMetaTags(input: MarketingPageMetaInput): MetaTag[] {
  const siteOrigin = getSeoSiteOrigin(input.siteOrigin)

  return asRouteMetaTags(
    getPageMetaTags({
      title: pageTitle(input.pageName),
      description: input.description,
      canonical: input.canonical,
      ogType: input.ogType,
      ogImage: input.ogImage,
      ogImageParams: input.ogImage
        ? undefined
        : {
            title: input.ogImageTitle ?? input.pageName,
            subtitle: input.ogImageSubtitle ?? input.description,
            eyebrow: input.ogImageEyebrow,
            cta: input.ogImageCta,
          },
      siteOrigin,
    }) as unknown as MetaTag[],
  )
}
