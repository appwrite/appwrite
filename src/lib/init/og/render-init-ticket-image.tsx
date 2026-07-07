import { ImageResponse } from '@vercel/og'
import type { ReactElement } from 'react'
import { loadInitTicketOgFonts } from '@/lib/init/og/fonts'
import {
  InitTicketOgBackgroundRoot,
  InitTicketOgTicketLayer,
} from '@/lib/init/og/init-ticket-og-root'
import {
  initTicketOgShadowPlacement,
  warpInitTicketOgPerspective,
} from '@/lib/init/og/init-ticket-og-perspective'
import { prepareInitTicketOgData } from '@/lib/init/og/prepare-init-ticket-og-data'
import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
} from '@/lib/init/ticket-layout'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

const OG_RENDER_OPTIONS = {
  width: INIT_TICKET_IMAGE_WIDTH,
  height: INIT_TICKET_IMAGE_HEIGHT,
}

async function renderOgPng(
  element: ReactElement,
  fonts: Awaited<ReturnType<typeof loadInitTicketOgFonts>>,
): Promise<Buffer> {
  const response = new ImageResponse(element, {
    ...OG_RENDER_OPTIONS,
    fonts,
  })
  return Buffer.from(await response.arrayBuffer())
}

async function renderInitTicketOgShadow(
  usesDarkChrome: boolean,
): Promise<{ input: Buffer; left: number; top: number }> {
  const shadow = initTicketOgShadowPlacement(
    INIT_TICKET_IMAGE_WIDTH,
    INIT_TICKET_IMAGE_HEIGHT,
  )
  const fill = usesDarkChrome ? 'rgba(0,0,0,0.38)' : 'rgba(0,0,0,0.12)'
  const svg = Buffer.from(
    `<svg width="${shadow.width}" height="${shadow.height}" xmlns="http://www.w3.org/2000/svg">
      <ellipse cx="${shadow.width / 2}" cy="${shadow.height / 2}" rx="${shadow.width / 2}" ry="${shadow.height / 2}" fill="${fill}"/>
    </svg>`,
  )
  const sharp = (await import('sharp')).default
  const input = await sharp(svg).blur(28).png().toBuffer()

  return {
    input,
    left: shadow.left,
    top: shadow.top,
  }
}

export async function renderInitTicketImageWithOg(
  data: InitTicketRenderData,
): Promise<Uint8Array> {
  const [fonts, prepared] = await Promise.all([
    loadInitTicketOgFonts(),
    prepareInitTicketOgData(data),
  ])

  const sharedProps = { data, prepared }
  const usesDarkChrome = data.ticketAppearance.usesDarkChrome

  const [backgroundPng, ticketPng, shadowLayer] = await Promise.all([
    renderOgPng(<InitTicketOgBackgroundRoot {...sharedProps} />, fonts),
    renderOgPng(<InitTicketOgTicketLayer {...sharedProps} />, fonts),
    renderInitTicketOgShadow(usesDarkChrome),
  ])

  const warpedTicket = await warpInitTicketOgPerspective(ticketPng)
  const sharp = (await import('sharp')).default
  const output = await sharp(backgroundPng)
    .composite([
      { input: shadowLayer.input, left: shadowLayer.left, top: shadowLayer.top },
      { input: warpedTicket, top: 0, left: 0 },
    ])
    .png()
    .toBuffer()

  return new Uint8Array(output)
}
