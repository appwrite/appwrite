'use client'

import { assetUrl } from '@/lib/asset-url'
import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  Check,
  ChevronDown,
  Globe,
  Loader2,
  Mail,
  Maximize2,
  Mic,
  Paperclip,
  Send,
  Wrench,
  X,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const REQUESTS_SERIES = [
  9.8, 8.6, 7.4, 8.1, 9.2, 14.8, 24.1, 31.2, 33.8, 32.1, 29.8, 28.6, 27.4,
  26.9, 28.1, 30.4, 32.7, 34.1, 31.8, 30.2, 28.9, 29.4, 30.8, 29.6,
]
const REQUESTS_AXIS_MAX = 40
const REQUESTS_DAY_KEYS = ['Fri', 'Sat', 'Sun', 'Mon'] as const
const REQUESTS_STROKE = 'var(--chart-brand)'

const STEP_DELAYS_MS = [
  280, 700, 770, 280, 1120, 280, 490, 380, 660, 560, 1400, 280, 490, 380, 630,
  840, 280, 460, 380, 630, 1260, 280, 490, 380, 700, 350, 980, 280, 560, 380,
]
const HOLD_MS = 30000
const ARTIFACT_REVEAL_MS = 460
const LAYOUT_COLLAPSE_MS = 180

type ArtifactId = 'users' | 'chart' | 'executions' | 'campaign' | 'files'

const SIGNUPS = [
  { name: 'Maya Chen', email: 'maya@orbit.dev', verified: true },
  { name: 'Luis Romero', email: 'luis@northwind.io', verified: true },
  { name: 'Priya Shah', email: 'priya@folio.app', verified: false },
  { name: 'Jonah Blake', email: 'jonah@kepler.so', verified: true },
] as const

const EXECUTIONS = [
  { name: 'auth-welcome', time: '142ms', ok: true },
  { name: 'stripe-webhook', time: '88ms', ok: true },
  { name: 'image-resize', time: 'timeout', ok: false },
  { name: 'auth-welcome', time: '91ms', ok: true },
] as const


const UPLOAD_IMAGE = {
  src: assetUrl('/images/home/kittens.avif'),
  name: 'kittens.jpg',
  size: '41 KB',
} as const


function prefersReducedMotion() {
  return (
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
  )
}

function seriesPoints(
  values: number[],
  width: number,
  height: number,
  max: number,
) {
  return values.map((value, index) => ({
    x: (index / (values.length - 1)) * width,
    y: height - (value / max) * height,
  }))
}

function toMonotoneLine(points: { x: number; y: number }[]) {
  const count = points.length
  if (count === 0) return ''
  if (count === 1) {
    return `M${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`
  }

  const deltasX: number[] = []
  const slopes: number[] = []
  for (let index = 0; index < count - 1; index += 1) {
    const deltaX = points[index + 1].x - points[index].x
    deltasX.push(deltaX)
    slopes.push((points[index + 1].y - points[index].y) / deltaX)
  }

  const tangents = [slopes[0]]
  for (let index = 1; index < count - 1; index += 1) {
    tangents[index] =
      slopes[index - 1] * slopes[index] <= 0
        ? 0
        : (slopes[index - 1] + slopes[index]) / 2
  }
  tangents[count - 1] = slopes[count - 2]

  for (let index = 0; index < count - 1; index += 1) {
    if (Math.abs(slopes[index]) < 1e-6) {
      tangents[index] = 0
      tangents[index + 1] = 0
      continue
    }
    const ratioA = tangents[index] / slopes[index]
    const ratioB = tangents[index + 1] / slopes[index]
    const magnitude = ratioA * ratioA + ratioB * ratioB
    if (magnitude > 9) {
      const scale = 3 / Math.sqrt(magnitude)
      tangents[index] = scale * ratioA * slopes[index]
      tangents[index + 1] = scale * ratioB * slopes[index]
    }
  }

  let path = `M${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`
  for (let index = 0; index < count - 1; index += 1) {
    const from = points[index]
    const to = points[index + 1]
    const deltaX = deltasX[index]
    path += ` C${(from.x + deltaX / 3).toFixed(2)},${(from.y + (tangents[index] * deltaX) / 3).toFixed(2)} ${(to.x - deltaX / 3).toFixed(2)},${(to.y - (tangents[index + 1] * deltaX) / 3).toFixed(2)} ${to.x.toFixed(2)},${to.y.toFixed(2)}`
  }
  return path
}

