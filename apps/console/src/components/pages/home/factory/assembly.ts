import { BufferAttribute, type BufferGeometry, type Vector3 } from 'three'
import {
  DONE_SLOT,
  FIRST_X,
  GATE,
  INSTALL_SLOT,
  PITCH,
  clamp01,
  easeInOutCubic,
  range,
} from './layout'

/**
 * The factory assembling itself as the scene first shows, in real seconds
 * from the first frame, the way a real one is installed: every machine
 * comes in from out of view on what it stands on, and slows to a stop in
 * its place. They are already on their way when the scene first shows
 * (their arrivals start before 0), just out of view, so they come into it
 * at once. The line glides in along its bed from past the left edge of
 * the frame, front first, every bay with what stands in it: the gate, the
 * shipped counter, each station (its arm and tool on it) with the carrier
 * under it, the framework installer, the queue. The server racks roll in
 * along the aisle from the back, one after another, and the Console screen
 * rolls in from the right on its base. Then the power cables are pulled out
 * from the Appwrite rack to every machine and the network's out of the
 * gate, and the line powers up.
 */

/**
 * How a machine comes into place: along one axis, from `distance` away (out
 * of view), speeding up and slowing to a stop over `duration`.
 */
export type Arrival = {
  start: number
  duration: number
  /** 0 x, 1 y, 2 z. */
  axis: 0 | 1 | 2
  distance: number
}

/**
 * The line's convoy: every bay travels the same distance, the front one
 * first and each one behind it a little later, so the bays never close up
 * on each other and settle one after another.
 */
const LINE = {
  start: -0.35,
  stagger: 0.05,
  duration: 1.6,
  distance: 22,
} as const
/** The gate's bay, at the front of the line. */
const FRONT = (GATE.start + GATE.length / 2 - FIRST_X) / PITCH

/** How whatever stands in bay `slot` of the line comes into place. */
export function onLine(slot: number): Arrival {
  return {
    start: LINE.start + (FRONT - slot) * LINE.stagger,
    duration: LINE.duration,
    axis: 0,
    distance: -LINE.distance,
  }
}

export const ARRIVALS = {
  /** Racks roll in from the back of the aisle, the Appwrite rack first. */
  rack: (index: number): Arrival => ({
    start: index * 0.07 - 0.5,
    duration: 1.3,
    axis: 2,
    distance: -12,
  }),
  /** The Console screen rolls in from the right. */
  wall: { start: -0.35, duration: 1.3, axis: 0, distance: 10 } as Arrival,
  installer: onLine(INSTALL_SLOT),
  station: (index: number) => onLine(index),
  counter: onLine(DONE_SLOT),
  gate: onLine(FRONT),
} as const

/** How far a machine arriving by `arrival` still is from its place, along its axis. */
export function away(assembly: number, arrival: Arrival): number {
  return (
    arrival.distance *
    (1 - easeInOutCubic(clamp01((assembly - arrival.start) / arrival.duration)))
  )
}

/** Sets `position` to (`x`, `y`, `z`), moved as far as a machine arriving by `arrival` still is. */
export function arrive(
  position: Vector3,
  x: number,
  y: number,
  z: number,
  assembly: number,
  arrival: Arrival,
): Vector3 {
  position.set(x, y, z)
  position.setComponent(
    arrival.axis,
    position.getComponent(arrival.axis) + away(assembly, arrival),
  )
  return position
}

/** The power cables are pulled out from the Appwrite rack once the machines are in. */
const CABLES = { start: 1.2, end: 1.9 } as const
/** The network's cables run out of the gate once it is in, gathering speed (meters, seconds). */
const NETWORK_RUN = { start: 1.3, speed: 4, gain: 20 } as const

/** When the line starts powering up: it starts moving once the queue is in. */
export const POWER_ON = 1.7
/** Every machine stands in place: displays may unfold. */
export const ASSEMBLED = 1.9
/** Every cable has run out: nothing of the assembly moves after this. */
export const SETTLED = 3.2

/** How far the power cables have been pulled out, 0 → 1 along each. */
export function cablesOut(assembly: number): number {
  return easeInOutCubic(range(assembly, CABLES.start, CABLES.end))
}

/** How far the network's cables have run out of the gate (meters; negative before they start). */
export function networkOut(assembly: number): number {
  const since = assembly - NETWORK_RUN.start
  return since < 0
    ? -1
    : since * NETWORK_RUN.speed + since * since * NETWORK_RUN.gain
}

/**
 * Marks `geometry` as part of a machine that comes into place by `arrival`
 * (`aArrive`: start, duration, distance, axis; the kit's `finish` material
 * moves it).
 */
export function arriving(
  geometry: BufferGeometry,
  arrival: Arrival,
): BufferGeometry {
  const count = geometry.getAttribute('position').count
  const values = new Float32Array(count * 4)
  for (let vertex = 0; vertex < count; vertex++) {
    values.set(
      [arrival.start, arrival.duration, arrival.distance, arrival.axis],
      vertex * 4,
    )
  }
  geometry.setAttribute('aArrive', new BufferAttribute(values, 4))
  return geometry
}

/**
 * The same arrival in GLSL, for a vertex shader (`transformed` is in the
 * mesh's own space; the line's merged hardware stands at the origin):
 * declarations, then the step that moves a part still on its way.
 */
export const ARRIVE_GLSL = {
  pars: /* glsl */ `
    attribute vec4 aArrive;
    uniform float uAssembly;`,
  vertex: /* glsl */ `
    if (aArrive.y > 0.0) {
      float arrived = clamp((uAssembly - aArrive.x) / aArrive.y, 0.0, 1.0);
      arrived = arrived < 0.5
        ? 4.0 * arrived * arrived * arrived
        : 1.0 - pow(-2.0 * arrived + 2.0, 3.0) / 2.0;
      float away = aArrive.z * (1.0 - arrived);
      if (aArrive.w < 0.5) transformed.x += away;
      else if (aArrive.w < 1.5) transformed.y += away;
      else transformed.z += away;
    }`,
} as const
