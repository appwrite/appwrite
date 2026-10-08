export type Vec = { x: number; y: number }

export type SpiderTarget = {
  element: HTMLElement | null
  point: Vec
}

export type SpiderBounds = {
  minX: number
  maxX: number
  minY: number
  maxY: number
}

const TARGET_SELECTOR = [
  'button',
  'a[href]',
  '[role="tab"]',
  '[role="switch"]',
  'input:not([type="hidden"])',
  'h1',
  'h2',
  'h3',
].join(', ')

const REACTIVE_SELECTOR = `${TARGET_SELECTOR}, img, [data-slot="badge"]`

/** Never walk onto or animate the overlay itself, open dialogs, or toasts. */
const IGNORE_SELECTOR =
  '[data-firewall-spider], [role="dialog"], [role="alertdialog"], [data-sonner-toaster]'

const MIN_HOP_PX = 130
const MAX_HOP_PX = 560
const IDEAL_HOP_PX = 290
/** Footstep dips skip big surfaces so whole layouts never jiggle. */
const MAX_DIP_AREA_PX = 90_000
const DIP_COOLDOWN_MS = 520
const TUG_MAX_OFFSET_PX = 8

const dipCooldowns = new WeakMap<Element, number>()

function animateAdditive(
  element: Element,
  keyframes: Keyframe[],
  options: KeyframeAnimationOptions,
): Animation | null {
  try {
    // Additive so the element keeps its own transform (centering, etc.).
    return element.animate(keyframes, { ...options, composite: 'add' })
  } catch {
    return null
  }
}

function isUsableElement(element: Element): element is HTMLElement {
  return element instanceof HTMLElement && !element.closest(IGNORE_SELECTOR)
}

function isUnobstructed(element: HTMLElement, x: number, y: number): boolean {
  const hit = document.elementFromPoint(x, y)
  return !!hit && (hit === element || element.contains(hit))
}

/** Pick the next real UI element to crawl to, roughly one hop away. */
export function pickNextTarget(
  from: Vec,
  visited: Set<Element>,
  bounds: SpiderBounds,
): SpiderTarget {
  const candidates: Array<{
    element: HTMLElement
    point: Vec
    score: number
  }> = []

  for (const node of document.querySelectorAll(TARGET_SELECTOR)) {
    if (visited.has(node) || !isUsableElement(node)) continue
    const rect = node.getBoundingClientRect()
    if (rect.width < 14 || rect.height < 12) continue
    if (rect.width > 520 || rect.height > 160) continue
    const point = {
      x: rect.left + rect.width / 2,
      y: rect.top + rect.height / 2,
    }
    if (point.x < bounds.minX || point.x > bounds.maxX) continue
    if (point.y < bounds.minY || point.y > bounds.maxY) continue
    const distance = Math.hypot(point.x - from.x, point.y - from.y)
    if (distance < MIN_HOP_PX || distance > MAX_HOP_PX) continue
    candidates.push({
      element: node,
      point,
      score: Math.abs(distance - IDEAL_HOP_PX) + Math.random() * 140,
    })
  }

  candidates.sort((a, b) => a.score - b.score)
  for (const candidate of candidates.slice(0, 10)) {
    if (
      isUnobstructed(candidate.element, candidate.point.x, candidate.point.y)
    ) {
      return { element: candidate.element, point: candidate.point }
    }
  }

  // Nothing suitable on screen: wander to an open spot instead.
  const angle = Math.random() * Math.PI * 2
  return {
    element: null,
    point: {
      x: clamp(
        from.x + Math.cos(angle) * IDEAL_HOP_PX,
        bounds.minX,
        bounds.maxX,
      ),
      y: clamp(
        from.y + Math.sin(angle) * IDEAL_HOP_PX,
        bounds.minY,
        bounds.maxY,
      ),
    },
  }
}

