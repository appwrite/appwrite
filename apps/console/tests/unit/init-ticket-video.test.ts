import { describe, expect, test } from 'bun:test'
import {
  INIT_TICKET_VIDEO_BACK_HOLD_END,
  INIT_TICKET_VIDEO_FLIP_START,
  INIT_TICKET_VIDEO_FLIP_TO_BACK_END,
  INIT_TICKET_VIDEO_FLIP_TO_FRONT_END,
  getInitTicketMotionForProgress,
  getInitTicketTiltForProgress,
  isInitTicketVideoShowingBack,
  resolveInitTicketVideoProjectionRotateY,
} from '@/lib/init/ticket-video-capture'
import {
  centerTicketVideoQuadInFrame,
  initTicketVideoTextureVMax,
  initTicketVideoTextureVMin,
  projectInitTicketPoint,
  projectInitTicketQuad,
  projectInitTicketVisibleQuad,
} from '@/lib/init/ticket-video-perspective'
import {
  computeInitTicketStubLabelOrigin,
  computeInitTicketStubLayoutBox,
  mapInitTicketVisualRectToStillCanvas,
  resolveInitTicketStillCaptureSize,
} from '@/lib/init/ticket-video-still'
import { initTicketDisplayAspectRatio } from '@/lib/init/ticket-layout'

describe('init ticket video perspective', () => {
  test('identity rotation keeps the rectangle axis-aligned', () => {
    const [tl, tr, br, bl] = projectInitTicketQuad(200, 100, 0, 0, 1000)

    expect(tl.x).toBeCloseTo(0, 5)
    expect(tl.y).toBeCloseTo(0, 5)
    expect(tr.x).toBeCloseTo(200, 5)
    expect(tr.y).toBeCloseTo(0, 5)
    expect(br.x).toBeCloseTo(200, 5)
    expect(br.y).toBeCloseTo(100, 5)
    expect(bl.x).toBeCloseTo(0, 5)
    expect(bl.y).toBeCloseTo(100, 5)
  })

  test('rotateY moves the right edge closer together than the left', () => {
    const [, tr, br] = projectInitTicketQuad(200, 100, 0, 18, 1000)
    const rightHeight = Math.abs(br.y - tr.y)

    expect(rightHeight).toBeLessThan(100)
    expect(tr.x).toBeGreaterThan(100)
  })

  test('projected interior points stay inside the outer quad', () => {
    const center = projectInitTicketPoint(100, 50, 200, 100, 12, -10, 1000)
    const [tl, tr, br, bl] = projectInitTicketQuad(200, 100, 12, -10, 1000)
    const minX = Math.min(tl.x, tr.x, br.x, bl.x)
    const maxX = Math.max(tl.x, tr.x, br.x, bl.x)
    const minY = Math.min(tl.y, tr.y, br.y, bl.y)
    const maxY = Math.max(tl.y, tr.y, br.y, bl.y)

    expect(center.x).toBeGreaterThan(minX)
    expect(center.x).toBeLessThan(maxX)
    expect(center.y).toBeGreaterThan(minY)
    expect(center.y).toBeLessThan(maxY)
  })

  test('visible quad uses trimmed bottom edge and display aspect', () => {
    const displayWidth = 820
    const [tl, , br, bl] = projectInitTicketVisibleQuad(displayWidth, 0, 0, 1000)
    const visibleHeight = br.y - tl.y
    const displayHeight = displayWidth / initTicketDisplayAspectRatio()

    expect(Math.abs(visibleHeight - displayHeight)).toBeLessThan(1)
    expect(bl.x).toBeCloseTo(tl.x, 5)
    expect(br.x - tl.x).toBeCloseTo(displayWidth, 1)
  })

  test('centers tilted ticket bbox in export frame', () => {
    const [tl, tr, br, bl] = projectInitTicketVisibleQuad(820, 12, -10, 1000)
    const quad = centerTicketVideoQuadInFrame([tl, tr, br, bl], 1920, 1080)
    const xs = quad.map((point) => point.x)
    const ys = quad.map((point) => point.y)

    expect((Math.min(...xs) + Math.max(...xs)) / 2).toBeCloseTo(960, 0)
    expect((Math.min(...ys) + Math.max(...ys)) / 2).toBeCloseTo(540, 0)
  })

  test('texture V bounds match bottom trim in canvas and WebGL space', () => {
    expect(initTicketVideoTextureVMax()).toBeCloseTo(0.95, 5)
    expect(initTicketVideoTextureVMin()).toBeCloseTo(0.05, 5)
    expect(initTicketVideoTextureVMax() + initTicketVideoTextureVMin()).toBeCloseTo(1, 5)
  })
})

