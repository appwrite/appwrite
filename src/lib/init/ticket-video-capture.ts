/** Dotted surface behind the ticket - matches the Init ticket section on the page. */
export const INIT_TICKET_VIDEO_SURFACE_CLASS =
  'bg-muted/40 dark:bg-background'

export const INIT_TICKET_VIDEO_DOT_PATTERN_CLASS =
  'bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]'

/** Let the hero particle loop settle before the first captured frame. */
export const INIT_TICKET_VIDEO_HERO_WARMUP_MS = 550

/** Target frame rate for tab element capture (Chrome & Edge). */
export const INIT_TICKET_VIDEO_EXPORT_FPS = 60
/** Canvas snapshot fallback - encode/replay target fps for the exported file. */
export const INIT_TICKET_VIDEO_FALLBACK_FPS = 60
/** Length of the exported video file. */
export const INIT_TICKET_VIDEO_CLIP_DURATION_SEC = 10
/**
 * Wall-clock capture duration before time-compression into
 * {@link INIT_TICKET_VIDEO_CLIP_DURATION_SEC}. Longer capture = more source
 * frames / motion samples for smoother 60fps output.
 */
export const INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC = 40
/** @deprecated Use {@link INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC}. */
export const INIT_TICKET_VIDEO_FALLBACK_CAPTURE_WALL_CLOCK_SEC =
  INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC
/** Live capture phase weight in overall export progress (0–1). */
export const INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT = 0.72
/** Hero particle motion during export (< 1 = slower background drift). */
export const INIT_TICKET_VIDEO_HERO_MOTION_SPEED = 0.38
/** Tilt cycles over one exported clip (progress 0→1). */
export const INIT_TICKET_VIDEO_MOTION_LOOP_CYCLES = 2