function RequestsChart() {
  const t = useT()
  const reactId = useId()
  const fillId = `${reactId}-requests-fill`
  const plotWidth = 440
  const plotHeight = 168
  const axisWidth = 48
  const padTop = 8
  const padRight = 8
  const padBottom = 28
  const points = seriesPoints(
    REQUESTS_SERIES,
    plotWidth,
    plotHeight,
    REQUESTS_AXIS_MAX,
  )
  const linePath = toMonotoneLine(points)
  const areaPath = `${linePath} L${plotWidth},${plotHeight} L0,${plotHeight} Z`
  const yTicks = [0, 20, 40]

  return (
    <div className="home-ai-pane-in flex h-full min-h-0 flex-col p-3">
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-card">
        <div className="flex shrink-0 flex-col gap-1 border-b border-border px-4 py-3">
          <h3 className="truncate text-[14px] font-medium text-foreground">
            {t('Requests')}
          </h3>
          <div className="flex min-h-[28px] flex-wrap items-baseline gap-x-2 gap-y-1">
            <span className="text-[22px] font-semibold tabular-nums text-foreground">
              184K
            </span>
            <span className="text-[13px] text-muted-foreground">
              {t('requests')}
            </span>
            <span className="text-[12px] font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
              +220% {t('vs previous period')}
            </span>
          </div>
        </div>

        <div className="min-h-0 flex-1 p-4 text-muted-foreground">
          <svg
            viewBox={`0 0 ${axisWidth + plotWidth + padRight} ${padTop + plotHeight + padBottom}`}
            className="h-full w-full overflow-visible"
            overflow="visible"
            preserveAspectRatio="xMidYMid meet"
            role="img"
            aria-label={t('Requests')}
          >
          <defs>
            <linearGradient id={fillId} x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stopColor={REQUESTS_STROKE} stopOpacity="0.2" />
              <stop offset="100%" stopColor={REQUESTS_STROKE} stopOpacity="0" />
            </linearGradient>
          </defs>
          <g transform={`translate(${axisWidth},${padTop})`}>
            {yTicks.map((tick) => {
              const y = plotHeight - (tick / REQUESTS_AXIS_MAX) * plotHeight
              return (
                <line
                  key={`grid-${tick}`}
                  x1={0}
                  x2={plotWidth}
                  y1={y}
                  y2={y}
                  stroke="var(--border)"
                  strokeDasharray="3 3"
                  vectorEffect="non-scaling-stroke"
                />
              )
            })}
            <path d={areaPath} fill={`url(#${fillId})`} />
            <path
              d={linePath}
              fill="none"
              stroke={REQUESTS_STROKE}
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              vectorEffect="non-scaling-stroke"
            />
          </g>
          {yTicks.map((tick) => {
            const y =
              padTop + plotHeight - (tick / REQUESTS_AXIS_MAX) * plotHeight
            return (
              <text
                key={tick}
                x={axisWidth - 8}
                y={y}
                textAnchor="end"
                dominantBaseline="middle"
                fill="currentColor"
                fontSize="10"
              >
                {tick === 0 ? '0' : `${tick}K`}
              </text>
            )
          })}
          {REQUESTS_DAY_KEYS.map((day, index) => {
            const x =
              axisWidth +
              8 +
              (index / (REQUESTS_DAY_KEYS.length - 1)) * (plotWidth - 16)
            return (
              <text
                key={day}
                x={x}
                y={padTop + plotHeight + 18}
                textAnchor="middle"
                fill="currentColor"
                fontSize="10"
              >
                {t(day)}
              </text>
            )
          })}
        </svg>
        </div>
      </div>
    </div>
  )
}

function UsersArtifact() {
  const t = useT()

  return (
    <div className="home-ai-pane-in flex h-full flex-col p-4">
      <div className="mb-3">
        <p className="text-[13px] font-medium text-foreground">users</p>
        <p className="mt-0.5 text-[11px] text-muted-foreground">
          {t('2,184 rows since Friday')}
        </p>
        <pre className="mt-2 overflow-x-auto overflow-y-clip rounded-md border border-border bg-muted/30 px-2.5 py-1.5 font-mono text-[10px] leading-4">
          <span className="text-[#7C3AED] dark:text-[#C4B5FD]">SELECT</span>{' '}
          <span className="text-foreground">*</span>{' '}
          <span className="text-[#7C3AED] dark:text-[#C4B5FD]">FROM</span>{' '}
          <span className="text-[#0369A1] dark:text-[#7DD3FC]">users</span>{' '}
          <span className="text-[#7C3AED] dark:text-[#C4B5FD]">WHERE</span>{' '}
          <span className="text-[#0369A1] dark:text-[#7DD3FC]">created_at</span>{' '}
          <span className="text-muted-foreground">&gt;=</span>{' '}
          <span className="text-[#059669] dark:text-[#6EE7B7]">
            {"'2026-08-21'"}
          </span>
        </pre>
      </div>
      <div className="min-h-0 flex-1 space-y-1.5">
        {SIGNUPS.map((user) => (
          <div
            key={user.email}
            className="flex items-center gap-2.5 rounded-md border border-border/80 bg-background/70 px-2.5 py-2"
          >
            <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-medium text-muted-foreground">
              {user.name
                .split(' ')
                .map((part) => part[0])
                .join('')}
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-[12px] font-medium text-foreground">
                {user.name}
              </p>
              <p className="truncate font-mono text-[10px] text-muted-foreground">
                {user.email}
              </p>
            </div>
            <Badge
              variant={user.verified ? 'success' : 'warning'}
              className="text-[10px] shrink-0"
            >
              {user.verified ? t('Verified') : t('Pending')}
            </Badge>
          </div>
        ))}
      </div>
    </div>
  )
}

