/** Colors resolved from the active console theme (hex, sRGB). */
export type Init5ScenePalette = {
  isDark: boolean
  background: string
  foreground: string
  /** `--brand-cta` (Appwrite pink on light/dark). */
  accent: string
  /** `--network-globe-edge` (brand violet). */
  violet: string
  /** `--network-globe-region` (brand peach). */
  peach: string
}

/**
 * Where the 5 sits inside the hero, normalized to the container
 * (0-1 from the top-left). `height` is the glyph height as a fraction of the
 * container height.
 */
export type Init5SceneLayout = {
  cx: number
  cy: number
  height: number
}

export type Init5SceneQuality = 'high' | 'low'

export type Init5SceneOptions = {
  canvas: HTMLCanvasElement
  width: number
  height: number
  palette: Init5ScenePalette
  layout: Init5SceneLayout
  quality: Init5SceneQuality
  reducedMotion: boolean
  /** Fired after the first frame is on screen (used to fade the canvas in). */
  onReady?: () => void
}

export type Init5SceneController = {
  /** Pointer in container space (0-1), or `null` when it leaves the hero. */
  setPointer: (x: number | null, y: number | null) => void
  /** Click / tap impulse at container-space coordinates. */
  pulse: (x: number, y: number) => void
  /** 0 while the hero is fully visible, 1 once it has scrolled away. */
  setScrollProgress: (progress: number) => void
  setPalette: (palette: Init5ScenePalette) => void
  setLayout: (layout: Init5SceneLayout) => void
  resize: (width: number, height: number) => void
  /** Pause / resume the render loop (offscreen, hidden tab, video capture). */
  setActive: (active: boolean) => void
  replayIntro: () => void
  /**
   * Freeze on the frame `seconds` into the intro (design review / tests).
   * `null` unfreezes and resumes the live loop.
   */
  seek: (seconds: number | null) => void
  dispose: () => void
}

export type Init5SceneFactory = (
  options: Init5SceneOptions,
) => Init5SceneController
