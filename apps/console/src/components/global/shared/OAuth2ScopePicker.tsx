import { useMemo, useState } from 'react'
import { Search } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
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
 * order (unknown values are dropped) so dirty checks stay stable. Deprecated
 * scopes only show while selected, so they can be cleared but not added.
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
  const [query, setQuery] = useState('')

  const groups = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const map = new Map<string, OAuth2ScopeOption[]>()
    for (const option of options) {
      if (option.deprecated && !selected.has(option.value)) continue
      if (
        needle &&
        !option.value.toLowerCase().includes(needle) &&
        !option.description?.toLowerCase().includes(needle) &&
        !option.category?.toLowerCase().includes(needle)
      ) {
        continue
      }
      const key = option.category ?? ''
      const list = map.get(key)
      if (list) list.push(option)
      else map.set(key, [option])
    }
    return Array.from(map.entries())
  }, [options, selected, query])

  if (options.length === 0) {
    return emptyMessage ? (
      <p className="text-[12px] text-muted-foreground">{emptyMessage}</p>
    ) : null
  }

  const toggle = (scopes: string[], checked: boolean) => {
    const next = new Set(selected)
    for (const scope of scopes) {
      if (checked) next.add(scope)
      else next.delete(scope)
    }
    onChange(
      options
        .map((option) => option.value)
        .filter((candidate) => next.has(candidate)),
    )
  }

  return (
    <div className={cn('space-y-3', className)}>
      <div className="relative">
        <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={t('Search scopes')}
          aria-label={t('Search scopes')}
          className="h-8 ps-9 text-[13px]"
        />
      </div>
      {groups.length === 0 ? (
        <p className="text-[12px] text-muted-foreground">
          {t('No scopes match your search.')}
        </p>
      ) : null}
      {groups.map(([category, items]) => {
        const addable = items.filter((option) => !option.deprecated)
        const allSelected = addable.every((option) =>
          selected.has(option.value),
        )
        return (
          <div key={category || 'default'} className="space-y-1.5">
            {category ? (
              <div className="flex items-center justify-between gap-2">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {category}
                </p>
                {addable.length > 1 ? (
                  <button
                    type="button"
                    disabled={disabled}
                    onClick={() =>
                      toggle(
                        addable.map((option) => option.value),
                        !allSelected,
                      )
                    }
                    className="text-[12px] text-muted-foreground hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                  >
                    {allSelected ? t('Clear all') : t('Select all')}
                  </button>
                ) : null}
              </div>
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
                        toggle([option.value], checked === true)
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
        )
      })}
    </div>
  )
}
