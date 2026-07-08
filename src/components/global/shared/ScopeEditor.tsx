import { useMemo, useState, useRef, useEffect, useLayoutEffect } from 'react'
import type { fetchConsoleProjectScopes } from '@/lib/react-query/hooks/console-project-scopes'

type ConsoleKeyScopeEntry = NonNullable<
  NonNullable<Awaited<ReturnType<typeof fetchConsoleProjectScopes>>['scopes']>
>[number]
import { Checkbox } from '@/components/ui/checkbox'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
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
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useConsoleProjectScopes } from '@/lib/react-query/hooks/console-project-scopes'
import {
  compareScopeEditorRowsForDisplay,
  compareScopeRowsDeprecatedLast,
  consoleKeyScopesToEditorRows,
  filterScopeEditorRows,
  getScopeCategoryIcon,
  isOAuth2AppsCatalogScope,
  isOAuth2AppsScopeEditorRow,
  isCloudEnvironment,
  scopeEditorCategory,
  scopeRowDeprecated,
  sortScopeCategories,
  type ScopeEditorRow,
} from '@/lib/console-project-scopes'
import { Loader2, MoreHorizontal, Search } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

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
  const t = useT()
  const isCloud = isCloudEnvironment()
  const { features } = useConsoleProfile()
  const { data: scopeList, isLoading, isError, error } = useConsoleProjectScopes()

  const [searchQuery, setSearchQuery] = useState('')
  const [openCategories, setOpenCategories] = useState<string[]>([])
  const openCategoriesRef = useRef<string[]>([])
  const previousValueRef = useRef<string[]>(value)
  const isUserInteractionRef = useRef(false)

  const availableScopes = useMemo(() => {
    const scopeById = new Map<string, ConsoleKeyScopeEntry>(
      (scopeList?.scopes ?? []).map((s: ConsoleKeyScopeEntry) => [s.$id, s]),
    )
    const base = consoleKeyScopesToEditorRows(scopeList, {
      isCloud,
      oauth2Server: features.oauth2Server,
      selectedScopeIds: value,
    })
    const byId = new Map(base.map((r) => [r.scope, r]))
    for (const v of value) {
      if (byId.has(v)) continue
      const orphanEntry = scopeById.get(v)
      if (
        !features.oauth2Server &&
        isOAuth2AppsCatalogScope(v, orphanEntry?.category)
      ) {
        continue
      }
      const categoryLooksDeprecated =
        (orphanEntry?.category ?? '').trim().toLowerCase() === 'deprecated'
      const deprecatedBadge = scopeRowDeprecated(
        orphanEntry?.deprecated,
        categoryLooksDeprecated,
      )
      const accordionCategory = scopeEditorCategory(v, orphanEntry?.category)
      byId.set(v, {
        scope: v,
        description:
          orphanEntry?.description ??
          'This scope is on the API key but was not returned in the server scope list.',
        category: accordionCategory,
        icon: getScopeCategoryIcon(accordionCategory, v),
        deprecated: deprecatedBadge,
      })
    }

    return Array.from(byId.values())
      .filter(
        (row) =>
          features.oauth2Server || !isOAuth2AppsScopeEditorRow(row),
      )
      .sort(compareScopeEditorRowsForDisplay)
  }, [features.oauth2Server, scopeList, isCloud, value])

  const filteredScopes = useMemo(
    () => filterScopeEditorRows(availableScopes, searchQuery),
    [availableScopes, searchQuery],
  )

  const scopesByCategory = useMemo(() => {
    const grouped: Record<string, ScopeEditorRow[]> = {}
    for (const row of filteredScopes) {
      if (!grouped[row.category]) grouped[row.category] = []
      grouped[row.category].push(row)
    }
    for (const k of Object.keys(grouped)) {
      grouped[k]!.sort(compareScopeRowsDeprecatedLast)
    }
    return grouped
  }, [filteredScopes])

  const categoryKeys = useMemo(
    () => sortScopeCategories(Object.keys(scopesByCategory)),
    [scopesByCategory],
  )

  const isFiltering = searchQuery.trim().length > 0

  useEffect(() => {
    openCategoriesRef.current = openCategories
  }, [openCategories])

  useEffect(() => {
    if (!isFiltering) return
    setOpenCategories(categoryKeys)
    openCategoriesRef.current = categoryKeys
  }, [categoryKeys, isFiltering, searchQuery])

  useEffect(() => {
    const valueChanged =
      JSON.stringify(previousValueRef.current) !== JSON.stringify(value)
    if (valueChanged && !isUserInteractionRef.current) {
      if (!isFiltering) {
        setOpenCategories([])
        openCategoriesRef.current = []
      }
    }
    if (isUserInteractionRef.current) {
      isUserInteractionRef.current = false
    }
    previousValueRef.current = value
  }, [isFiltering, value])

  const displayScopes = useMemo(() => Array.from(new Set(value)), [value])

  const handleScopeToggle = (scope: string, checked: boolean) => {
    isUserInteractionRef.current = true
    if (checked) {
      onChange([...new Set([...value, scope])])
    } else {
      onChange(value.filter((s) => s !== scope))
    }
  }

  const handleCategoryToggle = (category: string, checked: boolean) => {
    isUserInteractionRef.current = true
    const categoryScopes = scopesByCategory[category] || []
    const ids = categoryScopes.map((r) => r.scope)
    const idSet = new Set(ids)

    if (checked) {
      onChange([...new Set([...value, ...ids])])
    } else {
      onChange(value.filter((s) => !idSet.has(s)))
    }
  }

  const isSelectingAllRef = useRef(false)

  const handleSelectAll = (e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault()
    e.stopPropagation()
    isSelectingAllRef.current = true
    isUserInteractionRef.current = true
    const allIds = new Set<string>()
    filteredScopes.forEach((scopeDef) => {
      if (scopeDef.deprecated) return
      allIds.add(scopeDef.scope)
    })
    onChange([...new Set([...value, ...allIds])])
    requestAnimationFrame(() => {
      isSelectingAllRef.current = false
    })
  }

  const handleDeselectAll = (e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault()
    e.stopPropagation()
    isSelectingAllRef.current = true
    isUserInteractionRef.current = true
    const filteredIds = new Set(filteredScopes.map((scopeDef) => scopeDef.scope))
    onChange(value.filter((scope) => !filteredIds.has(scope)))
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
        {t('Loading scopes…')}
      </div>
    )
  }

  if (isError) {
    return (
      <p className="text-[13px] text-destructive">
        {error instanceof Error ? error.message : t('Failed to load scopes')}
      </p>
    )
  }

  if (availableScopes.length === 0) {
    return (
      <p className="text-[13px] text-muted-foreground">
        {t('No API key scopes are available from the server.')}
      </p>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative min-w-0 flex-1">
          <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchQuery}
            onChange={(event) => setSearchQuery(event.target.value)}
            placeholder={t('Search scopes...')}
            className="h-9 ps-9 text-[13px]"
            disabled={disabled}
          />
        </div>
        <div className="flex shrink-0 items-center justify-end gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 px-2.5 text-[12px]"
            onClick={handleSelectAll}
            disabled={disabled || filteredScopes.length === 0}
          >
            {isFiltering ? t('Select shown') : t('Select all')}
          </Button>
          <Separator orientation="vertical" className="h-3" />
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 px-2.5 text-[12px]"
            onClick={handleDeselectAll}
            disabled={disabled || filteredScopes.length === 0}
          >
            {isFiltering ? t('Clear shown') : t('Deselect all')}
          </Button>
        </div>
      </div>

      {isFiltering ? (
        <p className="text-[12px] text-muted-foreground">
          {filteredScopes.length === 0
            ? t('No scopes match your search.')
            : `${t('Showing')} ${filteredScopes.length} ${t('of')} ${availableScopes.length} ${t('scopes.')}`}
        </p>
      ) : null}

      {filteredScopes.length === 0 ? null : (
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
                  <div className="flex shrink-0 items-center self-center py-4 ps-1 pe-2">
                    <Checkbox
                      checked={
                        categoryState === 'checked'
                          ? true
                          : categoryState === 'indeterminate'
                            ? 'indeterminate'
                            : false
                      }
                      onCheckedChange={(checked) => {
                        handleCategoryToggle(category, checked === true)
                      }}
                      disabled={disabled}
                    />
                  </div>
                  <AccordionRowTrigger className="hover:no-underline min-w-0 flex-1">
                    <div className="flex min-w-0 flex-1 items-center justify-between pe-4">
                      <div className="flex min-w-0 items-center gap-3">
                        <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                        <span className="truncate text-[13px] font-medium text-foreground">
                          {t(category)}
                        </span>
                      </div>
                      <Badge variant="secondary" className="shrink-0 text-[12px]">
                        {selectedCount} {selectedCount === 1 ? t('Scope') : t('Scopes')}
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
                              {scopeDef.deprecated ? (
                                <Badge variant="warning" className="text-[10px] shrink-0">
                                  {t('Deprecated')}
                                </Badge>
                              ) : null}
                            </div>
                            <div className="text-[12px] text-muted-foreground">
                              {t(scopeDef.description)}
                            </div>
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
      )}
    </div>
  )
}
