import { Link } from '@tanstack/react-router'
import {
  AlertCircle,
  CheckCircle2,
  Clock,
  Loader2,
  XCircle,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import {
  getMigrationErrorMessage,
  getMigrationResourceBreakdown,
  type MigrationResourceBreakdownItem,
} from '@/components/pages/projects/$projectId/settings/migrations/migrationProgress'
import { migrationProgress } from '@/lib/project-environments'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const TONE_ICON: Record<
  MigrationResourceBreakdownItem['tone'],
  { icon: typeof Clock; className: string }
> = {
  waiting: { icon: Clock, className: 'text-muted-foreground' },
  processing: { icon: Loader2, className: 'animate-spin text-primary' },
  success: { icon: CheckCircle2, className: 'text-green-600' },
  error: { icon: XCircle, className: 'text-destructive' },
}

interface CopyProgressStepProps {
  /** The environment being copied into */
  environmentId: string
  /** The project it copies from, named for the user */
  sourceName: string
  /** Null until the environment lists its copy */
  migration: Models.Migration | null
}

/** The copy from the project as it runs, resource type by resource type. */
export function CopyProgressStep({
  environmentId,
  sourceName,
  migration,
}: CopyProgressStepProps) {
  const t = useT()
  const status = migration?.status
  const isFailed = status === 'failed'
  const isCompleted = status === 'completed'

  // The counters say how far the copy got once the worker has reported; before
  // that the bar shows it has started
  const progress = !migration
    ? 0
    : isCompleted || isFailed
      ? 100
      : Math.max(migrationProgress(migration.statusCounters), 5)

  const statusLabel = !migration
    ? t('Starting the copy')
    : isCompleted
      ? t('Copied from the project')
      : isFailed
        ? t('Could not copy from the project')
        : t('Copying from the project')

  const breakdown = migration ? getMigrationResourceBreakdown(migration) : []
  const errorMessage = isFailed
    ? getMigrationErrorMessage(migration?.errors)
    : null

  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-border bg-card/50 p-5">
        <div className="flex items-center gap-3">
          {isCompleted ? (
            <CheckCircle2 className="h-5 w-5 shrink-0 text-green-600" />
          ) : isFailed ? (
            <AlertCircle className="h-5 w-5 shrink-0 text-destructive" />
          ) : (
            <Loader2 className="h-5 w-5 shrink-0 animate-spin text-primary" />
          )}
          <div className="min-w-0">
            <h2 className="text-[15px] font-semibold text-foreground">
              {statusLabel}
            </h2>
            <p className="truncate text-[12px] text-muted-foreground">
              {sourceName}
            </p>
          </div>
        </div>
        <div className="mt-4">
          <ProgressBarRow value={progress} />
        </div>
        {errorMessage && (
          <p className="mt-3 text-[12px] text-destructive">{errorMessage}</p>
        )}
        {isFailed && (
          <Button
            asChild
            variant="link"
            size="sm"
            className="mt-1 h-auto p-0 text-[12px]"
          >
            <Link
              to="/projects/$projectId/settings/migrations"
              params={{ projectId: environmentId }}
            >
              {t('View details')}
            </Link>
          </Button>
        )}
      </div>

      {breakdown.length > 0 && (
        <ul className="divide-y divide-border rounded-xl border border-border bg-card/50">
          {breakdown.map((item) => {
            const { icon: Icon, className } = TONE_ICON[item.tone]
            return (
              <li
                key={item.key}
                className="flex items-center gap-3 px-4 py-2.5 text-[13px]"
              >
                <Icon className={cn('h-4 w-4 shrink-0', className)} />
                <span className="min-w-0 flex-1 truncate text-foreground">
                  {t(item.label)}
                </span>
                <span className="shrink-0 tabular-nums text-muted-foreground">
                  {item.succeeded} / {item.total}
                </span>
              </li>
            )
          })}
        </ul>
      )}

      {!isCompleted && !isFailed && (
        <p className="text-[12px] text-muted-foreground">
          {t(
            'You can skip this step. The copy keeps running in the background and shows its progress in the corner of the environment.',
          )}
        </p>
      )}
    </div>
  )
}
