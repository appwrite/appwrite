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

/** Fixed row height for org project list table (matches size-8 avatars + vertical rhythm). */
export const PROJECT_LIST_TABLE_ROW_HEIGHT_CLASS = 'h-14 py-0 align-middle'

export const PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS = 'size-8'

const MAX_VISIBLE_PLATFORMS = 4

const avatarClassName = cn(
  'grid shrink-0 place-items-center overflow-hidden rounded-full border border-border bg-muted text-muted-foreground ring-1 ring-background',
  PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS,
)

const emptyAvatarClassName = cn(
  avatarClassName,
  'border-dashed border-muted-foreground/35 bg-transparent transition-colors hover:border-muted-foreground/50 hover:bg-muted/40',
)

function ProjectListPlatformAvatarIcon({ platform }: { platform: string }) {
  return (
    <PlatformIcon
      platform={platform}
      size="sm"
      className={cn(
        '!size-4 shrink-0',
        '[&>div.absolute]:hidden',
        '[&>div]:flex [&>div]:!size-4 [&>div]:items-center [&>div]:justify-center',
        '[&_svg]:block [&_svg]:!size-4',
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

function PlatformAvatarsLoading({
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
        className={cn('flex items-center', PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS, className)}
        aria-label={t('Loading platforms')}
        aria-busy
      >
        <div className={cn(avatarClassName, 'animate-pulse bg-border/60')} aria-hidden />
      </div>
    )
  }

  return (
    <ul
      className={cn('inline-flex items-center ps-0', className)}
      aria-label={t('Loading platforms')}
      aria-busy
    >
      {Array.from({ length: 3 }).map((_, index) => (
        <li
          key={index}
          className={cn('relative shrink-0', index > 0 && '-ms-2')}
          style={{ zIndex: index + 1 }}
          aria-hidden
        >
          <div className={cn(avatarClassName, 'animate-pulse bg-border/60')} />
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
    return <PlatformAvatarsLoading className={className} variant={variant} />
  }

  if (unavailable) {
    return (
      <div
        className={cn(
          'inline-flex items-center text-[12px] font-medium text-muted-foreground',
          variant === 'table' && PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS,
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
          variant === 'table' && PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS,
          className,
        )}
      >
        <Tooltip>
          <TooltipTrigger asChild>
            <Link
              to="/projects/$projectId/apps"
              params={{ projectId }}
              className={cn(emptyAvatarClassName, 'pointer-events-auto')}
              aria-label={t('Add platform')}
              onClick={(event) => event.stopPropagation()}
            >
              <Plus
                className="size-4 text-muted-foreground/55"
                strokeWidth={2}
              />
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
        'inline-flex items-center ps-0',
        variant === 'table' && PROJECT_LIST_PLATFORM_AVATAR_SIZE_CLASS,
        className,
      )}
      aria-label={stackItems.map((item) => item.label).join(', ')}
    >
      {visibleItems.map((item, index) => (
        <li
          key={item.type}
          className={cn('relative shrink-0', index > 0 && '-ms-2')}
          style={{ zIndex: index + 1 }}
        >
          <Tooltip>
            <TooltipTrigger asChild>
              <div className={avatarClassName} aria-label={item.label}>
                <ProjectListPlatformAvatarIcon platform={item.type} />
              </div>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-[12px]">
              {item.label}
            </TooltipContent>
          </Tooltip>
        </li>
      ))}
      {overflowCount > 0 ? (
        <li
          className="relative shrink-0 -ms-2"
          style={{ zIndex: visibleItems.length + 1 }}
        >
          <div
            className={cn(
              avatarClassName,
              'text-[10px] font-medium tabular-nums text-muted-foreground',
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
