import { getRequestSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { pageTitle } from '@/lib/utils/page-title'

export const INIT_PAGE_SEO_TITLE =
  'Init week: Five days of launches, demos, and giveaways'

export const INIT_PAGE_SEO_DESCRIPTION =
  'Join Init week August 31–September 4. Claim your personalized pass, watch live product launches, and enter giveaways for exclusive swag.'

export const INIT_TICKET_SHARE_SEO_TITLE =
  'Init week ticket: Claim your pass and win launch swag'

export const INIT_TICKET_SHARE_SEO_DESCRIPTION =
  'Join Init week. Claim your personalized pass and share for a chance to win exclusive swag.'

export const INIT_OG_CTA_LABEL = 'Claim your ticket'

export const INIT_PAGE_OG_IMAGE_PATH = '/og/init.png'

export const INIT_PAGE_OG_IMAGE_PARAMS = {
  title: 'Five days of Appwrite product launches',
  subtitle: 'Claim your personalized Init pass and join live sessions.',
  eyebrow: 'Init week · August 31–September 4',
  cta: INIT_OG_CTA_LABEL,
} as const

export function getInitPageOgImageUrl(siteOrigin?: string): string {
  return resolveSiteAssetUrl(INIT_PAGE_OG_IMAGE_PATH, siteOrigin)
}

export function getInitPageMetaTags(siteOrigin?: string) {
  const origin = siteOrigin ?? getRequestSiteOrigin()

  return [...getPageMetaTags({
    title: pageTitle(INIT_PAGE_SEO_TITLE),
    description: INIT_PAGE_SEO_DESCRIPTION,
    canonical: resolveSiteAssetUrl('/init', origin),
    ogImage: getInitPageOgImageUrl(origin),
    siteOrigin: origin,
  })]
}
