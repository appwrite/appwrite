import { getSeoSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { pageTitle } from '@/lib/utils/page-title'

export const MARKETING_HOMEPAGE_OG_IMAGE_PATH = '/images/open-graph/website.avif'
export const BLOG_INDEX_OG_IMAGE_PATH = '/images/open-graph/blog.avif'

type MetaTag = Record<string, string>

type MarketingPageMetaInput = {
  pageName: string
  description: string
  ogImage?: string
  ogImageTitle?: string
  ogImageSubtitle?: string
  ogImageEyebrow?: string
  canonical?: string
  ogType?: string
  siteOrigin?: string
}

function asRouteMetaTags(tags: readonly MetaTag[]): MetaTag[] {
  return [...tags]
}

export function getMarketingHomeOgImage(siteOrigin?: string): string {
  return resolveSiteAssetUrl(MARKETING_HOMEPAGE_OG_IMAGE_PATH, siteOrigin)
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
          },
      siteOrigin,
    }) as unknown as MetaTag[],
  )
}
