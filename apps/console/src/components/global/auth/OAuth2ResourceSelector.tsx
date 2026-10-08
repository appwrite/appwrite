'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { Check, CircleAlert, Loader2, Pencil, Plus, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type {
  ResolvedResource,
  ResourceNameMap,
} from '@/lib/oauth2/authorization-details'

interface OAuth2ResourceSelectorProps {
  /** e.g. "projects" / "organizations" - used throughout the copy. */
  pluralLabel: string
  /** Client-requested identifiers; may be `['*']`. */
  requested: string[]
  /** Current selection; `['*']` means all. Controlled. */
  selected: string[]
  onSelectedChange: (next: string[]) => void
  find: (term: string) => Promise<ResolvedResource[]>
  resolveNames: (ids: string[]) => Promise<ResourceNameMap>
  disabled?: boolean
}

const WILDCARD = '*'

export function OAuth2ResourceSelector({
  pluralLabel,
  requested,
  selected,
  onSelectedChange,
  find,
  resolveNames,
  disabled = false,
}: OAuth2ResourceSelectorProps) {
  const t = useT()
  const [expanded, setExpanded] = useState(false)
  const [names, setNames] = useState<Record<string, ResolvedResource>>({})
  const [term, setTerm] = useState('')
  const [results, setResults] = useState<ResolvedResource[]>([])
  const [searching, setSearching] = useState(false)

  const wildcard = requested.includes(WILDCARD)
  const isAll = selected.includes(WILDCARD)
  const specificIds = useMemo(
    () => selected.filter((id) => id !== WILDCARD),
    [selected],
  )
  const isEmpty = !isAll && specificIds.length === 0
  const isDefault =
    selected.length === requested.length &&
    selected.every((id) => requested.includes(id))
  // Search (which can ADD resources) is only offered when narrowing a wildcard
  // grant; a specific requested list is fixed - the user can remove but never
  // add resources the client didn't ask for.
  const searchable = wildcard && !isAll

  const labelFor = (id: string): ResolvedResource =>
    names[id] ?? { id, name: id, resolved: false }

  // Resolve the display names for the requested specific ids.
  useEffect(() => {
    const ids = requested.filter((id) => id !== WILDCARD)
    if (ids.length === 0) return
    let cancelled = false
    void resolveNames(ids).then((map) => {
      if (cancelled) return
      setNames((prev) => {
        const next = { ...prev }
        for (const [id, resource] of map) next[id] = resource
        return next
      })
    })
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requested.join(',')])

  // Debounced live search while narrowing a wildcard grant.
  const findRef = useRef(find)
  findRef.current = find
  useEffect(() => {
    if (!expanded || !searchable) return
    let cancelled = false
    setSearching(true)
    const handle = setTimeout(() => {
      void findRef.current(term).then((found) => {
        if (cancelled) return
        setResults(found)
        setNames((prev) => {
          const next = { ...prev }
          for (const resource of found) next[resource.id] = resource
          return next
        })
        setSearching(false)
      })
    }, 250)
    return () => {
      cancelled = true
      clearTimeout(handle)
    }
  }, [term, expanded, searchable])

  const suggestions = results.filter((r) => !selected.includes(r.id))

  const setAll = (all: boolean) => onSelectedChange(all ? [WILDCARD] : [])
  const add = (id: string) =>
    onSelectedChange([...selected.filter((x) => x !== WILDCARD), id])
  const remove = (id: string) =>
    onSelectedChange(selected.filter((x) => x !== id))

  const summary = useMemo(() => {
    if (isAll) return `${t('All')} ${pluralLabel}`
    if (specificIds.length === 0)
      return `${t('No')} ${pluralLabel} ${t('selected')}`
    if (specificIds.length <= 2)
      return specificIds.map((id) => labelFor(id).name).join(', ')
    return `${specificIds.length} ${pluralLabel}`
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAll, specificIds, names, pluralLabel])

  return (
    <div>
      {/* Summary row */}
      <div className="flex items-center justify-between gap-2">
        <div
          className={cn(
            'flex min-w-0 items-center gap-1.5 text-[13px]',
            isEmpty && 'text-muted-foreground',
          )}
        >
          {isEmpty && <CircleAlert className="size-4 shrink-0" />}
          <span className="truncate font-medium">{summary}</span>
          {isDefault && (
            <Badge variant="info" className="ms-1 text-[10px] shrink-0">
              {t('Default')}
            </Badge>
          )}
        </div>
        <button
          type="button"
          disabled={disabled}
          onClick={() => setExpanded((v) => !v)}
          className={cn(
            'text-muted-foreground hover:text-foreground flex shrink-0 cursor-pointer items-center gap-1 text-xs font-medium disabled:opacity-50',
            expanded && 'text-foreground',
          )}
        >
          {expanded ? (
            <Check className="size-3.5" />
          ) : (
            <Pencil className="size-3.5" />
          )}
          {expanded ? t('Done') : t('Change')}
        </button>
      </div>

      {expanded && (
        <div className="space-y-3 pt-3">
          {/* Mode toggle for wildcard grants */}
          {wildcard && (
            <div
              role="group"
              className="bg-muted flex rounded-md p-0.5 text-xs font-medium"
            >
              <button
                type="button"
                onClick={() => setAll(true)}
                className={cn(
                  'flex-1 cursor-pointer rounded px-2 py-1.5 transition',
                  isAll
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground',
                )}
              >
                {t('All')} {pluralLabel}
              </button>
              <button
                type="button"
                onClick={() => setAll(false)}
                className={cn(
                  'flex-1 cursor-pointer rounded px-2 py-1.5 transition',
                  !isAll
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground',
                )}
              >
                {t('Specific')} {pluralLabel}
              </button>
            </div>
          )}

          {/* Selected chips */}
          {specificIds.length > 0 && (
            <div className="flex flex-wrap gap-1.5">
              {specificIds.map((id) => {
                const r = labelFor(id)
                return (
                  <span
                    key={id}
                    className="bg-muted flex items-center gap-1 rounded-md py-1 pe-1 ps-2 text-xs"
                  >
                    <span className={cn(!r.resolved && 'font-mono')}>
                      {r.name}
                    </span>
                    {r.region && (
                      <span className="text-muted-foreground bg-background rounded px-1 text-[0.6rem] uppercase">
                        {r.region}
                      </span>
                    )}
                    <button
                      type="button"
                      aria-label={`${t('Remove')} ${r.name}`}
                      onClick={() => remove(id)}
                      className="text-muted-foreground hover:text-foreground cursor-pointer"
                    >
                      <X className="size-3" />
                    </button>
                  </span>
                )
              })}
            </div>
          )}

          {/* Type-to-search */}
          {searchable && (
            <div className="space-y-2">
              <Input
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                placeholder={`${t('Search')} ${pluralLabel} ${t('by name')}...`}
                disabled={disabled}
                className="h-8 text-sm"
              />
              <ul className="max-h-48 space-y-1 overflow-y-auto">
                {searching ? (
                  <li className="text-muted-foreground flex items-center justify-center gap-2 py-3 text-xs">
                    <Loader2 className="size-4 animate-spin" />
                  </li>
                ) : suggestions.length === 0 ? (
                  <li className="text-muted-foreground py-3 text-center text-xs">
                    {term.trim()
                      ? `${t('No matching')} ${pluralLabel}`
                      : `${t('Type to search')} ${pluralLabel}`}
                  </li>
                ) : (
                  suggestions.map((r) => (
                    <li key={r.id}>
                      <button
                        type="button"
                        onClick={() => add(r.id)}
                        className="hover:bg-muted flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm"
                      >
                        <span className="min-w-0 flex-1 truncate">
                          {r.name}
                        </span>
                        {r.region && (
                          <span className="text-muted-foreground bg-muted rounded px-1 text-[0.6rem] uppercase">
                            {r.region}
                          </span>
                        )}
                        <Plus className="text-muted-foreground size-3.5 shrink-0" />
                      </button>
                    </li>
                  ))
                )}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
