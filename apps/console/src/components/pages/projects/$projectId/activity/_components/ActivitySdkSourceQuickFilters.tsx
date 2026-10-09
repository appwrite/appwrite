import { Terminal } from '@/lib/icons'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  serviceHeaderFiltersButton,
  serviceHeaderFiltersLabel,
} from '@/components/pages/projects/$projectId/shared/service-header-container'
import {
  ACTIVITY_SDK_SOURCE_CLI,
  ACTIVITY_SDK_SOURCE_MCP,
  type ActivitySdkSource,
} from '@/lib/table-filters'

const OPTIONS: Array<{
  value: ActivitySdkSource
  label: string
}> = [
  { value: ACTIVITY_SDK_SOURCE_MCP, label: 'Via MCP' },
  { value: ACTIVITY_SDK_SOURCE_CLI, label: 'Via CLI' },
]

/**
 * Segment attached to the Filters split button: Via MCP / Via CLI.
 */
export function ActivitySdkSourceQuickFilters({
  selected,
  onToggle,
}: {
  selected: ActivitySdkSource[]
  onToggle: (source: ActivitySdkSource) => void
}) {
  const t = useT()
  const selectedSet = new Set(selected)

  return (
    <>
      {OPTIONS.map((option, index) => {
        const active = selectedSet.has(option.value)
        const isLast = index === OPTIONS.length - 1
        return (
          <Button
            key={option.value}
            type="button"
            variant="outline"
            size="sm"
            aria-pressed={active}
            title={t(option.label)}
            onClick={() => onToggle(option.value)}
            className={cn(
              'border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground',
              serviceHeaderFiltersButton,
              'rounded-s-none border-s-0',
              isLast ? 'rounded-e-md' : 'rounded-e-none',
              active && 'bg-accent text-foreground',
            )}
          >
            {option.value === ACTIVITY_SDK_SOURCE_MCP ? (
              <McpIcon className="h-3.5 w-3.5 shrink-0" />
            ) : (
              <Terminal className="h-3.5 w-3.5 shrink-0" />
            )}
            <span className={serviceHeaderFiltersLabel}>{t(option.label)}</span>
          </Button>
        )
      })}
    </>
  )
}
