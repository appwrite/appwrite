import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import {
  Check,
  ChevronDown,
  ChevronRight,
  ExternalLink,
  Lock,
  Minus,
} from 'lucide-react'
import { ServiceHeader } from '../shared/ServiceHeader'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  useOnboardingProgressFromSnapshot,
  useOnboardingStepStates,
  useProjectOnboardingSnapshot,
  useSkipOnboardingStep,
} from '@/lib/react-query/hooks/onboarding'
import {
  computeOnboardingProductBreakdown,
  getOnboardingGroupState,
  isOnboardingStepDone,
  ONBOARDING_AGENT_STEP,
  ONBOARDING_CONNECT,
  ONBOARDING_PRODUCT_CATEGORIES,
  subStepCountsTowardProgress,
  type OnboardingStepState,
  type OnboardingProductBreakdownRow,
  type OnboardingConnectStepDef,
  type OnboardingSubStepDef,
  type ProductNavCategoryId,
  type ProjectOnboardingSnapshot,
} from '@/lib/onboarding/project-onboarding'
import {
  getEncouragementBand,
  pickEncouragementForBand,
} from '@/lib/onboarding/progress-encouragement'
import {
  getOnboardingAgentStepState,
  markOnboardingAgentStepDone,
  markOnboardingAgentStepSkipped,
} from '@/lib/mcp-adoption'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { cn } from '@/lib/utils'
import { useI18n } from '@/lib/i18n'
import { useT } from '@/lib/i18n/translate'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'

type OnboardingStepRow = OnboardingConnectStepDef | OnboardingSubStepDef

const CONNECT_SECTION = {
  title: 'Connect',
  description:
    'Register where your app runs, add API credentials, and connect a coding agent with MCP.',
}

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

/** Shared horizontal rhythm for checklist rows (connect, category headers, sub-steps). */
const ONBOARDING_ROW_X = 'px-4 sm:px-5'
const ONBOARDING_ICON_COL = 'flex w-7 shrink-0 justify-center'
const ONBOARDING_ICON_GAP = 'gap-3'

/** viewBox units - SVG scales with container (mobile vs desktop ring size). */
const RING_VB = 120
const RING_STROKE = 8

const EMPTY_SNAPSHOT: ProjectOnboardingSnapshot = {
  stagesBySdk: {},
}

function OnboardingProductBreakdown({
  rows,
  showSkeleton,
  className,
  connectComplete,
}: {
  rows: OnboardingProductBreakdownRow[]
  showSkeleton: boolean
  className?: string
  connectComplete: boolean
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'w-full space-y-3',
        showSkeleton && 'animate-pulse',
        className,
      )}
      aria-label={t('Progress by product')}
    >
      {rows.map((row) => {
        const pct =
          row.total === 0 ? 0 : Math.round((row.completed / row.total) * 100)
        const locked = row.id !== 'connect' && !connectComplete

        const rowInner = (
          <div
            className={cn(
              'space-y-1.5 w-full text-start',
              locked && 'opacity-[0.65]',
            )}
          >
            <div className="flex items-center justify-between gap-2 min-w-0">
              <span
                className={cn(
                  'text-[11px] font-medium truncate',
                  locked ? 'text-muted-foreground' : 'text-foreground',
                )}
              >
                {t(row.label)}
              </span>
              <span className="flex items-center gap-1.5 shrink-0">
                <span className="text-[10px] tabular-nums text-muted-foreground">
                  {row.completed}/{row.total}
                </span>
                {locked ? (
                  <Lock className="size-3 shrink-0 text-muted-foreground" aria-hidden />
                ) : null}
              </span>
            </div>
            <Progress
              value={showSkeleton ? 0 : pct}
              className={cn(
                'h-1.5 w-full bg-muted/80',
                locked
                  ? '[&>div]:!bg-muted-foreground/30'
                  : '[&>div]:!bg-[var(--brand-cta)]',
              )}
            />
          </div>
        )

        return locked ? (
          <Tooltip key={row.id}>
            <TooltipTrigger asChild>
              <div className="block w-full">{rowInner}</div>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs text-balance">
              {t('Connect your app first.')}
            </TooltipContent>
          </Tooltip>
        ) : (
          <div key={row.id}>{rowInner}</div>
        )
      })}
    </div>
  )
}

