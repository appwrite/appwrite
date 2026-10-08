import { ImageResponse } from '@vercel/og'
import type { ReactElement } from 'react'
import { buildInitTicketOgBackgroundSvg } from '@/lib/init/og/init-ticket-og-background'
import { loadInitTicketOgFonts } from '@/lib/init/og/fonts'
import { InitTicketOgTicketLayer } from '@/lib/init/og/init-ticket-og-root'
import {
  initTicketOgShadowPlacement,
  warpInitTicketOgPerspective,
} from '@/lib/init/og/init-ticket-og-perspective'
import { prepareInitTicketOgData } from '@/lib/init/og/prepare-init-ticket-og-data'
import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
  INIT_TICKET_OG_EXPORT_HEIGHT,
  INIT_TICKET_OG_EXPORT_WIDTH,
} from '@/lib/init/ticket-layout'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

const TICKET_RENDER_OPTIONS = {
  width: INIT_TICKET_IMAGE_WIDTH,
  height: INIT_TICKET_IMAGE_HEIGHT,
}

async function renderOgPng(
  element: ReactElement,
  fonts: Awaited<ReturnType<typeof loadInitTicketOgFonts>>,
  options: { width: number; height: number },
): Promise<Buffer> {
  const response = new ImageResponse(element, {
    ...options,
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

async function compositeInitTicketOgExport(params: {
  ticketLayerPng: Buffer
  usesDarkChrome: boolean
  accentColor: string
}): Promise<Uint8Array> {
  const sharp = (await import('sharp')).default
  const edgePadding = 24
  const contentHeight = INIT_TICKET_OG_EXPORT_HEIGHT - edgePadding * 2
  const contentWidth = INIT_TICKET_OG_EXPORT_WIDTH - edgePadding * 2
  const scale = Math.min(
    contentHeight / INIT_TICKET_IMAGE_HEIGHT,
    contentWidth / INIT_TICKET_IMAGE_WIDTH,
  )
  const scaledWidth = Math.round(INIT_TICKET_IMAGE_WIDTH * scale)
  const scaledHeight = Math.round(INIT_TICKET_IMAGE_HEIGHT * scale)
  const left = Math.round((INIT_TICKET_OG_EXPORT_WIDTH - scaledWidth) / 2)
  const top = Math.round((INIT_TICKET_OG_EXPORT_HEIGHT - scaledHeight) / 2)

  const [scaledTicketLayer, backgroundPng] = await Promise.all([
    sharp(params.ticketLayerPng)
      .resize(scaledWidth, scaledHeight, { fit: 'fill' })
      .png()
      .toBuffer(),
    sharp(
      Buffer.from(
        buildInitTicketOgBackgroundSvg(
          params.accentColor,
          params.usesDarkChrome,
          INIT_TICKET_OG_EXPORT_WIDTH,
          INIT_TICKET_OG_EXPORT_HEIGHT,
        ),
      ),
    )
      .png()
      .toBuffer(),
  ])

  const output = await sharp(backgroundPng)
    .composite([{ input: scaledTicketLayer, left, top }])
    .png()
    .toBuffer()

  return new Uint8Array(output)
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

  const [ticketPng, shadowLayer] = await Promise.all([
    renderOgPng(<InitTicketOgTicketLayer {...sharedProps} />, fonts, TICKET_RENDER_OPTIONS),
    renderInitTicketOgShadow(usesDarkChrome),
  ])

  const sharp = (await import('sharp')).default
  const warpedTicket = await warpInitTicketOgPerspective(ticketPng)
  const ticketLayerPng = await sharp({
    create: {
      width: INIT_TICKET_IMAGE_WIDTH,
      height: INIT_TICKET_IMAGE_HEIGHT,
      channels: 4,
      background: { r: 0, g: 0, b: 0, alpha: 0 },
    },
  })
    .composite([
      { input: shadowLayer.input, left: shadowLayer.left, top: shadowLayer.top },
      { input: warpedTicket, top: 0, left: 0 },
    ])
    .png()
    .toBuffer()

  return compositeInitTicketOgExport({
    ticketLayerPng,
    usesDarkChrome,
    accentColor: data.ticketAppearance.accentColor,
  })
}
