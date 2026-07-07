import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
} from '@/lib/init/ticket-layout'
import {
  getInitTicketStorageConfig,
  getInitTicketStorageFileViewUrl,
} from '@/lib/init/init-ticket-storage-config'
import { getRequestSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { pageTitle } from '@/lib/utils/page-title'

export function buildInitTicketSharePath(ticketId: string): string {
  return `/init/${ticketId}`
}

export function buildInitTicketShareUrl(
  ticketId: string,
  siteOrigin?: string,
): string {
  return resolveSiteAssetUrl(buildInitTicketSharePath(ticketId), siteOrigin)
}

export function getInitTicketStorageFilePreviewUrl(fileId: string): string {
  const { endpoint, projectId, bucketId } = getInitTicketStorageConfig()
  const params = new URLSearchParams({
    project: projectId,
    width: String(INIT_TICKET_IMAGE_WIDTH),
    height: String(INIT_TICKET_IMAGE_HEIGHT),
  })
  return `${endpoint}/storage/buckets/${bucketId}/files/${fileId}/preview?${params.toString()}`
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
  const ogImage = getInitTicketStorageFilePreviewUrl(params.ticketId)
  const title = pageTitle('Init ticket')
  const description =
    'Join Init week. Claim your personalized pass and share for a chance to win exclusive swag.'

  return getPageMetaTags({
    title,
    description,
    canonical: canonicalUrl,
    ogImage,
    ogType: 'website',
    siteOrigin,
  }).map((tag) => {
    if (tag.property === 'og:image:width') {
      return {
        property: 'og:image:width',
        content: String(INIT_TICKET_IMAGE_WIDTH),
      }
    }
    if (tag.property === 'og:image:height') {
      return {
        property: 'og:image:height',
        content: String(INIT_TICKET_IMAGE_HEIGHT),
      }
    }
    return tag
  })
}

export function getInitTicketShareImageSrc(ticketId: string): string {
  return getInitTicketStorageFileViewUrl(ticketId)
}