function OnboardingProgressPanel({
  projectId,
  progress,
  completedSteps,
  totalSteps,
  showSkeleton,
  productBreakdown,
  connectComplete,
}: {
  projectId: string
  progress: number
  completedSteps: number
  totalSteps: number
  showSkeleton: boolean
  productBreakdown: OnboardingProductBreakdownRow[]
  connectComplete: boolean
}) {
  const t = useT()
  const { previewOnboardingComplete } = useDebugOverrides()
  const [breakdownOpen, setBreakdownOpen] = useState(false)
  const c = RING_VB / 2
  const radius = (RING_VB - RING_STROKE) / 2
  const circumference = 2 * Math.PI * radius
  const displayProgress = previewOnboardingComplete ? 100 : progress
  const strokeDashoffset = circumference * (1 - displayProgress / 100)
  const complete = !showSkeleton && displayProgress === 100

  const encouragementBand = getEncouragementBand(displayProgress)
  const headline = useMemo(
    () => pickEncouragementForBand(encouragementBand),
    [encouragementBand, projectId],
  )

  const ringInline =
    'relative aspect-square w-[min(92px,25vw)] shrink-0 sm:w-[120px] text-[var(--brand-cta)]'
  const ringStacked =
    'relative aspect-square w-[120px] shrink-0 text-[var(--brand-cta)]'

  const progressRing = (layout: 'inline' | 'stacked') => {
    const wrap = layout === 'inline' ? ringInline : ringStacked
    if (showSkeleton) {
      return (
        <div
          className={cn(
            'rounded-full bg-muted animate-pulse shrink-0 aspect-square',
            layout === 'inline' ? 'w-[min(92px,25vw)] sm:w-[120px]' : 'w-[120px]',
          )}
          aria-hidden
        />
      )
    }
    if (complete) {
      return (
        <div
          className={cn(
            'flex shrink-0 items-center justify-center rounded-full border border-border bg-card/80',
            wrap,
          )}
        >
          <span className="flex h-[60%] w-[60%] items-center justify-center rounded-full border border-emerald-500/35 bg-emerald-500/10">
            <Check className="h-1/2 w-1/2 text-emerald-600 dark:text-emerald-400" />
          </span>
        </div>
      )
    }
    return (
      <div className={cn('relative shrink-0', wrap)}>
        <svg
          viewBox={`0 0 ${RING_VB} ${RING_VB}`}
          className="h-full w-full -rotate-90"
          aria-hidden
        >
          <circle
            cx={c}
            cy={c}
            r={radius}
            fill="none"
            stroke="currentColor"
            strokeWidth={RING_STROKE}
            className="opacity-20"
          />
          <circle
            cx={c}
            cy={c}
            r={radius}
            fill="none"
            stroke="currentColor"
            strokeWidth={RING_STROKE}
            strokeLinecap="round"
            strokeDasharray={circumference}
            strokeDashoffset={strokeDashoffset}
            className="transition-[stroke-dashoffset] duration-300 ease-out"
          />
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-0.5 px-1.5">
          <span
            className={cn(
              'font-semibold tabular-nums text-foreground leading-none',
              layout === 'inline'
                ? 'text-[clamp(1rem,4.5vw,1.375rem)]'
                : 'text-[22px]',
            )}
          >
            {progress}%
          </span>
          <span
            className={cn(
              'font-medium text-muted-foreground tabular-nums',
              layout === 'inline' ? 'text-[10px] sm:text-[11px]' : 'text-[11px]',
            )}
          >
            {completedSteps}/{totalSteps}
          </span>
        </div>
      </div>
    )
  }

  const headlineBlock = (
    <>
      <h2 className="text-[13px] font-semibold text-foreground leading-snug">
        {t(headline)}
      </h2>
      <p className="text-[11px] text-muted-foreground leading-snug mt-1.5">
        {t(
          complete
            ? "We're focused on building a product Appwriters love. The best way we grow is when the community helps spread the word."
            : 'Connect this project, then complete each product area - one clear action at a time.',
        )}
      </p>
    </>
  )

  return (
    <div className={cn(CARD_SHELL, 'w-full')}>
      {/* Narrow / single column: headline + ring side by side (saves vertical space) */}
      <div className="px-4 py-4 sm:px-5 sm:py-5 lg:hidden">
        <div className="flex flex-row items-start gap-3 sm:gap-5">
          <div className="min-w-0 flex-1 text-start">{headlineBlock}</div>
          <div
            className={cn(
              'flex shrink-0 justify-end pt-0.5',
              showSkeleton && 'items-center',
            )}
          >
            {progressRing('inline')}
          </div>
        </div>
        <div className="mt-5 w-full space-y-4 pt-1">
          <button
            type="button"
            onClick={() => setBreakdownOpen((o) => !o)}
            className="flex w-full cursor-pointer items-center justify-center gap-1.5 py-1 text-[11px] font-medium text-muted-foreground hover:text-foreground transition-colors"
            aria-expanded={breakdownOpen}
          >
            <span>{t('Breakdown')}</span>
            <ChevronDown
              className={cn(
                'size-3.5 shrink-0 transition-transform',
                breakdownOpen && 'rotate-180',
              )}
              aria-hidden
            />
          </button>
          {breakdownOpen ? (
            <OnboardingProductBreakdown
              rows={productBreakdown}
              showSkeleton={showSkeleton}
              connectComplete={connectComplete}
            />
          ) : null}
        </div>
      </div>

      {/* lg+ sidebar: original stacked card - copy, separator, centered ring */}
      <div className="hidden lg:block">
        <div className="px-4 py-4 text-start sm:px-5 sm:py-5">{headlineBlock}</div>
        <div className="border-t border-border" />
        <div className="flex flex-col items-center px-4 pb-6 pt-5 sm:px-5 sm:pb-7 sm:pt-6">
          {progressRing('stacked')}
          <div className="mt-6 w-full max-w-[240px] space-y-4">
            <button
              type="button"
              onClick={() => setBreakdownOpen((o) => !o)}
              className="flex w-full cursor-pointer items-center justify-center gap-1.5 py-1 text-[11px] font-medium text-muted-foreground hover:text-foreground transition-colors"
              aria-expanded={breakdownOpen}
            >
              <span>{t('Breakdown')}</span>
              <ChevronDown
                className={cn(
                  'size-3.5 shrink-0 transition-transform',
                  breakdownOpen && 'rotate-180',
                )}
                aria-hidden
              />
            </button>
            {breakdownOpen ? (
              <OnboardingProductBreakdown
                className="w-full"
                rows={productBreakdown}
                showSkeleton={showSkeleton}
                connectComplete={connectComplete}
              />
            ) : null}
          </div>
        </div>
      </div>

      <div className="border-t border-border" />

      <div className="px-4 py-4 sm:px-5 sm:py-4 bg-muted/30 flex flex-col gap-2">
        <Button variant="outline" size="sm" className="h-9 w-full text-[13px]" asChild>
          <Link to="/projects/$projectId" params={{ projectId }}>
            {t('Go to dashboard')}
          </Link>
        </Button>
        {complete ? (
          <Button variant="outline" size="sm" className="h-9 w-full text-[13px]" asChild>
            <a
              href={MARKETING_SOCIAL_STATS.github.link}
              target="_blank"
              rel="noopener noreferrer"
            >
              {t('Star on GitHub')}
              <ExternalLink className="ms-1.5 h-3.5 w-3.5 shrink-0" />
            </a>
          </Button>
        ) : null}
      </div>
    </div>
  )
}

