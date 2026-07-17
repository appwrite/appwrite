import { ChevronDown } from 'lucide-react'
import { DatabaseType } from '@/lib/databases/database-type'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { ToolbarCountBadge } from '@/components/global/shared/ToolbarCountBadge'
import {
  serviceHeaderFiltersButton,
  serviceHeaderFiltersLabel,
} from '@/components/pages/projects/$projectId/shared/service-header-container'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type DatabaseTypeFilterOption = {
  value: DatabaseType
  label: string
}

type DatabaseTypeFilterDropdownProps = {
  options: DatabaseTypeFilterOption[]
  /** Applied types from URL/API filters. Empty = all types. */
  selectedTypes: DatabaseType[]
  onSelectedTypesChange: (types: DatabaseType[]) => void
  className?: string
}

/**
 * Multi-select database type control for the All Databases list.
 * Writes the same `type` filter used by FiltersPopover / console.listDatabases.
 */
export function DatabaseTypeFilterDropdown({
  options,
  selectedTypes,
  onSelectedTypesChange,
  className,
}: DatabaseTypeFilterDropdownProps) {
  const t = useT()
  const selectedSet = new Set(selectedTypes)
  const selectedCount = selectedTypes.length
  const allSelected =
    selectedCount === 0 || selectedCount === options.length

  const label = allSelected
    ? t('All types')
    : selectedCount === 1
      ? (options.find((opt) => opt.value === selectedTypes[0])?.label ??
        t('Type'))
      : t('Types')

  const toggleType = (type: DatabaseType) => {
    const next = new Set(selectedSet)
    if (next.has(type)) next.delete(type)
    else next.add(type)

    // Selecting every available type is equivalent to no type filter.
    if (next.size === 0 || next.size === options.length) {
      onSelectedTypesChange([])
      return
    }

    onSelectedTypesChange(
      options
        .map((opt) => opt.value)
        .filter((value) => next.has(value)),
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn(
            'border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground',
            serviceHeaderFiltersButton,
            className,
          )}
        >
          <span className={serviceHeaderFiltersLabel}>{label}</span>
          {!allSelected && selectedCount > 0 ? (
            <ToolbarCountBadge
              count={selectedCount}
              placement="inline"
              inlineTone="emphasis"
            />
          ) : null}
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-70" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-52">
        <DropdownMenuLabel className="text-[12px] font-normal text-muted-foreground">
          {t('Database types')}
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuCheckboxItem
          checked={allSelected}
          onCheckedChange={() => onSelectedTypesChange([])}
          onSelect={(event) => event.preventDefault()}
        >
          {t('All types')}
        </DropdownMenuCheckboxItem>
        <DropdownMenuSeparator />
        {options.map((option) => (
          <DropdownMenuCheckboxItem
            key={option.value}
            checked={allSelected || selectedSet.has(option.value)}
            onCheckedChange={() => toggleType(option.value)}
            onSelect={(event) => event.preventDefault()}
          >
            {option.label}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
