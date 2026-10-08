import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import {
  Clock,
  GitBranch,
  Globe,
  LayoutTemplate,
  Play,
  Zap,
} from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const RUNTIMES = [
  'node-22',
  'python-3.12',
  'dart-3.5',
  'go-1.23',
  'bun-1.1',
  'php-8.3',
]

const TRIGGERS = [
  { icon: Globe, label: 'GET /ping' },
  { icon: Zap, label: 'users.*.create' },
  { icon: Clock, label: '0 * * * *' },
]

const keyword = 'text-[var(--brand-cta)]'
const string = 'text-emerald-600 dark:text-emerald-400'
const punctuation = 'text-muted-foreground'

/** Each line is a list of [text, className] tokens. */
const CODE: [string, string?][][] = [
  [
    ['export default async ', keyword],
    ['({ ', punctuation],
    ['req, res, log'],
    [' }) => {', punctuation],
  ],
  [
    ['  log', 'text-foreground'],
    ['(', punctuation],
    ["'Hello from Appwrite'", string],
    [')', punctuation],
  ],
  [
    ['  if ', keyword],
    ['(', punctuation],
    ['req.path === '],
    ["'/ping'", string],
    [') {', punctuation],
  ],
  [
    ['    return ', keyword],
    ['res.text', 'text-foreground'],
    ['(', punctuation],
    ["'pong'", string],
    [')', punctuation],
  ],
  [['  }', punctuation]],
  [
    ['  return ', keyword],
    ['res.json', 'text-foreground'],
    ['({ ', punctuation],
    ['ok: '],
    ['true', keyword],
    [' })', punctuation],
  ],
  [['}', punctuation]],
]

function Chip({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        'flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-border bg-popover px-2.5 py-1.5 shadow-lg',
        className,
      )}
    >
      {children}
    </span>
  )
}

/** Decorative function source with the triggers that run it and its response. */
function EditorVisual() {
  return (
    <ProductEmptyStateVisual className="pb-6 pt-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          {[0, 1, 2].map((dot) => (
            <span
              key={dot}
              className="h-2 w-2 rounded-full bg-muted-foreground/25"
            />
          ))}
          <span className="ms-3 flex items-center gap-1.5 rounded-md border border-border bg-background px-2 py-0.5">
            <RuntimeIcon runtime="node-22" size="sm" className="h-3 w-3" />
            <span className="font-mono text-[10px] text-foreground">
              main.js
            </span>
          </span>
          <span className="ms-auto flex items-center gap-1.5">
            {RUNTIMES.map((runtime) => (
              <RuntimeIcon
                key={runtime}
                runtime={runtime}
                size="sm"
                className="h-3 w-3"
              />
            ))}
          </span>
        </div>
        <pre
          dir="ltr"
          className="overflow-hidden px-3 pb-8 pt-3 text-start font-mono text-[11px] leading-[1.7]"
        >
          {CODE.map((tokens, line) => (
            <div key={line} className="flex">
              <span className="w-6 shrink-0 select-none text-muted-foreground/50 tabular-nums">
                {line + 1}
              </span>
              <span className="whitespace-pre text-foreground/80">
                {tokens.map(([text, className], index) => (
                  <span key={index} className={className}>
                    {text}
                  </span>
                ))}
              </span>
            </div>
          ))}
        </pre>
      </div>

      <div
        dir="ltr"
        className="absolute bottom-2 start-4 flex flex-wrap items-center gap-2"
      >
        {TRIGGERS.map(({ icon: Icon, label }) => (
          <Chip key={label}>
            <Icon className="h-3.5 w-3.5 text-muted-foreground" />
            <span className="font-mono text-[10px] text-foreground">
              {label}
            </span>
          </Chip>
        ))}
      </div>

      <Chip className="absolute -end-4 top-24">
        <span className="rounded bg-emerald-500/15 px-1 font-mono text-[10px] leading-4 text-emerald-600 dark:text-emerald-400">
          200
        </span>
        <span className="font-mono text-[10px] text-foreground">pong</span>
        <span className="font-mono text-[10px] tabular-nums text-muted-foreground">
          12ms
        </span>
      </Chip>
    </ProductEmptyStateVisual>
  )
}

export function FunctionsEmptyState({
  projectId,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  projectId: string
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  const params = { projectId }

  const steps: ProductEmptyStateStep[] = [
    {
      icon: LayoutTemplate,
      title: 'Start from a template',
      description:
        'Use a ready-made function, or pick a runtime like Node.js, Python, Dart, or Go.',
      link: { to: '/projects/$projectId/functions/templates', params },
    },
    {
      icon: GitBranch,
      title: 'Deploy your code',
      description:
        'Connect a Git repository to deploy on every push, or upload your code manually.',
    },
    {
      icon: Play,
      title: 'Trigger it',
      description:
        'Run it over HTTP, on events in your project, or on a schedule.',
    },
  ]

  return (
    <div className="@container py-6 sm:py-10">
      <div className="grid items-center gap-12 @3xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] @3xl:gap-12">
        <div className="text-start">
          <span className="flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-muted text-muted-foreground">
            <Zap className="h-5 w-5" />
          </span>
          <h2 className="mt-6 text-[28px] font-semibold leading-tight tracking-tight text-foreground">
            {t('Create your first function')}
          </h2>
          <p className="mt-3 max-w-md text-[14px] leading-relaxed text-muted-foreground">
            {t(
              'Run backend code without managing servers. Respond to HTTP requests, events in your project, and schedules.',
            )}
          </p>
          <div className="mt-7 flex flex-wrap items-center gap-2">
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create function')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <Link
                to="/projects/$projectId/functions/templates"
                params={params}
              >
                {t('Browse templates')}
              </Link>
            </Button>
          </div>
        </div>
        <div className="order-first w-full max-w-lg @3xl:order-none @3xl:max-w-none">
          <EditorVisual />
        </div>
      </div>
      <ProductEmptyStateSteps steps={steps} />
    </div>
  )
}
