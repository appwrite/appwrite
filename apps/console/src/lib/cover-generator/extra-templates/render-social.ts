import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverBrandLightRgb } from '@/lib/cover-generator/cover-brand-lights'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { COVER_PODCAST_WAVEFORM_BARS } from '@/lib/cover-generator/extra-templates/constants'
import {
  buildExtraAvatarSvg,
  buildExtraGlassCardSvg,
  buildExtraPillSvg,
  EXTRA_COVER_BODY_FONT,
  EXTRA_COVER_CONTENT_WIDTH,
  EXTRA_COVER_CONTENT_X,
  EXTRA_COVER_TITLE_FONT,
  estimateCoverTextWidth,
  getCoverNameInitials,
  getExtraCoverBrand,
  prepareExtraCoverImage,
  renderExtraBodyLines,
  renderExtraEyebrow,
  renderExtraTitleLines,
} from '@/lib/cover-generator/extra-templates/render-shared'
import { escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import type {
  CoverBlogPostData,
  CoverEventData,
  CoverPodcastEpisodeData,
  CoverProfileCardData,
  CoverQuoteData,
  CoverSocialPostData,
} from '@/lib/cover-generator/types'

function rgba([r, g, b]: [number, number, number], alpha: number): string {
  return `rgba(${r},${g},${b},${alpha})`
}

const QUOTE_MARK_FONT_SIZE = 150
const QUOTE_FONT_SIZE = 37
const QUOTE_STEP = QUOTE_FONT_SIZE + 12
const QUOTE_AUTHOR_NAME_FONT_SIZE = 23
const QUOTE_AUTHOR_ROLE_FONT_SIZE = 19
const QUOTE_AVATAR_RADIUS = 32

export async function renderQuoteTemplateSvg(
  data: CoverQuoteData,
  themeId: CoverThemeId,
): Promise<string> {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const quoteLines = wrapTextLines(data.quote.trim(), 32, 3)
  const hasAuthor = Boolean(data.authorName?.trim())

  const markHeight = 110
  const quoteHeight = quoteLines.length * QUOTE_STEP
  const authorGap = hasAuthor ? 44 : 0
  const authorHeight = hasAuthor ? QUOTE_AVATAR_RADIUS * 2 : 0
  const totalHeight = markHeight + quoteHeight + authorGap + authorHeight
  const startY = Math.round((COVER_HEIGHT - totalHeight) / 2) - 10

  const quoteStartY = startY + markHeight

  let authorSvg = ''
  if (hasAuthor) {
    const authorY = quoteStartY + quoteHeight + authorGap
    const avatarHref = await prepareExtraCoverImage(
      data.avatar,
      QUOTE_AVATAR_RADIUS * 2,
      themeId,
    )
    const avatarCx = EXTRA_COVER_CONTENT_X + QUOTE_AVATAR_RADIUS
    const avatarCy = authorY + QUOTE_AVATAR_RADIUS
    const textX = EXTRA_COVER_CONTENT_X + QUOTE_AVATAR_RADIUS * 2 + 20
    const nameLayoutY = authorY + 8
    const roleLayoutY = nameLayoutY + QUOTE_AUTHOR_NAME_FONT_SIZE + 12

    authorSvg = `
      ${buildExtraAvatarSvg({
        href: avatarHref,
        name: data.authorName ?? '',
        cx: avatarCx,
        cy: avatarCy,
        radius: QUOTE_AVATAR_RADIUS,
        brand,
        glass,
        clipId: 'cover-quote-avatar-clip',
      })}
      <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.foreground}" font-size="${QUOTE_AUTHOR_NAME_FONT_SIZE}" font-weight="600" x="${textX}" y="${coverSvgTextBaseline(nameLayoutY, QUOTE_AUTHOR_NAME_FONT_SIZE)}">${escapeXml(data.authorName ?? '')}</text>
      ${
        data.authorRole
          ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${QUOTE_AUTHOR_ROLE_FONT_SIZE}" x="${textX}" y="${coverSvgTextBaseline(roleLayoutY, QUOTE_AUTHOR_ROLE_FONT_SIZE)}">${escapeXml(data.authorRole)}</text>`
          : ''
      }
    `
  }

  return `
    <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.brandCta}" font-size="${QUOTE_MARK_FONT_SIZE}" x="${EXTRA_COVER_CONTENT_X - 6}" y="${coverSvgTextBaseline(startY, QUOTE_MARK_FONT_SIZE)}">“</text>
    ${renderExtraTitleLines({
      lines: quoteLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: quoteStartY,
      fontSize: QUOTE_FONT_SIZE,
      brand,
      lineGap: 12,
      fill: brand.foreground,
    })}
    ${authorSvg}
  `
}

const BLOG_TITLE_FONT_SIZE = 52
const BLOG_AVATAR_RADIUS = 34
const BLOG_AUTHOR_FONT_SIZE = 22
const BLOG_META_FONT_SIZE = 19
const BLOG_BYLINE_INSET = 8

export async function renderBlogPostTemplateSvg(
  data: CoverBlogPostData,
  themeId: CoverThemeId,
): Promise<string> {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const titleLines = wrapTextLines(stripCoverTitleSuffix(data.title), 25, 3)
  const eyebrowLayoutY = 116
  const titleStartY = data.category ? 116 + 18 + 34 : 128

  const metaParts = [data.date, data.readTime].filter(Boolean).join(' · ')
  const authorRowHeight = BLOG_AVATAR_RADIUS * 2
  const authorY = COVER_HEIGHT - 92 - authorRowHeight

  const avatarHref = data.authorName
    ? await prepareExtraCoverImage(data.avatar, authorRowHeight, themeId)
    : null

  let authorSvg = ''
  if (data.authorName) {
    const avatarCx = EXTRA_COVER_CONTENT_X + BLOG_AVATAR_RADIUS
    const avatarCy = authorY + BLOG_AVATAR_RADIUS
    const textX = EXTRA_COVER_CONTENT_X + authorRowHeight + 18
    const nameLayoutY = authorY + (metaParts ? BLOG_BYLINE_INSET : (authorRowHeight - BLOG_AUTHOR_FONT_SIZE) / 2)
    const metaLayoutY = authorY + authorRowHeight - BLOG_META_FONT_SIZE - BLOG_BYLINE_INSET

    authorSvg = `
      ${buildExtraAvatarSvg({
        href: avatarHref,
        name: data.authorName,
        cx: avatarCx,
        cy: avatarCy,
        radius: BLOG_AVATAR_RADIUS,
        brand,
        glass,
        clipId: 'cover-blog-avatar-clip',
        solidPlaceholder: true,
      })}
      <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.foreground}" font-size="${BLOG_AUTHOR_FONT_SIZE}" font-weight="600" x="${textX}" y="${coverSvgTextBaseline(nameLayoutY, BLOG_AUTHOR_FONT_SIZE)}">${escapeXml(data.authorName)}</text>
      ${
        metaParts
          ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${BLOG_META_FONT_SIZE}" x="${textX}" y="${coverSvgTextBaseline(metaLayoutY, BLOG_META_FONT_SIZE)}">${escapeXml(metaParts)}</text>`
          : ''
      }
    `
  }

  return `
    ${renderExtraEyebrow({
      eyebrow: data.category,
      x: EXTRA_COVER_CONTENT_X,
      layoutY: eyebrowLayoutY,
      brand,
    })}
    ${renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: titleStartY,
      fontSize: BLOG_TITLE_FONT_SIZE,
      brand,
      lineGap: 10,
    })}
    ${authorSvg}
  `
}

const PODCAST_TITLE_FONT_SIZE = 50
const PODCAST_TITLE_STEP = PODCAST_TITLE_FONT_SIZE + 8
const PODCAST_WAVE_HEIGHT = 104
const PODCAST_WAVE_GAP = 12
const PODCAST_META_FONT_SIZE = 21

export function renderPodcastEpisodeTemplateSvg(
  data: CoverPodcastEpisodeData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)

  const titleLines = wrapTextLines(stripCoverTitleSuffix(data.title), 27, 2)

  const pillLabel = data.episode ? `Episode ${data.episode.trim()}` : ''
  const pillHeight = pillLabel ? 40 : 0
  const pillGap = pillLabel ? 32 : 0
  const titleHeight = titleLines.length * PODCAST_TITLE_STEP
  const waveGap = 44
  const metaGap = data.duration || data.host ? 36 : 0
  const metaHeight = data.duration || data.host ? PODCAST_META_FONT_SIZE : 0

  const totalHeight =
    pillHeight + pillGap + titleHeight + waveGap + PODCAST_WAVE_HEIGHT + metaGap + metaHeight
  let cursorY = Math.round((COVER_HEIGHT - totalHeight) / 2)

  const parts: string[] = []

  if (pillLabel) {
    parts.push(
      buildExtraPillSvg({
        x: EXTRA_COVER_CONTENT_X,
        y: cursorY,
        label: pillLabel,
        fontSize: 16,
        brand,
        tone: 'brand-outline',
        uppercase: true,
        height: 40,
        paddingX: 24,
      }),
    )
    cursorY += pillHeight + pillGap
  }

  parts.push(
    renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: cursorY,
      fontSize: PODCAST_TITLE_FONT_SIZE,
      brand,
    }),
  )
  cursorY += titleHeight + waveGap

  const bars = COVER_PODCAST_WAVEFORM_BARS
  const barSlot = (EXTRA_COVER_CONTENT_WIDTH - (bars.length - 1) * PODCAST_WAVE_GAP) / bars.length
  const teal = getCoverBrandLightRgb('teal')
  const waveSvg = bars
    .map((value, index) => {
      const height = Math.max(8, (value / 100) * PODCAST_WAVE_HEIGHT)
      const x = EXTRA_COVER_CONTENT_X + index * barSlot
      const y = cursorY + (PODCAST_WAVE_HEIGHT - height) / 2
      const opacity = 0.3 + (value / 100) * 0.55
      return `<rect x="${x}" y="${y}" width="${barSlot}" height="${height}" rx="${Math.min(6, barSlot / 2)}" ry="${Math.min(6, barSlot / 2)}" fill="${rgba(teal, opacity)}" />`
    })
    .join('\n')
  parts.push(waveSvg)
  cursorY += PODCAST_WAVE_HEIGHT + metaGap

  const metaText = [data.duration, data.host].filter(Boolean).join(' · ')
  if (metaText) {
    parts.push(
      `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${PODCAST_META_FONT_SIZE}" x="${EXTRA_COVER_CONTENT_X}" y="${coverSvgTextBaseline(cursorY, PODCAST_META_FONT_SIZE)}">${escapeXml(metaText)}</text>`,
    )
  }

  return parts.join('\n')
}

