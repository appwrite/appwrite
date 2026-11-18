import { translate } from '@/lib/i18n/translate'
import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'
import { stringifyJsonLd } from '@/lib/seo/json-ld'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import type { OgImageParams } from '@/lib/seo/og-image'
import { asRouteHead } from '@/lib/seo/route-head'
import { pageTitle } from '@/lib/utils/page-title'

export const MARKETING_HOMEPAGE_TITLE =
  'Appwrite · The open-source cloud for developers and agents'

/**
 * Homepage social card: the Appwrite logotype on the brand background, a static
 * 1200×630 PNG for crawler compatibility. Bump `v` when replacing the file.
 */
const MARKETING_HOME_OG_IMAGE_PATH = '/images/og/home.png?v=20261118'

type MetaTag = Record<string, string>

type MarketingPageMetaInput = {
  pageName: string
  /** Full document title. Falls back to `pageName · Appwrite`. */
  title?: string
  description: string
  ogImage?: string
  ogImageWidth?: number
  ogImageHeight?: number
  ogImageParams?: OgImageParams
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
      ogImageWidth: input.ogImageWidth,
      ogImageHeight: input.ogImageHeight,
      ogImageParams: input.ogImage
        ? undefined
        : (input.ogImageParams ?? {
            title: input.ogImageTitle ?? input.pageName,
            subtitle: input.ogImageSubtitle ?? input.description,
            eyebrow: input.ogImageEyebrow,
            cta: input.ogImageCta,
          }),
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

/** `FAQPage` JSON-LD for the FAQ section a marketing page renders. */
export function getMarketingFaqScript(
  items: ReadonlyArray<{ question: string; answer: string }>,
) {
  return {
    type: 'application/ld+json',
    children: stringifyJsonLd({
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: items.map((item) => ({
        '@type': 'Question',
        name: translate(item.question),
        acceptedAnswer: {
          '@type': 'Answer',
          text: translate(item.answer),
        },
      })),
    }),
  }
}
