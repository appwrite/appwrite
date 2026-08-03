import { useState } from 'react'
import { ChevronRight, Cpu, Loader2, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { AgentModelDrawer } from '@/components/global/providers/agent/AgentModelDrawer'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { getAssistantModelIconPath } from '@/lib/assistant/model-providers'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
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
  const { data: models = [], isLoading } = useAssistantModels({
    enabled: isAuthenticated,
  })
  const [editor, setEditor] = useState<
    | { mode: 'closed' }
    | { mode: 'create' }
    | { mode: 'edit'; model: AssistantModel }
  >({ mode: 'closed' })

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
            onClick={() => setEditor({ mode: 'create' })}
          >
            <Plus className="h-3.5 w-3.5" />
            {t('Add model')}
          </Button>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          {!isAuthenticated ? (
            <EmptyState
              icon={Cpu}
              iconSize="md"
              title="Sign in to manage models."
              description="Add custom LLM providers and API keys for the Appwrite Agent."
              isEmpty
              className="py-6"
            />
          ) : isLoading && models.length === 0 ? (
            <div className="flex items-center justify-center gap-1.5 py-8 text-[13px] text-muted-foreground">
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              {t('Loading...')}
            </div>
          ) : models.length === 0 ? (
            <EmptyState
              icon={Cpu}
              iconSize="md"
              title="No custom models"
              description="No custom models yet. The Appwrite default model is always available."
              isEmpty
              className="py-6"
              action={
                <Button
                  type="button"
                  size="sm"
                  className="h-9 gap-1.5 text-[13px]"
                  onClick={() => setEditor({ mode: 'create' })}
                >
                  <Plus className="h-3.5 w-3.5" />
                  {t('Add model')}
                </Button>
              }
            />
          ) : (
            <ul className="overflow-hidden divide-y divide-border rounded-lg border border-border">
              {models.map((model) => {
                const enabled = model.enabled !== false
                return (
                  <li key={model.$id}>
                    <button
                      type="button"
                      className="flex w-full items-center gap-3 rounded-none px-3 py-3 text-start transition-colors hover:bg-accent/50"
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
          )}
        </div>
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
