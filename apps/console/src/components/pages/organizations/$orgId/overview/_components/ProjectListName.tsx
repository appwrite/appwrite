import { cn } from '@/lib/utils'
import {
  formatProjectNameForDisplay,
  getProjectNameDisplayTitle,
  PROJECT_NAME_DISPLAY_MAX,
} from '@/lib/react-query/hooks/projects'

/** @deprecated Use {@link PROJECT_NAME_DISPLAY_MAX} from projects hooks. */
export const PROJECT_LIST_NAME_DISPLAY_MAX = PROJECT_NAME_DISPLAY_MAX

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
  maxLength = PROJECT_NAME_DISPLAY_MAX,
}: ProjectListNameProps) {
  const displayName = formatProjectNameForDisplay(name, maxLength)

  return (
    <Component
      className={cn(
        'block min-w-0 truncate font-medium text-foreground',
        Component === 'h3' ? 'text-[14px]' : 'text-[13px]',
        className,
      )}
      title={getProjectNameDisplayTitle(name, maxLength)}
    >
      {displayName}
    </Component>
  )
}
