import { useId } from 'react'
import {
  AppWindow,
  Building2,
  KeyRound,
  User,
  type LucideIcon,
} from 'lucide-react'
import { APPWRITE_MARK } from '@/components/global/shared/AppwriteMarkIcon'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Scope = 'organization' | 'project'

const WIDTH = 520
const HEIGHT = 300

const HEADER = 38
const ROW = 34
/** Each person's name, drawn as a bar: nobody in particular. */
const PEOPLE = [42, 56, 34, 48] as const

const CARD_WIDTH = 160
const CARD_HEIGHT = HEADER + PEOPLE.length * ROW + 10
const CARD_Y = (HEIGHT - CARD_HEIGHT) / 2

/** Where the people are: the company that signs in. */
const COMPANY_X = 24
/** Where they sign in to: Appwrite Cloud, or the customer's app. */
const DESTINATION_X = WIDTH - 24 - CARD_WIDTH

/** The company's identity provider, which every sign-in goes through. */
const GATE = { x: WIDTH / 2, y: HEIGHT / 2, radius: 23 }
/** Its label, level with the cards' headers and clear of the sign-ins. */
const GATE_LABEL_Y = CARD_Y + HEADER / 2 + 3

const TILE = 20
const ICON = 12
const AVATAR = 9

/** How long a sign-in takes to travel from the company to the other side. */
const TRAVEL_SECONDS = 3.2

/**
 * The edge of a card. The theme's border is too faint to draw a card with on
 * the dark themes, where it sits within a few shades of the card itself.
 */
const CARD_STROKE = 'color-mix(in oklch, var(--foreground) 9%, var(--border))'

const NEUTRAL_HEX = '#94a3b8'

/**
 * The one accent of the drawing. An organization signs in to Appwrite, so it
 * is Appwrite's own pink; a project's is the blue its teams' screens use.
 * `--sso-accent` paints, `--sso-ink` writes and draws icons on a tint of it,
 * and the hex is for the SVG animation, which cannot read CSS.
 */
const ACCENT: Record<Scope, { hex: string; variables: string }> = {
  organization: {
    hex: '#fd366e',
    variables:
      '[--sso-accent:var(--brand-cta)] [--sso-ink:color-mix(in_oklch,var(--brand-cta)_88%,black)] dark:[--sso-ink:color-mix(in_oklch,var(--brand-cta)_70%,white)]',
  },
  project: {
    hex: '#0ea5e9',
    variables:
      '[--sso-accent:#0ea5e9] [--sso-ink:#0284c7] dark:[--sso-ink:#7dd3fc]',
  },
}

/** The roles people arrive with: an organization's, or a team's own. */
const ROLES: Record<Scope, readonly string[]> = {
  organization: ['developer', 'editor', 'analyst', 'billing'],
  project: ['admin', 'support', 'viewer', 'editor'],
}

function rowCenter(index: number) {
  return CARD_Y + HEADER + 5 + ROW / 2 + index * ROW
}

function Tile({ x, accent = false }: { x: number; accent?: boolean }) {
  return (
    <rect
      x={x}
      y={CARD_Y + (HEADER - TILE) / 2}
      width={TILE}
      height={TILE}
      rx={6}
      fill={accent ? 'var(--sso-accent)' : 'var(--muted)'}
      fillOpacity={accent ? 0.16 : 1}
    />
  )
}

function TileIcon({
  icon: Icon,
  x,
  accent = false,
}: {
  icon: LucideIcon
  x: number
  accent?: boolean
}) {
  return (
    <Icon
      x={x + (TILE - ICON) / 2}
      y={CARD_Y + (HEADER - ICON) / 2}
      width={ICON}
      height={ICON}
      strokeWidth={2}
      color={accent ? 'var(--sso-ink)' : 'var(--muted-foreground)'}
    />
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
        fill={accent ? 'var(--sso-accent)' : 'var(--muted)'}
        fillOpacity={accent ? 0.16 : 1}
      />
      <User
        x={x - 5.5}
        y={y - 5.5}
        width={11}
        height={11}
        strokeWidth={2}
        color={accent ? 'var(--sso-ink)' : 'var(--muted-foreground)'}
      />
    </>
  )
}