describe('init ticket video tilt', () => {
  test('loops x tilt through a full motion cycle', () => {
    const start = getInitTicketTiltForProgress(0)
    const mid = getInitTicketTiltForProgress(0.25)
    const end = getInitTicketTiltForProgress(1)

    expect(start.x).toBeCloseTo(0, 5)
    expect(start.y).not.toBe(0)
    expect(Math.abs(mid.x)).toBeGreaterThan(Math.abs(start.x))
    expect(end.x).toBeCloseTo(start.x, 5)
    expect(Number.isFinite(end.y)).toBe(true)
  })

  test('flip motion reveals the back, holds, then returns to front', () => {
    const beforeFlip = getInitTicketMotionForProgress(INIT_TICKET_VIDEO_FLIP_START - 0.01)
    const onBack = getInitTicketMotionForProgress(
      (INIT_TICKET_VIDEO_FLIP_TO_BACK_END + INIT_TICKET_VIDEO_BACK_HOLD_END) / 2,
    )
    const afterFlip = getInitTicketMotionForProgress(
      INIT_TICKET_VIDEO_FLIP_TO_FRONT_END + 0.01,
    )

    expect(beforeFlip.flipY).toBeCloseTo(0, 5)
    expect(isInitTicketVideoShowingBack(beforeFlip.flipY + beforeFlip.y)).toBe(false)
    expect(onBack.flipY).toBeCloseTo(180, 5)
    expect(isInitTicketVideoShowingBack(onBack.flipY + onBack.y)).toBe(true)
    expect(afterFlip.flipY).toBeCloseTo(360, 5)
    expect(isInitTicketVideoShowingBack(afterFlip.flipY + afterFlip.y)).toBe(false)
  })

  test('back hold keeps projection y in the front hemisphere', () => {
    const onBack = getInitTicketMotionForProgress(
      (INIT_TICKET_VIDEO_FLIP_TO_BACK_END + INIT_TICKET_VIDEO_BACK_HOLD_END) / 2,
    )
    const rotateY = onBack.flipY + onBack.y
    const projectionRotateY = resolveInitTicketVideoProjectionRotateY(rotateY)

    expect(isInitTicketVideoShowingBack(rotateY)).toBe(true)
    expect(Math.abs(projectionRotateY)).toBeLessThan(25)
  })
})

describe('init ticket still capture size', () => {
  test('uses layout pixels when the ticket is visually scaled down', () => {
    const size = resolveInitTicketStillCaptureSize(820, 546, 574, 382)

    expect(size.width).toBe(820)
    expect(size.height).toBe(546)
  })

  test('computes stub column layout box from ticket dimensions', () => {
    const height = Math.round(820 / initTicketDisplayAspectRatio())
    const box = computeInitTicketStubLayoutBox(820, height)

    expect(box.stubW).toBeGreaterThan(100)
    expect(box.contentH).toBeGreaterThan(100)
    expect(box.stubColX + box.stubW).toBeLessThanOrEqual(820)
    expect(box.stubColY).toBeGreaterThan(0)
  })

  test('stub label origin matches live inset pivot', () => {
    const height = Math.round(820 / initTicketDisplayAspectRatio())
    const box = computeInitTicketStubLayoutBox(820, height)
    const origin = computeInitTicketStubLabelOrigin(820, height)

    expect(origin.x).toBeCloseTo(box.stubColX + box.stubW * 0.52, 5)
    expect(origin.y).toBeCloseTo(box.stubColY + box.contentH * 0.92, 5)
    expect(origin.y).toBeLessThan(box.stubColY + box.contentH)
    expect(origin.x).toBeGreaterThan(box.stubColX)
  })

  test('maps scaled stub visuals to layout still coordinates', () => {
    const ticketLayoutWidth = 820
    const ticketVisualWidth = 574
    const pixelRatio = 2
    const placement = mapInitTicketVisualRectToStillCanvas(
      ticketLayoutWidth,
      ticketVisualWidth,
      pixelRatio,
      { left: 420, top: 80, width: 140, height: 320 },
      { left: 100, top: 40 },
    )
    const layoutScale = ticketLayoutWidth / ticketVisualWidth

    expect(placement.x).toBeCloseTo((420 - 100) * layoutScale * pixelRatio, 0)
    expect(placement.y).toBeCloseTo((80 - 40) * layoutScale * pixelRatio, 0)
    expect(placement.width).toBeCloseTo(140 * layoutScale * pixelRatio, 0)
    expect(placement.height).toBeCloseTo(320 * layoutScale * pixelRatio, 0)
  })
})