function ExecutionsArtifact() {
  const t = useT()

  return (
    <div className="home-ai-pane-in flex h-full flex-col p-4">
      <div className="mb-3">
        <p className="text-[13px] font-medium text-foreground">
          {t('Friday executions')}
        </p>
        <p className="mt-0.5 text-[11px] text-muted-foreground">
          {t('12 timed out on cold start')}
        </p>
      </div>
      <div className="space-y-1.5">
        {EXECUTIONS.map((execution, index) => (
          <div
            key={`${execution.name}-${index}`}
            className="flex items-center gap-2.5 rounded-md border border-border/80 bg-background/70 px-2.5 py-2"
          >
            <span
              className={cn(
                'size-1.5 shrink-0 rounded-full',
                execution.ok ? 'bg-emerald-500' : 'bg-red-500',
              )}
            />
            <p className="min-w-0 flex-1 truncate font-mono text-[11px] text-foreground">
              {execution.name}
            </p>
            <p className="shrink-0 font-mono text-[10px] text-muted-foreground">
              {execution.time}
            </p>
            <Badge
              variant={execution.ok ? 'success' : 'error'}
              className="text-[10px] shrink-0"
            >
              {execution.ok ? t('Completed') : t('Failed')}
            </Badge>
          </div>
        ))}
      </div>
    </div>
  )
}

function CampaignArtifact() {
  const t = useT()

  return (
    <div className="home-ai-pane-in flex h-full flex-col p-4">
      <div className="mb-3 flex items-start gap-2.5">
        <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background">
          <Mail className="size-3.5 text-muted-foreground" aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="text-[13px] font-medium text-foreground">
            {t('Verify your account')}
          </p>
          <p className="mt-0.5 text-[11px] text-muted-foreground">
            {t('187 unverified accounts')}
          </p>
        </div>
      </div>
      <div className="space-y-2 rounded-lg border border-border bg-background/70 p-3">
        <div className="flex items-baseline justify-between gap-3 text-[11px]">
          <span className="text-muted-foreground">{t('Audience')}</span>
          <span className="text-foreground">{t('Unverified users')}</span>
        </div>
        <div className="flex items-baseline justify-between gap-3 text-[11px]">
          <span className="text-muted-foreground">{t('Schedule')}</span>
          <span className="text-foreground">{t('Tomorrow, 9:00 AM')}</span>
        </div>
        <div className="border-t border-border pt-2 text-[11px] leading-4 text-muted-foreground">
          {t(
            'Finish creating your account to keep your data and start building.',
          )}
        </div>
      </div>
    </div>
  )
}


function FilesArtifact() {
  const t = useT()

  return (
    <div className="home-ai-pane-in flex h-full flex-col p-4">
      <div className="mb-3">
        <p className="text-[13px] font-medium text-foreground">campaign-assets</p>
        <p className="mt-0.5 text-[11px] text-muted-foreground">{t('1 file')}</p>
      </div>
      <div className="flex items-center gap-2.5 rounded-md border border-border/80 bg-background/70 px-2.5 py-2">
        <img
          src={assetUrl(UPLOAD_IMAGE.src)}
          alt=""
          className="size-10 shrink-0 rounded object-cover"
        />
        <div className="min-w-0 flex-1">
          <p className="truncate text-[12px] font-medium text-foreground">
            {UPLOAD_IMAGE.name}
          </p>
          <p className="font-mono text-[10px] text-muted-foreground">
            {UPLOAD_IMAGE.size}
          </p>
        </div>
        <Badge variant="success" className="text-[10px] shrink-0">
          {t('Ready')}
        </Badge>
      </div>
    </div>
  )
}

