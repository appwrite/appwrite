import {
  attachSilk,
  clamp,
  dipUnderFoot,
  pickNextTarget,
  pokeElement,
  type SilkTug,
  type SpiderBounds,
  type SpiderTarget,
  type Vec,
} from './spider-targets'

export type SpiderSceneRefs = {
  root: HTMLElement
  spider: SVGGElement
  abdomen: SVGGElement
  femurs: SVGPathElement[]
  tibias: SVGPathElement[]
  rope: SVGPathElement
  dragline: SVGLineElement
  silk: SVGPathElement
  card: HTMLElement
  hitTarget: HTMLElement
}

export type SpiderSceneCallbacks = {
  onBlocked: (position: Vec) => void
  onDone: () => void
}

export type SpiderSceneController = {
  /** Firewall blocks the crawler: it recoils and flees off screen. */
  block: () => void
  /** The crawler calmly walks off screen. */
  leave: () => void
  /** Hold still while the user hovers or focuses the card. */
  setHeld: (held: boolean) => void
  destroy: () => void
}

type Phase = 'descend' | 'walk' | 'inspect' | 'exit' | 'flee' | 'done'

type Leg = {
  hip: Vec
  rest: Vec
  femur: number
  tibia: number
  group: 0 | 1
  front: boolean
  foot: Vec
  lift: number
  step: { from: Vec; to: Vec; t: number; duration: number } | null
}

const WALK_SPEED = 135
const EXIT_SPEED = 210
const FLEE_SPEED = 700
const STOPS = 8
const INSPECT_SECONDS = 1.9
const POKE_AT_SECONDS = 0.6
const TAP_START_SECONDS = 0.3
const TAP_END_SECONDS = 1.0
const DESCEND_SECONDS = 1.9
const LAND_Y = 150
const START_Y = -70
const EDGE_MARGIN = 190
const DRAGLINE_RETRACT_SECONDS = 0.5
const SILK_RECOIL_SECONDS = 0.22
const SILK_SLACK_PX = 24
const SILK_SNAP_PX = 130

/** Abdomen tip in body space (body faces +x). */
const SPINNERET: Vec = { x: -27, y: 0 }

const ROPE_SEGMENTS = 16
const CARD_REACH_PX = 116
/** Spare silk beyond the taut reach, so the string can bow and curl. */
const ROPE_SEGMENT_PX = (CARD_REACH_PX * 1.08) / ROPE_SEGMENTS
const ROPE_STEP_SECONDS = 1 / 120
const ROPE_ITERATIONS = 8
/** Below 1 the tether stretches slightly, so the card bounces. */
const TETHER_STIFFNESS = 0.35
/**
 * Silk catches far more air than the card, so it lags behind and bows into a
 * curve while the spider moves instead of hanging as a straight line.
 */
const SILK_DAMPING = 0.95
const CARD_DAMPING = 0.988
const GRAVITY = 1500
const BREEZE = 130
/** Keeps the message readable mid-swing (about 20 degrees). */
const CARD_MAX_ANGLE = 0.36
const SILK_SAG_PX = 22

/** One side of the spider, front to back. Mirrored for the other side. */
const LEG_LAYOUT: ReadonlyArray<{ hip: Vec; rest: Vec }> = [
  { hip: { x: 6, y: 3.5 }, rest: { x: 31, y: 21 } },
  { hip: { x: 3, y: 5 }, rest: { x: 13, y: 32 } },
  { hip: { x: -0.5, y: 5 }, rest: { x: -9, y: 32 } },
  { hip: { x: -3.5, y: 3.5 }, rest: { x: -26, y: 24 } },
]

function createLegs(): Leg[] {
  const legs: Leg[] = []
  for (const side of [-1, 1] as const) {
    LEG_LAYOUT.forEach((layout, index) => {
      const hip = { x: layout.hip.x, y: layout.hip.y * side }
      const rest = { x: layout.rest.x, y: layout.rest.y * side }
      const reach = Math.hypot(rest.x - hip.x, rest.y - hip.y)
      legs.push({
        hip,
        rest,
        femur: reach * 0.56,
        tibia: reach * 0.62,
        // Alternating tetrapod gait: L1 R2 L3 R4 step together, then the rest.
        group: ((index + (side === 1 ? 1 : 0)) % 2) as 0 | 1,
        front: index === 0,
        foot: { x: 0, y: 0 },
        lift: 0,
        step: null,
      })
    })
  }
  return legs
}

