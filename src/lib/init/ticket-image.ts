import { readFile } from 'node:fs/promises'
import { join } from 'node:path'
import sharp from 'sharp'
import { getFrameworkIconFile } from '@/lib/frameworks'
import {
  buildInitTicketRenderData,
  getInitTicketNumberForUser,
  type InitTicketRenderData,
} from '@/lib/init/ticket-render-data'
import {
  DEFAULT_INIT_TICKET_PREFS,
  parseInitTicketPrefs,
  type InitTicketPrefs,
} from '@/lib/init/ticket-prefs'
import {
  getInitTicketStackOption,
  parseInitTicketStack,
} from '@/lib/init/ticket-stack'
import {
  INIT_TICKET_CONTENT_INSET,
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
  INIT_TICKET_STUB_LABEL_INSET,
  initTicketColumnSplit,
} from '@/lib/init/ticket-layout'
import {
  isInitTicketTypeId,
  type InitTicketTypeId,
} from '@/lib/init/ticket-types'
import type { InitDisplayEvent } from '@/lib/init/types'

const PUBLIC_DIR = join(process.cwd(), 'public')
const ICON_SIZE = 34
const SERVER_BRAND_CTA = '#fd366e'

type InitTicketImageEvent = Pick<
  InitDisplayEvent,
  'dateRangeLabel' | 'slug' | 'tickets'
>

function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

function truncate(value: string, maxLength: number): string {
  const trimmed = value.trim()
  if (trimmed.length <= maxLength) return trimmed
  return `${trimmed.slice(0, Math.max(0, maxLength - 3)).trim()}...`
}

function resolvePublicPath(publicSrc: string): string {
  return join(PUBLIC_DIR, publicSrc.replace(/^\/+/, ''))
}

async function readPublicAssetDataUri(
  publicSrc: string,
  mimeType: string,
): Promise<string | null> {
  try {
    const buffer = await readFile(resolvePublicPath(publicSrc))
    return `data:${mimeType};base64,${buffer.toString('base64')}`
  } catch {
    return null
  }
}

function parsePrefsJson(value: string | null): InitTicketPrefs | null {
  if (!value) return null
  try {
    return parseInitTicketPrefs(JSON.parse(value))
  } catch {
    return null
  }
}

function mergeTicketPrefsFromSearch(
  searchParams: URLSearchParams,
): InitTicketPrefs {
  const parsedPrefs = parsePrefsJson(searchParams.get('prefs'))
  const stackParam = searchParams.get('stack')
  const stack = stackParam
    ? parseInitTicketStack(
        stackParam
          .split(',')
          .map((item) => item.trim())
          .filter(Boolean),
      )
    : (parsedPrefs?.stack ?? DEFAULT_INIT_TICKET_PREFS.stack)
  const displayName =
    searchParams.get('name')?.trim() || parsedPrefs?.displayName
  const holderTitle =
    searchParams.get('title')?.trim() || parsedPrefs?.holderTitle

  return {
    ...DEFAULT_INIT_TICKET_PREFS,
    ...parsedPrefs,
    stack: stack.length ? stack : DEFAULT_INIT_TICKET_PREFS.stack,
    ...(displayName ? { displayName } : {}),
    ...(holderTitle ? { holderTitle } : {}),
  }
}

function parseTicketType(
  searchParams: URLSearchParams,
): InitTicketTypeId | undefined {
  const type = searchParams.get('type')
  return type && isInitTicketTypeId(type) ? type : undefined
}

function parseThemeUsesDarkImage(searchParams: URLSearchParams): boolean {
  const theme = searchParams.get('theme')?.trim().toLowerCase()
  return theme === 'dark'
}

export function buildInitTicketImageRenderData(
  event: InitTicketImageEvent,
  searchParams: URLSearchParams,
): InitTicketRenderData {
  const prefs = mergeTicketPrefsFromSearch(searchParams)
  const ticketNumber =
    searchParams.get('ticketNumber')?.trim() ||
    getInitTicketNumberForUser(searchParams.get('userId'))
  const githubUsername =
    searchParams.get('github')?.trim().replace(/^@+/, '') || undefined

  return buildInitTicketRenderData({
    event,
    prefs,
    themeUsesDarkImage: parseThemeUsesDarkImage(searchParams),
    mockTypeId: parseTicketType(searchParams),
    fallbackHolderName: 'Your name',
    githubUsername,
    ticketNumber,
  })
}

