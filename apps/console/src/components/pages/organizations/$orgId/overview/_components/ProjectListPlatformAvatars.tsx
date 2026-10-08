import type { Models } from '@appwrite.io/console'
import { Link } from '@tanstack/react-router'
import { Plus } from 'lucide-react'
import { PlatformIcon } from '@/components/global/shared/Icon'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { getPlatformDisplayName } from '@/lib/utils/platform'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Fixed row height for org project list table (matches platform tiles + vertical rhythm). */
export const PROJECT_LIST_TABLE_ROW_HEIGHT_CLASS = 'h-14 py-0 align-middle'

export const PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS = 'size-7'

/** Table cells: match tile height without clamping the row of icons to a square. */
const tableRowAlignClassName = 'h-7'

const MAX_VISIBLE_PLATFORMS = 4

const tileClassName = cn(
  'grid shrink-0 place-items-center overflow-hidden rounded-md border border-border/80 bg-muted/50 text-muted-foreground',
  'transition-colors duration-150',
  PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS,
)

const emptyTileClassName = cn(
  tileClassName,
  'border-dashed border-muted-foreground/30 bg-transparent text-muted-foreground/60',
  'hover:border-muted-foreground/45 hover:bg-muted/40 hover:text-muted-foreground',
)

function ProjectListPlatformTileIcon({ platform }: { platform: string }) {
  return (
    <PlatformIcon
      platform={platform}
      size="sm"
      className={cn(
        '!size-3.5 shrink-0',
        // Compact list tiles: drop framework corner badges and force a single glyph size.
        '[&>div.absolute]:hidden',
        '[&>div]:flex [&>div]:!size-3.5 [&>div]:items-center [&>div]:justify-center',
        '[&_svg]:block [&_svg]:!size-3.5',
      )}
    />
  )
}

type PlatformStackItem = {
  type: string
  label: string
}

function groupPlatformsForStack(
  platforms: Models.PlatformList['platforms'],
): PlatformStackItem[] {
  const byType = new Map<string, number>()

  for (const platform of platforms) {
    const type = platform.type ?? 'web'
    byType.set(type, (byType.get(type) ?? 0) + 1)
  }

  return Array.from(byType.entries()).map(([type, count]) => ({
    type,
    label:
      count > 1
        ? `${getPlatformDisplayName(type)} (${count})`
        : getPlatformDisplayName(type),
  }))
}

type ProjectListPlatformAvatarsProps = {
  projectId: string
  platforms: Models.PlatformList['platforms']
  isLoading?: boolean
  /** When true, skip empty-state CTA and show N/A (locked / blocked projects). */
  unavailable?: boolean
  className?: string
  variant?: 'card' | 'table'
}

function PlatformTilesLoading({
  className,
  variant = 'card',
}: {
  className?: string
  variant?: 'card' | 'table'
}) {
  const t = useT()
  if (variant === 'table') {
    return (
      <div
        className={cn('flex items-center', tableRowAlignClassName, className)}
        aria-label={t('Loading platforms')}
        aria-busy
      >
        <div
          className={cn(tileClassName, 'animate-pulse bg-border/50')}
          aria-hidden
        />
      </div>
    )
  }

  return (
    <ul
      className={cn('inline-flex items-center gap-1 ps-0', className)}
      aria-label={t('Loading platforms')}
      aria-busy
    >
      {Array.from({ length: 3 }).map((_, index) => (
        <li key={index} className="shrink-0" aria-hidden>
          <div className={cn(tileClassName, 'animate-pulse bg-border/50')} />
        </li>
      ))}
    </ul>
  )
}

export function ProjectListPlatformAvatars({
  projectId,
  platforms,
  isLoading = false,
  unavailable = false,
  className,
  variant = 'card',
}: ProjectListPlatformAvatarsProps) {
  const t = useT()
  if (isLoading) {
    return <PlatformTilesLoading className={className} variant={variant} />
  }

  if (unavailable) {
    return (
      <div
        className={cn(
          'inline-flex items-center text-[12px] font-medium text-muted-foreground',
          variant === 'table' && tableRowAlignClassName,
          className,
        )}
        aria-label={t('N/A')}
      >
        {t('N/A')}
      </div>
    )
  }

  const stackItems = groupPlatformsForStack(platforms)
  if (stackItems.length === 0) {
    return (
      <div
        className={cn(
          'inline-flex items-center',
          variant === 'table' && tableRowAlignClassName,
          className,
        )}
      >
        <Tooltip>
          <TooltipTrigger asChild>
            <Link
              to="/projects/$projectId/apps"
              params={{ projectId }}
              className={cn(emptyTileClassName, 'pointer-events-auto')}
              aria-label={t('Add platform')}
              onClick={(event) => event.stopPropagation()}
            >
              <Plus className="size-3.5" strokeWidth={2} />
            </Link>
          </TooltipTrigger>
          <TooltipContent side="bottom" className="text-[12px]">
            {t('Add platform')}
          </TooltipContent>
        </Tooltip>
      </div>
    )
  }

  const visibleItems = stackItems.slice(0, MAX_VISIBLE_PLATFORMS)
  const overflowCount = stackItems.length - visibleItems.length

  return (
    <ul
      className={cn(
        'inline-flex items-center gap-1 ps-0',
        variant === 'table' && tableRowAlignClassName,
        className,
      )}
      aria-label={stackItems.map((item) => item.label).join(', ')}
    >
      {visibleItems.map((item) => (
        <li key={item.type} className="shrink-0">
          <Tooltip>
            <TooltipTrigger asChild>
              <div
                className={cn(
                  tileClassName,
                  'hover:border-border hover:bg-muted hover:text-foreground',
                )}
                aria-label={item.label}
              >
                <ProjectListPlatformTileIcon platform={item.type} />
              </div>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-[12px]">
              {item.label}
            </TooltipContent>
          </Tooltip>
        </li>
      ))}
      {overflowCount > 0 ? (
        <li className="shrink-0">
          <div
            className={cn(
              tileClassName,
              'text-[10px] font-semibold tabular-nums tracking-tight text-muted-foreground',
            )}
            aria-label={`${overflowCount} ${t('more platforms')}`}
          >
            +{overflowCount}
          </div>
        </li>
      ) : null}
    </ul>
  )
}