const EVENT_CARD_WIDTH = 240
const EVENT_CARD_HEIGHT = 268
const EVENT_MONTH_FONT_SIZE = 27
const EVENT_DAY_FONT_SIZE = 118
const EVENT_TITLE_FONT_SIZE = 54
const EVENT_TITLE_STEP = EVENT_TITLE_FONT_SIZE + 10
const EVENT_LOCATION_FONT_SIZE = 26
const EVENT_CTA_FONT_SIZE = 19

/** Filled map-pin marker matching the location text color. */
function buildEventPinSvg(x: number, y: number, size: number, color: string): string {
  const scale = size / 24
  return `
    <g transform="translate(${x} ${y}) scale(${scale})">
      <path fill="${color}" fill-rule="evenodd" d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0ZM12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
    </g>
  `
}

export function renderEventTemplateSvg(
  data: CoverEventData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const cardX = EXTRA_COVER_CONTENT_X
  const cardY = Math.round((COVER_HEIGHT - EVENT_CARD_HEIGHT) / 2)

  const rightX = cardX + EVENT_CARD_WIDTH + 56
  const rightWidth = EXTRA_COVER_CONTENT_X + EXTRA_COVER_CONTENT_WIDTH - rightX

  const titleLines = wrapTextLines(
    stripCoverTitleSuffix(data.title),
    Math.max(16, Math.floor(rightWidth / (EVENT_TITLE_FONT_SIZE * 0.56))),
    2,
  )
  const titleHeight = titleLines.length * EVENT_TITLE_STEP
  const locationGap = data.location ? 34 : 0
  const locationHeight = data.location ? EVENT_LOCATION_FONT_SIZE + 8 : 0
  const ctaGap = data.cta ? 40 : 0
  const ctaHeight = data.cta ? 50 : 0
  const rightHeight = titleHeight + locationGap + locationHeight + ctaGap + ctaHeight
  let rightY = Math.round((COVER_HEIGHT - rightHeight) / 2)

  const monthLayoutY = cardY + 46
  const dayLayoutY = monthLayoutY + EVENT_MONTH_FONT_SIZE + 20

  const parts: string[] = []

  parts.push(`
    ${buildExtraGlassCardSvg({
      x: cardX,
      y: cardY,
      width: EVENT_CARD_WIDTH,
      height: EVENT_CARD_HEIGHT,
      radius: 28,
      glass,
    })}
    <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.brandCta}" font-size="${EVENT_MONTH_FONT_SIZE}" font-weight="700" letter-spacing="0.22em" x="${cardX + EVENT_CARD_WIDTH / 2}" y="${coverSvgTextBaseline(monthLayoutY, EVENT_MONTH_FONT_SIZE)}" text-anchor="middle">${escapeXml(data.month.trim().toUpperCase())}</text>
    <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.foreground}" font-size="${EVENT_DAY_FONT_SIZE}" letter-spacing="-0.02em" x="${cardX + EVENT_CARD_WIDTH / 2}" y="${coverSvgTextBaseline(dayLayoutY, EVENT_DAY_FONT_SIZE)}" text-anchor="middle">${escapeXml(data.day.trim())}</text>
  `)

  parts.push(
    renderExtraTitleLines({
      lines: titleLines,
      x: rightX,
      startLayoutY: rightY,
      fontSize: EVENT_TITLE_FONT_SIZE,
      brand,
    }),
  )
  rightY += titleHeight

  if (data.location) {
    rightY += locationGap
    const pinSize = 30
    const pinY = rightY - 3
    parts.push(`
      ${buildEventPinSvg(rightX, pinY, pinSize, brand.mutedForeground)}
      <text class="cover-body" fill="${brand.mutedForeground}" font-size="${EVENT_LOCATION_FONT_SIZE}" x="${rightX + pinSize + 12}" y="${coverSvgTextBaseline(rightY, EVENT_LOCATION_FONT_SIZE)}">${escapeXml(data.location)}</text>
    `)
    rightY += locationHeight
  }

  if (data.cta) {
    rightY += ctaGap
    parts.push(
      buildExtraPillSvg({
        x: rightX,
        y: rightY,
        label: data.cta,
        fontSize: EVENT_CTA_FONT_SIZE,
        brand,
        tone: 'brand-solid',
        height: 50,
        paddingX: 34,
      }),
    )
  }

  return parts.join('\n')
}