function wordmarkSvg(params: {
  x: number
  y: number
  width: number
  textColor: string
  accentColor: string
}): string {
  const height = params.width * 0.5
  return `
    <svg x="${params.x}" y="${params.y}" width="${params.width}" height="${height}" viewBox="-1 -82.4 185 92.4" fill="none" xmlns="http://www.w3.org/2000/svg">
      <g fill="${params.textColor}">
        <g transform="translate(0 0)"><path d="M19.4 -62.6Q15.4 -62.6 12.9 -65.05Q10.4 -67.5 10.4 -71.3Q10.4 -75.6 13.4 -78.5Q16.4 -81.4 20.7 -81.4Q24.7 -81.4 27.2 -78.95Q29.7 -76.5 29.7 -72.7Q29.7 -68.4 26.7 -65.5Q23.7 -62.6 19.4 -62.6ZM0 -7.2 9.1 -58.8H26.5L17.4 -7.2Z" /></g>
        <g transform="translate(24 0)"><path d="M39.3 -59.4Q46.9 -59.4 52.3 -55.15Q57.7 -50.9 57.7 -41.4Q57.7 -38 57 -34.2L52.2 -7.2H34.9L39.7 -34.3Q40.2 -37.3 40.2 -39.1Q40.2 -45.6 33.2 -45.6Q24.1 -45.6 21.9 -33.3L17.3 -7.2H0L9.1 -58.8H24.2L24.4 -53.3Q30.8 -59.4 39.3 -59.4Z" /></g>
        <g transform="translate(82 0)"><path d="M19.4 -62.6Q15.4 -62.6 12.9 -65.05Q10.4 -67.5 10.4 -71.3Q10.4 -75.6 13.4 -78.5Q16.4 -81.4 20.7 -81.4Q24.7 -81.4 27.2 -78.95Q29.7 -76.5 29.7 -72.7Q29.7 -68.4 26.7 -65.5Q23.7 -62.6 19.4 -62.6ZM0 -7.2 9.1 -58.8H26.5L17.4 -7.2Z" /></g>
        <g transform="translate(106 0)"><path d="M6.3 -23.3 10.1 -44.5H2L4.5 -58.8H12.6L15.2 -73.3H32.5L29.9 -58.8H41L38.5 -44.5H27.4L24.1 -26.2Q23.9 -25 23.9 -24.2Q23.9 -21.5 27.5 -21.5H34.4L31.9 -7.2H20.5Q13.6 -7.2 9.75 -10.3Q5.9 -13.4 5.9 -19.4Q5.9 -20.5 6.3 -23.3Z" /></g>
      </g>
      <g transform="translate(141.7 0)"><path fill="${params.accentColor}" d="M38.5 9H-2.9L-0.1 -6.6H41.3Z" /></g>
    </svg>
  `
}

async function buildStackIconImages(
  data: InitTicketRenderData,
): Promise<string> {
  const icons = await Promise.all(
    data.prefs.stack.map(async (id, index) => {
      const option = getInitTicketStackOption(id)
      if (!option) return ''
      const iconSrc =
        option.iconKey === 'appwrite'
          ? `/icons/${data.ticketAppearance.usesDarkChrome ? 'appwrite-white.svg' : 'appwrite.svg'}`
          : (() => {
              const file = getFrameworkIconFile(option.iconKey)
              return file ? `/icons/${file}` : null
            })()
      const x = index * 52
      if (!iconSrc) {
        return `<circle cx="${x + ICON_SIZE / 2}" cy="${ICON_SIZE / 2}" r="12" fill="${data.ticketAppearance.usesDarkChrome ? '#ffffff' : '#111827'}" opacity="0.9" />`
      }
      const uri = await readPublicAssetDataUri(iconSrc, 'image/svg+xml')
      if (!uri) return ''
      return `<image href="${uri}" x="${x}" y="0" width="${ICON_SIZE}" height="${ICON_SIZE}" preserveAspectRatio="xMidYMid meet" />`
    }),
  )
  return icons.join('')
}