export function createSpiderScene(
  refs: SpiderSceneRefs,
  callbacks: SpiderSceneCallbacks,
): SpiderSceneController {
  const startX = clamp(window.innerWidth * 0.62, 240, window.innerWidth - 260)
  const legs = createLegs()
  const visited = new Set<Element>()

  let phase: Phase = 'descend'
  let phaseTime = 0
  let time = 0
  const pos: Vec = { x: startX, y: START_Y }
  let vel: Vec = { x: 0, y: 0 }
  let heading = Math.PI / 2
  let sway = 0
  let target: SpiderTarget | null = null
  let stopsLeft = STOPS
  let poked = false
  let held = false
  let modalOpen = false
  let nextModalCheck = 0
  let exitPoint: Vec = { x: 0, y: 0 }
  let draglineRetract = -1
  let tug: SilkTug | null = null
  let silkRecoil: { from: Vec; t: number } | null = null
  let silkTension = 0
  let ropeAccumulator = 0
  let cardAngle = 0
  let cardWidth = refs.card.offsetWidth || 252
  let lastFrame = performance.now()
  let frameId = 0

  const toWorld = (local: Vec): Vec => {
    const c = Math.cos(heading)
    const s = Math.sin(heading)
    return {
      x: pos.x + local.x * c - local.y * s,
      y: pos.y + local.x * s + local.y * c,
    }
  }

  const spawn = toWorld(SPINNERET)
  const rope: Vec[] = Array.from({ length: ROPE_SEGMENTS + 1 }, (_, i) => ({
    x: spawn.x,
    y: spawn.y + i * ROPE_SEGMENT_PX,
  }))
  const ropePrev: Vec[] = rope.map((point) => ({ ...point }))
  const card: Vec = { x: spawn.x, y: spawn.y + CARD_REACH_PX }
  const cardPrev: Vec = { ...card }

  const bounds = (): SpiderBounds => {
    const minY = 96
    return {
      minX: 80,
      maxX: window.innerWidth - 180,
      minY,
      maxY: Math.max(minY + 40, window.innerHeight - 330),
    }
  }

  const setPhase = (next: Phase) => {
    phase = next
    phaseTime = 0
  }

  const chooseTarget = () => {
    target = pickNextTarget(pos, visited, bounds())
    if (target.element) visited.add(target.element)
    poked = false
  }

  const beginExit = (next: 'exit' | 'flee') => {
    const toLeft = pos.x < window.innerWidth / 2
    exitPoint = {
      x: toLeft ? -EDGE_MARGIN : window.innerWidth + EDGE_MARGIN,
      y: clamp(pos.y - (next === 'flee' ? 60 : 20), 40, window.innerHeight),
    }
    setPhase(next)
  }

  const snapSilk = () => {
    if (!tug) return
    silkRecoil = { from: tug.anchor(), t: 0 }
    tug.snap()
    tug = null
  }

  const land = () => {
    for (const leg of legs) {
      leg.step = {
        from: leg.foot,
        to: toWorld(leg.rest),
        t: 0,
        duration: 0.16,
      }
    }
    draglineRetract = 0
    vel = { x: 0, y: 0 }
    setPhase('walk')
    chooseTarget()
  }

  const seek = (
    point: Vec,
    maxSpeed: number,
    responsiveness: number,
    dt: number,
    options: { wander?: boolean; ignoreHold?: boolean } = {},
  ): number => {
    const dx = point.x - pos.x
    const dy = point.y - pos.y
    const distance = Math.hypot(dx, dy)
    const stopped = !options.ignoreHold && (held || modalOpen)
    const speed = stopped ? 0 : maxSpeed * clamp(distance / 90, 0.2, 1)
    let angle = Math.atan2(dy, dx)
    if (options.wander && distance > 120) {
      angle += Math.sin(time * 1.7) * 0.38
    }
    const k = Math.min(1, dt * responsiveness)
    vel = {
      x: vel.x + (Math.cos(angle) * speed - vel.x) * k,
      y: vel.y + (Math.sin(angle) * speed - vel.y) * k,
    }
    return distance
  }

  const isOffscreen = (point: Vec, margin: number) =>
    point.x < -margin || point.x > window.innerWidth + margin

  const finish = () => {
    if (phase === 'done') return
    phase = 'done'
    snapSilk()
    cancelAnimationFrame(frameId)
    callbacks.onDone()
  }

  const updateBehavior = (dt: number) => {
    time += dt
    const paused =
      (held || modalOpen) && (phase === 'walk' || phase === 'inspect')
    if (!paused) phaseTime += dt

    switch (phase) {
      case 'descend': {
        const p = Math.min(1, phaseTime / DESCEND_SECONDS)
        const previous = { ...pos }
        pos.y = START_Y + (LAND_Y - START_Y) * easeOutBack(p)
        pos.x = startX + Math.sin(time * 2.4) * 4 * (1 - p)
        vel = {
          x: (pos.x - previous.x) / dt,
          y: (pos.y - previous.y) / dt,
        }
        if (phaseTime >= DESCEND_SECONDS + 0.25) land()
        break
      }
      case 'walk': {
        if (!target || (target.element && !target.element.isConnected)) {
          chooseTarget()
        }
        if (!target) break
        if (target.element) {
          const rect = target.element.getBoundingClientRect()
          target.point = {
            x: rect.left + rect.width / 2,
            y: rect.top + rect.height / 2,
          }
        }
        const distance = seek(target.point, WALK_SPEED, 5, dt, {
          wander: true,
        })
        if (distance < 6) {
          vel = { x: 0, y: 0 }
          setPhase('inspect')
        }
        break
      }
      case 'inspect': {
        vel = { x: vel.x * 0.8, y: vel.y * 0.8 }
        if (!poked && phaseTime >= POKE_AT_SECONDS) {
          poked = true
          if (target?.element) {
            pokeElement(target.element, {
              x: Math.cos(heading),
              y: Math.sin(heading),
            })
          }
        }
        if (phaseTime >= INSPECT_SECONDS) {
          const previous = target
          stopsLeft -= 1
          const exiting = stopsLeft <= 0
          if (exiting) {
            beginExit('exit')
          } else {
            chooseTarget()
            setPhase('walk')
          }
          const next = !exiting && target ? target.point : exitPoint
          if (previous?.element?.isConnected) {
            const dx = next.x - pos.x
            const dy = next.y - pos.y
            const length = Math.hypot(dx, dy) || 1
            snapSilk()
            tug = attachSilk(previous.element, {
              x: dx / length,
              y: dy / length,
            })
          }
        }
        break
      }
      case 'exit':
      case 'flee': {
        const fleeing = phase === 'flee'
        seek(
          exitPoint,
          fleeing ? FLEE_SPEED : EXIT_SPEED,
          fleeing ? 9 : 4,
          dt,
          { ignoreHold: fleeing },
        )
        const cardTop = rope[ROPE_SEGMENTS]
        if (isOffscreen(pos, 90) && isOffscreen(cardTop, cardWidth / 2 + 60)) {
          finish()
        }
        break
      }
    }
  }

  const updateBody = (dt: number) => {
    if (phase === 'descend') return
    pos.x += vel.x * dt
    pos.y += vel.y * dt

    let desired = heading
    if (phase === 'inspect' && target) {
      const dx = target.point.x - pos.x
      const dy = target.point.y - pos.y
      if (Math.hypot(dx, dy) > 2) desired = Math.atan2(dy, dx)
    } else if (Math.hypot(vel.x, vel.y) > 10) {
      desired = Math.atan2(vel.y, vel.x)
    }
    const turn =
      wrapAngle(desired - heading) *
      Math.min(1, dt * (phase === 'flee' ? 14 : 7))
    heading += turn
    // The abdomen lags behind turns, which sells the weight of the body.
    const swayTarget = clamp((-turn / dt) * 0.09, -0.4, 0.4)
    sway += (swayTarget - sway) * Math.min(1, dt * 6)
  }

  const updateLegs = (dt: number) => {
    if (phase === 'descend') {
      legs.forEach((leg, index) => {
        const curl = 0.7 + Math.sin(time * 5 + index) * 0.05
        leg.foot = toWorld({
          x: leg.hip.x + (leg.rest.x - leg.hip.x) * curl,
          y: leg.hip.y + (leg.rest.y - leg.hip.y) * curl,
        })
        leg.lift = 0.6
      })
      return
    }

    const speed = Math.hypot(vel.x, vel.y)
    const stepDuration = clamp(0.15 - speed / 5200, 0.06, 0.15)
    const tapping =
      phase === 'inspect' &&
      phaseTime >= TAP_START_SECONDS &&
      phaseTime <= TAP_END_SECONDS
    const landed: Leg[] = []

    for (const leg of legs) {
      if (!leg.step) {
        leg.lift = 0
        continue
      }
      leg.step.t = Math.min(1, leg.step.t + dt / leg.step.duration)
      const eased = easeInOutSine(leg.step.t)
      leg.foot = {
        x: leg.step.from.x + (leg.step.to.x - leg.step.from.x) * eased,
        y: leg.step.from.y + (leg.step.to.y - leg.step.from.y) * eased,
      }
      leg.lift = Math.sin(Math.PI * leg.step.t)
      if (leg.step.t >= 1) {
        leg.step = null
        landed.push(leg)
      }
    }

    if (tapping) {
      for (const leg of legs) {
        if (!leg.front) continue
        const phaseOffset = leg.rest.y > 0 ? Math.PI : 0
        const tap = Math.max(
          0,
          Math.sin((phaseTime - TAP_START_SECONDS) * 22 + phaseOffset),
        )
        leg.step = null
        leg.foot = toWorld({
          x: leg.rest.x + 5 - tap * 7,
          y: leg.rest.y * 0.55,
        })
        leg.lift = tap
      }
    }

    const isFree = (leg: Leg) => !(tapping && leg.front)
    const groupStepping = [0, 1].map((group) =>
      legs.some((leg) => leg.group === group && leg.step),
    )
    const threshold = speed > 15 ? 13 : 3.5
    let bestGroup: 0 | 1 | null = null
    let bestError = threshold
    for (const group of [0, 1] as const) {
      const other = group === 0 ? 1 : 0
      if (groupStepping[group]) continue
      // At a scurry both groups may overlap; otherwise strictly alternate.
      if (groupStepping[other] && speed < 260) continue
      for (const leg of legs) {
        if (leg.group !== group || !isFree(leg)) continue
        const rest = toWorld(leg.rest)
        const error = Math.hypot(leg.foot.x - rest.x, leg.foot.y - rest.y)
        if (error > bestError) {
          bestError = error
          bestGroup = group
        }
      }
    }
    if (bestGroup !== null) {
      for (const leg of legs) {
        if (leg.group !== bestGroup || !isFree(leg)) continue
        const rest = toWorld(leg.rest)
        leg.step = {
          from: { ...leg.foot },
          to: {
            x: rest.x + vel.x * stepDuration * 1.1,
            y: rest.y + vel.y * stepDuration * 1.1,
          },
          t: 0,
          duration: stepDuration,
        }
      }
    }

    if (phase === 'walk' || phase === 'exit') {
      for (const leg of landed) dipUnderFoot(leg.foot)
    }
  }

  const updateRope = (dt: number) => {
    ropeAccumulator += dt
    const anchor = toWorld(SPINNERET)
    let steps = 0
    while (ropeAccumulator >= ROPE_STEP_SECONDS && steps < 8) {
      ropeAccumulator -= ROPE_STEP_SECONDS
      steps += 1
      const h2 = ROPE_STEP_SECONDS * ROPE_STEP_SECONDS

      // The card is a weight on an elastic tether; the silk is drawn between.
      const cardVx = (card.x - cardPrev.x) * CARD_DAMPING
      const cardVy = (card.y - cardPrev.y) * CARD_DAMPING
      cardPrev.x = card.x
      cardPrev.y = card.y
      card.x += cardVx
      card.y += cardVy + GRAVITY * h2
      const tetherX = card.x - anchor.x
      const tetherY = card.y - anchor.y
      const tether = Math.hypot(tetherX, tetherY) || 0.0001
      if (tether > CARD_REACH_PX) {
        const pull = ((tether - CARD_REACH_PX) / tether) * TETHER_STIFFNESS
        card.x -= tetherX * pull
        card.y -= tetherY * pull
      }

      rope[0] = { ...anchor }
      ropePrev[0] = { ...anchor }
      rope[ROPE_SEGMENTS] = { ...card }
      ropePrev[ROPE_SEGMENTS] = { ...card }
      for (let i = 1; i < ROPE_SEGMENTS; i++) {
        const point = rope[i]
        const vx = (point.x - ropePrev[i].x) * SILK_DAMPING
        const vy = (point.y - ropePrev[i].y) * SILK_DAMPING
        ropePrev[i] = { ...point }
        // A faint drifting breeze keeps the silk alive even when the spider
        // stands still. It peaks mid-string, since both ends are held in place.
        const along = Math.sin((i / ROPE_SEGMENTS) * Math.PI)
        const breeze =
          (Math.sin(time * 1.3 + i * 0.42) * 0.7 +
            Math.sin(time * 2.9 + i * 0.95) * 0.3) *
          BREEZE *
          along
        point.x += vx + breeze * h2
        point.y += vy + GRAVITY * h2
      }
      for (let iteration = 0; iteration < ROPE_ITERATIONS; iteration++) {
        for (let i = 0; i < ROPE_SEGMENTS; i++) {
          const a = rope[i]
          const b = rope[i + 1]
          const dx = b.x - a.x
          const dy = b.y - a.y
          const distance = Math.hypot(dx, dy) || 0.0001
          // Silk only resists stretching; it goes slack when compressed.
          if (distance <= ROPE_SEGMENT_PX) continue
          const difference = (distance - ROPE_SEGMENT_PX) / distance
          // Both ends are pinned: one to the spider, one to the card.
          const weightA = i === 0 ? 0 : 1
          const weightB = i + 1 === ROPE_SEGMENTS ? 0 : 1
          const total = weightA + weightB
          if (total === 0) continue
          a.x += dx * difference * (weightA / total)
          a.y += dy * difference * (weightA / total)
          b.x -= dx * difference * (weightB / total)
          b.y -= dy * difference * (weightB / total)
        }
      }
    }
    if (steps === 0) return

    // The card swings like a pendulum under the spider.
    const hangAngle = clamp(
      Math.atan2(card.x - anchor.x, Math.max(1, card.y - anchor.y)),
      -CARD_MAX_ANGLE,
      CARD_MAX_ANGLE,
    )
    cardAngle += (hangAngle - cardAngle) * Math.min(1, dt * 9)
  }

  const updateSilk = (dt: number) => {
    if (tug) {
      if (!tug.element.isConnected) {
        snapSilk()
      } else {
        const anchor = tug.anchor()
        const spinneret = toWorld(SPINNERET)
        const stretch =
          Math.hypot(anchor.x - spinneret.x, anchor.y - spinneret.y) -
          SILK_SLACK_PX
        silkTension = clamp(stretch / SILK_SNAP_PX, 0, 1)
        tug.setTension(silkTension)
        if (stretch > SILK_SNAP_PX) snapSilk()
      }
    }
    if (silkRecoil) {
      silkRecoil.t += dt / SILK_RECOIL_SECONDS
      if (silkRecoil.t >= 1) silkRecoil = null
    }
    if (draglineRetract >= 0 && draglineRetract < 1) {
      draglineRetract = Math.min(
        1,
        draglineRetract + dt / DRAGLINE_RETRACT_SECONDS,
      )
    }
  }

  const render = () => {
    refs.spider.setAttribute(
      'transform',
      `translate(${fmt(pos.x)} ${fmt(pos.y)}) rotate(${fmt(toDegrees(heading))})`,
    )
    refs.abdomen.setAttribute(
      'transform',
      `rotate(${fmt(toDegrees(sway))} -6 0)`,
    )

    legs.forEach((leg, index) => {
      const hip = toWorld(leg.hip)
      let foot = leg.foot
      const reach = leg.femur + leg.tibia
      const dx = foot.x - hip.x
      const dy = foot.y - hip.y
      const distance = Math.hypot(dx, dy)
      if (distance > reach) {
        foot = {
          x: hip.x + (dx / distance) * reach,
          y: hip.y + (dy / distance) * reach,
        }
      }
      const knee = solveKnee(hip, foot, leg.femur, leg.tibia, leg.lift, pos)
      refs.femurs[index].setAttribute(
        'd',
        `M${fmt(hip.x)} ${fmt(hip.y)}L${fmt(knee.x)} ${fmt(knee.y)}`,
      )
      refs.tibias[index].setAttribute(
        'd',
        `M${fmt(knee.x)} ${fmt(knee.y)}L${fmt(foot.x)} ${fmt(foot.y)}`,
      )
    })

    let ropePath = `M${fmt(rope[0].x)} ${fmt(rope[0].y)}`
    for (let i = 1; i < ROPE_SEGMENTS; i++) {
      const midX = (rope[i].x + rope[i + 1].x) / 2
      const midY = (rope[i].y + rope[i + 1].y) / 2
      ropePath += `Q${fmt(rope[i].x)} ${fmt(rope[i].y)} ${fmt(midX)} ${fmt(midY)}`
    }
    const attach = rope[ROPE_SEGMENTS]
    ropePath += `L${fmt(attach.x)} ${fmt(attach.y)}`
    refs.rope.setAttribute('d', ropePath)
    refs.card.style.transform = `translate3d(${fmt(attach.x - cardWidth / 2)}px, ${fmt(attach.y)}px, 0) rotate(${fmt(-cardAngle)}rad)`

    const spinneret = toWorld(SPINNERET)
    if (phase === 'descend' || (draglineRetract >= 0 && draglineRetract < 1)) {
      const top = { x: startX, y: -8 }
      const t = draglineRetract < 0 ? 0 : easeInQuad(draglineRetract)
      setLine(
        refs.dragline,
        top,
        {
          x: spinneret.x + (top.x - spinneret.x) * t,
          y: spinneret.y + (top.y - spinneret.y) * t,
        },
        1 - t * 0.4,
      )
    } else {
      refs.dragline.setAttribute('opacity', '0')
    }

    if (tug) {
      setSaggingSilk(refs.silk, tug.anchor(), spinneret, silkTension, 0.9)
    } else if (silkRecoil) {
      const t = easeOutQuad(silkRecoil.t)
      // A snapped strand whips back loose before it disappears.
      setSaggingSilk(
        refs.silk,
        {
          x: silkRecoil.from.x + (spinneret.x - silkRecoil.from.x) * t,
          y: silkRecoil.from.y + (spinneret.y - silkRecoil.from.y) * t,
        },
        spinneret,
        t * 0.4,
        0.9 * (1 - t),
      )
    } else {
      refs.silk.setAttribute('opacity', '0')
    }

    refs.hitTarget.style.transform = `translate3d(${fmt(pos.x - 24)}px, ${fmt(pos.y - 24)}px, 0)`
  }

  const checkModal = (now: number) => {
    if (now < nextModalCheck) return
    nextModalCheck = now + 400
    modalOpen = !!document.querySelector(
      '[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]',
    )
    refs.root.style.opacity = modalOpen ? '0' : '1'
  }

  const frame = (now: number) => {
    const dt = Math.min(0.033, Math.max(0.001, (now - lastFrame) / 1000))
    lastFrame = now
    checkModal(now)
    updateBehavior(dt)
    if (phase === 'done') return
    updateBody(dt)
    updateLegs(dt)
    updateRope(dt)
    updateSilk(dt)
    render()
    frameId = requestAnimationFrame(frame)
  }

  const handleResize = () => {
    cardWidth = refs.card.offsetWidth || cardWidth
  }

  window.addEventListener('resize', handleResize)
  render()
  frameId = requestAnimationFrame(frame)

  return {
    block: () => {
      if (phase === 'flee' || phase === 'done') return
      if (phase === 'descend') land()
      snapSilk()
      // Recoil backwards first, then scurry for the nearest edge.
      vel = { x: -Math.cos(heading) * 280, y: -Math.sin(heading) * 280 }
      beginExit('flee')
      callbacks.onBlocked({ ...pos })
    },
    leave: () => {
      if (phase === 'exit' || phase === 'flee' || phase === 'done') return
      if (phase === 'descend') land()
      beginExit('exit')
    },
    setHeld: (next) => {
      held = next
    },
    destroy: () => {
      cancelAnimationFrame(frameId)
      window.removeEventListener('resize', handleResize)
      tug?.snap()
      tug = null
    },
  }
}