type ViewProps = {
  initialData?: { snapshot: ProjectOnboardingSnapshot }
}

function StepStatusIcon({ state }: { state: OnboardingStepState }) {
  const t = useT()
  if (state === 'completed') {
    return (
      <span
        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-emerald-500/30 bg-emerald-500/10"
        aria-hidden
      >
        <Check className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
      </span>
    )
  }
  if (state === 'skipped') {
    return (
      <span
        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/35 bg-muted/30"
        title={t('Skipped')}
        aria-hidden
      >
        <Minus className="h-3.5 w-3.5 text-muted-foreground" />
      </span>
    )
  }
  return (
    <span
      className="h-7 w-7 shrink-0 rounded-full border-2 border-muted-foreground/20 bg-transparent"
      aria-hidden
    />
  )
}

/** Accordion group header: lock, completion ring, skipped, or empty ring on the left. */
function GroupStatusIcon({
  locked,
  state,
}: {
  locked: boolean
  state: OnboardingStepState
}) {
  if (locked) {
    return (
      <span
        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-border bg-muted/40"
        aria-hidden
      >
        <Lock className="h-3.5 w-3.5 text-muted-foreground" />
      </span>
    )
  }
  return <StepStatusIcon state={state} />
}

/** Shown when a row is navigable but not part of the global progress denominator. */
function StepStatusNotTrackedIcon() {
  const t = useT()
  return (
    <span
      className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/35 bg-muted/25"
      title={t('Not counted in overall progress')}
    >
      <span className="sr-only">{t('Not counted in overall progress')}</span>
      <span className="text-[11px] font-medium leading-none text-muted-foreground/80" aria-hidden>
         - 
      </span>
    </span>
  )
}

