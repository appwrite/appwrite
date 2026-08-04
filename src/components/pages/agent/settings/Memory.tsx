import { useEffect, useState } from 'react'
import { Brain, ChevronRight, Loader2, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { AgentMemoryDrawer } from '@/components/global/providers/agent/AgentMemoryDrawer'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import {
  ASSISTANT_SETTINGS_PAGE_SIZE,
  ASSISTANT_SETTINGS_PAGE_SIZE_OPTIONS,
  useAssistantMemories,
  type AssistantMemory,
} from '@/lib/react-query/hooks'

function categoryLabel(category: string, t: (text: string) => string) {
  switch (category) {
    case 'instruction':
      return t('Instruction')
    case 'fact':
      return t('Fact')
    case 'preference':
      return t('Preference')
    default:
      return category || t('Preference')
  }
}

function memoryStatusLabel(
  memory: AssistantMemory,
  t: (text: string) => string,
) {
  return memory.status === 'archived' ? t('Archived') : t('Active')
}

export function Memory() {
  const t = useT()
  const { isAuthenticated } = useAuth()
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(ASSISTANT_SETTINGS_PAGE_SIZE)
  const [editor, setEditor] = useState<
    | { mode: 'closed' }
    | { mode: 'create' }
    | { mode: 'edit'; memory: AssistantMemory }
  >({ mode: 'closed' })

  const {
    data: requestedData,
    isFetching: requestedFetching,
  } = useAssistantMemories(requestedPage - 1, pageSize, {
    enabled: isAuthenticated,
  })
  const { data: displayedData, isLoading: displayedLoading } =
    useAssistantMemories(displayedPage - 1, pageSize, {
      enabled: isAuthenticated,
    })

  useEffect(() => {
    if (
      !requestedFetching &&
      requestedPage !== displayedPage &&
      requestedData
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [displayedPage, requestedData, requestedFetching, requestedPage])

  const memories = displayedData?.memories ?? []
  const total = displayedData?.total ?? 0
  const closeEditor = () => setEditor({ mode: 'closed' })

  return (
    <>
      <div
        data-settings-card="Memory"
        className="w-full rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="flex items-start justify-between gap-3 px-6 py-4">
          <div className="min-w-0 flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Memory')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t(
                'Preferences, instructions, and facts the Appwrite Agent can reuse across conversations.',
              )}
            </p>
          </div>
          <Button
            type="button"
            size="sm"
            className="h-9 shrink-0 gap-1.5 text-[13px]"
            disabled={!isAuthenticated}
            {...analyticsAttrs('create-agent-memory')}
            onClick={() => setEditor({ mode: 'create' })}
          >
            <Plus className="h-3.5 w-3.5" />
            {t('Add memory')}
          </Button>
        </div>
        <div className="border-t border-border" />
        {!isAuthenticated ? (
          <div className="px-6 py-8">
            <EmptyState
              icon={Brain}
              iconSize="md"
              title="Sign in to manage memory."
              description="Preferences, instructions, and facts the Appwrite Agent can reuse across conversations."
              isEmpty
            />
          </div>
        ) : displayedLoading && memories.length === 0 ? (
          <div className="flex items-center justify-center gap-1.5 px-6 py-8 text-[13px] text-muted-foreground">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            {t('Loading...')}
          </div>
        ) : total === 0 ? (
          <div className="px-6 py-8">
            <EmptyState
              icon={Brain}
              iconSize="md"
              title="No memories"
              description="Add preferences, instructions, or facts for the agent to remember."
              isEmpty
              action={
                <Button
                  type="button"
                  size="sm"
                  className="h-9 gap-1.5 text-[13px]"
                  {...analyticsAttrs('create-agent-memory')}
                  onClick={() => setEditor({ mode: 'create' })}
                >
                  <Plus className="h-3.5 w-3.5" />
                  {t('Add memory')}
                </Button>
              }
            />
          </div>
        ) : (
          <>
            <ul className="divide-y divide-border">
              {memories.map((memory) => (
                <li key={memory.$id}>
                  <button
                    type="button"
                    className="flex w-full cursor-pointer items-center gap-3 px-6 py-4 text-start transition-colors hover:bg-accent/50"
                    onClick={() => setEditor({ mode: 'edit', memory })}
                  >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
                      <Brain
                        className="h-4 w-4 text-muted-foreground"
                        aria-hidden
                      />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex min-w-0 items-center gap-2">
                        <p className="truncate text-[13px] font-medium text-foreground">
                          {memory.key || memory.$id}
                        </p>
                        <Badge variant="info" className="text-[10px] shrink-0">
                          {categoryLabel(memory.category, t)}
                        </Badge>
                      </div>
                      <p className="truncate text-[11px] text-muted-foreground">
                        {[
                          memory.content?.trim() || null,
                          memoryStatusLabel(memory, t),
                        ]
                          .filter(Boolean)
                          .join(' · ')}
                      </p>
                    </div>
                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                  </button>
                </li>
              ))}
            </ul>
            <div className="border-t border-border px-6 py-2">
              <Pagination
                currentPage={displayedPage}
                totalItems={total}
                pageSize={pageSize}
                pageSizeOptions={[...ASSISTANT_SETTINGS_PAGE_SIZE_OPTIONS]}
                onPageChange={setRequestedPage}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setRequestedPage(1)
                  setDisplayedPage(1)
                }}
                itemLabel="memories"
                scrollToTopOnPageChange={false}
                className="py-0"
              />
            </div>
          </>
        )}
      </div>

      <AgentMemoryDrawer
        open={editor.mode !== 'closed'}
        onOpenChange={(open) => {
          if (!open) closeEditor()
        }}
        memory={editor.mode === 'edit' ? editor.memory : null}
        disabled={!isAuthenticated}
        onSaved={() => {
          closeEditor()
        }}
      />
    </>
  )
}
