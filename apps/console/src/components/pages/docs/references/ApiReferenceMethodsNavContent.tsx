'use client'

import { useEffect, useMemo, useRef, useState, type RefObject } from 'react'
import { Search, X } from 'lucide-react'
import { groupMethodsByResource } from '@/lib/api-explorer/parse-spec'
import { apiNavMethodItemClassName } from '@/lib/api-explorer/nav-styles'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ScrollArea } from '@/components/ui/scroll-area'
import { StartTruncatedText } from '@/components/global/shared/StartTruncatedText'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import {
  getHttpMethodVariant,
  REFERENCE_PILL_CLASS,
  REFERENCE_SCROLL_AREA_CLASS,
} from './explorer-styles'

function methodMatchesSearch(method: ApiReferenceMethod, query: string): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true
  return (
    method.summary.toLowerCase().includes(q) ||
    method.path.toLowerCase().includes(q) ||
    method.id.toLowerCase().includes(q) ||
    method.httpMethod.toLowerCase().includes(q) ||
    (method.resourceGroup?.toLowerCase().includes(q) ?? false)
  )
}

type MethodListProps = {
  methods: ApiReferenceMethod[]
  selectedMethodId?: string
  onSelectMethod: (methodId: string) => void
  selectedMethodRef: RefObject<HTMLButtonElement | null>
}

function MethodList({
  methods,
  selectedMethodId,
  onSelectMethod,
  selectedMethodRef,
}: MethodListProps) {
  return (
    <ul className="space-y-0.5">
      {methods.map((method) => {
        const isActive = method.id === selectedMethodId
        return (
          <li key={method.id}>
            <button
              ref={
                isActive
                  ? (node) => {
                      selectedMethodRef.current = node
                    }
                  : undefined
              }
              type="button"
              onClick={() => onSelectMethod(method.id)}
              className={apiNavMethodItemClassName(isActive)}
            >
              <span className="min-w-0 truncate text-[13px] font-medium">
                {method.summary}
              </span>
              <div className="flex w-full min-w-0 max-w-full items-center gap-2">
                <Badge
                  variant={getHttpMethodVariant(method.httpMethod)}
                  className={cn(
                    'text-[10px] shrink-0 uppercase',
                    REFERENCE_PILL_CLASS,
                  )}
                >
                  {method.httpMethod}
                </Badge>
                <StartTruncatedText
                  text={method.path}
                  className="min-w-0 flex-1 font-mono text-[11px] text-muted-foreground"
                />
              </div>
            </button>
          </li>
        )
      })}
    </ul>
  )
}

export type ApiReferenceMethodsNavContentProps = {
  serviceId: string
  methods: ApiReferenceMethod[]
  selectedMethodId?: string
  onSelectMethod: (methodId: string) => void
  /** Called after a method is chosen (e.g. close mobile sheet). */
  onMethodSelected?: () => void
  className?: string
}

export function ApiReferenceMethodsNavContent({
  serviceId,
  methods,
  selectedMethodId,
  onSelectMethod,
  onMethodSelected,
  className,
}: ApiReferenceMethodsNavContentProps) {
  const selectedMethodRef = useRef<HTMLButtonElement | null>(null)
  const [searchValue, setSearchValue] = useState('')

  useEffect(() => {
    setSearchValue('')
  }, [serviceId])

  const resourceGroups = useMemo(
    () => groupMethodsByResource(methods),
    [methods],
  )

  const filteredGroups = useMemo(() => {
    const query = searchValue.trim()
    if (!query) return resourceGroups

    return resourceGroups
      .map((group) => ({
        ...group,
        methods: group.methods.filter((method) => methodMatchesSearch(method, query)),
      }))
      .filter((group) => group.methods.length > 0)
  }, [resourceGroups, searchValue])

  useEffect(() => {
    selectedMethodRef.current?.scrollIntoView({
      block: 'nearest',
      inline: 'nearest',
    })
  }, [selectedMethodId, filteredGroups])

  const handleSelectMethod = (methodId: string) => {
    onSelectMethod(methodId)
    onMethodSelected?.()
  }

  return (
    <div className={cn('flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden', className)}>
      <div className="shrink-0 border-b border-border bg-muted/20 px-2 py-2">
        <div className="relative">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchValue}
            onChange={(event) => setSearchValue(event.target.value)}
            placeholder="Search methods..."
            className="h-8 border-border/60 bg-background ps-8 pe-8 text-[13px]"
            aria-label="Search methods"
          />
          {searchValue ? (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="absolute end-0.5 top-1/2 h-7 w-7 -translate-y-1/2 text-muted-foreground"
              aria-label="Clear method search"
              onClick={() => setSearchValue('')}
            >
              <X className="size-3.5" />
            </Button>
          ) : null}
        </div>
      </div>

      <ScrollArea className={REFERENCE_SCROLL_AREA_CLASS}>
        <div className="box-border w-full max-w-full min-w-0 space-y-3 p-2">
          {methods.length === 0 ? (
            <p className="px-2 py-4 text-[13px] text-muted-foreground">
              No methods available.
            </p>
          ) : filteredGroups.length === 0 ? (
            <p className="px-2 py-4 text-[13px] text-muted-foreground">
              No methods match your search.
            </p>
          ) : (
            filteredGroups.map((group) => (
              <div key={group.id || 'default'} className="space-y-1.5">
                {group.label ? (
                  <p className="px-0.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70">
                    {group.label}
                  </p>
                ) : null}
                <MethodList
                  methods={group.methods}
                  selectedMethodId={selectedMethodId}
                  onSelectMethod={handleSelectMethod}
                  selectedMethodRef={selectedMethodRef}
                />
              </div>
            ))
          )}
        </div>
      </ScrollArea>
    </div>
  )
}