function SubStepRow({
  step,
  projectId,
  state,
  countsTowardProgress,
  isDebugModeOpen,
  onSkip,
  skipPending,
}: {
  step: OnboardingStepRow
  projectId: string
  state: OnboardingStepState
  countsTowardProgress: boolean
  isDebugModeOpen: boolean
  onSkip?: () => void
  skipPending?: boolean
}) {
  const t = useT()
  const fulfilled = state !== 'pending'
  const ctaLabel = t(fulfilled ? (step.ctaDone ?? 'Open') : step.cta)

  return (
    <div
      className={cn(
        'flex flex-col gap-3 py-3 sm:flex-row sm:items-stretch sm:gap-3',
        ONBOARDING_ROW_X,
      )}
    >
      <div className={cn('flex min-w-0 flex-1', ONBOARDING_ICON_GAP)}>
        <div
          className={cn(
            ONBOARDING_ICON_COL,
            'items-start pt-0.5 sm:items-center sm:self-stretch sm:pt-0',
          )}
        >
          {countsTowardProgress ? (
            <StepStatusIcon state={state} />
          ) : (
            <StepStatusNotTrackedIcon />
          )}
        </div>
        <div className="min-w-0 flex-1 space-y-0.5">
          <span
            className={cn(
              'text-[13px] font-medium block',
              state === 'skipped'
                ? 'text-muted-foreground'
                : 'text-foreground',
            )}
          >
            {t(step.label)}
            {state === 'skipped' ? (
              <span className="sr-only"> ({t('skipped')})</span>
            ) : null}
          </span>
          <p className="text-[12px] text-muted-foreground leading-relaxed">
            {t(step.hint)}
          </p>
          {isDebugModeOpen && (
            <p className="text-[10px] text-amber-700/90 dark:text-amber-400/90 font-mono leading-snug pt-1">
              {step.debug}
            </p>
          )}
        </div>
      </div>
      <div className="flex w-full shrink-0 items-center justify-end gap-1.5 sm:w-auto sm:self-center sm:ps-0">
        {!fulfilled && onSkip ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 shrink-0 px-2 text-[12px] font-normal text-muted-foreground hover:text-foreground"
            disabled={skipPending}
            onClick={onSkip}
          >
            {t('Skip')}
          </Button>
        ) : null}
        <Button
          variant="outline"
          size="sm"
          className={cn(
            'h-9 min-w-0 flex-1 gap-1.5 px-3 text-[12px] font-medium sm:h-8 sm:w-auto sm:max-w-[11rem] sm:flex-none',
            fulfilled
              ? 'text-muted-foreground'
              : 'border-[color-mix(in_srgb,var(--brand-cta)_40%,var(--border))] bg-background text-[var(--brand-cta)] hover:bg-[color-mix(in_srgb,var(--brand-cta)_10%,transparent)] hover:text-[var(--brand-cta)]',
          )}
          asChild
        >
          <Link
            to={step.to}
            params={{
              projectId,
              ...('params' in step ? step.params : undefined),
            }}
            className="inline-flex min-w-0 items-center justify-center gap-1.5 sm:justify-start"
            title={ctaLabel}
          >
            <span className="truncate">{ctaLabel}</span>
            <ChevronRight className="size-3.5 shrink-0 opacity-70" />
          </Link>
        </Button>
      </div>
    </div>
  )
}

