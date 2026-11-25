import { useId } from 'react'
import {
  AppWindow,
  BadgeCheck,
  Building2,
  KeyRound,
  ShieldCheck,
  User,
  type LucideIcon,
} from 'lucide-react'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const WIDTH = 520
const HEIGHT = 300

const HEADER = 38
const ROW = 34
/** Each person's name, drawn as a bar: nobody in particular. */
const PEOPLE = [46, 60, 38, 52] as const

const CARD_WIDTH = 172
const CARD_HEIGHT = HEADER + PEOPLE.length * ROW + 10
const CARD_Y = (HEIGHT - CARD_HEIGHT) / 2

/** The identity provider: where the people are. */
const PROVIDER_X = 24
/** Where they sign in to: the Console, or the customer's app. */
const DESTINATION_X = WIDTH - 24 - CARD_WIDTH

/** The sign-in everyone goes through, between the two. */
const GATE = { x: WIDTH / 2, y: HEIGHT / 2, radius: 22 }

const TILE = 20
const ICON = 12
const AVATAR = 9

/** How long a sign-in takes to travel from the provider to the other side. */
const TRAVEL_SECONDS = 3.2

/**
 * The edge of a card. The theme's border is too faint to draw a card with on
 * the dark themes, where it sits within a few shades of the card itself.
 */
const CARD_STROKE = 'color-mix(in oklch, var(--foreground) 9%, var(--border))'

/** The accent, for the SVG animation that cannot read CSS. */
const ACCENT_HEX = '#0ea5e9'
const NEUTRAL_HEX = '#94a3b8'

/** The roles people arrive with: an organization's, or a team's own. */
const ROLES: Record<'organization' | 'project', readonly string[]> = {
  organization: ['developer', 'editor', 'analyst', 'billing'],
  project: ['admin', 'support', 'viewer', 'editor'],
}

/** A verified domain, over the gate: whose sign-ins it lets through. */
const DOMAIN = 'acme.com'
const DOMAIN_CHIP = { width: 86, height: 22 }
/** Level with the cards' headers, clear of the sign-ins passing under it. */
const DOMAIN_CHIP_Y = CARD_Y + (HEADER - DOMAIN_CHIP.height) / 2

function rowCenter(index: number) {
  return CARD_Y + HEADER + 5 + ROW / 2 + index * ROW
}

function HeaderTile({
  icon: Icon,
  x,
  accent = false,
}: {
  icon: LucideIcon
  x: number
  accent?: boolean
}) {
  const y = CARD_Y + (HEADER - TILE) / 2
  return (
    <>
      <rect
        x={x}
        y={y}
        width={TILE}
        height={TILE}
        rx={6}
        className={accent ? 'fill-sky-500/15' : 'fill-muted'}
      />
      <Icon
        x={x + (TILE - ICON) / 2}
        y={y + (TILE - ICON) / 2}
        width={ICON}
        height={ICON}
        strokeWidth={2}
        className={
          accent ? 'text-sky-600 dark:text-sky-300' : 'text-muted-foreground'
        }
      />
    </>
  )
}

function Avatar({
  x,
  y,
  accent = false,
}: {
  x: number
  y: number
  accent?: boolean
}) {
  return (
    <>
      <circle
        cx={x}
        cy={y}
        r={AVATAR}
        className={accent ? 'fill-sky-500/15' : 'fill-muted'}
      />
      <User
        x={x - 5.5}
        y={y - 5.5}
        width={11}
        height={11}
        strokeWidth={2}
        className={
          accent ? 'text-sky-600 dark:text-sky-300' : 'text-muted-foreground'
        }
      />
    </>
  )
}

/**
 * The Single sign-on add-on's illustration: the people at an identity provider
 * sign in through one gate and arrive on the other side with their roles. For
 * an organization the other side is the Console; for a project it is the
 * customer's own app.
 */
