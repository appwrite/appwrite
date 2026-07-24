/**
 * Database Section Selector
 *
 * Mobile droplist for database-level sidebar destinations (Tables/Collections,
 * Visualizer, Monitor, Backups, Export / Import, Settings). Shown when the
 * secondary sidebar is hidden (lg breakpoint).
 */

import { useMemo, useState, type ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  Archive,
  Check,
  ChevronDown,
  Download,
  Network,
  Settings,
  Table2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useBackupPolicies } from '@/lib/react-query/hooks'
import { NoBackupPoliciesWarningIcon } from './DatabaseBackupsNavLink'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { DatabaseTabId } from '../workspace-types'

export type DatabaseSectionId = 'tables' | DatabaseTabId

export type DatabaseSectionItem = {
  id: DatabaseSectionId
  label: string
  icon: LucideIcon
}

export type DatabaseSectionSelectorProps = {
  projectId: string
  databaseId: string
  value: DatabaseSectionId
  tablesLabel: string
  tablesIcon?: LucideIcon
  showMonitor?: boolean
  showBackups?: boolean
  showSettings?: boolean
  onSelect: (sectionId: DatabaseSectionId) => void
  triggerClassName?: string
}

export function DatabaseSectionSelector({
  projectId,
  databaseId,
  value,
  tablesLabel,
  tablesIcon: TablesIcon = Table2,
  showMonitor = false,
  showBackups = false,
  showSettings = false,
  onSelect,
  triggerClassName,
}: DatabaseSectionSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const { data: policiesData, isLoading: policiesLoading } = useBackupPolicies(
    projectId,
    databaseId,
    { enabled: showBackups },
  )
  const showBackupsWarning =
    showBackups &&
    !policiesLoading &&
    (policiesData?.policies?.length ?? 0) === 0

  const items = useMemo(() => {
    const next: Array<DatabaseSectionItem & { trailing?: ReactNode }> = [
      { id: 'tables', label: tablesLabel, icon: TablesIcon },
      { id: 'visualizer', label: 'Visualizer', icon: Network },
    ]
    if (showMonitor) {
      next.push({ id: 'monitor', label: 'Monitor', icon: Activity })
    }
    if (showBackups) {
      next.push({
        id: 'backups',
        label: 'Backups',
        icon: Archive,
        trailing: showBackupsWarning ? (
          <NoBackupPoliciesWarningIcon />
        ) : undefined,
      })
    }
    next.push({
      id: 'export-import',
      label: 'Export / Import',
      icon: Download,
    })
    if (showSettings) {
      next.push({ id: 'settings', label: 'Settings', icon: Settings })
    }
    return next
  }, [
    TablesIcon,
    showBackups,
    showBackupsWarning,
    showMonitor,
    showSettings,
    tablesLabel,
  ])

  const selected = useMemo(
    () => items.find((item) => item.id === value) ?? items[0],
    [items, value],
  )
  const SelectedIcon = selected?.icon ?? Table2

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          className={cn(
            'h-8 min-w-0 w-full justify-between gap-1.5 text-[13px] font-normal',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-1.5">
            <SelectedIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
            <span className="truncate">{selected ? t(selected.label) : ''}</span>
            {selected?.id === 'backups' && showBackupsWarning ? (
              <NoBackupPoliciesWarningIcon />
            ) : null}
          </span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="min-w-[var(--radix-popover-trigger-width)] max-w-[320px] p-1"
        align="start"
      >
        <div className="flex flex-col gap-0.5">
          {items.map((item) => {
            const Icon = item.icon
            const isActive = item.id === value
            return (
              <button
                key={item.id}
                type="button"
                onClick={() => {
                  onSelect(item.id)
                  setOpen(false)
                }}
                className={cn(
                  'flex w-full cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-start text-[13px] outline-none transition-colors hover:bg-accent hover:text-accent-foreground',
                  isActive && 'bg-accent/60',
                )}
              >
                <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1 truncate">{t(item.label)}</span>
                {item.trailing}
                {isActive ? (
                  <Check className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                ) : null}
              </button>
            )
          })}
        </div>
      </PopoverContent>
    </Popover>
  )
}