const PROFILE_AVATAR_RADIUS = 140
const PROFILE_NAME_FONT_SIZE = 52
const PROFILE_ROLE_FONT_SIZE = 28
const PROFILE_HANDLE_FONT_SIZE = 21

export async function renderProfileCardTemplateSvg(
  data: CoverProfileCardData,
  themeId: CoverThemeId,
): Promise<string> {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)
  const centerX = COVER_WIDTH / 2
  const { paddingX, borderWidth, imageOpacity } = COVER_HERO_SCREENSHOT_FRAME
  const outerRadius = PROFILE_AVATAR_RADIUS + paddingX
  const avatarSize = outerRadius * 2

  const avatarHref = await prepareExtraCoverImage(
    data.avatar,
    PROFILE_AVATAR_RADIUS * 2,
    themeId,
    { fit: 'cover' },
  )

  const nameGap = 40
  const nameHeight = PROFILE_NAME_FONT_SIZE
  const roleGap = data.role ? 16 : 0
  const roleHeight = data.role ? PROFILE_ROLE_FONT_SIZE : 0
  const handleGap = data.handle ? 28 : 0
  const handleHeight = data.handle ? 44 : 0

  const totalHeight =
    avatarSize + nameGap + nameHeight + roleGap + roleHeight + handleGap + handleHeight
  let cursorY = Math.round((COVER_HEIGHT - totalHeight) / 2)
  const avatarCx = centerX
  const avatarCy = cursorY + outerRadius

  const parts: string[] = [
    `<circle cx="${avatarCx}" cy="${avatarCy}" r="${outerRadius}" fill="${glass.shellFill}" stroke="${glass.shellBorder}" stroke-width="${borderWidth}" />`,
    `<clipPath id="cover-profile-avatar-clip"><circle cx="${avatarCx}" cy="${avatarCy}" r="${PROFILE_AVATAR_RADIUS}" /></clipPath>`,
  ]

  if (avatarHref) {
    parts.push(
      `<image href="${avatarHref}" x="${avatarCx - PROFILE_AVATAR_RADIUS}" y="${avatarCy - PROFILE_AVATAR_RADIUS}" width="${PROFILE_AVATAR_RADIUS * 2}" height="${PROFILE_AVATAR_RADIUS * 2}" clip-path="url(#cover-profile-avatar-clip)" preserveAspectRatio="xMidYMid slice" opacity="${imageOpacity}" />`,
    )
  } else {
    const initials = getCoverNameInitials(data.name)
    const fontSize = Math.round(PROFILE_AVATAR_RADIUS * 0.56)
    parts.push(
      `<text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.mutedForeground}" font-size="${fontSize}" font-weight="600" x="${avatarCx}" y="${coverSvgTextBaseline(avatarCy - fontSize / 2, fontSize)}" text-anchor="middle">${escapeXml(initials)}</text>`,
    )
  }

  cursorY += avatarSize + nameGap

  parts.push(
    `<text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.foreground}" font-size="${PROFILE_NAME_FONT_SIZE}" font-weight="600" letter-spacing="-0.01em" x="${centerX}" y="${coverSvgTextBaseline(cursorY, PROFILE_NAME_FONT_SIZE)}" text-anchor="middle">${escapeXml(data.name)}</text>`,
  )
  cursorY += nameHeight + roleGap

  if (data.role) {
    parts.push(
      `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${PROFILE_ROLE_FONT_SIZE}" x="${centerX}" y="${coverSvgTextBaseline(cursorY, PROFILE_ROLE_FONT_SIZE)}" text-anchor="middle">${escapeXml(data.role)}</text>`,
    )
    cursorY += roleHeight
  }

  cursorY += handleGap
  if (data.handle) {
    parts.push(
      buildExtraPillSvg({
        x: centerX,
        y: cursorY,
        label: data.handle,
        fontSize: PROFILE_HANDLE_FONT_SIZE,
        brand,
        anchor: 'middle',
        tone: 'muted',
        height: 44,
        paddingX: 28,
      }),
    )
  }

  return parts.join('\n')
}