function solveKnee(
  hip: Vec,
  foot: Vec,
  femur: number,
  tibia: number,
  lift: number,
  center: Vec,
): Vec {
  const dx = foot.x - hip.x
  const dy = foot.y - hip.y
  const length = Math.hypot(dx, dy) || 0.0001
  const ux = dx / length
  const uy = dy / length
  const d = clamp(length, Math.abs(femur - tibia) + 0.5, femur + tibia - 0.01)
  const along = (femur * femur - tibia * tibia + d * d) / (2 * d)
  const height =
    Math.sqrt(Math.max(0, femur * femur - along * along)) * (1 + lift * 0.45)
  const baseX = hip.x + ux * along
  const baseY = hip.y + uy * along
  const a = { x: baseX - uy * height, y: baseY + ux * height }
  const b = { x: baseX + uy * height, y: baseY - ux * height }
  // Knees always arch away from the body.
  const da = (a.x - center.x) ** 2 + (a.y - center.y) ** 2
  const db = (b.x - center.x) ** 2 + (b.y - center.y) ** 2
  return da > db ? a : b
}

function setLine(line: SVGLineElement, from: Vec, to: Vec, opacity: number) {
  line.setAttribute('x1', fmt(from.x))
  line.setAttribute('y1', fmt(from.y))
  line.setAttribute('x2', fmt(to.x))
  line.setAttribute('y2', fmt(to.y))
  line.setAttribute('opacity', fmt(opacity))
}

