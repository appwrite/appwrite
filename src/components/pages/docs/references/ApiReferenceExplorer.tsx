'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import { groupMethodsByResource } from '@/lib/api-explorer/parse-spec'
import { ReferenceColumnsResizableLayout } from '@/components/global/api-explorer/ApiExplorerResizableLayout'
import {
  API_REFERENCE_COLUMNS_DEFAULT_LAYOUT,
  normalizeApiReferenceColumnsLayout,
} from '@/lib/resizable-layout'
import type {
  ReferencePlatform,
  ReferenceVersion,
} from '@/lib/docs/references/constants'
import type { ApiReferenceServiceData } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import { ApiReferenceMethodsPanel } from './ApiReferenceMethodsPanel'
import { ApiReferenceMethodPanel } from './ApiReferenceMethodPanel'
import {
  REFERENCE_RESIZE_HANDLE_CLASS,
} from './explorer-styles'

const COLUMNS_LAYOUT_STORAGE_KEY = 'docs-api-reference-columns-layout'

type ApiReferenceExplorerProps = {
  data: ApiReferenceServiceData
  version: ReferenceVersion
  platform: ReferencePlatform
}

function getDefaultMethodId(methods: ApiReferenceServiceData['methods']): string | undefined {
  return (
    groupMethodsByResource(methods)[0]?.methods[0]?.id ?? methods[0]?.id
  )
}

function getInitialMethodId(methods: ApiReferenceServiceData['methods']): string | undefined {
  if (typeof window === 'undefined') {
    return getDefaultMethodId(methods)
  }

  const hash = window.location.hash.slice(1)
  if (hash && methods.some((method) => method.id === hash)) {
    return hash
  }
  return getDefaultMethodId(methods)
}

function readStoredColumnsLayout(): number[] {
  if (typeof window === 'undefined') {
    return [...API_REFERENCE_COLUMNS_DEFAULT_LAYOUT]
  }
  try {
    const raw = sessionStorage.getItem(COLUMNS_LAYOUT_STORAGE_KEY)
    if (!raw) return [...API_REFERENCE_COLUMNS_DEFAULT_LAYOUT]
    return normalizeApiReferenceColumnsLayout(JSON.parse(raw) as number[])
  } catch {
    return [...API_REFERENCE_COLUMNS_DEFAULT_LAYOUT]
  }
}

export function ApiReferenceExplorer({
  data,
  version,
  platform,
}: ApiReferenceExplorerProps) {
  const [selectedMethodId, setSelectedMethodId] = useState<string | undefined>(
    () => getInitialMethodId(data.methods),
  )
  const [columnsLayout, setColumnsLayout] = useState<number[]>(
    readStoredColumnsLayout,
  )

  const persistColumnsLayout = useCallback((layout: number[]) => {
    setColumnsLayout(layout)
    try {
      sessionStorage.setItem(COLUMNS_LAYOUT_STORAGE_KEY, JSON.stringify(layout))
    } catch {
      // ignore quota errors
    }
  }, [])

  const selectedMethod = useMemo(
    () => data.methods.find((method) => method.id === selectedMethodId),
    [data.methods, selectedMethodId],
  )

  const selectMethod = useCallback((methodId: string) => {
    setSelectedMethodId(methodId)
    const url = `${window.location.pathname}${window.location.search}#${methodId}`
    window.history.replaceState(null, '', url)
  }, [])

  useEffect(() => {
    const onHashChange = () => {
      const hash = window.location.hash.slice(1)
      if (hash && data.methods.some((method) => method.id === hash)) {
        setSelectedMethodId(hash)
      }
    }

    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [data.methods])

  useEffect(() => {
    if (
      selectedMethodId &&
      data.methods.some((method) => method.id === selectedMethodId)
    ) {
      return
    }
    const fallback = getDefaultMethodId(data.methods)
    if (fallback) {
      setSelectedMethodId(fallback)
    }
  }, [data.methods, selectedMethodId])

  if (data.methods.length === 0) {
    return (
      <div className="flex h-full min-h-0 items-center justify-center px-6 text-center text-[13px] text-muted-foreground">
        No endpoints found for this version and platform. Try switching to a
        different version or platform.
      </div>
    )
  }

  return (
    <div className={cn('flex h-full min-h-0 flex-1 flex-col')}>
      <div className="min-h-0 flex-1">
        <ReferenceColumnsResizableLayout
          layout={columnsLayout}
          persistLayout={persistColumnsLayout}
          handleClassName={REFERENCE_RESIZE_HANDLE_CLASS}
          className="h-full min-h-0 overflow-hidden"
          methods={
            <ApiReferenceMethodsPanel
              serviceId={data.id}
              serviceLabel={data.label}
              methods={data.methods}
              selectedMethodId={selectedMethodId}
              onSelectMethod={selectMethod}
            />
          }
          request={
            <ApiReferenceMethodPanel
              method={selectedMethod}
              version={version}
              platform={platform}
            />
          }
        />
      </div>
    </div>
  )
}
