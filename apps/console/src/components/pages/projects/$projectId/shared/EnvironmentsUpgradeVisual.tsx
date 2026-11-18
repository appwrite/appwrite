import { useId } from 'react'
import {
  Copy,
  Database,
  Folder,
  Plus,
  Users,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'
import {
  projectEnvironmentExamples,
  type ProjectEnvironmentExample,
} from '@/lib/project-environments'
import { cn } from '@/lib/utils'

const WIDTH = 520
const HEIGHT = 220

/** The project, drawn as the card every environment is copied from. */
const PRODUCTION = { x: 32, y: 36, width: 164, height: 148 }
const PRODUCTION_HEADER = 36
const PRODUCTION_ROW = 28

const ENVIRONMENT_X = 310
/**
 * The environments stop short of the trailing edge, where the dialog's close
 * button sits over the illustration.
 */
const ENVIRONMENT_WIDTH = 160
const ENVIRONMENT_HEIGHT = 38
/** The picked environment is drawn as created, with resources of its own. */
const ENVIRONMENT_HEIGHT_PICKED = 64
const ENVIRONMENT_GAP = 14

/** Where the copies leave the project: the middle of its trailing edge. */
const ORIGIN = {
  x: PRODUCTION.x + PRODUCTION.width,
  y: PRODUCTION.y + PRODUCTION.height / 2,
}

/** What a project holds, and so what an environment gets a copy of. */
const RESOURCES: readonly { icon: LucideIcon; bar: number }[] = [
  { icon: Database, bar: 58 },
  { icon: Users, bar: 40 },
  { icon: Zap, bar: 50 },
  { icon: Folder, bar: 34 },
]

const TILE = 18
const TILE_GAP = 6
const ICON = 11
/** The copy a branch goes through on its way, halfway along it. */
const COPY_RADIUS = 11

/** How long a copy takes to travel from the project to the environment. */
const TRAVEL_SECONDS = 2.7
const TRAVELLERS = 3

/**
 * The edge of a card. The theme's border is too faint to draw a card with on
 * the dark themes, where it sits within a few shades of the card itself.
 */
const CARD_STROKE = 'color-mix(in oklch, var(--foreground) 9%, var(--border))'

/** The dot colors of the switcher, for the SVG animation that cannot read CSS. */
const PRODUCTION_HEX = '#10b981'
const ENVIRONMENT_HEX = '#a855f7'

function ResourceTile({
  icon: Icon,
  x,
  y,
  picked = false,
}: {
  icon: LucideIcon
  x: number
  y: number
  picked?: boolean
}) {
  return (
    <>
      <rect
        x={x}
        y={y}
        width={TILE}
        height={TILE}
        rx={5}
        className={picked ? 'fill-purple-500/15' : 'fill-muted'}
      />
      <Icon
        x={x + (TILE - ICON) / 2}
        y={y + (TILE - ICON) / 2}
        width={ICON}
        height={ICON}
        strokeWidth={2}
        className={
          picked
            ? 'text-purple-600 dark:text-purple-300'
            : 'text-muted-foreground'
        }
      />
    </>
  )
}

/**
 * The upgrade dialog's illustration: the project as production, and the
 * example environments branching off it. The one that was picked is drawn as
 * it would be once created, with copies of the project's resources arriving;
 * the others stay the placeholders they are in the switcher.
 */
export function EnvironmentsUpgradeVisual({
  picked,
}: {
  picked: ProjectEnvironmentExample['id']
}) {
  const t = useT()
  const id = useId()
  const flowId = `${id}-flow`
  const flowGlowId = `${id}-flow-glow`
  const productionGlowId = `${id}-production-glow`
  const environmentGlowId = `${id}-environment-glow`

  const examples = projectEnvironmentExamples([])
  const stackHeight =
    ENVIRONMENT_HEIGHT_PICKED +
    ENVIRONMENT_HEIGHT * (examples.length - 1) +
    ENVIRONMENT_GAP * (examples.length - 1)
  let nextY = (HEIGHT - stackHeight) / 2
  const environments = examples.map((example) => {
    const isPicked = example.id === picked
    const height = isPicked ? ENVIRONMENT_HEIGHT_PICKED : ENVIRONMENT_HEIGHT
    const y = nextY
    nextY += height + ENVIRONMENT_GAP
    const centerY = y + height / 2
    return {
      ...example,
      isPicked,
      y,
      height,
      centerY,
      // The curve is symmetric, so it is halfway at the middle of its ends
      middle: {
        x: (ORIGIN.x + ENVIRONMENT_X) / 2,
        y: (ORIGIN.y + centerY) / 2,
      },
      path: `M${ORIGIN.x} ${ORIGIN.y} C${ORIGIN.x + 62} ${ORIGIN.y} ${ENVIRONMENT_X - 62} ${centerY} ${ENVIRONMENT_X} ${centerY}`,
    }
  })
  const pickedEnvironment =
    environments.find((environment) => environment.isPicked) ?? environments[0]

  return (
    <div
      aria-hidden
      className="relative overflow-hidden border-b border-border bg-muted/40 dark:bg-black/25"
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
          'relative block h-auto w-full select-none',
          FORCE_LTR_CLASS,
        )}
      >
        <defs>
          <linearGradient
            id={flowId}
            gradientUnits="userSpaceOnUse"
            x1={ORIGIN.x}
            x2={ENVIRONMENT_X}
            y1={0}
            y2={0}
          >
            <stop
              offset="0"
              stopColor="currentColor"
              className="text-emerald-500"
            />
            <stop
              offset="1"
              stopColor="currentColor"
              className="text-purple-500"
            />
          </linearGradient>
          <filter id={flowGlowId} x="-20%" y="-60%" width="140%" height="220%">
            <feGaussianBlur stdDeviation="3.5" />
          </filter>
          <radialGradient id={productionGlowId}>
            <stop
              offset="0"
              stopColor="currentColor"
              stopOpacity={0.14}
              className="text-emerald-500"
            />
            <stop
              offset="1"
              stopColor="currentColor"
              stopOpacity={0}
              className="text-emerald-500"
            />
          </radialGradient>
          <radialGradient id={environmentGlowId}>
            <stop
              offset="0"
              stopColor="currentColor"
              stopOpacity={0.2}
              className="text-purple-500"
            />
            <stop
              offset="1"
              stopColor="currentColor"
              stopOpacity={0}
              className="text-purple-500"
            />
          </radialGradient>
        </defs>

        <ellipse
          cx={PRODUCTION.x + PRODUCTION.width / 2}
          cy={ORIGIN.y}
          rx={150}
          ry={120}
          fill={`url(#${productionGlowId})`}
        />
        <ellipse
          cx={ENVIRONMENT_X + ENVIRONMENT_WIDTH / 2}
          cy={pickedEnvironment.centerY}
          rx={150}
          ry={96}
          fill={`url(#${environmentGlowId})`}
        />

        {/* The branches: dashed to what could be, drawn to what was picked */}
        {environments.map((environment) =>
          environment.isPicked ? null : (
            <path
              key={environment.id}
              d={environment.path}
              fill="none"
              stroke="var(--muted-foreground)"
              strokeOpacity={0.45}
              strokeWidth={1}
              strokeLinecap="round"
              strokeDasharray="1 4.5"
            />
          ),
        )}
        <path
          d={pickedEnvironment.path}
          fill="none"
          stroke={`url(#${flowId})`}
          strokeOpacity={0.55}
          strokeWidth={4}
          strokeLinecap="round"
          filter={`url(#${flowGlowId})`}
        />
        <path
          d={pickedEnvironment.path}
          fill="none"
          stroke={`url(#${flowId})`}
          strokeWidth={1.75}
          strokeLinecap="round"
        />
        <g className="motion-reduce:hidden">
          {Array.from({ length: TRAVELLERS }, (_, index) => {
            const begin = `${(index * TRAVEL_SECONDS) / TRAVELLERS}s`
            return (
              <circle key={index} r={2.5} fill={PRODUCTION_HEX} opacity={0}>
                <animateMotion
                  path={pickedEnvironment.path}
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
                  keyTimes="0;0.15;0.8;1"
                  dur={`${TRAVEL_SECONDS}s`}
                  begin={begin}
                  repeatCount="indefinite"
                />
                <animate
                  attributeName="fill"
                  values={`${PRODUCTION_HEX};${ENVIRONMENT_HEX}`}
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

        <circle
          cx={pickedEnvironment.middle.x}
          cy={pickedEnvironment.middle.y}
          r={COPY_RADIUS}
          fill="var(--card)"
          stroke={CARD_STROKE}
          style={{ filter: 'drop-shadow(0 4px 10px rgb(0 0 0 / 0.16))' }}
        />
        <Copy
          x={pickedEnvironment.middle.x - ICON / 2}
          y={pickedEnvironment.middle.y - ICON / 2}
          width={ICON}
          height={ICON}
          strokeWidth={2}
          className="text-foreground/80"
        />

        {/* Production: the project, with what it holds */}
        <g style={{ filter: 'drop-shadow(0 8px 18px rgb(0 0 0 / 0.14))' }}>
          <rect
            x={PRODUCTION.x}
            y={PRODUCTION.y}
            width={PRODUCTION.width}
            height={PRODUCTION.height}
            rx={10}
            fill="var(--card)"
            stroke={CARD_STROKE}
          />
        </g>
        <path
          d={`M${PRODUCTION.x + 0.5} ${PRODUCTION.y + PRODUCTION_HEADER}v${-(PRODUCTION_HEADER - 10)}a9.5 9.5 0 0 1 9.5 -9.5h${PRODUCTION.width - 20}a9.5 9.5 0 0 1 9.5 9.5v${PRODUCTION_HEADER - 10}z`}
          fill="var(--muted)"
          fillOpacity={0.45}
        />
        <circle
          cx={PRODUCTION.x + 18}
          cy={PRODUCTION.y + PRODUCTION_HEADER / 2}
          r={8}
          className="fill-emerald-500/15"
        />
        <circle
          cx={PRODUCTION.x + 18}
          cy={PRODUCTION.y + PRODUCTION_HEADER / 2}
          r={4}
          className="fill-emerald-500"
        />
        <text
          x={PRODUCTION.x + 32}
          y={PRODUCTION.y + PRODUCTION_HEADER / 2 + 4.5}
          fontSize={12.5}
          fontWeight={600}
          fill="var(--foreground)"
        >
          {t('Production')}
        </text>
        <line
          x1={PRODUCTION.x}
          x2={PRODUCTION.x + PRODUCTION.width}
          y1={PRODUCTION.y + PRODUCTION_HEADER}
          y2={PRODUCTION.y + PRODUCTION_HEADER}
          stroke={CARD_STROKE}
        />
        {RESOURCES.map((resource, index) => {
          const centerY =
            PRODUCTION.y +
            PRODUCTION_HEADER +
            PRODUCTION_ROW / 2 +
            index * PRODUCTION_ROW
          return (
            <g key={index}>
              <ResourceTile
                icon={resource.icon}
                x={PRODUCTION.x + 12}
                y={centerY - TILE / 2}
              />
              <rect
                x={PRODUCTION.x + 12 + TILE + 10}
                y={centerY - 2.5}
                width={resource.bar}
                height={5}
                rx={2.5}
                fill="var(--foreground)"
                fillOpacity={0.22}
              />
              <rect
                x={PRODUCTION.x + PRODUCTION.width - 12 - 14}
                y={centerY - 2.5}
                width={14}
                height={5}
                rx={2.5}
                fill="var(--muted-foreground)"
                fillOpacity={0.25}
              />
            </g>
          )
        })}

        {environments.map((environment) =>
          environment.isPicked ? (
            <g
              key={environment.id}
              className="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-left-2 motion-safe:duration-500 motion-safe:fill-mode-both"
            >
              <g
                style={{ filter: 'drop-shadow(0 8px 18px rgb(0 0 0 / 0.14))' }}
              >
                <rect
                  x={ENVIRONMENT_X}
                  y={environment.y}
                  width={ENVIRONMENT_WIDTH}
                  height={environment.height}
                  rx={10}
                  fill="var(--card)"
                />
              </g>
              <rect
                x={ENVIRONMENT_X}
                y={environment.y}
                width={ENVIRONMENT_WIDTH}
                height={environment.height}
                rx={10}
                className="fill-purple-500/[0.07] stroke-purple-500/50"
              />
              <circle
                cx={ENVIRONMENT_X + 18}
                cy={environment.y + 19}
                r={8}
                className="fill-purple-500/15"
              />
              <circle
                cx={ENVIRONMENT_X + 18}
                cy={environment.y + 19}
                r={4}
                className="fill-purple-500"
              />
              <text
                x={ENVIRONMENT_X + 32}
                y={environment.y + 23.5}
                fontSize={12.5}
                fontWeight={600}
                fill="var(--foreground)"
              >
                {environment.name}
              </text>
              {RESOURCES.map((resource, index) => (
                <g
                  key={index}
                  className="origin-center [transform-box:fill-box] motion-safe:animate-in motion-safe:fade-in motion-safe:zoom-in-50 motion-safe:duration-300"
                  style={{
                    animationDelay: `${500 + index * 110}ms`,
                    animationFillMode: 'both',
                  }}
                >
                  <ResourceTile
                    icon={resource.icon}
                    x={ENVIRONMENT_X + 12 + index * (TILE + TILE_GAP)}
                    y={environment.y + 35}
                    picked
                  />
                </g>
              ))}
            </g>
          ) : (
            <g key={environment.id}>
              <rect
                x={ENVIRONMENT_X}
                y={environment.y}
                width={ENVIRONMENT_WIDTH}
                height={environment.height}
                rx={10}
                fill="var(--card)"
                fillOpacity={0.55}
                stroke="var(--muted-foreground)"
                strokeOpacity={0.35}
                strokeDasharray="4 3.5"
              />
              <circle
                cx={ENVIRONMENT_X + 18}
                cy={environment.centerY}
                r={3.5}
                fill="none"
                stroke="var(--muted-foreground)"
                strokeOpacity={0.8}
                strokeWidth={1.25}
                strokeDasharray="1.7 1.45"
              />
              <text
                x={ENVIRONMENT_X + 32}
                y={environment.centerY + 4.5}
                fontSize={12.5}
                fontWeight={500}
                fill="var(--muted-foreground)"
              >
                {environment.name}
              </text>
              <Plus
                x={ENVIRONMENT_X + ENVIRONMENT_WIDTH - 12 - 12}
                y={environment.centerY - 6}
                width={12}
                height={12}
                strokeWidth={2}
                className="text-muted-foreground/70"
              />
            </g>
          ),
        )}

        {/* Where each branch leaves the project and reaches its environment */}
        <circle
          cx={ORIGIN.x}
          cy={ORIGIN.y}
          r={4}
          fill="var(--card)"
          strokeWidth={1.5}
          className="stroke-emerald-500"
        />
        {environments.map((environment) => (
          <circle
            key={environment.id}
            cx={ENVIRONMENT_X}
            cy={environment.centerY}
            r={environment.isPicked ? 4 : 3}
            fill="var(--card)"
            strokeWidth={environment.isPicked ? 1.5 : 1}
            stroke={environment.isPicked ? undefined : CARD_STROKE}
            className={environment.isPicked ? 'stroke-purple-500' : undefined}
          />
        ))}
      </svg>
    </div>
  )
}
