import { useMemo, useState } from 'react'
import { Check, ChevronDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  createDefaultMysqlColumnTypeState,
  formatMysqlColumnTypeLabel,
  formatMysqlColumnTypePropertyLimits,
  getMysqlColumnTypeDefinition,
  getMysqlColumnTypePropertyPlaceholder,
  getMysqlColumnTypePropertyRangeError,
  getMysqlColumnTypePropertyValue,
  getMysqlColumnTypeSearchValue,
  isMysqlSerialColumnType,
  MYSQL_COLUMN_TYPE_DEFINITIONS,
  MYSQL_COLUMN_TYPE_GROUPS,
  type MysqlColumnTypeId,
  type MysqlColumnTypeProperty,
  type MysqlColumnTypeState,
} from '@/lib/mysql-column-types'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { MysqlEnumValuesEditor } from './MysqlEnumValuesEditor'

type MysqlColumnTypeSelectorProps = {
  value: MysqlColumnTypeState
  onChange: (value: MysqlColumnTypeState) => void
  allowSerialTypes?: boolean
  /** Compact trigger for inline column grids (hides label and advanced options). */
  compact?: boolean
  /** Show length/precision fields. Defaults to the inverse of `compact`. */
  showTypeOptions?: boolean
  /** Show the array checkbox. Defaults to the inverse of `compact`. */
  showArrayOption?: boolean
  /** Show the type picker trigger. Defaults to true. */
  showTypePicker?: boolean
  /** When editing an existing enum column, show the in-use-value warning. */
  existing?: boolean
}

function MysqlColumnTypePropertyField({
  property,
  value,
  onChange,
}: {
  property: MysqlColumnTypeProperty
  value: MysqlColumnTypeState
  onChange: (value: MysqlColumnTypeState) => void
}) {
  const t = useT()
  const propertyValue = getMysqlColumnTypePropertyValue(value, property.key)
  const inputId = `mysql-column-${property.key}`
  const limitsLabel = formatMysqlColumnTypePropertyLimits(property)
  const rangeError = getMysqlColumnTypePropertyRangeError(
    property,
    propertyValue,
  )

  const handleChange = (nextValue: number | undefined) => {
    onChange({
      ...value,
      [property.key]: nextValue,
    })
  }

  return (
    <div className="space-y-2">
      <div className="flex items-start justify-between gap-3">
        <Label htmlFor={inputId} className="text-[12px] font-medium leading-snug">
          {property.label}
        </Label>
        <span className="shrink-0 text-end text-[11px] font-medium tabular-nums text-muted-foreground">
          {limitsLabel}
        </span>
      </div>
      {property.hint ? (
        <p className="text-[11px] text-muted-foreground">{property.hint}</p>
      ) : null}
      {property.optional && property.optionalEmptyLabel ? (
        <p className="text-[11px] text-muted-foreground">
          {t('Leave empty for')} {t(property.optionalEmptyLabel)}.
        </p>
      ) : null}
      <Input
        id={inputId}
        type="number"
        min={property.min}
        max={property.max}
        value={propertyValue ?? ''}
        placeholder={(() => {
          const placeholder = getMysqlColumnTypePropertyPlaceholder(property)
          return placeholder ? t(placeholder) : undefined
        })()}
        aria-invalid={rangeError ? true : undefined}
        onChange={(event) => {
          const raw = event.target.value.trim()
          if (!raw) {
            handleChange(undefined)
            return
          }
          const parsed = Number.parseInt(raw, 10)
          handleChange(Number.isFinite(parsed) ? parsed : undefined)
        }}
        className={cn(
          'tabular-nums',
          rangeError && 'border-destructive focus-visible:ring-destructive/30',
        )}
      />
      {rangeError ? (
        <p className="text-[12px] text-destructive">{rangeError}</p>
      ) : null}
    </div>
  )
}

