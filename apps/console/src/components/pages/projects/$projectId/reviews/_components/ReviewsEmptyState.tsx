import type { ReactNode } from 'react'
import { AtSign, FileCode, GitPullRequest, ListChecks } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { VcsIcon } from '@/lib/vcs/providers'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Sample findings in the illustration; sample data, so never translated. */
const FINDINGS = [
  {
    severity: 'Major',
    variant: 'warning',
    path: 'src/auth/session.ts:88',
    title: 'Token is logged before it is hashed',
  },
  {
    severity: 'Minor',
    variant: 'info',
    path: 'src/limits/redis.ts:52',
    title: 'Key is built twice per request',
  },
] as const

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

/** Decorative pull request review: summary, findings and the grade it posts. */
function ReviewVisual() {
  return (
    <ProductEmptyStateVisual className="pb-8 pt-4">
      <div
        dir="ltr"
        className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl"
      >
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
          <GitPullRequest className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
          <span className="truncate text-[11px] font-medium text-foreground">
            Add rate limiting to sessions
          </span>
          <span className="font-mono text-[10px] text-muted-foreground">
            #128
          </span>
          <span className="ms-auto flex items-center gap-1 rounded-md border border-border bg-background px-1.5 py-0.5">
            <VcsIcon type="github" className="h-3 w-3 text-muted-foreground" />
            <span className="font-mono text-[10px] text-muted-foreground">
              acme/api
            </span>
          </span>
        </div>
        <div className="space-y-2.5 px-3 py-3">
          <div className="space-y-1.5">
            <span className="block h-1.5 w-11/12 rounded-full bg-muted-foreground/20" />
            <span className="block h-1.5 w-3/4 rounded-full bg-muted-foreground/15" />
          </div>
          {FINDINGS.map((finding) => (
            <div
              key={finding.path}
              className="rounded-lg border border-border bg-background/60 px-2.5 py-2"
            >
              <div className="flex items-center gap-1.5">
                <Badge variant={finding.variant} className="text-[9px]">
                  {finding.severity}
                </Badge>
                <span className="truncate text-[11px] font-medium text-foreground">
                  {finding.title}
                </span>
              </div>
              <div className="mt-1 flex items-center gap-1 font-mono text-[10px] text-muted-foreground">
                <FileCode className="h-3 w-3" />
                {finding.path}
              </div>
            </div>
          ))}
        </div>
      </div>

      <Chip className="absolute -end-5 top-12">
        <Badge variant="error" className="text-[9px]">
          Changes requested
        </Badge>
        <Badge variant="warning" className="font-mono text-[9px]">
          B
        </Badge>
      </Chip>

      <Chip className="absolute bottom-1 start-4">
        <AtSign className="h-3.5 w-3.5 text-muted-foreground" />
        <span className="font-mono text-[10px] text-foreground">
          remember: we use early returns
        </span>
      </Chip>
    </ProductEmptyStateVisual>
  )
}

/**
 * First run of the Review section: no repository is reviewed and no review
 * has run yet.
 */
export function ReviewsEmptyState({
  onChoose,
  chooseDisabled = false,
  chooseDisabledTooltip,
}: {
  onChoose: () => void
  chooseDisabled?: boolean
  chooseDisabledTooltip?: string
}) {
  const t = useT()

  const steps: ProductEmptyStateStep[] = [
    {
      icon: (props) => <VcsIcon type="github" {...props} />,
      title: 'Connect GitHub',
      description:
        'Install the GitHub app on your account or organization, then turn on review for the repositories you pick.',
    },
    {
      icon: ListChecks,
      title: 'Get a review on every pull request',
      description:
        'Each new pull request and commit gets a summary, findings on the lines they concern, a verdict, and a merge confidence grade.',
    },
    {
      icon: AtSign,
      title: 'Talk to the reviewer',
      description:
        'Mention the app in a comment to ask a question, request a new review, or have it remember a preference for later reviews.',
    },
  ]

  return (
    <div className="py-6 sm:py-10">
      <ProductEmptyStateHero
        layout="columns"
        visual={<ReviewVisual />}
        icon={GitPullRequest}
        title={t('Review pull requests with AI')}
        description={t(
          'Catch bugs and security issues before they merge. Choose the repositories to review, and every pull request in them gets a review on GitHub.',
        )}
        actions={
          <ProductEmptyStateCreateButton
            onClick={onChoose}
            disabled={chooseDisabled}
            disabledTooltip={chooseDisabledTooltip}
          >
            {t('Choose repositories')}
          </ProductEmptyStateCreateButton>
        }
      />
      <ProductEmptyStateSteps steps={steps} />
    </div>
  )
}
