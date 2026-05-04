import { useMemo, useState, useRef, useEffect, useLayoutEffect } from 'react'
import type { fetchConsoleProjectScopes } from '@/lib/react-query/hooks/console-project-scopes'

type ConsoleKeyScopeEntry = NonNullable<
  NonNullable<Awaited<ReturnType<typeof fetchConsoleProjectScopes>>['scopes']>
>[number]
import { Checkbox } from '@/components/ui/checkbox'
import { Button } from '@/components/ui/button'
import {
  Accordion,
  AccordionContent,
  AccordionHeader,
  AccordionItem,
  AccordionRowTrigger,
} from '@/components/ui/accordion'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { cn } from '@/lib/utils'
import { useConsoleProjectScopes } from '@/lib/react-query/hooks/console-project-scopes'
import {
  compareScopeEditorRowsForDisplay,
  compareScopeRowsDeprecatedLast,
  consoleKeyScopesToEditorRows,
  getScopeCategoryIcon,
  getScopeVariants,
  isCloudEnvironment,
  LEGACY_SCOPE_MAP,
  normalizeScopeForDisplay,
  scopeEditorCategory,
  scopeEditorRowIsDeprioritized,
  scopeRowDeprecated,
  shouldDisplayScope,
  sortScopeCategories,
  type ScopeEditorRow,
} from '@/lib/console-project-scopes'
import { Loader2, Minus, MoreHorizontal } from 'lucide-react'

interface ScopeEditorProps {
  value: string[]
  onChange: (scopes: string[]) => void
  disabled?: boolean
}

