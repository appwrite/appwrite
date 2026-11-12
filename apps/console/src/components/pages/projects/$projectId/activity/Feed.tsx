import { cn } from '@/lib/utils'
import { Plus, Pencil, Trash2, Zap, Upload, LogIn } from 'lucide-react'
import { activityEvents, type ActivityEvent } from '@/lib/utils/mock-data'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'

const eventIcons: Record<ActivityEvent['type'], React.ReactNode> = {
  create: <Plus className="h-3 w-3" />,
  update: <Pencil className="h-3 w-3" />,
  delete: <Trash2 className="h-3 w-3" />,
  execute: <Zap className="h-3 w-3" />,
  upload: <Upload className="h-3 w-3" />,
  login: <LogIn className="h-3 w-3" />,
}

const eventColors: Record<ActivityEvent['type'], string> = {
  create: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  update: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  delete: 'bg-red-500/10 text-red-600 dark:text-red-400',
  execute: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  upload: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
  login: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
}

const eventLabels: Record<ActivityEvent['type'], string> = {
  create: 'Created',
  update: 'Updated',
  delete: 'Deleted',
  execute: 'Executed',
  upload: 'Uploaded',
  login: 'Logged in',
}

interface ActivityFeedProps {
  className?: string
  limit?: number
}

export function ActivityFeed({ className, limit = 8 }: ActivityFeedProps) {
  const t = useT()
  const events = activityEvents.slice(0, limit)

  return (
    <div className={cn('rounded-lg border border-border bg-card', className)}>
      <div className="flex items-center justify-between border-b border-border px-3 py-2.5 sm:px-4 sm:py-3">
        <h3 className="text-[13px] font-medium text-foreground">
          {t('Recent Activity')}
        </h3>
        <button className="text-[12px] font-medium text-muted-foreground transition-colors hover:text-foreground">
          {t('View all')}
        </button>
      </div>

      <div className="divide-y divide-border">
        {events.map((event) => (
          <div
            key={event.$id}
            className="flex items-center gap-2.5 px-3 py-2.5 transition-colors hover:bg-accent/50 sm:gap-3 sm:px-4 sm:py-3"
          >
            <div
              className={cn(
                'flex h-5 w-5 shrink-0 items-center justify-center rounded sm:h-6 sm:w-6',
                eventColors[event.type],
              )}
            >
              {eventIcons[event.type]}
            </div>

            <div className="min-w-0 flex-1">
              <p className="truncate text-[12px] text-foreground/80 sm:text-[13px]">
                <span className="font-medium text-foreground">
                  {t(eventLabels[event.type])}
                </span>{' '}
                {event.resource}
              </p>
              <p className="text-[10px] text-muted-foreground sm:text-[11px]">
                {t('by')} {event.userName}
              </p>
            </div>

            <DateTooltip
              date={event.timestamp}
              className="shrink-0 text-[10px] text-muted-foreground/70 sm:text-[11px]"
            />
          </div>
        ))}
      </div>
    </div>
  )
}
