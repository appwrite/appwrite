import { toCanvas } from 'html-to-image'
import {
  buildInitTicketCaptureFontEmbedCss,
  preloadInitTicketCaptureFonts,
} from '@/lib/init/ticket-font-embed'
import {
  INIT_TICKET_CONTENT_INSET,
  INIT_TICKET_STUB_LABEL_INSET,
  initTicketColumnSplit,
} from '@/lib/init/ticket-layout'

const STILL_CAPTURE_PIXEL_RATIO = 2

function isCaptureExcludedNode(node: Node) {
  if (!(node instanceof HTMLElement)) return false
  if (node.dataset.initTicketCaptureExclude !== undefined) return true
  // Stub uses -rotate-90; foreignObject misplaces it. Painted separately.
  if (node.dataset.initTicketStubCol !== undefined) return true
  return false
}

async function preloadImages(element: HTMLElement) {
  const images = element.querySelectorAll('img')
  await Promise.all(
    Array.from(images).map(
      (img) =>
        new Promise<void>((resolve) => {
          if (img.complete && img.naturalWidth > 0) {
            resolve()
            return
          }
          const done = () => resolve()
          img.addEventListener('load', done, { once: true })
          img.addEventListener('error', done, { once: true })
        }),
    ),
  )
}

function waitForPaint() {
  return new Promise<void>((resolve) => {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => resolve())
    })
  })
}

/**
 * Prefer layout pixels over `getBoundingClientRect`.
 * The live ticket sits inside `transform: scale(...)`, so the visual box is
 * smaller than the 820px CSS the card is designed for. html-to-image must
 * clone at the layout size or insets, stub rotation, and type all collapse.
 */
export function resolveInitTicketStillCaptureSize(
  offsetWidth: number,
  offsetHeight: number,
  rectWidth: number,
  rectHeight: number,
) {
  const width = offsetWidth > 1 ? offsetWidth : rectWidth
  const height = offsetHeight > 1 ? offsetHeight : rectHeight
  return {
    width: Math.max(1, Math.round(width)),
    height: Math.max(1, Math.round(height)),
  }
}

export function computeInitTicketStubLayoutBox(
  ticketLayoutWidth: number,
  ticketLayoutHeight: number,
) {
  const top = (INIT_TICKET_CONTENT_INSET.top / 100) * ticketLayoutHeight
  const right = (INIT_TICKET_CONTENT_INSET.right / 100) * ticketLayoutWidth
  const bottom = (INIT_TICKET_CONTENT_INSET.bottom / 100) * ticketLayoutHeight
  const left = (INIT_TICKET_CONTENT_INSET.left / 100) * ticketLayoutWidth
  const contentW = ticketLayoutWidth - left - right
  const contentH = ticketLayoutHeight - top - bottom
  const { main, stub } = initTicketColumnSplit()
  const stubW = contentW * (stub / (main + stub))
  const mainW = contentW - stubW

  return {
    contentW,
    contentH,
    stubW,
    stubColX: left + mainW,
    stubColY: top,
  }
}

/**
 * CSS `origin-bottom-start -rotate-90` pivot on the still, in layout CSS pixels.
 * Fallback when the live label cannot be measured.
 */
export function computeInitTicketStubLabelOrigin(
  ticketLayoutWidth: number,
  ticketLayoutHeight: number,
) {
  const box = computeInitTicketStubLayoutBox(
    ticketLayoutWidth,
    ticketLayoutHeight,
  )
  const { left, bottom } = INIT_TICKET_STUB_LABEL_INSET
  return {
    x: box.stubColX + (left / 100) * box.stubW,
    y: box.stubColY + box.contentH * (1 - bottom / 100),
  }
}

/** Map a visual rect on the scaled ticket to layout pixels on the still canvas. */
export function mapInitTicketVisualRectToStillCanvas(
  ticketLayoutWidth: number,
  ticketVisualWidth: number,
  pixelRatio: number,
  stubVisualRect: { left: number; top: number; width: number; height: number },
  ticketVisualRect: { left: number; top: number },
) {
  const layoutScale = ticketLayoutWidth / ticketVisualWidth
  return {
    x:
      (stubVisualRect.left - ticketVisualRect.left) *
      layoutScale *
      pixelRatio,
    y:
      (stubVisualRect.top - ticketVisualRect.top) * layoutScale * pixelRatio,
    width: stubVisualRect.width * layoutScale * pixelRatio,
    height: stubVisualRect.height * layoutScale * pixelRatio,
  }
}

