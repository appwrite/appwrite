import { useMemo } from 'react'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type OAuth2ScopeOption = {
  value: string
  /** Dynamic API copy, rendered as-is. */
  description?: string
  category?: string
  deprecated?: boolean
}

type OAuth2ScopePickerProps = {
  options: OAuth2ScopeOption[]
  value: string[]
  onChange: (next: string[]) => void
  disabled?: boolean
  /** Prefix for checkbox ids so several pickers can share a page. */
  idPrefix: string
  emptyMessage?: string
  className?: string
}

/**
 * Checkbox list for choosing a subset of OAuth2 scopes. Options that carry a
 * category are grouped under it. The emitted selection follows the options'
 * order (unknown values are dropped) so dirty checks stay stable.
 */
export function OAuth2ScopePicker({
  options,
  value,
  onChange,
  disabled,
  idPrefix,
  emptyMessage,
  className,
}: OAuth2ScopePickerProps) {
  const t = useT()
  const selected = useMemo(() => new Set(value), [value])

  const groups = useMemo(() => {
    const map = new Map<string, OAuth2ScopeOption[]>()
    for (const option of options) {
      const key = option.category ?? ''
      const list = map.get(key)
      if (list) list.push(option)
      else map.set(key, [option])
    }
    return Array.from(map.entries())
  }, [options])

  if (options.length === 0) {
    return emptyMessage ? (
      <p className="text-[12px] text-muted-foreground">{emptyMessage}</p>
    ) : null
  }

  const toggle = (scope: string, checked: boolean) => {
    const next = new Set(selected)
    if (checked) next.add(scope)
    else next.delete(scope)
    onChange(
      options
        .map((option) => option.value)
        .filter((candidate) => next.has(candidate)),
    )
  }

  return (
    <div className={cn('space-y-3', className)}>
      {groups.map(([category, items]) => (
        <div key={category || 'default'} className="space-y-1.5">
          {category ? (
            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {category}
            </p>
          ) : null}
          <div className="overflow-hidden rounded-lg border border-border divide-y divide-border">
            {items.map((option) => {
              const id = `${idPrefix}-${option.value}`
              return (
                <label
                  key={option.value}
                  htmlFor={id}
                  className={cn(
                    'flex items-start gap-3 px-3 py-2',
                    disabled
                      ? 'cursor-not-allowed opacity-60'
                      : 'cursor-pointer',
                  )}
                >
                  <Checkbox
                    id={id}
                    checked={selected.has(option.value)}
                    disabled={disabled}
                    onCheckedChange={(checked) =>
                      toggle(option.value, checked === true)
                    }
                    className="mt-0.5"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                      <code className="font-mono text-[12px] text-foreground">
                        {option.value}
                      </code>
                      {option.deprecated ? (
                        <Badge
                          variant="warning"
                          className="text-[10px] shrink-0"
                        >
                          {t('Deprecated')}
                        </Badge>
                      ) : null}
                    </span>
                    {option.description ? (
                      <span className="mt-0.5 block text-[12px] text-muted-foreground">
                        {option.description}
                      </span>
                    ) : null}
                  </span>
                </label>
              )
            })}
          </div>
        </div>
      ))}
    </div>
  )
}
