import sharp from 'sharp'
import { buildInitTicketOgBackgroundDataUri } from '@/lib/init/og/init-ticket-og-background'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'
import { getFrameworkIconFile } from '@/lib/frameworks'
import { getInitTicketStackOption } from '@/lib/init/ticket-stack'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

export type PreparedInitTicketOgData = {
  uiBackgroundSrc: string
  backgroundSrc: string | null
  stackIcons: Array<{ src: string | null; label: string; iconKey: string }>
  githubIconSrc: string | null
}

async function bufferToPngDataUri(buffer: Buffer): Promise<string> {
  return `data:image/png;base64,${buffer.toString('base64')}`
}

async function loadRasterImageDataUri(publicSrc: string): Promise<string | null> {
  const buffer = await readCoverPublicAssetBuffer(publicSrc)
  if (!buffer) return null
  const png = await sharp(buffer).png().toBuffer()
  return bufferToPngDataUri(png)
}

async function loadTicketIconDataUri(
  publicSrc: string,
  iconKey: string,
  usesDarkChrome: boolean,
): Promise<string | null> {
  const buffer = await readCoverPublicAssetBuffer(publicSrc)
  if (!buffer) return null

  const size = 64
  const { data, info } = await sharp(buffer)
    .ensureAlpha()
    .resize(size, size, {
      fit: 'contain',
      background: { r: 0, g: 0, b: 0, alpha: 0 },
    })
    .raw()
    .toBuffer({ resolveWithObject: true })

  const pixels = new Uint8Array(data)
  for (let i = 0; i < pixels.length; i += 4) {
    const alpha = pixels[i + 3]
    if (alpha <= 12) {
      pixels[i + 3] = 0
      continue
    }

    if (iconKey === 'appwrite' && usesDarkChrome) {
      continue
    }

    const tone = usesDarkChrome ? 255 : 0
    pixels[i] = tone
    pixels[i + 1] = tone
    pixels[i + 2] = tone
    pixels[i + 3] = usesDarkChrome
      ? Math.min(255, Math.round(alpha * 0.9))
      : Math.min(255, Math.round(alpha * 0.85))
  }

  const png = await sharp(Buffer.from(pixels), {
    raw: { width: info.width, height: info.height, channels: 4 },
  })
    .png()
    .toBuffer()

  return bufferToPngDataUri(png)
}

function resolveStackIconSrc(
  iconKey: string,
  usesDarkChrome: boolean,
): string | null {
  if (iconKey === 'appwrite') {
    return `/icons/${usesDarkChrome ? 'appwrite-white.svg' : 'appwrite.svg'}`
  }
  const iconFile = getFrameworkIconFile(iconKey)
  return iconFile ? `/icons/${iconFile}` : null
}

export async function prepareInitTicketOgData(
  data: InitTicketRenderData,
): Promise<PreparedInitTicketOgData> {
  const usesDarkChrome = data.ticketAppearance.usesDarkChrome
  const uiBackgroundSrc = buildInitTicketOgBackgroundDataUri(
    data.ticketAppearance.accentColor,
    usesDarkChrome,
  )

  const [backgroundSrc, githubIconSrc, stackIcons] = await Promise.all([
    loadRasterImageDataUri(data.ticketAppearance.backgroundSrc),
    loadTicketIconDataUri('/icons/github.svg', 'github', usesDarkChrome),
    Promise.all(
      data.prefs.stack.map(async (id) => {
        const option = getInitTicketStackOption(id)
        if (!option) return { src: null, label: id, iconKey: id }
        const iconSrc = resolveStackIconSrc(option.iconKey, usesDarkChrome)
        return {
          src: iconSrc
            ? await loadTicketIconDataUri(
                iconSrc,
                option.iconKey,
                usesDarkChrome,
              )
            : null,
          label: option.label,
          iconKey: option.iconKey,
        }
      }),
    ),
  ])

  return { uiBackgroundSrc, backgroundSrc, stackIcons, githubIconSrc }
}