function copyComputedTypography(source: HTMLElement, clone: HTMLElement) {
  const sources = [source, ...source.querySelectorAll<HTMLElement>('*')]
  const clones = [clone, ...clone.querySelectorAll<HTMLElement>('*')]
  const count = Math.min(sources.length, clones.length)
  for (let i = 0; i < count; i += 1) {
    const computed = getComputedStyle(sources[i])
    clones[i].style.fontSize = computed.fontSize
    clones[i].style.lineHeight = computed.lineHeight
    clones[i].style.letterSpacing = computed.letterSpacing
    clones[i].style.fontWeight = computed.fontWeight
    clones[i].style.color = computed.color
    clones[i].style.fontFamily = computed.fontFamily
    clones[i].style.textTransform = computed.textTransform
    clones[i].style.whiteSpace = computed.whiteSpace
  }
}

function parseCssPx(value: string) {
  const n = Number.parseFloat(value)
  return Number.isFinite(n) ? n : 0
}

function setCanvasLetterSpacing(
  ctx: CanvasRenderingContext2D,
  letterSpacingPx: number,
) {
  try {
    ;(ctx as CanvasRenderingContext2D & { letterSpacing?: string }).letterSpacing =
      letterSpacingPx ? `${letterSpacingPx}px` : '0px'
  } catch {
    // letterSpacing on canvas is not supported in every browser.
  }
}