/**
 * The Single sign-on add-on's illustration: the people of a company sign in
 * through its identity provider and arrive on the other side with their roles.
 *
 * - An organization's: your company signs in to Appwrite Cloud, in Appwrite's
 *   pink.
 * - A project's: your customer signs in to your app, in the blue of the
 *   project's own screens.
 *
 * Nothing in it names a real company, domain or provider: which ones is what
 * the add-on is set up with.
 */
export function SSOAddonVisual({
  scope,
  className,
}: {
  scope: Scope
  className?: string
}) {
  const t = useT()
  const id = useId()
  const flowId = `${id}-flow`
  const flowGlowId = `${id}-flow-glow`
  const gateGlowId = `${id}-gate-glow`
  const destinationGlowId = `${id}-destination-glow`
  const accent = ACCENT[scope]

  const companyEdge = COMPANY_X + CARD_WIDTH
  const gateStart = GATE.x - GATE.radius
  const gateEnd = GATE.x + GATE.radius
  const lanes = PEOPLE.map((_, index) => {
    const y = rowCenter(index)
    return {
      y,
      // Out of the company, through the identity provider, to the same row
      // on the other side
      path:
        `M${companyEdge} ${y} C${companyEdge + 30} ${y} ${gateStart - 28} ${GATE.y} ${gateStart} ${GATE.y}` +
        ` L${gateEnd} ${GATE.y} C${gateEnd + 28} ${GATE.y} ${DESTINATION_X - 30} ${y} ${DESTINATION_X} ${y}`,
    }
  })

  return (
    <div
      aria-hidden
      className={cn(
        'relative flex items-center justify-center overflow-hidden bg-muted/40 dark:bg-black/25',
        accent.variables,
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
            x1={companyEdge}
            x2={DESTINATION_X}
            y1={0}
            y2={0}
          >
            <stop
              offset="0"
              style={{ stopColor: 'var(--muted-foreground)' }}
              stopOpacity={0.35}
            />
            <stop offset="0.5" style={{ stopColor: 'var(--sso-accent)' }} />
            <stop offset="1" style={{ stopColor: 'var(--sso-accent)' }} />
          </linearGradient>
          <filter id={flowGlowId} x="-20%" y="-60%" width="140%" height="220%">
            <feGaussianBlur stdDeviation="3.5" />
          </filter>
          <radialGradient id={gateGlowId}>
            <stop
              offset="0"
              style={{ stopColor: 'var(--sso-accent)' }}
              stopOpacity={0.3}
            />
            <stop
              offset="1"
              style={{ stopColor: 'var(--sso-accent)' }}
              stopOpacity={0}
            />
          </radialGradient>
          <radialGradient id={destinationGlowId}>
            <stop
              offset="0"
              style={{ stopColor: 'var(--sso-accent)' }}
              stopOpacity={0.16}
            />
            <stop
              offset="1"
              style={{ stopColor: 'var(--sso-accent)' }}
              stopOpacity={0}
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
        <circle cx={GATE.x} cy={GATE.y} r={62} fill={`url(#${gateGlowId})`} />

        {/* Every sign-in takes the same way: in to the provider, out to a seat */}
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
                {/*
                  keyPoints go with keyTimes: a path of several segments has
                  more vertices than the two key times, and without key points
                  Firefox refuses the motion and leaves the dot at the origin.
                */}
                <animateMotion
                  path={lane.path}
                  dur={`${TRAVEL_SECONDS}s`}
                  begin={begin}
                  repeatCount="indefinite"
                  calcMode="spline"
                  keyPoints="0;1"
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
                  values={`${NEUTRAL_HEX};${accent.hex}`}
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

        {/* The company, with its people */}
        <g style={{ filter: 'drop-shadow(0 8px 18px rgb(0 0 0 / 0.14))' }}>
          <rect
            x={COMPANY_X}
            y={CARD_Y}
            width={CARD_WIDTH}
            height={CARD_HEIGHT}
            rx={10}
            fill="var(--card)"
            stroke={CARD_STROKE}
          />
        </g>
        <Tile x={COMPANY_X + 12} />
        <TileIcon icon={Building2} x={COMPANY_X + 12} />
        <text
          x={COMPANY_X + 12 + TILE + 9}
          y={CARD_Y + HEADER / 2 + 4.5}
          fontSize={12.5}
          fontWeight={600}
          fill="var(--foreground)"
        >
          {scope === 'organization' ? t('Your company') : t('Your customer')}
        </text>
        <line
          x1={COMPANY_X}
          x2={COMPANY_X + CARD_WIDTH}
          y1={CARD_Y + HEADER}
          y2={CARD_Y + HEADER}
          stroke={CARD_STROKE}
        />
        {PEOPLE.map((bar, index) => {
          const y = rowCenter(index)
          return (
            <g key={index}>
              <Avatar x={COMPANY_X + 12 + AVATAR} y={y} />
              <rect
                x={COMPANY_X + 12 + AVATAR * 2 + 9}
                y={y - 6.5}
                width={bar}
                height={5}
                rx={2.5}
                fill="var(--foreground)"
                fillOpacity={0.22}
              />
              <rect
                x={COMPANY_X + 12 + AVATAR * 2 + 9}
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
          fill="var(--sso-accent)"
          fillOpacity={0.06}
          stroke="var(--sso-accent)"
          strokeOpacity={0.5}
        />
        <Tile x={DESTINATION_X + 12} accent />
        {scope === 'organization' ? (
          // Appwrite's own mark: this is where they sign in to
          <g
            transform={`translate(${DESTINATION_X + 12 + (TILE - ICON) / 2} ${CARD_Y + (HEADER - (ICON * APPWRITE_MARK.height) / APPWRITE_MARK.width) / 2}) scale(${ICON / APPWRITE_MARK.width})`}
            fill="var(--sso-ink)"
          >
            {APPWRITE_MARK.paths.map((d) => (
              <path key={d} d={d} />
            ))}
          </g>
        ) : (
          <TileIcon icon={AppWindow} x={DESTINATION_X + 12} accent />
        )}
        <text
          x={DESTINATION_X + 12 + TILE + 9}
          y={CARD_Y + HEADER / 2 + 4.5}
          fontSize={12.5}
          fontWeight={600}
          fill="var(--foreground)"
        >
          {scope === 'organization' ? t('Appwrite Cloud') : t('Your app')}
        </text>
        <line
          x1={DESTINATION_X}
          x2={DESTINATION_X + CARD_WIDTH}
          y1={CARD_Y + HEADER}
          y2={CARD_Y + HEADER}
          stroke="var(--sso-accent)"
          strokeOpacity={0.25}
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
                width={bar - 16}
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
                fill="var(--sso-accent)"
                fillOpacity={0.12}
                stroke="var(--sso-accent)"
                strokeOpacity={0.32}
              />
              <text
                x={DESTINATION_X + CARD_WIDTH - 12 - pill / 2}
                y={y + 3.25}
                fontSize={9.5}
                fontWeight={500}
                textAnchor="middle"
                fill="var(--sso-ink)"
              >
                {role}
              </text>
            </g>
          )
        })}

        {/* The identity provider: one sign-in for everyone, whichever it is */}
        <text
          x={GATE.x}
          y={GATE_LABEL_Y}
          fontSize={9}
          fontWeight={600}
          textAnchor="middle"
          fill="var(--muted-foreground)"
          style={{ letterSpacing: '0.07em', textTransform: 'uppercase' }}
        >
          {t('Identity provider')}
        </text>
        <line
          x1={GATE.x}
          x2={GATE.x}
          y1={GATE_LABEL_Y + 7}
          y2={GATE.y - GATE.radius - 3}
          stroke="var(--sso-accent)"
          strokeOpacity={0.5}
          strokeWidth={1}
          strokeDasharray="1.5 3"
          strokeLinecap="round"
        />
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
          fill="var(--sso-accent)"
          fillOpacity={0.1}
          stroke="var(--sso-accent)"
          strokeOpacity={0.65}
          strokeWidth={1.25}
        />
        <KeyRound
          x={GATE.x - 9}
          y={GATE.y - 9}
          width={18}
          height={18}
          strokeWidth={1.9}
          color="var(--sso-ink)"
        />

        {/* Where each sign-in leaves the company and where it arrives */}
        {lanes.map((lane, index) => (
          <g key={index}>
            <circle
              cx={companyEdge}
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
              stroke="var(--sso-accent)"
              strokeWidth={1.25}
            />
          </g>
        ))}
      </svg>
    </div>
  )
}
