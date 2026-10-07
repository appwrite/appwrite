import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { asRouteHead } from '@/lib/seo/route-head'
import { pageTitle } from '@/lib/utils/page-title'

export const MARKETING_HOMEPAGE_TITLE =
  'Appwrite · The open-source cloud for developers and agents'

type MetaTag = Record<string, string>

type MarketingPageMetaInput = {
  pageName: string
  /** Full document title. Falls back to `pageName · Appwrite`. */
  title?: string
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

/** Static Open Graph art for `/` and `/home` (1200×630). */
export const MARKETING_HOME_OG_IMAGE_PATH = '/images/marketing/home-og.png'

export function getMarketingHomeOgImage(siteOrigin?: string): string {
  return `${getSeoSiteOrigin(siteOrigin)}${MARKETING_HOME_OG_IMAGE_PATH}`
}

export function getMarketingPageMetaTags(input: MarketingPageMetaInput): MetaTag[] {
  const siteOrigin = getSeoSiteOrigin(input.siteOrigin)

  return asRouteMetaTags(
    getPageMetaTags({
      title: input.title ?? pageTitle(input.pageName),
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

/** Marketing route `head()` with canonical link in `links` (required for crawlers). */
export function getMarketingRouteHead(
  input: MarketingPageMetaInput & { canonicalPath: string },
) {
  const siteOrigin = getSeoSiteOrigin(input.siteOrigin)
  const canonical = input.canonical ?? `${siteOrigin}${input.canonicalPath}`

  return asRouteHead(getMarketingPageMetaTags({ ...input, canonical }), {
    canonicalHref: canonical,
  })
}