export function ScopeEditor({
  value,
  onChange,
  disabled = false,
}: ScopeEditorProps) {
  const isCloud = isCloudEnvironment()
  const { data: scopeList, isLoading, isError, error } = useConsoleProjectScopes()

  const [openCategories, setOpenCategories] = useState<string[]>([])
  const openCategoriesRef = useRef<string[]>([])
  const previousValueRef = useRef<string[]>(value)
  const isUserInteractionRef = useRef(false)

  const availableScopes = useMemo(() => {
    const scopeById = new Map<string, ConsoleKeyScopeEntry>(
      (scopeList?.scopes ?? []).map((s: ConsoleKeyScopeEntry) => [s.$id, s]),
    )
    const base = consoleKeyScopesToEditorRows(scopeList, { isCloud })
    const byId = new Map(base.map((r) => [r.scope, r]))
    for (const v of value) {
      const n = normalizeScopeForDisplay(v)
      if (!byId.has(n) && shouldDisplayScope(v)) {
        const orphanEntry = scopeById.get(n)
        const categoryLooksDeprecated =
          (orphanEntry?.category ?? '').trim().toLowerCase() === 'deprecated'
        const deprecatedBadge = scopeRowDeprecated(
          orphanEntry?.deprecated,
          categoryLooksDeprecated,
        )
        const accordionCategory = scopeEditorCategory(n, undefined)
        byId.set(n, {
          scope: n,
          description:
            'This scope is on the API key but was not returned in the server scope list.',
          category: accordionCategory,
          icon: getScopeCategoryIcon(accordionCategory, n),
          deprecated: deprecatedBadge,
        })
      }
    }

    const legacyOnKeyByModern = new Map<string, string[]>()
    for (const v of value) {
      const modern = LEGACY_SCOPE_MAP[v as keyof typeof LEGACY_SCOPE_MAP]
      if (modern) {
        const list = legacyOnKeyByModern.get(modern) ?? []
        list.push(v)
        legacyOnKeyByModern.set(modern, list)
      }
    }
    for (const [modern, aliases] of legacyOnKeyByModern) {
      const row = byId.get(modern)
      if (!row) continue
      const unique = [...new Set(aliases)].sort()
      byId.set(modern, {
        ...row,
        legacyAliasesOnKey: unique,
      })
    }

    return Array.from(byId.values()).sort(compareScopeEditorRowsForDisplay)
  }, [scopeList, isCloud, value])

  const scopesByCategory = useMemo(() => {
    const grouped: Record<string, ScopeEditorRow[]> = {}
    for (const row of availableScopes) {
      if (!grouped[row.category]) grouped[row.category] = []
      grouped[row.category].push(row)
    }
    for (const k of Object.keys(grouped)) {
      grouped[k]!.sort(compareScopeRowsDeprecatedLast)
    }
    return grouped
  }, [availableScopes])

  const categoryKeys = useMemo(
    () => sortScopeCategories(Object.keys(scopesByCategory)),
    [scopesByCategory],
  )

  useEffect(() => {
    openCategoriesRef.current = openCategories
  }, [openCategories])

  useEffect(() => {
    const valueChanged =
      JSON.stringify(previousValueRef.current) !== JSON.stringify(value)
    if (valueChanged && !isUserInteractionRef.current) {
      setOpenCategories([])
      openCategoriesRef.current = []
    }
    if (isUserInteractionRef.current) {
      isUserInteractionRef.current = false
    }
    previousValueRef.current = value
  }, [value])

  const displayScopes = useMemo(() => {
    const normalized = new Set<string>()
    value.forEach((scope) => {
      const normalizedScope = normalizeScopeForDisplay(scope)
      if (shouldDisplayScope(scope)) {
        normalized.add(normalizedScope)
      } else {
        normalized.add(normalizedScope)
      }
    })
    return Array.from(normalized)
  }, [value])

  const handleScopeToggle = (scope: string, checked: boolean) => {
    isUserInteractionRef.current = true
    const variants = getScopeVariants(scope)
    let newScopes: string[]

    if (checked) {
      newScopes = [...new Set([...value, ...variants])]
    } else {
      newScopes = value.filter((s) => !variants.includes(s))
    }

    onChange(newScopes)
  }

  const handleCategoryToggle = (category: string, checked: boolean) => {
    isUserInteractionRef.current = true
    const categoryScopes = scopesByCategory[category] || []
    const allVariants = new Set<string>()

    categoryScopes.forEach((scopeDef) => {
      const variants = getScopeVariants(scopeDef.scope)
      variants.forEach((v) => allVariants.add(v))
    })

    let newScopes: string[]

    if (checked) {
      newScopes = [...new Set([...value, ...Array.from(allVariants)])]
    } else {
      newScopes = value.filter((s) => !allVariants.has(s))
    }

    onChange(newScopes)
  }

  const isSelectingAllRef = useRef(false)

  const handleSelectAll = (e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault()
    e.stopPropagation()
    isSelectingAllRef.current = true
    isUserInteractionRef.current = true
    const allVariants = new Set<string>()
    availableScopes.forEach((scopeDef) => {
      if (scopeDef.deprecated) return
      const variants = getScopeVariants(scopeDef.scope)
      variants.forEach((v) => allVariants.add(v))
    })
    onChange(Array.from(allVariants))
    requestAnimationFrame(() => {
      isSelectingAllRef.current = false
    })
  }

  const handleDeselectAll = (e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault()
    e.stopPropagation()
    isSelectingAllRef.current = true
    isUserInteractionRef.current = true
    onChange([])
    requestAnimationFrame(() => {
      isSelectingAllRef.current = false
    })
  }

  const handleAccordionChange = (newValue: string[]) => {
    if (isSelectingAllRef.current) {
      return
    }
    setOpenCategories(newValue)
    openCategoriesRef.current = newValue
  }

  useLayoutEffect(() => {
    if (isSelectingAllRef.current) {
      setOpenCategories(openCategoriesRef.current)
    }
  }, [value])

  const getCategoryState = (
    category: string,
  ): 'checked' | 'unchecked' | 'indeterminate' => {
    const categoryScopes = scopesByCategory[category] || []
    if (categoryScopes.length === 0) return 'unchecked'

    const selectedCount = categoryScopes.filter((scopeDef) =>
      displayScopes.includes(scopeDef.scope),
    ).length

    if (selectedCount === 0) return 'unchecked'
    if (selectedCount === categoryScopes.length) return 'checked'
    return 'indeterminate'
  }

  const getCategorySelectedCount = (category: string): number => {
    const categoryScopes = scopesByCategory[category] || []
    return categoryScopes.filter((scopeDef) =>
      displayScopes.includes(scopeDef.scope),
    ).length
  }

  if (isLoading && !scopeList) {
    return (
      <div className="flex items-center justify-center gap-2 py-8 text-muted-foreground text-[13px]">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading scopes…
      </div>
    )
  }

  if (isError) {
    return (
      <p className="text-[13px] text-destructive">
        {error instanceof Error ? error.message : 'Failed to load scopes'}
      </p>
    )
  }

  if (availableScopes.length === 0) {
    return (
      <p className="text-[13px] text-muted-foreground">
        No API key scopes are available from the server.
      </p>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-end gap-1.5">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-7 px-2.5 text-[12px]"
          onClick={handleSelectAll}
          disabled={disabled}
        >
          Select all
        </Button>
        <Separator orientation="vertical" className="h-3" />
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-7 px-2.5 text-[12px]"
          onClick={handleDeselectAll}
          disabled={disabled}
        >
          Deselect all
        </Button>
      </div>

      <Accordion
        type="multiple"
        value={openCategories}
        onValueChange={handleAccordionChange}
        className="w-full"
      >
        {categoryKeys.map((category, categoryIndex) => {
          const categoryScopes = scopesByCategory[category] || []
          if (categoryScopes.length === 0) return null

          const Icon = categoryScopes[0]?.icon || MoreHorizontal
          const selectedCount = getCategorySelectedCount(category)
          const categoryState = getCategoryState(category)
          const isLastCategory = categoryIndex === categoryKeys.length - 1

          return (
            <AccordionItem
              key={category}
              value={category}
              className={cn('border-b', isLastCategory && 'border-b-0')}
            >
              <AccordionHeader className="flex w-full min-w-0 items-stretch">
                <div className="flex shrink-0 items-center self-center py-4 pl-1 pr-2">
                  <div className="relative">
                    <Checkbox
                      checked={categoryState === 'checked'}
                      onCheckedChange={(checked) => {
                        handleCategoryToggle(category, checked === true)
                      }}
                      disabled={disabled}
                      className={cn(
                        categoryState === 'indeterminate' &&
                          'bg-primary border-primary',
                      )}
                    />
                    {categoryState === 'indeterminate' && (
                      <Minus className="pointer-events-none absolute left-0.5 top-0.5 h-3 w-3 text-primary-foreground" />
                    )}
                  </div>
                </div>
                <AccordionRowTrigger className="hover:no-underline min-w-0 flex-1">
                  <div className="flex min-w-0 flex-1 items-center justify-between pr-4">
                    <div className="flex min-w-0 items-center gap-3">
                      <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="truncate text-[13px] font-medium text-foreground">
                        {category}
                      </span>
                    </div>
                    <Badge variant="secondary" className="shrink-0 text-[12px]">
                      {selectedCount} {selectedCount === 1 ? 'Scope' : 'Scopes'}
                    </Badge>
                  </div>
                </AccordionRowTrigger>
              </AccordionHeader>
              <AccordionContent>
                <div className="space-y-2 pt-2">
                  {categoryScopes.map((scopeDef) => {
                    const isSelected = displayScopes.includes(scopeDef.scope)
                    return (
                      <label
                        key={scopeDef.scope}
                        className={cn(
                          'flex items-start gap-3 rounded-md px-3 py-2.5 transition-colors',
                          'hover:bg-accent/50 cursor-pointer',
                          disabled && 'cursor-not-allowed opacity-50',
                        )}
                      >
                        <Checkbox
                          checked={isSelected}
                          onCheckedChange={(checked) => {
                            handleScopeToggle(scopeDef.scope, checked === true)
                          }}
                          disabled={disabled}
                          className="mt-0.5"
                        />
                        <div className="flex-1 space-y-0.5">
                          <div className="flex flex-wrap items-center gap-2 text-[13px] font-mono text-foreground">
                            <span>{scopeDef.scope}</span>
                            {scopeEditorRowIsDeprioritized(scopeDef) ? (
                              <Badge variant="warning" className="text-[10px] shrink-0">
                                Deprecated
                              </Badge>
                            ) : null}
                          </div>
                          <div className="text-[12px] text-muted-foreground">
                            {scopeDef.description}
                          </div>
                          {scopeDef.legacyAliasesOnKey &&
                          scopeDef.legacyAliasesOnKey.length > 0 ? (
                            <div className="text-[11px] text-muted-foreground font-mono leading-snug">
                              On key as legacy:{' '}
                              {scopeDef.legacyAliasesOnKey.join(', ')}
                            </div>
                          ) : null}
                        </div>
                      </label>
                    )
                  })}
                </div>
              </AccordionContent>
            </AccordionItem>
          )
        })}
      </Accordion>
    </div>
  )
}
