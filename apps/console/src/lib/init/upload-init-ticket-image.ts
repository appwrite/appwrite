import { ID } from '@appwrite.io/console'
import { buildInitTicketImageUrl } from '@/lib/init/build-init-ticket-image-url'
import { buildInitTicketImageSignature } from '@/lib/init/init-ticket-image-signature'
import { buildInitTicketFilePermissions } from '@/lib/init/init-ticket-file-permissions'
import { getInitTicketStorageSdk } from '@/lib/init/init-ticket-storage-client'
import { getInitTicketStorageConfig } from '@/lib/init/init-ticket-storage-config'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

export type UploadInitTicketImageResult = {
  fileId: string
  signature: string
}

export async function uploadInitTicketImage(params: {
  eventSlug: string
  renderData: InitTicketRenderData
  themeUsesDarkImage: boolean
  consoleUserId?: string
  signal?: AbortSignal
}): Promise<UploadInitTicketImageResult> {
  const signature = buildInitTicketImageSignature(params.renderData)
  const imageUrl = buildInitTicketImageUrl({
    eventSlug: params.eventSlug,
    renderData: params.renderData,
    themeUsesDarkImage: params.themeUsesDarkImage,
    consoleUserId: params.consoleUserId,
  })

  const imageResponse = await fetch(imageUrl, { signal: params.signal })
  if (!imageResponse.ok) {
    throw new Error(`Init ticket image render failed (${imageResponse.status})`)
  }

  const blob = await imageResponse.blob()
  const { bucketId } = getInitTicketStorageConfig()
  const storage = getInitTicketStorageSdk()

  const file = await storage.createFile({
    bucketId,
    fileId: ID.unique(),
    file: new File([blob], 'init-ticket.png', { type: 'image/png' }),
    permissions: buildInitTicketFilePermissions(),
  })

  return { fileId: file.$id, signature }
}