export async function renderInitTicketImagePng(
  data: InitTicketRenderData,
): Promise<Uint8Array> {
  const accentColor = data.ticketAppearance.accentColor.startsWith('var(')
    ? SERVER_BRAND_CTA
    : data.ticketAppearance.accentColor
  const backgroundPath = resolvePublicPath(data.ticketAppearance.backgroundSrc)
  const contentX =
    (INIT_TICKET_CONTENT_INSET.left / 100) * INIT_TICKET_IMAGE_WIDTH
  const contentY =
    (INIT_TICKET_CONTENT_INSET.top / 100) * INIT_TICKET_IMAGE_HEIGHT
  const contentW =
    INIT_TICKET_IMAGE_WIDTH *
    ((100 - INIT_TICKET_CONTENT_INSET.left - INIT_TICKET_CONTENT_INSET.right) /
      100)
  const contentH =
    INIT_TICKET_IMAGE_HEIGHT *
    ((100 - INIT_TICKET_CONTENT_INSET.top - INIT_TICKET_CONTENT_INSET.bottom) /
      100)
  const { main, stub } = initTicketColumnSplit()
  const mainW = (contentW * main) / (main + stub)
  const stubW = contentW - mainW
  const stubX = contentX + mainW
  const stubLabelX =
    stubX + stubW * (INIT_TICKET_STUB_LABEL_INSET.left / 100)
  const textColor = data.ticketAppearance.usesDarkChrome ? '#ffffff' : '#111827'
  const mutedColor = data.ticketAppearance.usesDarkChrome
    ? 'rgba(255,255,255,0.62)'
    : '#6b7280'
  const labelColor = data.ticketAppearance.usesDarkChrome
    ? 'rgba(255,255,255,0.72)'
    : '#525252'
  const separatorColor = data.ticketAppearance.usesDarkChrome
    ? 'rgba(255,255,255,0.22)'
    : 'rgba(17,24,39,0.18)'
  const stackIcons = await buildStackIconImages(data)
  const holderName = escapeXml(truncate(data.holderName, 34))
  const holderTitle = escapeXml(
    truncate(
      data.prefs.holderTitle?.trim() || data.ticketAppearance.holderTitle,
      34,
    ),
  )
  const githubUsername = data.githubUsername
    ? escapeXml(`@${truncate(data.githubUsername, 24)}`)
    : null
  const passLabel = escapeXml(data.ticketAppearance.passLabel)
  const ticketNumber = escapeXml(data.ticketNumber)
  const dateRangeLabel = escapeXml(data.dateRangeLabel)

  const overlay = `
    <svg width="${INIT_TICKET_IMAGE_WIDTH}" height="${INIT_TICKET_IMAGE_HEIGHT}" viewBox="0 0 ${INIT_TICKET_IMAGE_WIDTH} ${INIT_TICKET_IMAGE_HEIGHT}" xmlns="http://www.w3.org/2000/svg">
      <style>
        text { font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .mono { font-family: "SF Mono", "Roboto Mono", Menlo, Consolas, monospace; }
        .upper { text-transform: uppercase; letter-spacing: 5px; font-weight: 700; }
      </style>
      ${wordmarkSvg({
        x: contentX,
        y: contentY + 12,
        width: 112,
        textColor,
        accentColor,
      })}
      <text x="${contentX}" y="${contentY + 84}" class="upper" fill="${mutedColor}" font-size="18">${dateRangeLabel}</text>

      <g transform="translate(${contentX} ${contentY + contentH - 160})">${stackIcons}</g>
      <line x1="${contentX}" y1="${contentY + contentH - 110}" x2="${contentX + mainW - 50}" y2="${contentY + contentH - 110}" stroke="${separatorColor}" stroke-width="2" stroke-dasharray="8 8" />
      <text x="${contentX}" y="${contentY + contentH - 58}" fill="${textColor}" font-size="${data.holderName.length > 24 ? 40 : 52}" font-weight="500" letter-spacing="-2">${holderName}</text>
      <text x="${contentX}" y="${contentY + contentH - 22}" fill="${labelColor}" font-size="22" font-weight="600">${holderTitle}</text>
      ${
        githubUsername
          ? `<text x="${contentX}" y="${contentY + contentH + 12}" fill="${labelColor}" font-size="16" font-weight="600">${githubUsername}</text>`
          : ''
      }
      <text x="${contentX}" y="${contentY + contentH + 44}" class="upper" fill="${mutedColor}" font-size="13">${passLabel}</text>
      <text x="${contentX}" y="${contentY + contentH + 72}" class="mono" fill="${accentColor}" font-size="14" font-weight="600">${ticketNumber}</text>

      <g transform="translate(${stubLabelX} ${contentY + contentH - 8}) rotate(-90)">
        ${wordmarkSvg({
          x: 0,
          y: -2,
          width: 72,
          textColor,
          accentColor,
        })}
        <text x="0" y="56" class="mono" fill="${accentColor}" font-size="16" font-weight="700" letter-spacing="1">${ticketNumber}</text>
        <text x="0" y="90" fill="${labelColor}" font-size="21">${holderName}</text>
        <text x="0" y="116" fill="${mutedColor}" font-size="13" font-weight="600">${holderTitle}</text>
        <text x="0" y="146" class="upper" fill="${mutedColor}" font-size="10">${passLabel}</text>
        <text x="0" y="172" class="upper" fill="${mutedColor}" font-size="10">${dateRangeLabel}</text>
      </g>
    </svg>
  `

  return sharp(backgroundPath)
    .composite([{ input: Buffer.from(overlay), left: 0, top: 0 }])
    .png()
    .toBuffer()
}
