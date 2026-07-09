import {
  INIT_TICKET_OG_EXPORT_HEIGHT,
  INIT_TICKET_OG_EXPORT_WIDTH,
} from '@/lib/init/ticket-layout'
import {
  INIT_TICKET_SHARE_SEO_DESCRIPTION,
  INIT_TICKET_SHARE_SEO_TITLE,
} from '@/lib/init/init-seo'
import {
  getInitTicketStorageConfig,
  getInitTicketStorageFileViewUrl,
} from '@/lib/init/init-ticket-storage-config'
import { getRequestSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { pageTitle } from '@/lib/utils/page-title'

export function buildInitTicketShareOgImagePath(ticketId: string): string {
  return `/init/${ticketId}/og.png`
}

export function getInitTicketShareOgImageUrl(
  ticketId: string,
  siteOrigin?: string,
): string {
  return resolveSiteAssetUrl(buildInitTicketShareOgImagePath(ticketId), siteOrigin)
}

export function buildInitTicketSharePath(ticketId: string): string {
  return `/init/${ticketId}`
}

export function buildInitTicketShareUrl(
  ticketId: string,
  siteOrigin?: string,
): string {
  return resolveSiteAssetUrl(buildInitTicketSharePath(ticketId), siteOrigin)
}

export async function initTicketStorageFileExists(fileId: string): Promise<boolean> {
  const { endpoint, projectId, bucketId } = getInitTicketStorageConfig()
  const params = new URLSearchParams({ project: projectId })
  try {
    const response = await fetch(
      `${endpoint}/storage/buckets/${bucketId}/files/${fileId}?${params.toString()}`,
      { method: 'GET' },
    )
    return response.ok
  } catch {
    return false
  }
}

export function getInitTicketShareRouteMetaTags(params: {
  ticketId: string
  siteOrigin?: string
}) {
  const siteOrigin = params.siteOrigin ?? getRequestSiteOrigin()
  const canonicalUrl = buildInitTicketShareUrl(params.ticketId, siteOrigin)
  const ogImage = getInitTicketShareOgImageUrl(params.ticketId, siteOrigin)
  const title = pageTitle(INIT_TICKET_SHARE_SEO_TITLE)

  return [...getPageMetaTags({
    title,
    description: INIT_TICKET_SHARE_SEO_DESCRIPTION,
    canonical: canonicalUrl,
    ogImage,
    ogType: 'website',
    siteOrigin,
  }).map((tag) => {
    if ('property' in tag && tag.property === 'og:image:width') {
      return {
        property: 'og:image:width',
        content: String(INIT_TICKET_OG_EXPORT_WIDTH),
      }
    }
    if ('property' in tag && tag.property === 'og:image:height') {
      return {
        property: 'og:image:height',
        content: String(INIT_TICKET_OG_EXPORT_HEIGHT),
      }
    }
    return tag
  })]
}

export function getInitTicketShareImageSrc(ticketId: string): string {
  return getInitTicketStorageFileViewUrl(ticketId)
}