const POST_CARD_WIDTH = 700
const POST_CARD_PADDING = 34
const POST_AVATAR_RADIUS = 28
const POST_NAME_FONT_SIZE = 22
const POST_HANDLE_FONT_SIZE = 19
const POST_TEXT_FONT_SIZE = 25
const POST_TEXT_STEP = POST_TEXT_FONT_SIZE + 11
const POST_STAT_FONT_SIZE = 19

function buildPostIconSvg(
  kind: 'comment' | 'heart',
  x: number,
  y: number,
  size: number,
  color: string,
): string {
  const scale = size / 24
  const path =
    kind === 'comment'
      ? 'M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z'
      : 'M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z'
  return `<g transform="translate(${x} ${y}) scale(${scale})" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="${path}" /></g>`
}

export async function renderSocialPostTemplateSvg(
  data: CoverSocialPostData,
  themeId: CoverThemeId,
): Promise<string> {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const textWidth = POST_CARD_WIDTH - POST_CARD_PADDING * 2
  const textLines = wrapTextLines(
    data.text.trim(),
    Math.max(20, Math.floor(textWidth / (POST_TEXT_FONT_SIZE * 0.5))),
    4,
  )

  const headerHeight = POST_AVATAR_RADIUS * 2
  const textGap = 26
  const textHeight = textLines.length * POST_TEXT_STEP
  const hasStats = Boolean(data.likes || data.comments)
  const dividerGap = hasStats ? 26 : 0
  const statsHeight = hasStats ? 24 : 0
  const statsGap = hasStats ? 24 : 0

  const contentHeight =
    headerHeight + textGap + textHeight + dividerGap + 1 + statsGap + statsHeight
  const cardHeight = contentHeight + POST_CARD_PADDING * 2
  const cardX = Math.round((COVER_WIDTH - POST_CARD_WIDTH) / 2)
  const cardY = Math.round((COVER_HEIGHT - cardHeight) / 2)

  const avatarHref = await prepareExtraCoverImage(
    data.avatar,
    POST_AVATAR_RADIUS * 2,
    themeId,
  )

  let cursorY = cardY + POST_CARD_PADDING

  const avatarCx = cardX + POST_CARD_PADDING + POST_AVATAR_RADIUS
  const avatarCy = cursorY + POST_AVATAR_RADIUS
  const headerTextX = cardX + POST_CARD_PADDING + POST_AVATAR_RADIUS * 2 + 16
  const nameLayoutY = cursorY + (headerHeight - POST_NAME_FONT_SIZE) / 2
  const nameWidth = estimateCoverTextWidth(data.name, POST_NAME_FONT_SIZE, 'semibold')
  const handleParts = [data.handle, data.time].filter(Boolean).join(' · ')

  const headerSvg = `
    ${buildExtraAvatarSvg({
      href: avatarHref,
      name: data.name,
      cx: avatarCx,
      cy: avatarCy,
      radius: POST_AVATAR_RADIUS,
      brand,
      glass,
      clipId: 'cover-post-avatar-clip',
    })}
    <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.foreground}" font-size="${POST_NAME_FONT_SIZE}" font-weight="600" x="${headerTextX}" y="${coverSvgTextBaseline(nameLayoutY, POST_NAME_FONT_SIZE)}">${escapeXml(data.name)}</text>
    ${
      handleParts
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${POST_HANDLE_FONT_SIZE}" x="${headerTextX + nameWidth + 10}" y="${coverSvgTextBaseline(nameLayoutY + 1, POST_HANDLE_FONT_SIZE)}">${escapeXml(handleParts)}</text>`
        : ''
    }
  `
  cursorY += headerHeight + textGap

  const textSvg = renderExtraBodyLines({
    lines: textLines,
    x: cardX + POST_CARD_PADDING,
    startLayoutY: cursorY,
    fontSize: POST_TEXT_FONT_SIZE,
    brand,
    lineGap: 11,
    fill: brand.foreground,
  })
  cursorY += textHeight + dividerGap

  let footerSvg = ''
  if (hasStats) {
    const dividerY = cursorY
    const statsY = dividerY + statsGap
    const iconSize = 22
    const statIconY = statsY + (POST_STAT_FONT_SIZE - iconSize) / 2 + 1

    let statX = cardX + POST_CARD_PADDING
    const statParts: string[] = []

    if (data.comments) {
      statParts.push(`
        ${buildPostIconSvg('comment', statX, statIconY, iconSize, brand.mutedForeground)}
        <text class="cover-body" fill="${brand.mutedForeground}" font-size="${POST_STAT_FONT_SIZE}" x="${statX + iconSize + 10}" y="${coverSvgTextBaseline(statsY, POST_STAT_FONT_SIZE)}">${escapeXml(data.comments)}</text>
      `)
      statX += iconSize + 10 + estimateCoverTextWidth(data.comments, POST_STAT_FONT_SIZE) + 56
    }

    if (data.likes) {
      statParts.push(`
        ${buildPostIconSvg('heart', statX, statIconY, iconSize, brand.mutedForeground)}
        <text class="cover-body" fill="${brand.mutedForeground}" font-size="${POST_STAT_FONT_SIZE}" x="${statX + iconSize + 10}" y="${coverSvgTextBaseline(statsY, POST_STAT_FONT_SIZE)}">${escapeXml(data.likes)}</text>
      `)
    }

    footerSvg = `
      <line x1="${cardX + POST_CARD_PADDING}" y1="${dividerY}" x2="${cardX + POST_CARD_WIDTH - POST_CARD_PADDING}" y2="${dividerY}" stroke="${glass.shellBorder}" stroke-width="1.5" />
      ${statParts.join('\n')}
    `
  }

  return `
    ${buildExtraGlassCardSvg({
      x: cardX,
      y: cardY,
      width: POST_CARD_WIDTH,
      height: cardHeight,
      radius: 26,
      glass,
    })}
    ${headerSvg}
    ${textSvg}
    ${footerSvg}
  `
}