export function SSOAddonVisual({
  scope,
  className,
}: {
  scope: 'organization' | 'project'
  className?: string
}) {
  const t = useT()
  const id = useId()
  const flowId = `${id}-flow`
  const flowGlowId = `${id}-flow-glow`
  const gateGlowId = `${id}-gate-glow`
  const destinationGlowId = `${id}-destination-glow`

  const providerEdge = PROVIDER_X + CARD_WIDTH
  const gateStart = GATE.x - GATE.radius
  const gateEnd = GATE.x + GATE.radius
  const lanes = PEOPLE.map((_, index) => {
    const y = rowCenter(index)
    return {
      y,
      // In from the provider, through the gate, out to the same row over there
      path:
        `M${providerEdge} ${y} C${providerEdge + 26} ${y} ${gateStart - 24} ${GATE.y} ${gateStart} ${GATE.y}` +
        ` L${gateEnd} ${GATE.y} C${gateEnd + 24} ${GATE.y} ${DESTINATION_X - 26} ${y} ${DESTINATION_X} ${y}`,
      inbound: `M${providerEdge} ${y} C${providerEdge + 26} ${y} ${gateStart - 24} ${GATE.y} ${gateStart} ${GATE.y}`,
      outbound: `M${gateEnd} ${GATE.y} C${gateEnd + 24} ${GATE.y} ${DESTINATION_X - 26} ${y} ${DESTINATION_X} ${y}`,
    }
  })

  return (
    <div
      aria-hidden
      className={cn(
        'relative flex items-center justify-center overflow-hidden bg-muted/40 dark:bg-black/25',
        className,
      )}
    >
      <div
        className="pointer-events-none absolute inset-0"
        style={{
          backgroundImage:
            'radial-gradient(color-mix(in oklch, var(--muted-foreground) 30%, transparent) 1px, transparent 1px)',
          backgroundSize: '14px 14px',
          maskImage:
            'radial-gradient(ellipse at center, black 35%, transparent 80%)',
          WebkitMaskImage:
            'radial-gradient(ellipse at center, black 35%, transparent 80%)',
        }}
      />
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        role="presentation"
        className={cn(
          'relative block h-auto w-full max-w-[560px] select-none',
          FORCE_LTR_CLASS,
        )}
      >
        <defs>
          <linearGradient
            id={flowId}
            gradientUnits="userSpaceOnUse"
            x1={providerEdge}
            x2={DESTINATION_X}
            y1={0}
            y2={0}
          >
            <stop
              offset="0"
              stopColor="currentColor"
              stopOpacity={0.35}
              className="text-muted-foreground"
            />
            <stop
              offset="0.5"
              stopColor="currentColor"
              className="text-sky-500"
            />
            <stop
              offset="1"
              stopColor="currentColor"
              className="text-sky-500"
            />
          </linearGradient>
          <filter id={flowGlowId} x="-20%" y="-60%" width="140%" height="220%">
            <feGaussianBlur stdDeviation="3.5" />
          </filter>
          <radialGradient id={gateGlowId}>
            <stop
              offset="0"
              stopColor="currentColor"
              stopOpacity={0.28}
              className="text-sky-500"
            />
            <stop
              offset="1"
              stopColor="currentColor"
              stopOpacity={0}
              className="text-sky-500"
            />
          </radialGradient>
          <radialGradient id={destinationGlowId}>
            <stop
              offset="0"
              stopColor="currentColor"
              stopOpacity={0.16}
              className="text-sky-500"
            />
            <stop
              offset="1"
              stopColor="currentColor"
              stopOpacity={0}
              className="text-sky-500"
            />
          </radialGradient>
        </defs>

        <ellipse
          cx={DESTINATION_X + CARD_WIDTH / 2}
          cy={GATE.y}
          rx={150}
          ry={112}
          fill={`url(#${destinationGlowId})`}
        />
        <circle cx={GATE.x} cy={GATE.y} r={58} fill={`url(#${gateGlowId})`} />

        {/* Every sign-in takes the same way: in to the gate, out to a seat */}
        {lanes.map((lane, index) => (
          <g key={index}>
            <path
              d={lane.path}
              fill="none"
              stroke={`url(#${flowId})`}
              strokeOpacity={0.35}
              strokeWidth={4}
              strokeLinecap="round"
              filter={`url(#${flowGlowId})`}
            />
            <path
              d={lane.path}
              fill="none"
              stroke={`url(#${flowId})`}
              strokeWidth={1.5}
              strokeLinecap="round"
            />
          </g>
        ))}
        <g className="motion-reduce:hidden">
          {lanes.map((lane, index) => {
            const begin = `${(index * TRAVEL_SECONDS) / lanes.length}s`
            return (
              <circle key={index} r={2.75} fill={NEUTRAL_HEX} opacity={0}>
                <animateMotion
                  path={lane.path}
                  dur={`${TRAVEL_SECONDS}s`}
                  begin={begin}
                  repeatCount="indefinite"
                  calcMode="spline"
                  keyTimes="0;1"
                  keySplines="0.45 0 0.55 1"
                />
                <animate
                  attributeName="opacity"
                  values="0;1;1;0"
                  keyTimes="0;0.12;0.85;1"
                  dur={`${TRAVEL_SECONDS}s`}
                  begin={begin}
                  repeatCount="indefinite"
                />
                <animate
                  attributeName="fill"
                  values={`${NEUTRAL_HEX};${ACCENT_HEX}`}
                  calcMode="discrete"
                  keyTimes="0;0.5"
                  dur={`${TRAVEL_SECONDS}s`}
                  begin={begin}
                  repeatCount="indefinite"
                />
              </circle>
            )
          })}
        </g>

        {/* The identity provider, with the people it knows */}
        <g style={{ filter: 'drop-shadow(0 8px 18px rgb(0 0 0 / 0.14))' }}>
          <rect
            x={PROVIDER_X}
            y={CARD_Y}
            width={CARD_WIDTH}
            height={CARD_HEIGHT}
            rx={10}
            fill="var(--card)"
            stroke={CARD_STROKE}
          />
        </g>
        <HeaderTile icon={KeyRound} x={PROVIDER_X + 12} />
        <text
          x={PROVIDER_X + 12 + TILE + 9}
          y={CARD_Y + HEADER / 2 + 4.5}
          fontSize={12.5}
          fontWeight={600}
          fill="var(--foreground)"
        >
          {t('Identity provider')}
        </text>
        <line
          x1={PROVIDER_X}
          x2={PROVIDER_X + CARD_WIDTH}
          y1={CARD_Y + HEADER}
          y2={CARD_Y + HEADER}
          stroke={CARD_STROKE}
        />
        {PEOPLE.map((bar, index) => {
          const y = rowCenter(index)
          return (
            <g key={index}>
              <Avatar x={PROVIDER_X + 12 + AVATAR} y={y} />
              <rect
                x={PROVIDER_X + 12 + AVATAR * 2 + 9}
                y={y - 6.5}
                width={bar}
                height={5}
                rx={2.5}
                fill="var(--foreground)"
                fillOpacity={0.22}
              />
              <rect
                x={PROVIDER_X + 12 + AVATAR * 2 + 9}
                y={y + 2.5}
                width={bar + 22}
                height={4}
                rx={2}
                fill="var(--muted-foreground)"
                fillOpacity={0.22}
              />
            </g>
          )
        })}

        {/* The other side: the same people, signed in, each with a role */}
        <g style={{ filter: 'drop-shadow(0 8px 18px rgb(0 0 0 / 0.14))' }}>
          <rect
            x={DESTINATION_X}
            y={CARD_Y}
            width={CARD_WIDTH}
            height={CARD_HEIGHT}
            rx={10}
            fill="var(--card)"
          />
        </g>
        <rect
          x={DESTINATION_X}
          y={CARD_Y}
          width={CARD_WIDTH}
          height={CARD_HEIGHT}
          rx={10}
          className="fill-sky-500/[0.06] stroke-sky-500/45"
        />
        <HeaderTile
          icon={scope === 'organization' ? Building2 : AppWindow}
          x={DESTINATION_X + 12}
          accent
        />
        <text
          x={DESTINATION_X + 12 + TILE + 9}
          y={CARD_Y + HEADER / 2 + 4.5}
          fontSize={12.5}
          fontWeight={600}
          fill="var(--foreground)"
        >
          {scope === 'organization' ? t('Appwrite Console') : t('Your app')}
        </text>
        <line
          x1={DESTINATION_X}
          x2={DESTINATION_X + CARD_WIDTH}
          y1={CARD_Y + HEADER}
          y2={CARD_Y + HEADER}
          className="stroke-sky-500/25"
        />
        {PEOPLE.map((bar, index) => {
          const y = rowCenter(index)
          const role = ROLES[scope][index]
          const pill = role.length * 5.6 + 14
          return (
            <g
              key={index}
              className="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-left-1 motion-safe:duration-500"
              style={{
                animationDelay: `${400 + index * 180}ms`,
                animationFillMode: 'both',
              }}
            >
              <Avatar x={DESTINATION_X + 12 + AVATAR} y={y} accent />
              <rect
                x={DESTINATION_X + 12 + AVATAR * 2 + 9}
                y={y - 2.5}
                width={bar - 14}
                height={5}
                rx={2.5}
                fill="var(--foreground)"
                fillOpacity={0.22}
              />
              <rect
                x={DESTINATION_X + CARD_WIDTH - 12 - pill}
                y={y - 8.5}
                width={pill}
                height={17}
                rx={8.5}
                className="fill-sky-500/12 stroke-sky-500/30"
              />
              <text
                x={DESTINATION_X + CARD_WIDTH - 12 - pill / 2}
                y={y + 3.25}
                fontSize={9.5}
                fontWeight={500}
                textAnchor="middle"
                className="fill-sky-700 dark:fill-sky-200"
              >
                {role}
              </text>
            </g>
          )
        })}

        {/* The verified domain decides whose sign-ins the gate lets through */}
        <line
          x1={GATE.x}
          x2={GATE.x}
          y1={DOMAIN_CHIP_Y + DOMAIN_CHIP.height}
          y2={GATE.y - GATE.radius}
          className="stroke-sky-500/50"
          strokeWidth={1}
          strokeDasharray="1.5 3"
          strokeLinecap="round"
        />
        <rect
          x={GATE.x - DOMAIN_CHIP.width / 2}
          y={DOMAIN_CHIP_Y}
          width={DOMAIN_CHIP.width}
          height={DOMAIN_CHIP.height}
          rx={DOMAIN_CHIP.height / 2}
          fill="var(--card)"
          stroke={CARD_STROKE}
          style={{ filter: 'drop-shadow(0 4px 10px rgb(0 0 0 / 0.14))' }}
        />
        <BadgeCheck
          x={GATE.x - DOMAIN_CHIP.width / 2 + 8}
          y={DOMAIN_CHIP_Y + (DOMAIN_CHIP.height - 12) / 2}
          width={12}
          height={12}
          strokeWidth={2}
          className="text-sky-600 dark:text-sky-300"
        />
        <text
          x={GATE.x - DOMAIN_CHIP.width / 2 + 25}
          y={DOMAIN_CHIP_Y + DOMAIN_CHIP.height / 2 + 3.5}
          fontSize={10.5}
          fontWeight={500}
          fill="var(--foreground)"
          fillOpacity={0.85}
        >
          {DOMAIN}
        </text>

        {/* The gate: one sign-in for everyone */}
        <circle
          cx={GATE.x}
          cy={GATE.y}
          r={GATE.radius}
          fill="var(--card)"
          style={{ filter: 'drop-shadow(0 6px 14px rgb(0 0 0 / 0.18))' }}
        />
        <circle
          cx={GATE.x}
          cy={GATE.y}
          r={GATE.radius}
          className="fill-sky-500/10 stroke-sky-500/60"
          strokeWidth={1.25}
        />
        <ShieldCheck
          x={GATE.x - 9}
          y={GATE.y - 9}
          width={18}
          height={18}
          strokeWidth={1.9}
          className="text-sky-600 dark:text-sky-300"
        />

        {/* Where each sign-in leaves the provider and where it arrives */}
        {lanes.map((lane, index) => (
          <g key={index}>
            <circle
              cx={providerEdge}
              cy={lane.y}
              r={3}
              fill="var(--card)"
              stroke={CARD_STROKE}
            />
            <circle
              cx={DESTINATION_X}
              cy={lane.y}
              r={3.5}
              fill="var(--card)"
              strokeWidth={1.25}
              className="stroke-sky-500"
            />
          </g>
        ))}
      </svg>
    </div>
  )
}
