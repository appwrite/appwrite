import { buildInitTicketImageSignature } from '@/lib/init/init-ticket-image-signature'
import { isInitTicketStorageConfigured } from '@/lib/init/init-ticket-storage-config'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'
import { uploadInitTicketImage } from '@/lib/init/upload-init-ticket-image'

export type SyncInitTicketImageResult = {
  fileId: string
  signature: string
}

export async function syncInitTicketImage(params: {
  eventSlug: string
  renderData: InitTicketRenderData
  themeUsesDarkImage: boolean
  consoleUserId?: string
  signal?: AbortSignal
}): Promise<SyncInitTicketImageResult> {
  if (!isInitTicketStorageConfigured()) {
    throw new Error('Init ticket storage is not configured')
  }

  return uploadInitTicketImage(params)
}

export { buildInitTicketImageSignature }