export function MysqlColumnTypeSelector({
  value,
  onChange,
  allowSerialTypes = true,
  compact = false,
  showTypeOptions,
  showArrayOption,
  showTypePicker = true,
  existing = false,
}: MysqlColumnTypeSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const definition = getMysqlColumnTypeDefinition(value.typeId)
  const resolvedShowTypeOptions =
    showTypeOptions ?? (!compact || value.typeId === 'enum')
  const resolvedShowArrayOption = showArrayOption ?? !compact

  const visibleDefinitions = useMemo(
    () =>
      MYSQL_COLUMN_TYPE_DEFINITIONS.filter(
        (entry) => allowSerialTypes || !entry.createOnly,
      ),
    [allowSerialTypes],
  )

  const supportsArray =
    !isMysqlSerialColumnType(value.typeId) && value.typeId !== 'enum'

  const handleTypeChange = (nextTypeId: MysqlColumnTypeId) => {
    const next = createDefaultMysqlColumnTypeState(nextTypeId)
    if (
      value.isArray &&
      !isMysqlSerialColumnType(nextTypeId) &&
      nextTypeId !== 'enum'
    ) {
      next.isArray = true
    }
    onChange(next)
  }

  return (
    <div className={compact ? '' : 'space-y-3'}>
      {showTypePicker ? (
        <div className={compact ? '' : 'space-y-2'}>
          {!compact ? (
            <Label htmlFor="mysql-column-type" className="text-[12px] font-medium">
              {t('Type')} <span className="text-destructive">*</span>
            </Label>
          ) : null}
          <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
              <Button
                id={compact ? undefined : 'mysql-column-type'}
                type="button"
                variant="outline"
                role="combobox"
                aria-expanded={open}
                className={cn(
                  'w-full justify-between gap-2 font-normal',
                  compact
                    ? 'h-8 px-2 text-[12px]'
                    : 'h-9 text-[13px]',
                )}
              >
                <span className="truncate">{formatMysqlColumnTypeLabel(value)}</span>
                <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
              </Button>
            </PopoverTrigger>
            <PopoverContent
              className="max-h-[min(360px,var(--radix-popover-content-available-height))] w-[max(var(--radix-popover-trigger-width),20rem)] overflow-hidden p-0"
              align="start"
              onWheelCapture={(event) => {
                event.stopPropagation()
              }}
            >
              <Command>
                <CommandInput
                  placeholder={t('Search types...')}
                  className="h-9 text-[13px]"
                />
                <CommandList className="max-h-[280px] overflow-y-auto overscroll-contain">
                  <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                    {t('No types found')}
                  </CommandEmpty>
                  {MYSQL_COLUMN_TYPE_GROUPS.map((group) => {
                    const options = visibleDefinitions.filter(
                      (entry) => entry.group === group,
                    )
                    if (options.length === 0) return null

                    return (
                      <CommandGroup
                        key={group}
                        heading={group}
                        className="[&_[cmdk-group-heading]]:text-[11px] [&_[cmdk-group-heading]]:uppercase [&_[cmdk-group-heading]]:tracking-wider"
                      >
                        {options.map((entry) => (
                          <CommandItem
                            key={entry.id}
                            value={getMysqlColumnTypeSearchValue(entry)}
                            className="text-[13px]"
                            onSelect={() => {
                              handleTypeChange(entry.id)
                              setOpen(false)
                            }}
                          >
                            <Check
                              className={cn(
                                'me-2 h-4 w-4 shrink-0',
                                value.typeId === entry.id
                                  ? 'opacity-100'
                                  : 'opacity-0',
                              )}
                            />
                            <div className="flex min-w-0 flex-1 items-baseline justify-between gap-3">
                              <span className="shrink-0">{entry.label}</span>
                              <span className="truncate text-[11px] text-muted-foreground">
                                {t(entry.description)}
                              </span>
                            </div>
                          </CommandItem>
                        ))}
                      </CommandGroup>
                    )
                  })}
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </div>
      ) : null}

      {resolvedShowArrayOption && supportsArray ? (
        <div className="flex items-start gap-2">
          <Checkbox
            id="mysql-column-array"
            checked={value.isArray === true}
            onCheckedChange={(checked) =>
              onChange({
                ...value,
                isArray: checked === true,
              })
            }
            className="mt-0.5"
          />
          <div className="space-y-1">
            <Label
              htmlFor="mysql-column-array"
              className="text-[12px] font-medium leading-none"
            >
              {t('Define as array')}
            </Label>
            <p className="text-[11px] text-muted-foreground">
              {t('Store multiple values of this type in a single column.')}
            </p>
          </div>
        </div>
      ) : null}

      {resolvedShowTypeOptions &&
      (definition.properties.length > 0 || value.typeId === 'enum') ? (
        <div className="space-y-3 rounded-lg border border-border bg-muted/20 px-3 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {value.typeId === 'enum' ? t('Allowed values') : t('Type options')}
          </p>
          {value.typeId === 'enum' ? (
            <MysqlEnumValuesEditor
              values={value.enumValues?.length ? value.enumValues : ['']}
              onChange={(enumValues) => onChange({ ...value, enumValues })}
              existing={existing}
            />
          ) : (
            definition.properties.map((property) => (
              <MysqlColumnTypePropertyField
                key={property.key}
                property={property}
                value={value}
                onChange={onChange}
              />
            ))
          )}
        </div>
      ) : null}
    </div>
  )
}