async function loadSvgElementAsImage(svg: SVGElement): Promise<HTMLImageElement> {
  const clone = svg.cloneNode(true) as SVGElement
  if (!clone.getAttribute('xmlns')) {
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
  }
  const cs = getComputedStyle(svg)
  const color = cs.color || '#ffffff'
  clone.style.color = color
  // data: SVG images don't resolve currentColor from CSS — bake fills in.
  clone.querySelectorAll('[fill="currentColor"]').forEach((node) => {
    node.setAttribute('fill', color)
  })
  const serialized = new XMLSerializer().serializeToString(clone)
  const url = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(serialized)}`
  const img = new Image()
  img.decoding = 'async'
  img.src = url
  await img.decode()
  return img
}

type StubPaintLine =
  | {
      kind: 'text'
      text: string
      font: string
      fill: string
      letterSpacing: number
      height: number
      width: number
    }
  | {
      kind: 'svg'
      image: HTMLImageElement
      height: number
      width: number
    }

/**
 * Paint stub copy with canvas 2D (no foreignObject rotate).
 * Pivot and scale come from the live rotated label's bounding box so the
 * export matches the on-page ticket without changing page layout insets.
 */
async function paintInitTicketStubLabelOntoStill(
  still: HTMLCanvasElement,
  ticketElement: HTMLElement,
  sourceLabel: HTMLElement,
  ticketLayoutWidth: number,
  ticketLayoutHeight: number,
) {
  const ctx = still.getContext('2d')
  if (!ctx) return

  const pr = STILL_CAPTURE_PIXEL_RATIO
  const gap = parseCssPx(getComputedStyle(sourceLabel).gap) || 4
  const lines: StubPaintLine[] = []

  for (const child of Array.from(sourceLabel.children)) {
    if (!(child instanceof HTMLElement) && !(child instanceof SVGElement)) {
      continue
    }

    const svg =
      child instanceof SVGElement
        ? child
        : child.querySelector(':scope > svg, svg')

    if (svg instanceof SVGSVGElement) {
      const cs = getComputedStyle(svg)
      const height = Math.max(
        1,
        parseCssPx(cs.fontSize) || parseCssPx(cs.height) || 14,
      )
      const viewBox = svg.viewBox.baseVal
      const aspect =
        viewBox.width > 0 && viewBox.height > 0
          ? viewBox.width / viewBox.height
          : 185 / 92.4
      const width = height * aspect
      try {
        const image = await loadSvgElementAsImage(svg)
        lines.push({ kind: 'svg', image, width, height })
      } catch {
        // Skip wordmark if SVG decode fails; text lines still paint.
      }
      continue
    }

    if (!(child instanceof HTMLElement)) continue
    let text = (child.textContent || '').trim()
    if (!text) continue

    const cs = getComputedStyle(child)
    if (cs.textTransform === 'uppercase') text = text.toUpperCase()
    else if (cs.textTransform === 'lowercase') text = text.toLowerCase()
    else if (cs.textTransform === 'capitalize') {
      text = text.replace(/\b\w/g, (c) => c.toUpperCase())
    }
    const fontSize = Math.max(1, parseCssPx(cs.fontSize) || 12)
    const font =
      `${cs.fontStyle} ${cs.fontWeight} ${fontSize}px ${cs.fontFamily}`.trim()
    const letterSpacing = parseCssPx(cs.letterSpacing)
    ctx.font = font
    setCanvasLetterSpacing(ctx, letterSpacing)
    const metrics = ctx.measureText(text)
    const width = Math.max(1, metrics.width)
    const ascent = metrics.actualBoundingBoxAscent || fontSize * 0.8
    const descent = metrics.actualBoundingBoxDescent || fontSize * 0.2
    const height = Math.max(fontSize, ascent + descent)
    lines.push({
      kind: 'text',
      text,
      font,
      fill: cs.color || '#fff',
      letterSpacing,
      height,
      width,
    })
  }

  if (lines.length === 0) return

  const totalHeight =
    lines.reduce((sum, line) => sum + line.height, 0) +
    gap * Math.max(0, lines.length - 1)
  const totalWidth = Math.max(...lines.map((line) => line.width), 1)

  // Rasterize unrotated stub at device pixels.
  const flat = document.createElement('canvas')
  flat.width = Math.max(1, Math.ceil(totalWidth * pr))
  flat.height = Math.max(1, Math.ceil(totalHeight * pr))
  const flatCtx = flat.getContext('2d')
  if (!flatCtx) return
  flatCtx.scale(pr, pr)
  flatCtx.imageSmoothingEnabled = true
  flatCtx.imageSmoothingQuality = 'high'

  let y = 0
  for (const line of lines) {
    if (line.kind === 'svg') {
      flatCtx.drawImage(line.image, 0, y, line.width, line.height)
    } else {
      flatCtx.font = line.font
      flatCtx.fillStyle = line.fill
      flatCtx.textAlign = 'left'
      flatCtx.textBaseline = 'alphabetic'
      setCanvasLetterSpacing(flatCtx, line.letterSpacing)
      const metrics = flatCtx.measureText(line.text)
      const ascent = metrics.actualBoundingBoxAscent || line.height * 0.8
      flatCtx.fillText(line.text, 0, y + ascent)
    }
    y += line.height + gap
  }

  const ticketRect = ticketElement.getBoundingClientRect()
  const labelRect = sourceLabel.getBoundingClientRect()
  const fallbackOrigin = computeInitTicketStubLabelOrigin(
    ticketLayoutWidth,
    ticketLayoutHeight,
  )

  // Use the live rotated label only for the pivot. Keep natural aspect so
  // text is not stretched to the AABB (which rarely matches paint metrics 1:1).
  let originX = fallbackOrigin.x * pr
  let originY = fallbackOrigin.y * pr
  if (ticketRect.width > 1 && labelRect.width > 1 && labelRect.height > 1) {
    const placement = mapInitTicketVisualRectToStillCanvas(
      ticketLayoutWidth,
      ticketRect.width,
      pr,
      {
        left: labelRect.left,
        top: labelRect.top,
        width: labelRect.width,
        height: labelRect.height,
      },
      ticketRect,
    )
    // -90° around bottom-left → pivot at bottom-right of the rotated AABB.
    originX = placement.x + placement.width
    originY = placement.y + placement.height
  }

  ctx.save()
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'
  ctx.translate(originX, originY)
  ctx.rotate(-Math.PI / 2)
  ctx.drawImage(
    flat,
    0,
    0,
    flat.width,
    flat.height,
    0,
    -flat.height,
    flat.width,
    flat.height,
  )
  ctx.restore()
}

/**
 * html-to-image rasterizes via SVG foreignObject, which drops `height: 100%`
 * and `justify-between`. Pin the overlay to the real inset box and copy
 * computed type so vw clamps stay as they appear on the live card.
 */
export function copyInitTicketStillLayout(
  source: HTMLElement,
  clone: HTMLElement,
) {
  copyComputedTypography(source, clone)

  const width = source.offsetWidth
  const height = source.offsetHeight
  clone.style.boxSizing = 'border-box'
  clone.style.width = `${width}px`
  clone.style.height = `${height}px`

  const sourceOverlay = [...source.children].find(
    (node): node is HTMLElement =>
      node instanceof HTMLElement && node.tagName !== 'IMG',
  )
  const cloneOverlay = [...clone.children].find(
    (node): node is HTMLElement =>
      node instanceof HTMLElement && node.tagName !== 'IMG',
  )
  if (!sourceOverlay || !cloneOverlay) return

  const top = (INIT_TICKET_CONTENT_INSET.top / 100) * height
  const right = (INIT_TICKET_CONTENT_INSET.right / 100) * width
  const bottom = (INIT_TICKET_CONTENT_INSET.bottom / 100) * height
  const left = (INIT_TICKET_CONTENT_INSET.left / 100) * width
  const contentW = width - left - right
  const contentH = height - top - bottom

  cloneOverlay.style.position = 'absolute'
  cloneOverlay.style.top = `${top}px`
  cloneOverlay.style.left = `${left}px`
  cloneOverlay.style.right = 'auto'
  cloneOverlay.style.bottom = 'auto'
  cloneOverlay.style.width = `${contentW}px`
  cloneOverlay.style.height = `${contentH}px`

  const sourceGrid = sourceOverlay.firstElementChild
  const cloneGrid = cloneOverlay.firstElementChild
  if (!(cloneGrid instanceof HTMLElement)) return

  cloneGrid.style.display = 'grid'
  cloneGrid.style.width = `${contentW}px`
  cloneGrid.style.height = `${contentH}px`
  if (sourceGrid instanceof HTMLElement) {
    cloneGrid.style.gridTemplateColumns =
      getComputedStyle(sourceGrid).gridTemplateColumns
  }

  const mainCol = cloneGrid.firstElementChild
  if (mainCol instanceof HTMLElement) {
    mainCol.style.display = 'flex'
    mainCol.style.flexDirection = 'column'
    mainCol.style.justifyContent = 'space-between'
    mainCol.style.height = `${contentH}px`
    mainCol.style.minHeight = `${contentH}px`
  }

  const stubCol = cloneGrid.children[1]
  if (stubCol instanceof HTMLElement) {
    const { stubW } = computeInitTicketStubLayoutBox(width, height)
    stubCol.style.position = 'relative'
    stubCol.style.height = `${contentH}px`
    stubCol.style.width = `${stubW}px`
    stubCol.style.overflow = 'visible'
  }
}

/** Back face uses a single flex column (no stub). */
export function copyInitTicketBackStillLayout(
  source: HTMLElement,
  clone: HTMLElement,
  layoutSize?: { width: number; height: number },
) {
  copyComputedTypography(source, clone)

  const width =
    source.offsetWidth > 1
      ? source.offsetWidth
      : (layoutSize?.width ?? source.offsetWidth)
  const height =
    source.offsetHeight > 1
      ? source.offsetHeight
      : (layoutSize?.height ?? source.offsetHeight)
  clone.style.boxSizing = 'border-box'
  clone.style.width = `${width}px`
  clone.style.height = `${height}px`

  const sourceOverlay = [...source.children].find(
    (node): node is HTMLElement =>
      node instanceof HTMLElement && node.tagName !== 'IMG',
  )
  const cloneOverlay = [...clone.children].find(
    (node): node is HTMLElement =>
      node instanceof HTMLElement && node.tagName !== 'IMG',
  )
  if (!sourceOverlay || !cloneOverlay) return

  const top = (INIT_TICKET_CONTENT_INSET.top / 100) * height
  const right = (INIT_TICKET_CONTENT_INSET.right / 100) * width
  const bottom = (INIT_TICKET_CONTENT_INSET.bottom / 100) * height
  const left = (INIT_TICKET_CONTENT_INSET.left / 100) * width
  const contentW = width - left - right
  const contentH = height - top - bottom

  cloneOverlay.style.position = 'absolute'
  cloneOverlay.style.top = `${top}px`
  cloneOverlay.style.left = `${left}px`
  cloneOverlay.style.right = 'auto'
  cloneOverlay.style.bottom = 'auto'
  cloneOverlay.style.width = `${contentW}px`
  cloneOverlay.style.height = `${contentH}px`

  const sourceContent = sourceOverlay.firstElementChild
  const cloneContent = cloneOverlay.firstElementChild
  if (
    !(sourceContent instanceof HTMLElement) ||
    !(cloneContent instanceof HTMLElement)
  ) {
    return
  }

  const computed = getComputedStyle(sourceContent)
  cloneContent.style.display = 'flex'
  cloneContent.style.flexDirection = 'column'
  cloneContent.style.justifyContent = 'space-between'
  cloneContent.style.width = `${contentW}px`
  cloneContent.style.height = `${contentH}px`
  cloneContent.style.minHeight = `${contentH}px`
  cloneContent.style.boxSizing = 'border-box'
  cloneContent.style.paddingBottom = computed.paddingBottom
  cloneContent.style.paddingRight = computed.paddingRight
  cloneContent.style.overflow = 'visible'

  const sourceBottom = sourceContent.children[1]
  const cloneBottom = cloneContent.children[1]
  if (sourceBottom instanceof HTMLElement && cloneBottom instanceof HTMLElement) {
    const bottomComputed = getComputedStyle(sourceBottom)
    cloneBottom.style.marginTop = bottomComputed.marginTop
  }
}

function buildCaptureOptions(
  width: number,
  height: number,
  backgroundColor: string,
  fontEmbedCSS: string,
) {
  return {
    width,
    height,
    pixelRatio: STILL_CAPTURE_PIXEL_RATIO,
    cacheBust: false,
    includeQueryParams: false,
    skipAutoScale: true,
    backgroundColor:
      backgroundColor === 'rgba(0, 0, 0, 0)' || backgroundColor === 'transparent'
        ? undefined
        : backgroundColor,
    preferredFontFormat: 'woff2' as const,
    fontEmbedCSS,
    style: {
      transform: 'none',
      position: 'relative',
      inset: 'auto',
      width: `${width}px`,
      height: `${height}px`,
      margin: '0',
    } as Partial<CSSStyleDeclaration>,
    filter: (node: HTMLElement) => !isCaptureExcludedNode(node),
    fetchRequestInit: { cache: 'force-cache' as RequestCache },
  }
}

export type InitTicketStillCaptureOptions = {
  face?: 'front' | 'back'
  /** Used when the live element has no measurable layout box (e.g. invisible overlay). */
  layoutSize?: { width: number; height: number }
}

function forceStillCaptureVisibility(root: HTMLElement) {
  root.style.opacity = '1'
  root.querySelectorAll<HTMLElement>('*').forEach((node) => {
    node.style.opacity = '1'
  })
}

export async function captureInitTicketStillCanvas(
  element: HTMLElement,
  options?: InitTicketStillCaptureOptions,
): Promise<HTMLCanvasElement> {
  return captureInitTicketFaceStillCanvas(
    element,
    options?.face ?? 'front',
    options?.layoutSize,
  )
}

async function captureInitTicketFaceStillCanvas(
  element: HTMLElement,
  face: 'front' | 'back',
  layoutSize?: { width: number; height: number },
): Promise<HTMLCanvasElement> {
  await preloadInitTicketCaptureFonts()
  await preloadImages(element)
  await document.fonts.ready

  const rect = element.getBoundingClientRect()
  let { width, height } = resolveInitTicketStillCaptureSize(
    element.offsetWidth,
    element.offsetHeight,
    rect.width,
    rect.height,
  )
  if ((width < 2 || height < 2) && layoutSize) {
    width = layoutSize.width
    height = layoutSize.height
  }
  const fontEmbedCSS = buildInitTicketCaptureFontEmbedCss()
  const backgroundColor =
    getComputedStyle(element).backgroundColor || 'transparent'

  const sandbox = document.createElement('div')
  sandbox.setAttribute('aria-hidden', 'true')
  sandbox.style.cssText = [
    'position:fixed',
    'left:-10000px',
    'top:0',
    `width:${width}px`,
    `height:${height}px`,
    'pointer-events:none',
    'overflow:hidden',
  ].join(';')

  const clone = element.cloneNode(true) as HTMLElement
  clone.removeAttribute('data-init-ticket-still')
  clone.removeAttribute('data-init-ticket-still-back')
  if (face === 'back') {
    copyInitTicketBackStillLayout(element, clone, { width, height })
  } else {
    copyInitTicketStillLayout(element, clone)
  }
  forceStillCaptureVisibility(clone)
  clone.style.transform = 'none'
  clone.style.position = 'relative'
  clone.style.inset = 'auto'
  clone.style.left = '0'
  clone.style.top = '0'
  clone.style.width = `${width}px`
  clone.style.height = `${height}px`
  clone.style.margin = '0'
  sandbox.appendChild(clone)
  document.body.appendChild(sandbox)

  try {
    await preloadImages(clone)
    await waitForPaint()
    const captureOptions = buildCaptureOptions(
      width,
      height,
      backgroundColor,
      fontEmbedCSS,
    )
    try {
      await toCanvas(clone, captureOptions)
      await document.fonts.ready
    } catch {
      // Warm-up failure should not block export.
    }
    const still = await toCanvas(clone, captureOptions)

    if (face === 'front') {
      const sourceLabel = element.querySelector<HTMLElement>(
        '[data-init-ticket-stub-label]',
      )
      if (sourceLabel) {
        await document.fonts.ready
        await paintInitTicketStubLabelOntoStill(
          still,
          element,
          sourceLabel,
          width,
          height,
        )
      }
    }

    return still
  } finally {
    sandbox.remove()
  }
}
