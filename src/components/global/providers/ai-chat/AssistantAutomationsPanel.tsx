import { useEffect, useMemo, useState } from 'react'
import { Loader2, Plus, Search, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  useAssistantAutomations,
  useAssistantModels,
  useDeleteAssistantAutomation,
  useUpdateAssistantAutomation,
  type AssistantAutomation,
} from '@/lib/react-query/hooks'

type AssistantAutomationsPanelProps = {
  disabled?: boolean
  onCreate?: () => void
  onEdit?: (automation: AssistantAutomation) => void
}

export function AssistantAutomationsPanel({
  disabled = false,
  onCreate,
  onEdit,
}: AssistantAutomationsPanelProps) {
  const t = useT()
  const [automationSearch, setAutomationSearch] = useState('')
  const [debouncedAutomationSearch, setDebouncedAutomationSearch] = useState('')
  const { data: automations = [], isLoading } = useAssistantAutomations(
    debouncedAutomationSearch || undefined,
    { enabled: !disabled },
  )
  const { data: models = [] } = useAssistantModels({ enabled: !disabled })
  const updateMutation = useUpdateAssistantAutomation()
  const deleteMutation = useDeleteAssistantAutomation()
  const [deletingId, setDeletingId] = useState<string | null>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedAutomationSearch(automationSearch.trim())
    }, 300)
    return () => window.clearTimeout(timer)
  }, [automationSearch])

  const hasAutomationSearch = debouncedAutomationSearch.length > 0

  const modelNameById = useMemo(() => {
    const map = new Map<string, string>()
    for (const model of models) {
      map.set(model.$id, model.name || model.model || model.$id)
    }
    return map
  }, [models])

  const handleToggleEnabled = async (
    automation: AssistantAutomation,
    enabled: boolean,
  ) => {
    try {
      await updateMutation.mutateAsync({
        automationId: automation.$id,
        enabled,
      })
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to update automation')))
    }
  }

  const handleDelete = async (automationId: string) => {
    setDeletingId(automationId)
    try {
      await deleteMutation.mutateAsync(automationId)
      toast.success(t('Automation deleted'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete automation')))
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div>
      <div className="relative">
        <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          value={automationSearch}
          onChange={(event) => setAutomationSearch(event.target.value)}
          placeholder={t('Search automations...')}
          className="h-8 border-border bg-background pe-2 ps-8 text-[12px]"
          aria-label={t('Search automations...')}
          disabled={disabled}
        />
      </div>
      <div className="my-8">
        <Button
          type="button"
          variant="outline"
          className="h-8 shrink-0 gap-1.5 px-2.5 text-[12px]"
          onClick={onCreate}
          disabled={disabled}
        >
          <Plus className="h-3.5 w-3.5" />
          {t('Create automation')}
        </Button>
      </div>

      {isLoading && automations.length === 0 ? (
        <div className="flex min-h-[120px] items-center justify-center py-8">
          <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
        </div>
      ) : automations.length === 0 ? (
        <div className="flex min-h-[120px] items-center justify-center px-3 py-8">
          <p className="text-center text-[12px] text-muted-foreground">
            {hasAutomationSearch
              ? t('No automations match your search.')
              : t('No automations yet.')}
          </p>
        </div>
      ) : (
        <div className="space-y-1.5">
          {automations.map((automation) => {
            const modelLabel = automation.modelId
              ? (modelNameById.get(automation.modelId) ?? t('Custom model'))
              : t('Appwrite default')
            return (
              <button
                key={automation.$id}
                type="button"
                onClick={() => onEdit?.(automation)}
                disabled={disabled}
                className="flex w-full items-start gap-2 rounded-lg border border-border px-2.5 py-2 text-start transition-colors hover:bg-accent/50 disabled:pointer-events-none disabled:opacity-60"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-foreground">
                    {automation.name || t('Untitled automation')}
                  </p>
                  <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
                    {automation.schedule} · {modelLabel}
                  </p>
                  {automation.lastRunAt ? (
                    <div className="mt-0.5 flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground">
                      <span>{t('Last run')}</span>
                      <DateTooltip date={automation.lastRunAt} />
                    </div>
                  ) : null}
                </div>
                <div
                  className="flex shrink-0 items-center gap-1"
                  onClick={(event) => event.stopPropagation()}
                  onKeyDown={(event) => event.stopPropagation()}
                >
                  <Switch
                    checked={automation.enabled !== false}
                    onCheckedChange={(checked) =>
                      void handleToggleEnabled(automation, checked)
                    }
                    disabled={disabled || updateMutation.isPending}
                    aria-label={t('Enabled')}
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 text-muted-foreground hover:text-foreground"
                    onClick={() => void handleDelete(automation.$id)}
                    disabled={disabled || deletingId === automation.$id}
                    aria-label={t('Delete')}
                  >
                    {deletingId === automation.$id ? (
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    ) : (
                      <Trash2 className="h-3.5 w-3.5" />
                    )}
                  </Button>
                </div>
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
