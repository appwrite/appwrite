/** Dotted surface behind the ticket - matches the Init ticket section on the page. */
export const INIT_TICKET_VIDEO_SURFACE_CLASS =
  'bg-muted/40 dark:bg-background'

export const INIT_TICKET_VIDEO_DOT_PATTERN_CLASS =
  'bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]'

/** Target frame rate for the exported clip. */
export const INIT_TICKET_VIDEO_EXPORT_FPS = 60
/** Length of the exported video file. */
export const INIT_TICKET_VIDEO_CLIP_DURATION_SEC = 20
/** 1080p 16:9. Even dimensions required for H.264. */
export const INIT_TICKET_VIDEO_EXPORT_WIDTH = 1920
export const INIT_TICKET_VIDEO_EXPORT_HEIGHT = 1080
/** Ticket still capture as a share of overall export progress (0–1). */
export const INIT_TICKET_VIDEO_STILL_PROGRESS_WEIGHT = 0.08
/** CSS `perspective` on InitTicketCard, in CSS pixels of the ticket scene. */
export const INIT_TICKET_VIDEO_CSS_PERSPECTIVE = 1000
/** Ticket width as a fraction of the 16:9 frame (leaves room for 3D tilt). */
export const INIT_TICKET_VIDEO_TICKET_WIDTH_RATIO = 0.72
/** Dot grid spacing in CSS px on the live 820px-wide stage. */
export const INIT_TICKET_VIDEO_DOT_SPACING_CSS_PX = 18
/** Tilt cycles over one exported clip (progress 0→1), outside flip holds. */
export const INIT_TICKET_VIDEO_MOTION_LOOP_CYCLES = 2

/** Normalized timeline: flip to back, hold, flip to front. */
export const INIT_TICKET_VIDEO_FLIP_START = 0.1
export const INIT_TICKET_VIDEO_FLIP_TO_BACK_END = 0.16
export const INIT_TICKET_VIDEO_BACK_HOLD_END = 0.46
export const INIT_TICKET_VIDEO_FLIP_TO_FRONT_END = 0.52

export type InitTicketVideoMotion = {
  x: number
  y: number
  flipY: number
}

const CAPTURE_TILT_MAX_X = 22
const CAPTURE_TILT_MAX_Y = 16

function easeInOutCubic(t: number) {
  const clamped = Math.min(1, Math.max(0, t))
  return clamped < 0.5
    ? 4 * clamped * clamped * clamped
    : 1 - Math.pow(-2 * clamped + 2, 3) / 2
}

/** Matches the live ticket capture tilt path (no flip). */
export function getInitTicketTiltForProgress(t: number) {
  const angle = t * Math.PI * 2 * INIT_TICKET_VIDEO_MOTION_LOOP_CYCLES
  return {
    x: Math.sin(angle) * CAPTURE_TILT_MAX_X * 0.92,
    y: Math.sin(angle * 0.92 + 0.45) * CAPTURE_TILT_MAX_Y,
  }
}

function getInitTicketFlipYForProgress(t: number) {
  if (t < INIT_TICKET_VIDEO_FLIP_START) return 0
  if (t < INIT_TICKET_VIDEO_FLIP_TO_BACK_END) {
    const local =
      (t - INIT_TICKET_VIDEO_FLIP_START) /
      (INIT_TICKET_VIDEO_FLIP_TO_BACK_END - INIT_TICKET_VIDEO_FLIP_START)
    return easeInOutCubic(local) * 180
  }
  if (t < INIT_TICKET_VIDEO_BACK_HOLD_END) return 180
  if (t < INIT_TICKET_VIDEO_FLIP_TO_FRONT_END) {
    const local =
      (t - INIT_TICKET_VIDEO_BACK_HOLD_END) /
      (INIT_TICKET_VIDEO_FLIP_TO_FRONT_END - INIT_TICKET_VIDEO_BACK_HOLD_END)
    return 180 + easeInOutCubic(local) * 180
  }
  return 360
}

/** Full motion path for exported video: tilt wobble + Y-axis flip reveal. */
export function getInitTicketMotionForProgress(t: number): InitTicketVideoMotion {
  const tilt = getInitTicketTiltForProgress(t)
  const flipY = getInitTicketFlipYForProgress(t)
  const edgeFactor = Math.abs(Math.sin((flipY * Math.PI) / 180))
  const tiltScale = 1 - edgeFactor * 0.88

  return {
    x: tilt.x * tiltScale,
    y: tilt.y * tiltScale,
    flipY,
  }
}

/** True when the card back faces the camera (rotateY on the flipper). */
export function isInitTicketVideoShowingBack(rotateYDeg: number) {
  const normalized = ((rotateYDeg % 360) + 360) % 360
  return normalized > 90 && normalized < 270
}

/** Keep projection in the front hemisphere; swap textures instead of rotating past 90deg. */
export function resolveInitTicketVideoProjectionRotateY(rotateYDeg: number) {
  return isInitTicketVideoShowingBack(rotateYDeg) ? rotateYDeg - 180 : rotateYDeg
}