/** The spider prods an element with its front legs. */
export function pokeElement(element: HTMLElement, direction: Vec): void {
  if (!element.isConnected) return
  const dx = direction.x * 4
  const dy = direction.y * 4

  if (/^H[1-3]$/.test(element.tagName)) {
    animateAdditive(
      element,
      [
        { transform: 'translate(0px, 0px) rotate(0deg)' },
        {
          transform: `translate(${dx * 0.6}px, ${dy * 0.6}px) rotate(-1.6deg)`,
          offset: 0.2,
        },
        { transform: 'translate(0px, 0px) rotate(1.1deg)', offset: 0.45 },
        { transform: 'translate(0px, 0px) rotate(-0.5deg)', offset: 0.7 },
        { transform: 'translate(0px, 0px) rotate(0deg)' },
      ],
      { duration: 820, easing: 'ease-out' },
    )
    return
  }

  animateAdditive(
    element,
    [
      { transform: 'translate(0px, 0px) scale(1)' },
      { transform: `translate(${dx}px, ${dy}px) scale(0.95)`, offset: 0.22 },
      {
        transform: `translate(${-dx * 0.35}px, ${-dy * 0.35}px) scale(1.02)`,
        offset: 0.55,
      },
      { transform: 'translate(0px, 0px) scale(0.995)', offset: 0.8 },
      { transform: 'translate(0px, 0px) scale(1)' },
    ],
    { duration: 560, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)' },
  )
}

/** A foot lands on whatever control sits under it. */
export function dipUnderFoot(point: Vec): void {
  const hit = document.elementFromPoint(point.x, point.y)
  const element = hit?.closest(REACTIVE_SELECTOR)
  if (!element || !isUsableElement(element)) return

  const rect = element.getBoundingClientRect()
  if (rect.width * rect.height > MAX_DIP_AREA_PX) return

  const now = performance.now()
  if ((dipCooldowns.get(element) ?? 0) > now) return
  dipCooldowns.set(element, now + DIP_COOLDOWN_MS)

  animateAdditive(
    element,
    [
      { transform: 'translate(0px, 0px) scale(1)' },
      { transform: 'translate(0px, 1.5px) scale(0.985)', offset: 0.3 },
      { transform: 'translate(0px, 0px) scale(1)' },
    ],
    { duration: 300, easing: 'ease-out' },
  )
}

export type SilkTug = {
  element: HTMLElement
  /** Current center of the element, including the tug offset. */
  anchor: () => Vec
  /** 0 = slack, 1 = fully stretched. */
  setTension: (tension: number) => void
  /** Snap the strand; the element springs back past its origin. */
  snap: () => void
}

/** Silk stuck to an element pulls it along as the spider walks away. */
export function attachSilk(element: HTMLElement, direction: Vec): SilkTug {
  const offsetX = direction.x * TUG_MAX_OFFSET_PX
  const offsetY = direction.y * TUG_MAX_OFFSET_PX
  const tilt = Math.sign(direction.x || 1) * 1.4
  const pull = animateAdditive(
    element,
    [
      { transform: 'translate(0px, 0px) rotate(0deg)' },
      { transform: `translate(${offsetX}px, ${offsetY}px) rotate(${tilt}deg)` },
    ],
    { duration: 1000, fill: 'both' },
  )
  pull?.pause()

  let tension = 0
  let snapped = false

  return {
    element,
    anchor: () => {
      const rect = element.getBoundingClientRect()
      return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 }
    },
    setTension: (next) => {
      if (snapped || !pull) return
      tension = clamp(next, 0, 1)
      pull.currentTime = easeOutQuad(tension) * 1000
    },
    snap: () => {
      if (snapped) return
      snapped = true
      pull?.cancel()
      if (!element.isConnected || tension < 0.05) return
      const amount = easeOutQuad(tension)
      const x = offsetX * amount
      const y = offsetY * amount
      const r = tilt * amount
      animateAdditive(
        element,
        [
          { transform: `translate(${x}px, ${y}px) rotate(${r}deg)` },
          {
            transform: `translate(${-x * 0.45}px, ${-y * 0.45}px) rotate(${-r * 0.5}deg)`,
            offset: 0.3,
          },
          {
            transform: `translate(${x * 0.18}px, ${y * 0.18}px) rotate(${r * 0.2}deg)`,
            offset: 0.6,
          },
          { transform: 'translate(0px, 0px) rotate(0deg)' },
        ],
        { duration: 680, easing: 'ease-out' },
      )
    },
  }
}

export function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

function easeOutQuad(t: number): number {
  return 1 - (1 - t) * (1 - t)
}
