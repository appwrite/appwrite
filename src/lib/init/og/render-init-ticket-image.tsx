import { ImageResponse } from '@vercel/og'
import { InitTicketOgRoot } from '@/lib/init/og/init-ticket-og-root'
import { loadInitTicketOgFonts } from '@/lib/init/og/fonts'
import { prepareInitTicketOgData } from '@/lib/init/og/prepare-init-ticket-og-data'
import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
} from '@/lib/init/ticket-layout'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

export async function renderInitTicketImageWithOg(
  data: InitTicketRenderData,
): Promise<Uint8Array> {
  const [fonts, prepared] = await Promise.all([
    loadInitTicketOgFonts(),
    prepareInitTicketOgData(data),
  ])

  const response = new ImageResponse(
    <InitTicketOgRoot data={data} prepared={prepared} />,
    {
      width: INIT_TICKET_IMAGE_WIDTH,
      height: INIT_TICKET_IMAGE_HEIGHT,
      fonts,
    },
  )

  return new Uint8Array(await response.arrayBuffer())
}
