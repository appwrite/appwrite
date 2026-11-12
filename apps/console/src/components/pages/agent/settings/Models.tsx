import { useEffect, useState } from 'react'
import { ChevronRight, Cpu, Loader2, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { AgentModelDrawer } from '@/components/global/providers/agent/AgentModelDrawer'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { getAssistantModelIconPath } from '@/lib/assistant/model-providers'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ASSISTANT_SETTINGS_PAGE_SIZE,
  ASSISTANT_SETTINGS_PAGE_SIZE_OPTIONS,
  useAssistantModels,
  type AssistantModel,
} from '@/lib/react-query/hooks'

function ModelIcon({ model }: { model: AssistantModel }) {
  const icon = getAssistantModelIconPath(model.provider, model.model)
  if (icon) {
    return (
      <img
        src={icon}
        alt=""
        className={cn('h-4 w-4 shrink-0', PUBLIC_ICON_MUTED_CLASSES)}
      />
    )
  }
  return <Cpu className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
}

function modelLabel(model: AssistantModel): string {
  return model.name?.trim() || model.model?.trim() || model.$id
}

export function Models() {
  const t = useT()
  const { isAuthenticated } = useAuth()
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(ASSISTANT_SETTINGS_PAGE_SIZE)
  const [editor, setEditor] = useState<
    | { mode: 'closed' }
    | { mode: 'create' }
    | { mode: 'edit'; model: AssistantModel }
  >({ mode: 'closed' })

  const {
    data: requestedData,
    isFetching: requestedFetching,
  } = useAssistantModels(requestedPage - 1, pageSize, {
    enabled: isAuthenticated,
  })
  const { data: displayedData, isLoading: displayedLoading } = useAssistantModels(
    displayedPage - 1,
    pageSize,
    { enabled: isAuthenticated },
  )

  useEffect(() => {
    if (
      !requestedFetching &&
      requestedPage !== displayedPage &&
      requestedData
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [displayedPage, requestedData, requestedFetching, requestedPage])

  const models = displayedData?.models ?? []
  const total = displayedData?.total ?? 0
  const closeEditor = () => setEditor({ mode: 'closed' })

  return (
    <>
      <div
        data-settings-card="Models"
        className="w-full rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="flex items-start justify-between gap-3 px-6 py-4">
          <div className="min-w-0 flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Models')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t(
                'Add custom LLM providers and API keys for the Appwrite Agent.',
              )}
            </p>
          </div>
          <Button
            type="button"
            size="sm"
            className="h-9 shrink-0 gap-1.5 text-[13px]"
            disabled={!isAuthenticated}
            {...analyticsAttrs('create-agent-model')}
            onClick={() => setEditor({ mode: 'create' })}
          >
            <Plus className="h-3.5 w-3.5" />
            {t('Add model')}
          </Button>
        </div>
        <div className="border-t border-border" />
        {!isAuthenticated ? (
          <div className="px-6 py-8">
            <EmptyState
              icon={Cpu}
              iconSize="md"
              title="Sign in to manage models."
              description="Add custom LLM providers and API keys for the Appwrite Agent."
              isEmpty
            />
          </div>
        ) : displayedLoading && models.length === 0 ? (
          <div className="flex items-center justify-center gap-1.5 px-6 py-8 text-[13px] text-muted-foreground">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            {t('Loading...')}
          </div>
        ) : total === 0 ? (
          <div className="px-6 py-8">
            <EmptyState
              icon={Cpu}
              iconSize="md"
              title="No custom models"
              description="No custom models yet. The Appwrite default model is always available."
              isEmpty
              action={
                <Button
                  type="button"
                  size="sm"
                  className="h-9 gap-1.5 text-[13px]"
                  {...analyticsAttrs('create-agent-model')}
                  onClick={() => setEditor({ mode: 'create' })}
                >
                  <Plus className="h-3.5 w-3.5" />
                  {t('Add model')}
                </Button>
              }
            />
          </div>
        ) : (
          <>
            <ul className="divide-y divide-border">
              {models.map((model) => {
                const enabled = model.enabled !== false
                return (
                  <li key={model.$id}>
                    <button
                      type="button"
                      className="flex w-full cursor-pointer items-center gap-3 px-6 py-4 text-start transition-colors hover:bg-accent/50"
                      onClick={() => setEditor({ mode: 'edit', model })}
                    >
                      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
                        <ModelIcon model={model} />
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-medium text-foreground">
                          {modelLabel(model)}
                        </p>
                        <p className="truncate text-[11px] text-muted-foreground">
                          {model.provider}
                          {model.model ? ` · ${model.model}` : ''}
                          {` · ${enabled ? t('Enabled') : t('Disabled')}`}
                        </p>
                      </div>
                      <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                    </button>
                  </li>
                )
              })}
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
                itemLabel="models"
                scrollToTopOnPageChange={false}
                className="py-0"
              />
            </div>
          </>
        )}
      </div>

      <AgentModelDrawer
        open={editor.mode !== 'closed'}
        onOpenChange={(open) => {
          if (!open) closeEditor()
        }}
        model={editor.mode === 'edit' ? editor.model : null}
        disabled={!isAuthenticated}
        onSaved={() => {
          closeEditor()
        }}
      />
    </>
  )
}