/** Slack silk droops under gravity; taut silk pulls straight. */
function setSaggingSilk(
  path: SVGPathElement,
  from: Vec,
  to: Vec,
  tension: number,
  opacity: number,
) {
  const span = Math.hypot(to.x - from.x, to.y - from.y)
  const sag = (1 - tension) * Math.min(SILK_SAG_PX, span * 0.35)
  const controlX = (from.x + to.x) / 2
  const controlY = (from.y + to.y) / 2 + sag
  path.setAttribute(
    'd',
    `M${fmt(from.x)} ${fmt(from.y)}Q${fmt(controlX)} ${fmt(controlY)} ${fmt(to.x)} ${fmt(to.y)}`,
  )
  path.setAttribute('opacity', fmt(opacity))
}

function wrapAngle(angle: number): number {
  return Math.atan2(Math.sin(angle), Math.cos(angle))
}

function toDegrees(radians: number): number {
  return (radians * 180) / Math.PI
}

function fmt(value: number): string {
  return value.toFixed(2)
}

function easeOutBack(t: number): number {
  const c1 = 1.2
  const c3 = c1 + 1
  return 1 + c3 * (t - 1) ** 3 + c1 * (t - 1) ** 2
}

function easeInOutSine(t: number): number {
  return -(Math.cos(Math.PI * t) - 1) / 2
}

function easeInQuad(t: number): number {
  return t * t
}

function easeOutQuad(t: number): number {
  return 1 - (1 - t) * (1 - t)
}