function AgentConnectStepRow({
  projectId,
  isDebugModeOpen,
}: {
  projectId: string
  isDebugModeOpen: boolean
}) {
  const t = useT()
  const projectConnect = useProjectConnectDialog()
  const [state, setState] = useState<OnboardingStepState>(() =>
    getOnboardingAgentStepState(projectId),
  )

  useEffect(() => {
    setState(getOnboardingAgentStepState(projectId))
  }, [projectId])

  const fulfilled = state !== 'pending'
  const ctaLabel = t(
    fulfilled ? ONBOARDING_AGENT_STEP.ctaDone : ONBOARDING_AGENT_STEP.cta,
  )

  const handleSkip = () => {
    markOnboardingAgentStepSkipped(projectId)
    setState('skipped')
  }

  const handleOpen = () => {
    markOnboardingAgentStepDone(projectId)
    setState('completed')
    projectConnect?.openConnect('mcp')
  }

  return (
    <div
      className={cn(
        'flex flex-col gap-3 py-3 sm:flex-row sm:items-stretch sm:gap-3',
        ONBOARDING_ROW_X,
      )}
    >
      <div className={cn('flex min-w-0 flex-1', ONBOARDING_ICON_GAP)}>
        <div
          className={cn(
            ONBOARDING_ICON_COL,
            'items-start pt-0.5 sm:items-center sm:self-stretch sm:pt-0',
          )}
        >
          <StepStatusNotTrackedIcon />
        </div>
        <div className="min-w-0 flex-1 space-y-0.5">
          <span
            className={cn(
              'text-[13px] font-medium block',
              state === 'skipped'
                ? 'text-muted-foreground'
                : 'text-foreground',
            )}
          >
            {t(ONBOARDING_AGENT_STEP.label)}
            {state === 'skipped' ? (
              <span className="sr-only"> ({t('skipped')})</span>
            ) : null}
          </span>
          <p className="text-[12px] text-muted-foreground leading-relaxed">
            {t(ONBOARDING_AGENT_STEP.hint)}
          </p>
          {isDebugModeOpen && (
            <p className="text-[10px] text-amber-700/90 dark:text-amber-400/90 font-mono leading-snug pt-1">
              {ONBOARDING_AGENT_STEP.debug}
            </p>
          )}
        </div>
      </div>
      <div className="flex w-full shrink-0 items-center justify-end gap-1.5 sm:w-auto sm:self-center sm:ps-0">
        {!fulfilled ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 shrink-0 px-2 text-[12px] font-normal text-muted-foreground hover:text-foreground"
            onClick={handleSkip}
          >
            {t('Skip')}
          </Button>
        ) : null}
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn(
            'h-9 min-w-0 flex-1 gap-1.5 px-3 text-[12px] font-medium sm:h-8 sm:w-auto sm:max-w-[11rem] sm:flex-none',
            fulfilled
              ? 'text-muted-foreground'
              : 'border-[color-mix(in_srgb,var(--brand-cta)_40%,var(--border))] bg-background text-[var(--brand-cta)] hover:bg-[color-mix(in_srgb,var(--brand-cta)_10%,transparent)] hover:text-[var(--brand-cta)]',
          )}
          onClick={handleOpen}
          title={ctaLabel}
        >
          <span className="truncate">{ctaLabel}</span>
          <ChevronRight className="size-3.5 shrink-0 opacity-70" />
        </Button>
      </div>
    </div>
  )
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { catalog } = useI18n()
  const getStartedTitle = catalog.app.sidebar.onboarding.getStarted
  const { projectId } = useParams({ strict: false })
  const { data: snapshotFromHook, isLoading } =
    useProjectOnboardingSnapshot(projectId)
  const snapshot = snapshotFromHook ?? initialData?.snapshot
  const { isDebugModeOpen } = useDebugMode()
  const { unlockOnboardingLocks } = useDebugOverrides()
  const skipStepMutation = useSkipOnboardingStep(projectId)

  const { progress, completedSteps, totalSteps } =
    useOnboardingProgressFromSnapshot(snapshot)
  const stepStates = useOnboardingStepStates(snapshot)

  const connectComplete =
    unlockOnboardingLocks ||
    (!!snapshot &&
      ONBOARDING_CONNECT.every((step) =>
        isOnboardingStepDone(snapshot, step.sdkKeys),
      ))

  const showSkeleton = isLoading && !snapshot

  const productBreakdown = useMemo(
    () => computeOnboardingProductBreakdown(snapshot ?? EMPTY_SNAPSHOT),
    [snapshot],
  )

  /** Per-category open accordion item ids (`type="multiple"`). */
  const [accordionOpenByCategory, setAccordionOpenByCategory] = useState<
    Partial<Record<ProductNavCategoryId, string[]>>
  >({})

  if (!projectId) {
    return null
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader title={getStartedTitle} fullWidthBorder />

      <div className="mx-auto flex w-full min-w-0 max-w-7xl flex-1 flex-col gap-6 px-4 pt-4 pb-4 sm:px-6 sm:pt-6 sm:pb-6 lg:grid lg:grid-cols-[minmax(0,240px)_minmax(0,1fr)] lg:items-start lg:gap-8">
        <aside className="w-full min-w-0 lg:sticky lg:top-4 lg:z-10">
          <OnboardingProgressPanel
            projectId={projectId}
            progress={progress}
            completedSteps={completedSteps}
            totalSteps={totalSteps}
            showSkeleton={showSkeleton}
            productBreakdown={productBreakdown}
            connectComplete={connectComplete}
          />
        </aside>

        <div className="min-w-0 flex flex-col">
          <div className={CARD_SHELL}>
          <div className="px-4 py-3 border-b border-border bg-muted/10">
            <h2 className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t(CONNECT_SECTION.title)}
            </h2>
            <p className="text-[13px] text-muted-foreground mt-1.5 leading-snug">
              {t(CONNECT_SECTION.description)}
            </p>
          </div>
          <ul className="divide-y divide-border">
            {ONBOARDING_CONNECT.map((step) => {
              const state = stepStates.get(step.id) ?? 'pending'
              return (
                <li key={step.id}>
                  <SubStepRow
                    step={step}
                    projectId={projectId}
                    state={state}
                    countsTowardProgress
                    isDebugModeOpen={isDebugModeOpen}
                    onSkip={() => skipStepMutation.mutate(step.sdkKeys)}
                    skipPending={skipStepMutation.isPending}
                  />
                </li>
              )
            })}
            <li key={ONBOARDING_AGENT_STEP.id}>
              <AgentConnectStepRow
                projectId={projectId}
                isDebugModeOpen={isDebugModeOpen}
              />
            </li>
          </ul>
          </div>

          {ONBOARDING_PRODUCT_CATEGORIES.map((category) => {
            const categoryGroupIds = category.groups.map((g) => g.id)
            const openForCategory = accordionOpenByCategory[category.id] ?? []
            const hasAnyOpen = openForCategory.length > 0

            return (
            <section key={category.id} className="mt-10 space-y-3">
              <div className="flex items-start justify-between gap-3 px-1">
                <h3 className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground min-w-0">
                  {t(category.label)}
                </h3>
                {connectComplete && categoryGroupIds.length > 1 ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-auto min-h-0 shrink-0 py-1 -me-1 px-2 text-[11px] font-medium text-muted-foreground hover:text-foreground"
                    onClick={() => {
                      setAccordionOpenByCategory((prev) => ({
                        ...prev,
                        [category.id]: hasAnyOpen ? [] : categoryGroupIds,
                      }))
                    }}
                    aria-expanded={hasAnyOpen}
                    aria-label={
                      hasAnyOpen
                        ? `Collapse all ${category.label} sections`
                        : `Expand all ${category.label} sections`
                    }
                  >
                    {hasAnyOpen ? t('Collapse all') : t('Expand all')}
                  </Button>
                ) : null}
              </div>

              <Accordion
                type="multiple"
                value={openForCategory}
                onValueChange={(next) => {
                  setAccordionOpenByCategory((prev) => ({
                    ...prev,
                    [category.id]: next,
                  }))
                }}
                className="flex flex-col gap-4"
              >
                {category.groups.map((group) => {
                  const subs = group.subSteps
                  const trackedSubs = subs.filter((s) =>
                    subStepCountsTowardProgress(s),
                  )
                  const trackedSubIds = trackedSubs.map((s) => s.id)
                  const doneInGroup = trackedSubs.filter(
                    (s) => (stepStates.get(s.id) ?? 'pending') !== 'pending',
                  ).length
                  const trackedTotal = trackedSubs.length
                  const groupState = getOnboardingGroupState(
                    trackedSubIds,
                    stepStates,
                  )
                  const locked = !connectComplete

                  const item = (
                    <AccordionItem
                      key={group.id}
                      value={group.id}
                      disabled={locked}
                      className={cn(
                        'rounded-xl border border-border bg-card/50 overflow-hidden',
                        'last:border-b last:border-border',
                        locked && 'bg-muted/20 border-muted-foreground/15',
                      )}
                    >
                      <AccordionTrigger
                        className={cn(
                          'items-start py-4 hover:no-underline',
                          ONBOARDING_ROW_X,
                          '[&>svg]:mt-1.5 [&>svg]:shrink-0',
                          locked
                            ? 'cursor-not-allowed'
                            : 'cursor-pointer',
                        )}
                      >
                        <div
                          className={cn(
                            'flex min-w-0 flex-1 items-start',
                            ONBOARDING_ICON_GAP,
                          )}
                        >
                          <div className={cn(ONBOARDING_ICON_COL, 'pt-0.5')}>
                            <GroupStatusIcon locked={locked} state={groupState} />
                          </div>
                          <div className="flex min-w-0 flex-1 items-start justify-between gap-4">
                            <div className="min-w-0 flex-1 flex flex-col gap-1.5">
                              <span className="flex flex-wrap items-baseline gap-2 min-w-0">
                                <span className="text-[15px] font-semibold tracking-tight text-foreground leading-snug">
                                  {t(group.label)}
                                </span>
                                {group.comingSoon ? (
                                  <Badge
                                    variant="info"
                                    className="text-[10px] shrink-0"
                                  >
                                    {t('Soon')}
                                  </Badge>
                                ) : null}
                              </span>
                              <p className="text-[13px] text-muted-foreground leading-relaxed line-clamp-4 m-0">
                                {t(group.description)}
                              </p>
                            </div>
                            {trackedTotal > 0 ? (
                              <span className="flex shrink-0 items-center pt-0.5 text-[11px] font-medium text-muted-foreground tabular-nums">
                                {doneInGroup}/{trackedTotal}
                              </span>
                            ) : null}
                          </div>
                        </div>
                      </AccordionTrigger>
                      <AccordionContent className="border-t border-border bg-muted/5 pb-0">
                        <ul className="divide-y divide-border">
                          {subs.map((sub) => {
                            const state = stepStates.get(sub.id) ?? 'pending'
                            return (
                              <li key={sub.id}>
                                <SubStepRow
                                  step={sub}
                                  projectId={projectId}
                                  state={state}
                                  countsTowardProgress={subStepCountsTowardProgress(
                                    sub,
                                  )}
                                  isDebugModeOpen={isDebugModeOpen}
                                  onSkip={() => skipStepMutation.mutate(sub.sdkKeys)}
                                  skipPending={skipStepMutation.isPending}
                                />
                              </li>
                            )
                          })}
                        </ul>
                      </AccordionContent>
                    </AccordionItem>
                  )

                  return locked ? (
                    <Tooltip key={group.id}>
                      <TooltipTrigger asChild>{item}</TooltipTrigger>
                      <TooltipContent side="top" className="max-w-xs text-balance">
                        {t('Connect your app first.')}
                      </TooltipContent>
                    </Tooltip>
                  ) : (
                    item
                  )
                })}
              </Accordion>
            </section>
            )
          })}
        </div>
      </div>
    </div>
  )
}
