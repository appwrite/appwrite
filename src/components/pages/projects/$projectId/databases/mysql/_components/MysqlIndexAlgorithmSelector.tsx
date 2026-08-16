import { useState } from 'react'
import { Check, ChevronDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Label } from '@/components/ui/label'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  getMysqlIndexAlgorithmDefinition,
  getMysqlIndexAlgorithmSearchValue,
  MYSQL_INDEX_ALGORITHMS,
  type MysqlIndexAlgorithm,
} from '@/lib/mysql-index-metadata'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { localizeMysqlIndexAlgorithmLabel } from '@/lib/i18n/resource-status-labels'

type MysqlIndexAlgorithmSelectorProps = {
  value: MysqlIndexAlgorithm
  onChange: (value: MysqlIndexAlgorithm) => void
}

export function MysqlIndexAlgorithmSelector({
  value,
  onChange,
}: MysqlIndexAlgorithmSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const selected = getMysqlIndexAlgorithmDefinition(value)

  return (
    <div className="space-y-3">
      <div className="space-y-2">
        <Label htmlFor="index-algorithm" className="text-[12px] font-medium">
          Algorithm <span className="text-destructive">*</span>
        </Label>
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button
              id="index-algorithm"
              type="button"
              variant="outline"
              role="combobox"
              aria-expanded={open}
              className="h-9 w-full justify-between gap-2 text-[13px] font-normal"
            >
              <span className="truncate">
                {localizeMysqlIndexAlgorithmLabel(value, t)}
              </span>
              <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
            </Button>
          </PopoverTrigger>
          <PopoverContent
            className="max-h-[min(360px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] overflow-hidden p-0"
            align="start"
            onWheelCapture={(event) => {
              event.stopPropagation()
            }}
          >
            <Command>
              <CommandInput
                placeholder={t('Search algorithms...')}
                className="h-9 text-[13px]"
              />
              <CommandList className="max-h-[280px] overflow-y-auto overscroll-contain p-1">
                <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                  {t('No algorithms found')}
                </CommandEmpty>
                <CommandGroup>
                  {MYSQL_INDEX_ALGORITHMS.map((entry) => (
                    <CommandItem
                      key={entry.id}
                      value={getMysqlIndexAlgorithmSearchValue(entry)}
                      className="items-start rounded-md px-2 py-2.5 aria-selected:bg-accent"
                      onSelect={() => {
                        onChange(entry.id)
                        setOpen(false)
                      }}
                    >
                      <Check
                        className={cn(
                          'me-2 mt-0.5 h-4 w-4 shrink-0',
                          value === entry.id ? 'opacity-100' : 'opacity-0',
                        )}
                      />
                      <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-[13px] font-medium leading-none text-foreground">
                          {t(entry.label)}
                        </p>
                        <p className="text-[12px] leading-relaxed text-muted-foreground">
                          {entry.description}
                        </p>
                      </div>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      </div>

      <div className="rounded-lg border border-border bg-muted/20 px-3 py-3">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('About this algorithm')}
        </p>
        <p className="mt-2 text-[12px] font-medium text-foreground">
          {t(selected.label)}
        </p>
        <p className="mt-1 text-[11px] leading-relaxed text-muted-foreground">
          {selected.description}
        </p>
      </div>
    </div>
  )
}
