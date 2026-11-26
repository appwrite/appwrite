import { ChevronDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  RUNTIME_LOG_LEVELS,
  type RuntimeLogLevel,
} from '@/lib/runtime-log-line'
import { useT } from '@/lib/i18n/translate'

/** Most severe first, as the menu lists them. */
const LEVELS: { value: RuntimeLogLevel; label: string }[] = [
  { value: 'error', label: 'Error' },
  { value: 'warn', label: 'Warning' },
  { value: 'info', label: 'Info' },
  { value: 'debug', label: 'Debug' },
]

/**
 * The runtime logs level filter: any combination of levels, checked in a
 * menu. An empty selection shows every level.
 */
export function RuntimeLogLevelFilter({
  value,
  onValueChange,
}: {
  value: ReadonlySet<RuntimeLogLevel>
  onValueChange: (value: ReadonlySet<RuntimeLogLevel>) => void
}) {
  const t = useT()
  const all = value.size === 0
  const shown = (level: RuntimeLogLevel) => all || value.has(level)

  const toggle = (level: RuntimeLogLevel) => {
    const next = new Set(all ? RUNTIME_LOG_LEVELS : value)
    if (next.has(level)) next.delete(level)
    else next.add(level)
    // Every level, or none, is the same as no filter
    onValueChange(
      next.size === 0 || next.size === RUNTIME_LOG_LEVELS.length
        ? new Set()
        : next,
    )
  }

  const picked = LEVELS.filter((level) => value.has(level.value))
  const label = all
    ? t('All levels')
    : picked.length === 1
      ? t(picked[0].label)
      : picked.map((level) => t(level.label)).join(', ')

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          aria-label={t('Level')}
          className="h-9 w-[170px] justify-between gap-2 px-3 text-[13px] font-normal"
        >
          <span className="truncate">{label}</span>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-[170px]">
        <DropdownMenuCheckboxItem
          checked={all}
          onCheckedChange={() => onValueChange(new Set())}
          onSelect={(event) => event.preventDefault()}
          className="text-[13px]"
        >
          {t('All levels')}
        </DropdownMenuCheckboxItem>
        <DropdownMenuSeparator />
        {LEVELS.map((level) => (
          <DropdownMenuCheckboxItem
            key={level.value}
            checked={shown(level.value)}
            onCheckedChange={() => toggle(level.value)}
            onSelect={(event) => event.preventDefault()}
            className="text-[13px]"
          >
            {t(level.label)}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