function ArtifactPane({ active }: { active: ArtifactId }) {
  const t = useT()
  const tabs: { id: ArtifactId; label: string }[] = [
    { id: 'users', label: t('Postgres') },
    { id: 'chart', label: t('Requests') },
    { id: 'executions', label: t('Executions') },
    { id: 'campaign', label: t('Campaign') },
    { id: 'files', label: t('Files') },
  ]
  const visibleTabs = tabs.filter((tab) => {
    if (tab.id === 'users') return true
    if (tab.id === 'chart') {
      return (
        active === 'chart' ||
        active === 'executions' ||
        active === 'campaign' ||
        active === 'files'
      )
    }
    if (tab.id === 'executions') {
      return active === 'executions' || active === 'campaign' || active === 'files'
    }
    if (tab.id === 'campaign') return active === 'campaign' || active === 'files'
    return active === 'files'
  })

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex gap-1 border-b border-border px-3 pt-2">
        {visibleTabs.map((tab) => (
          <span
            key={tab.id}
            className={cn(
              'rounded-t-md px-2.5 py-1.5 text-[11px] font-medium',
              tab.id === active
                ? 'bg-muted/60 text-foreground'
                : 'text-muted-foreground',
            )}
          >
            {tab.label}
          </span>
        ))}
      </div>
      <div className="min-h-0 flex-1" key={active}>
        {active === 'users' ? <UsersArtifact /> : null}
        {active === 'chart' ? <RequestsChart /> : null}
        {active === 'executions' ? <ExecutionsArtifact /> : null}
        {active === 'campaign' ? <CampaignArtifact /> : null}
        {active === 'files' ? <FilesArtifact /> : null}
      </div>
    </div>
  )
}

