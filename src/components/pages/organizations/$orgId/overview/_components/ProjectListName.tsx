import { truncateMiddle } from '@/lib/utils'
import { cn } from '@/lib/utils'

/** Visible project name length in org list/grid cards (full name in title tooltip). */
export const PROJECT_LIST_NAME_DISPLAY_MAX = 28

type ProjectListNameProps = {
  name: string
  className?: string
  /** Defaults to span for table cells; use h3 for card titles. */
  as?: 'h3' | 'span'
  maxLength?: number
}

export function ProjectListName({
  name,
  className,
  as: Component = 'span',
  maxLength = PROJECT_LIST_NAME_DISPLAY_MAX,
}: ProjectListNameProps) {
  const displayName = truncateMiddle(name, maxLength)

  return (
    <Component
      className={cn(
        'block min-w-0 truncate font-medium text-foreground',
        Component === 'h3' ? 'text-[14px]' : 'text-[13px]',
        className,
      )}
      title={name.length > maxLength ? name : undefined}
    >
      {displayName}
    </Component>
  )
}
