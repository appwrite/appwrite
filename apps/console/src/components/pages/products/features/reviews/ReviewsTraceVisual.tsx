import {
  BookOpen,
  CircleX,
  FileCode2,
  FileSearch,
  GraduationCap,
  Link2,
  Package,
  Search,
  ShieldCheck,
  SquareTerminal,
  Upload,
  type LucideIcon,
} from 'lucide-react'
import {
  ArtConnector,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** What a review starts from: the pull request and the repository, never your project's data. */
const CONTEXT: { icon: LucideIcon; label: string; detail: string }[] = [
  {
    icon: FileCode2,
    label: 'The diff and the repository',
    detail: 'Read at the pull request’s head',
  },
  {
    icon: BookOpen,
    label: 'Your team’s guidelines',
    detail: 'AGENTS.md, CLAUDE.md, CONTRIBUTING.md',
  },
  { icon: Link2, label: 'Linked issues', detail: 'What the change is for' },
  { icon: CircleX, label: 'Failed checks', detail: 'From your other CI apps' },
  {
    icon: Package,
    label: 'Dependency changes',
    detail: 'Versions bumped in the lockfile',
  },
  {
    icon: GraduationCap,
    label: 'Learnings',
    detail: 'Rules your team asked it to keep',
  },
]

/** The steps of one review, as the Console's live trace lists them. */
const TRACE: {
  icon: LucideIcon
  label: string
  detail: string
  highlight?: boolean
}[] = [
  { icon: FileSearch, label: 'Read file', detail: 'src/auth/retry.ts' },
  { icon: Search, label: 'Search', detail: 'signIn(' },
  {
    icon: SquareTerminal,
    label: 'Run command',
    detail: 'node scripts/try-retry.mjs',
  },
  {
    icon: ShieldCheck,
    label: 'Verified',
    detail: 'Kept 3 of 5 findings',
    highlight: true,
  },
  { icon: Upload, label: 'Posted', detail: '3 comments and a summary' },
]

/** Context flowing into the review, then the trace of what it read, ran, and kept. */
export function ReviewsTraceVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[600px] py-4 text-start">
      <div className="grid items-center gap-5 sm:grid-cols-[minmax(0,1fr)_2.5rem_minmax(0,1fr)] sm:gap-0">
        <ul className="space-y-1.5">
          {CONTEXT.map((item, index) => {
            const Icon = item.icon
            return (
              <li key={item.label}>
                <ArtPanel
                  delayMs={60 + index * 80}
                  innerClassName="flex items-center gap-2.5 px-3 py-2"
                >
                  <Icon
                    className="size-3.5 shrink-0 text-[var(--tone-ink)]"
                    aria-hidden
                  />
                  <span className="min-w-0">
                    <span className="block truncate text-[11.5px] font-medium text-foreground">
                      {t(item.label)}
                    </span>
                    <span className="block truncate text-[10px] text-muted-foreground">
                      {t(item.detail)}
                    </span>
                  </span>
                </ArtPanel>
              </li>
            )
          })}
        </ul>

        <div className="hidden sm:block">
          <ArtConnector
            travel
            travelDelayMs={700}
            className="-mx-px w-[calc(100%+2px)]"
          />
        </div>

        <ArtPanel delayMs={520} innerClassName="product-tone-shadow p-3">
          <p className="flex items-center justify-between text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Trace')}
            <span className="font-normal normal-case tracking-normal">
              {t('Incremental review')}
            </span>
          </p>
          <ol className="mt-2 space-y-1">
            {TRACE.map((step, index) => {
              const Icon = step.icon
              return (
                <li
                  key={step.label}
                  className={cn(
                    'product-hero-rise flex items-center gap-2 rounded-md px-1.5 py-1.5 text-[11px]',
                    step.highlight && 'bg-[rgb(var(--tone-rgb)/0.12)]',
                  )}
                  style={riseStyle(700 + index * 120)}
                >
                  <Icon
                    className={cn(
                      'size-3.5 shrink-0',
                      step.highlight
                        ? 'text-[var(--tone-ink)]'
                        : 'text-muted-foreground',
                    )}
                    aria-hidden
                  />
                  <span className="shrink-0 font-medium text-foreground">
                    {t(step.label)}
                  </span>
                  <span
                    dir={
                      step.highlight || step.label === 'Posted'
                        ? undefined
                        : 'ltr'
                    }
                    className={cn(
                      'min-w-0 truncate text-muted-foreground',
                      !step.highlight &&
                        step.label !== 'Posted' &&
                        'font-mono text-[10.5px]',
                    )}
                  >
                    {step.highlight || step.label === 'Posted'
                      ? t(step.detail)
                      : step.detail}
                  </span>
                </li>
              )
            })}
          </ol>
          <p className="mt-2 border-t border-border/70 pt-2 text-[10.5px] leading-4 text-muted-foreground">
            {t(
              'A second model checks every finding against the code before anything is posted.',
            )}
          </p>
        </ArtPanel>
      </div>
    </div>
  )
}
