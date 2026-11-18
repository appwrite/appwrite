import type { GlowLayer, ShadowLayer } from './effects'
import type { FactoryPalette } from './palette'
import { STATION_COUNT, STATION_DELAY, clamp01, type LineState } from './layout'

/** Everything a system needs to draw one frame. */
export type FrameState = {
  /** Factory clock in seconds (frozen while the line powers up, then eased in). */
  time: number
  /** Real seconds since the previous frame. */
  dt: number
  /** Real seconds since the scene booted. Drives the power-up. */
  introTime: number
  line: LineState
  palette: FactoryPalette
  glows: GlowLayer
  shadows: ShadowLayer
  /** Hovered station or -1. */
  highlight: number
  /** Per-station activity 0..1, written by stations, read by the world. */
  levels: number[]
  /** Real shadow maps are on, so fake contact blobs can step back. */
  realShadows: boolean
  /**
   * How far every display's mast rises (0 → 1): one height for all, so no
   * open display meets the page copy laid over the scene.
   */
  lift: number
}

const POWER_ORDER = Array.from({ length: STATION_COUNT }, (_, index) => index)
  .sort((a, b) => STATION_DELAY[a]! - STATION_DELAY[b]!)
  .reduce<number[]>((order, station, position) => {
    order[station] = position
    return order
  }, [])

/** 0 → 1 over `duration` seconds starting at `start` seconds into the intro. */
export function introStep(
  introTime: number,
  start: number,
  duration: number,
): number {
  return clamp01((introTime - start) / duration)
}

/**
 * How far station `index` has powered up, 0 → 1. Stations nearest the core
 * come on first, the way the power wave reaches them every cycle.
 */
export function stationPower(introTime: number, index: number): number {
  return introStep(introTime, 0.35 + POWER_ORDER[index]! * 0.1, 0.55)
}

/** How far the framework installer has powered up: last, as the wave reaches it last. */
export function installPower(introTime: number): number {
  return introStep(introTime, 0.35 + STATION_COUNT * 0.1, 0.55)
}