function McpConnectCard({
  phase,
}: {
  phase: 'prompt' | 'oauth' | 'connected'
}) {
  const t = useT()

  return (
    <div className="home-ai-msg-in w-full max-w-[22rem] overflow-hidden rounded-lg border border-border bg-background/90">
      <div className="flex items-start gap-2.5 px-2.5 py-2">
        <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
          <img
            src={assetUrl("/icons/mcp.svg")}
            alt=""
            className={cn('size-3.5 object-contain', PUBLIC_ICON_MUTED_CLASSES)}
          />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5">
            <p className="truncate text-[12px] font-medium text-foreground">
              Appwrite MCP
            </p>
            {phase === 'connected' ? (
              <Badge variant="success" className="text-[9px] shrink-0">
                {t('Connected')}
              </Badge>
            ) : (
              <Badge variant="info" className="text-[9px] shrink-0">
                {t('Remote MCP')}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 flex items-center gap-1 font-mono text-[10px] text-muted-foreground">
            <Globe className="size-2.5 shrink-0" aria-hidden />
            mcp.appwrite.io
          </p>
        </div>
      </div>

      {phase === 'prompt' ? (
        <div className="flex items-center justify-between gap-2 border-t border-border bg-muted/20 px-2.5 py-2">
          <p className="text-[11px] text-muted-foreground">
            {t('OAuth required')}
          </p>
          <span className="rounded-md bg-foreground px-2 py-1 text-[11px] font-medium text-background">
            {t('Sign in with Appwrite')}
          </span>
        </div>
      ) : null}

      {phase === 'oauth' ? (
        <div className="home-ai-oauth-sheet border-t border-border p-2">
          <div className="overflow-hidden rounded-md border border-border bg-card">
            <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-2 py-1">
              <span className="size-1.5 rounded-full bg-muted-foreground/40" />
              <span className="size-1.5 rounded-full bg-muted-foreground/40" />
              <span className="size-1.5 rounded-full bg-muted-foreground/40" />
              <span className="ms-1 min-w-0 truncate font-mono text-[9px] text-muted-foreground">
                mcp.appwrite.io/authorize
              </span>
            </div>
            <div className="px-3 py-2.5">
              <div className="mb-2 flex items-center gap-2">
                <img src={assetUrl("/icons/appwrite.svg")} alt="" className="size-4" />
                <p className="text-[12px] font-medium text-foreground">
                  {t('Authorize Appwrite MCP')}
                </p>
              </div>
              <p className="text-[11px] leading-4 text-muted-foreground">
                {t('No API key needed. Sign in with OAuth.')}
              </p>
              <div className="mt-2.5 flex justify-end">
                <span className="home-ai-oauth-press rounded-md bg-foreground px-2.5 py-1 text-[11px] font-medium text-background">
                  {t('Allow access')}
                </span>
              </div>
            </div>
          </div>
        </div>
      ) : null}

      {phase === 'connected' ? (
        <div className="flex items-center gap-1.5 border-t border-border px-2.5 py-1.5 text-[11px] text-muted-foreground">
          <Check
            className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400"
            aria-hidden
          />
          {t('Authenticated with OAuth')}
        </div>
      ) : null}
    </div>
  )
}

function ToolRow({
  icon,
  label,
  detail,
  running,
}: {
  icon: 'mcp' | 'skill'
  label: string
  detail: string
  running: boolean
}) {
  return (
    <div className="flex items-center gap-2 rounded-md border border-border/80 bg-muted/20 px-2.5 py-1.5">
      <span className="flex size-5 shrink-0 items-center justify-center rounded-sm border border-border bg-background">
        {icon === 'mcp' ? (
          <img
            src={assetUrl("/icons/mcp.svg")}
            alt=""
            className={cn('size-3 object-contain', PUBLIC_ICON_MUTED_CLASSES)}
          />
        ) : (
          <Wrench
            className="size-3 fill-current text-muted-foreground"
            aria-hidden
          />
        )}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-[11px] font-medium text-foreground">{label}</p>
        <p className="truncate font-mono text-[10px] text-muted-foreground">{detail}</p>
      </div>
      {running ? (
        <Loader2
          className="size-3.5 shrink-0 animate-spin text-muted-foreground"
          aria-hidden
        />
      ) : (
        <Check
          className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
          aria-hidden
        />
      )}
    </div>
  )
}

function ChatMessage({
  align,
  image,
  children,
}: {
  align: 'user' | 'assistant'
  image?: { src: string; name: string }
  children?: string
}) {
  return (
    <div
      className={cn(
        'home-ai-msg-in flex max-w-[94%] flex-col gap-2',
        align === 'user' ? 'ms-auto items-end' : 'items-start',
      )}
    >
      {children ? (
        <div
          className={cn(
            'px-3 py-2 text-[12px] leading-5',
            align === 'user'
              ? 'rounded-lg rounded-br-sm bg-muted/60 text-foreground'
              : 'rounded-lg rounded-bl-sm border border-border bg-background/80 text-muted-foreground',
          )}
        >
          {children}
        </div>
      ) : null}
      {image ? (
        <div className="group relative w-fit max-w-full overflow-hidden rounded-md border border-border bg-muted/20">
          <img
            src={assetUrl(image.src)}
            alt={image.name}
            className="h-auto max-h-64 w-auto max-w-full"
          />
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/30">
            <div className="rounded-md bg-black/60 p-1.5 text-white opacity-0 transition-opacity group-hover:opacity-100">
              <Maximize2 className="h-3.5 w-3.5" />
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}

function MockComposerChip({
  icon,
  label,
}: {
  icon: ReactNode
  label: string
}) {
  return (
    <span className="inline-flex h-7 max-w-[180px] items-center gap-1 rounded-md px-2 text-[11px] font-medium text-muted-foreground">
      {icon}
      <span className="min-w-0 truncate">{label}</span>
      <ChevronDown className="h-3 w-3 shrink-0 opacity-70" aria-hidden />
    </span>
  )
}

function MockAgentComposer({
  draft,
  isTyping,
  attachment,
}: {
  draft: string
  isTyping: boolean
  attachment: {
    src: string
    name: string
    size: string
    uploading: boolean
  } | null
}) {
  const t = useT()
  const growInnerRef = useRef<HTMLDivElement>(null)
  const [growHeight, setGrowHeight] = useState<number>()
  const [animateGrow, setAnimateGrow] = useState(false)
  const showPlaceholder = draft.length === 0 && !attachment
  const sendActive = draft.length > 0 && !attachment?.uploading

  useLayoutEffect(() => {
    const inner = growInnerRef.current
    if (!inner) return

    const syncHeight = () => {
      setGrowHeight(inner.offsetHeight)
    }

    syncHeight()
    const observer = new ResizeObserver(syncHeight)
    observer.observe(inner)
    return () => observer.disconnect()
  }, [draft, isTyping, attachment])

  useEffect(() => {
    if (growHeight === undefined) return
    const frameId = window.requestAnimationFrame(() => setAnimateGrow(true))
    return () => window.cancelAnimationFrame(frameId)
  }, [growHeight])

  return (
    <div aria-hidden>
      <div className="overflow-clip rounded-md border border-border bg-card">
        <div
          className={cn(
            'home-ai-composer-grow overflow-clip',
            animateGrow && 'home-ai-composer-grow-ready',
          )}
          style={growHeight === undefined ? undefined : { height: growHeight }}
        >
          <div ref={growInnerRef}>
            {attachment ? (
              <div className="border-b border-border px-2 py-2">
                <div className="flex min-w-max flex-nowrap gap-1.5">
                  <div className="w-40 shrink-0 rounded-md border border-border bg-muted/20 p-1.5">
                    <img
                      src={assetUrl(attachment.src)}
                      alt={attachment.name}
                      className="mb-1 aspect-video w-full rounded object-cover"
                    />
                    <div className="flex items-start gap-1.5">
                      <div className="min-w-0 flex-1">
                        <p
                          className="truncate text-[11px] font-medium text-foreground"
                          title={attachment.name}
                        >
                          {attachment.name}
                        </p>
                        <p className="text-[10px] text-muted-foreground">
                          {attachment.uploading
                            ? t('Uploading...')
                            : attachment.size}
                        </p>
                      </div>
                      <span className="rounded p-0.5 text-muted-foreground">
                        <X className="h-3 w-3" />
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            ) : null}
            <div className="flex items-end gap-2 p-2">
              <div
                className={cn(
                  'min-h-10 min-w-0 flex-1 px-2 py-2.5 text-[13px] leading-5',
                  showPlaceholder ? 'text-muted-foreground' : 'text-foreground',
                )}
              >
                {showPlaceholder ? t('Ask anything...') : draft}
                {isTyping ? (
                  <span className="ms-px inline-block h-[1em] w-px translate-y-[2px] bg-foreground animate-[ai-mock-cursor-blink_1s_step-end_infinite]" />
                ) : null}
              </div>
              <span
                className={cn(
                  'mb-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md',
                  attachment ? 'text-foreground' : 'text-muted-foreground',
                )}
              >
                <Paperclip className="h-3.5 w-3.5" />
              </span>
              <span className="mb-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground">
                <Mic className="h-3.5 w-3.5" />
              </span>
              <span
                className={cn(
                  'mb-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md',
                  sendActive
                    ? 'bg-foreground text-background'
                    : 'bg-muted text-muted-foreground',
                )}
              >
                <Send className="h-3.5 w-3.5" />
              </span>
            </div>
          </div>
        </div>
        <div className="flex min-h-9 items-center gap-1 border-t border-border px-1.5 py-1">
          <MockComposerChip
            icon={
              <img
                src={assetUrl("/icons/chatgpt.svg")}
                alt=""
                className={cn('h-3.5 w-3.5 shrink-0', PUBLIC_ICON_MUTED_CLASSES)}
              />
            }
            label="GPT 5.5"
          />
          <MockComposerChip
            icon={
              <span className="flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-sm bg-muted text-[8px] font-medium text-muted-foreground">
                Ac
              </span>
            }
            label="Acme"
          />
        </div>
      </div>
      <p className="mt-1.5 text-center text-[11px] text-muted-foreground">
        {t('Press Enter to send, Shift+Enter for new line')}
      </p>
    </div>
  )
}

export function AiAgentWorkspace() {
  const t = useT()
  const rootRef = useRef<HTMLDivElement>(null)
  const transcriptRef = useRef<HTMLDivElement>(null)
  const [inView, setInView] = useState(false)
  const [beat, setBeat] = useState(0)
  const [chatShifted, setChatShifted] = useState(false)
  const [artifactVisible, setArtifactVisible] = useState(false)
  const [topOverflow, setTopOverflow] = useState(false)
  const [typedDraft, setTypedDraft] = useState('')

  useEffect(() => {
    const node = rootRef.current
    if (!node) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry) return
        setInView(entry.isIntersecting)
      },
      { rootMargin: '200px 0px', threshold: 0 },
    )

    observer.observe(node)
    const rect = node.getBoundingClientRect()
    if (rect.bottom > 0 && rect.top < window.innerHeight) {
      setInView(true)
    }

    return () => observer.disconnect()
  }, [])

  useEffect(() => {
    if (!inView) return
    if (prefersReducedMotion()) {
      setBeat(STEP_DELAYS_MS.length)
      return
    }

    setBeat(0)
    let step = 0
    let timeoutId = 0

    const advance = () => {
      if (step >= STEP_DELAYS_MS.length) {
        timeoutId = window.setTimeout(() => {
          step = 0
          setBeat(0)
          timeoutId = window.setTimeout(advance, STEP_DELAYS_MS[0])
        }, HOLD_MS)
        return
      }

      timeoutId = window.setTimeout(() => {
        step += 1
        setBeat(step)
        advance()
      }, STEP_DELAYS_MS[Math.max(step, 0)] ?? 800)
    }

    timeoutId = window.setTimeout(advance, STEP_DELAYS_MS[0])
    return () => window.clearTimeout(timeoutId)
  }, [inView])

  useEffect(() => {
    const transcript = transcriptRef.current
    const frame = transcript?.parentElement
    if (!transcript || !frame) return

    const syncOverflow = () => {
      setTopOverflow(transcript.offsetHeight > frame.clientHeight + 8)
    }

    syncOverflow()
    const frameId = window.requestAnimationFrame(syncOverflow)
    const timeoutId = window.setTimeout(syncOverflow, 420)
    return () => {
      window.cancelAnimationFrame(frameId)
      window.clearTimeout(timeoutId)
    }
  }, [beat])

  const showMcpCard = true
  const mcpPhase: 'prompt' | 'oauth' | 'connected' =
    beat >= 3 ? 'connected' : beat >= 2 ? 'oauth' : 'prompt'
  const showUser1 = beat >= 5
  const showTools1 = beat >= 6
  const tools1Done = beat >= 7
  const showAssistant1 = beat >= 8
  const showUsersPane = beat >= 9
  const showUser2 = beat >= 11
  const showTools2 = beat >= 12
  const tools2Done = beat >= 13
  const showChartPane = beat >= 14
  const showUser3 = beat >= 16
  const showTools3 = beat >= 17
  const tools3Done = beat >= 18
  const showExecutionsPane = beat >= 19
  const showUser4 = beat >= 21
  const showTools4 = beat >= 22
  const tools4Done = beat >= 23
  const showCampaignPane = beat >= 24
  const showUser5 = beat >= 27
  const showTools5 = beat >= 28
  const tools5Done = beat >= 29
  const showFilesPane = beat >= 30

  const userPrompts = [
    t("Query Postgres for Friday's new users. Did the launch convert?"),
    t('Show requests since Friday. Did the launch spike traffic?'),
    t('Any failed executions from Friday?'),
    t(
      'Queue a Messaging campaign for the 9% who have not verified yet.',
    ),
    t('Upload this photo to a new Storage bucket.'),
  ]
  const typingPrompt =
    beat >= 4 && beat < 5
      ? userPrompts[0]
      : beat >= 10 && beat < 11
        ? userPrompts[1]
        : beat >= 15 && beat < 16
          ? userPrompts[2]
          : beat >= 20 && beat < 21
            ? userPrompts[3]
            : beat >= 26 && beat < 27
              ? userPrompts[4]
              : ''
  const isTyping = typingPrompt.length > 0
  const composerAttachment =
    beat >= 25 && beat < 27
      ? {
          src: UPLOAD_IMAGE.src,
          name: UPLOAD_IMAGE.name,
          size: UPLOAD_IMAGE.size,
          uploading: beat === 25,
        }
      : null

  const openLayout = showUsersPane
  const activeArtifact: ArtifactId = showFilesPane
    ? 'files'
    : showCampaignPane
      ? 'campaign'
      : showExecutionsPane
        ? 'executions'
        : showChartPane
          ? 'chart'
          : 'users'


  useEffect(() => {
    if (!typingPrompt) {
      setTypedDraft('')
      return
    }
    if (prefersReducedMotion()) {
      setTypedDraft(typingPrompt)
      return
    }
    setTypedDraft('')
    let index = 0
    let timeoutId = 0
    const tick = () => {
      index += 1
      setTypedDraft(typingPrompt.slice(0, index))
      if (index < typingPrompt.length) {
        const character = typingPrompt[index - 1]
        timeoutId = window.setTimeout(tick, character === ' ' ? 20 : 12)
      }
    }
    timeoutId = window.setTimeout(tick, 40)
    return () => window.clearTimeout(timeoutId)
  }, [typingPrompt])

  useEffect(() => {
    const reduced = prefersReducedMotion()
    if (openLayout) {
      setChatShifted(true)
      if (reduced) {
        setArtifactVisible(true)
        return
      }
      const timeoutId = window.setTimeout(() => {
        setArtifactVisible(true)
      }, ARTIFACT_REVEAL_MS)
      return () => window.clearTimeout(timeoutId)
    }

    setArtifactVisible(false)
    if (reduced) {
      setChatShifted(false)
      return
    }
    const timeoutId = window.setTimeout(() => {
      setChatShifted(false)
    }, LAYOUT_COLLAPSE_MS)
    return () => window.clearTimeout(timeoutId)
  }, [openLayout])

  return (
    <div
      ref={rootRef}
      dir="ltr"
      role="region"
      aria-label={t('Agent chat with Appwrite MCP')}
      className={cn(FORCE_LTR_CLASS, 'mx-auto flex w-full flex-col')}
    >
      <div className="pointer-events-none flex w-full min-w-0 flex-col lg:flex-row">
        <div
          aria-hidden
          className={cn(
            'home-ai-chat-spacer hidden lg:block',
            chatShifted && 'shifted',
          )}
          style={{ flexGrow: chatShifted ? 0 : 1 }}
        />
        <div className="mx-auto w-full max-w-[34rem] shrink-0 lg:mx-0 lg:w-[34rem]">
          <div className="relative h-[32rem] min-h-[32rem] min-w-0 overflow-clip">
            <div
              ref={transcriptRef}
              className="absolute inset-x-0 bottom-0 flex min-h-full flex-col gap-3 px-1 py-1 sm:px-2"
            >
            {showMcpCard ? <McpConnectCard phase={mcpPhase} /> : null}

            {showUser1 ? (
              <ChatMessage align="user">
                {t("Query Postgres for Friday's new users. Did the launch convert?")}
              </ChatMessage>
            ) : null}

            {showTools1 ? (
              <div className="home-ai-msg-in space-y-1.5">
                <ToolRow
                  icon="skill"
                  label={t('Using skill')}
                  detail="appwrite-postgres"
                  running={!tools1Done}
                />
                <ToolRow
                  icon="mcp"
                  label={t('Ran MCP')}
                  detail="postgresql.createExecution"
                  running={!tools1Done}
                />
              </div>
            ) : null}

            {showAssistant1 ? (
              <ChatMessage align="assistant">
                {t(
                  '2,184 new rows in users since Friday. Email verification is at 91%. Opening the latest signups.',
                )}
              </ChatMessage>
            ) : null}

            {showUser2 ? (
              <ChatMessage align="user">
                {t(
                  'Show requests since Friday. Did the launch spike traffic?',
                )}
              </ChatMessage>
            ) : null}

            {showTools2 ? (
              <div className="home-ai-msg-in space-y-1.5">
                <ToolRow
                  icon="skill"
                  label={t('Using skill')}
                  detail="appwrite-usage"
                  running={!tools2Done}
                />
                <ToolRow
                  icon="mcp"
                  label={t('Ran MCP')}
                  detail="project.getUsage"
                  running={!tools2Done}
                />
              </div>
            ) : null}

            {showChartPane ? (
              <ChatMessage align="assistant">
                {t(
                  '184K requests since Friday, 3.2x Thursday. Traffic is holding through the weekend.',
                )}
              </ChatMessage>
            ) : null}

            {showUser3 ? (
              <ChatMessage align="user">
                {t('Any failed executions from Friday?')}
              </ChatMessage>
            ) : null}

            {showTools3 ? (
              <div className="home-ai-msg-in space-y-1.5">
                <ToolRow
                  icon="mcp"
                  label={t('Ran MCP')}
                  detail="functions.listExecutions"
                  running={!tools3Done}
                />
              </div>
            ) : null}

            {showExecutionsPane ? (
              <ChatMessage align="assistant">
                {t(
                  '12 executions timed out on cold start. The rest completed.',
                )}
              </ChatMessage>
            ) : null}

            {showUser4 ? (
              <ChatMessage align="user">
                {t(
                  'Queue a Messaging campaign for the 9% who have not verified yet.',
                )}
              </ChatMessage>
            ) : null}

            {showTools4 ? (
              <div className="home-ai-msg-in space-y-1.5">
                <ToolRow
                  icon="skill"
                  label={t('Using skill')}
                  detail="appwrite-messaging"
                  running={!tools4Done}
                />
                <ToolRow
                  icon="mcp"
                  label={t('Ran MCP')}
                  detail="messaging.createEmail"
                  running={!tools4Done}
                />
              </div>
            ) : null}

            {showCampaignPane ? (
              <ChatMessage align="assistant">
                {t(
                  'Draft is ready for 187 unverified accounts, scheduled tomorrow at 9am.',
                )}
              </ChatMessage>
            ) : null}

            {showUser5 ? (
              <ChatMessage
                align="user"
                image={{ src: UPLOAD_IMAGE.src, name: UPLOAD_IMAGE.name }}
              >
                {t('Upload this photo to a new Storage bucket.')}
              </ChatMessage>
            ) : null}

            {showTools5 ? (
              <div className="home-ai-msg-in space-y-1.5">
                <ToolRow
                  icon="skill"
                  label={t('Using skill')}
                  detail="appwrite-storage"
                  running={!tools5Done}
                />
                <ToolRow
                  icon="mcp"
                  label={t('Ran MCP')}
                  detail="storage.createBucket"
                  running={!tools5Done}
                />
                <ToolRow
                  icon="mcp"
                  label={t('Ran MCP')}
                  detail="storage.createFile"
                  running={!tools5Done}
                />
              </div>
            ) : null}

            {showFilesPane ? (
              <ChatMessage align="assistant">
                {t(
                  'Created the campaign-assets bucket and uploaded kittens.jpg.',
                )}
              </ChatMessage>
            ) : null}
            </div>
            <div
              aria-hidden
              className={cn(
                'pointer-events-none absolute inset-x-0 top-0 z-10 h-20 bg-gradient-to-b from-background via-background/75 to-transparent transition-opacity duration-500 ease-out',
                topOverflow ? 'opacity-100' : 'opacity-0',
              )}
            />
          </div>
        </div>

        <div
          className={cn(
            'home-ai-layout-pane relative min-w-0 lg:flex lg:min-w-0 lg:flex-1 lg:basis-0',
            artifactVisible
              ? 'mt-4 h-[18rem] w-full opacity-100 lg:mt-0 lg:h-[32rem] lg:w-auto lg:translate-x-0'
              : 'pointer-events-none h-0 w-full opacity-0 lg:h-[32rem] lg:w-auto lg:translate-x-4',
          )}
        >
          {chatShifted ? (
            <div
              className={cn(
                'h-full w-full overflow-clip rounded-xl border border-border lg:ms-6',
                artifactVisible && 'home-ai-pane-in',
              )}
            >
              <ArtifactPane active={activeArtifact} />
            </div>
          ) : null}
        </div>
      </div>

      <div className="pointer-events-none mx-auto w-full max-w-[34rem] shrink-0 pt-6">
        <MockAgentComposer
          draft={typedDraft}
          isTyping={isTyping}
          attachment={composerAttachment}
        />
      </div>
    </div>
  )
}
