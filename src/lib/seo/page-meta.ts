import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'
import {
  buildOgImageUrl,
  OG_IMAGE_HEIGHT,
  OG_IMAGE_WIDTH,
  type OgImageParams,
} from '@/lib/seo/og-image'

export const SEO_SITE_NAME = 'Appwrite'

export type PageSeoOptions = {
  title: string
  description?: string
  canonical?: string
  ogType?: string
  ogImage?: string
  ogImageParams?: OgImageParams
  siteName?: string
  siteOrigin?: string
}

function stripPageTitleSuffix(title: string): string {
  return title.replace(/\s·\sAppwrite$/u, '').trim() || title
}

export function getPageMetaTags(options: PageSeoOptions) {
  const siteOrigin = getSeoSiteOrigin(options.siteOrigin)
  const ogImage =
    options.ogImage ??
    buildOgImageUrl(
      options.ogImageParams ?? { title: stripPageTitleSuffix(options.title) },
      siteOrigin,
    )

  return [
    { title: options.title },
    ...(options.description
      ? [{ name: 'description', content: options.description }]
      : []),
    { property: 'og:title', content: options.title },
    ...(options.description
      ? [{ property: 'og:description', content: options.description }]
      : []),
    { property: 'og:site_name', content: options.siteName ?? SEO_SITE_NAME },
    { property: 'og:type', content: options.ogType ?? 'website' },
    ...(options.canonical ? [{ property: 'og:url', content: options.canonical }] : []),
    { property: 'og:image', content: ogImage },
    { property: 'og:image:width', content: String(OG_IMAGE_WIDTH) },
    { property: 'og:image:height', content: String(OG_IMAGE_HEIGHT) },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: options.title },
    ...(options.description
      ? [{ name: 'twitter:description', content: options.description }]
      : []),
    { name: 'twitter:image', content: ogImage },
    ...(options.canonical
      ? [{ tag: 'link', rel: 'canonical', href: options.canonical }]
      : []),
  ] as const
}
