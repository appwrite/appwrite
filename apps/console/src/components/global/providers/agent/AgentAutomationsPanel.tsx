import { useEffect, useState } from 'react'
import { Loader2, Plus, Search, Settings, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { formatDisplayKeys } from '@/lib/keyboard-shortcuts/display'
import { ShortcutGlyphs } from '@/components/global/shared/ShortcutGlyphs'
import { AGENT_NEW_AUTOMATION_SHORTCUT_RAW } from '@/lib/assistant/agent-shortcuts'
import {
  useAssistantAutomations,
  useDeleteAssistantAutomation,
  type AssistantAutomation,
} from '@/lib/react-query/hooks'

type AgentAutomationsPanelProps = {
  disabled?: boolean
  /** Highlight the active automation in the sidebar list. */
  selectedAutomationId?: string | null
  onCreate?: () => void
  /** Open automation detail (runs). */
  onSelect?: (automation: AssistantAutomation) => void
  /** Open settings / edit drawer. */
  onEdit?: (automation: AssistantAutomation) => void
}

export function AgentAutomationsPanel({
  disabled = false,
  selectedAutomationId = null,
  onCreate,
  onSelect,
  onEdit,
}: AgentAutomationsPanelProps) {
  const t = useT()
  const { isMac } = usePlatform()
  const newAutomationShortcutKeys = formatDisplayKeys(
    AGENT_NEW_AUTOMATION_SHORTCUT_RAW,
    isMac,
  )
  const newAutomationShortcutLabel = newAutomationShortcutKeys.join('')
  const [automationSearch, setAutomationSearch] = useState('')
  const [debouncedAutomationSearch, setDebouncedAutomationSearch] = useState('')
  const { data: automations = [], isLoading } = useAssistantAutomations(
    debouncedAutomationSearch || undefined,
    { enabled: !disabled },
  )
  const deleteMutation = useDeleteAssistantAutomation()
  const [deleteTarget, setDeleteTarget] = useState<AssistantAutomation | null>(
    null,
  )
  const [isDeleting, setIsDeleting] = useState(false)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedAutomationSearch(automationSearch.trim())
    }, 300)
    return () => window.clearTimeout(timer)
  }, [automationSearch])

  const hasAutomationSearch = debouncedAutomationSearch.length > 0

  const openDelete = (automation: AssistantAutomation) => {
    openDialogAfterOverlayCloses(() => setDeleteTarget(automation))
  }

  const closeDeleteDialog = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteTarget(null))
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    setIsDeleting(true)
    try {
      await deleteMutation.mutateAsync(deleteTarget.$id)
      toast.success(t('Automation deleted'))
      closeDeleteDialog()
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete automation')))
    } finally {
      setIsDeleting(false)
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
          {...analyticsAttrs('create-agent-automation')}
          onClick={onCreate}
          disabled={disabled}
          title={`${t('Create automation')} (${newAutomationShortcutLabel})`}
        >
          <Plus className="h-3.5 w-3.5" />
          {t('Create automation')}
          <kbd className="ms-0.5 hidden items-center rounded border border-border bg-muted/50 px-1 py-0.5 font-mono text-[10px] font-medium text-muted-foreground sm:inline-flex">
            <ShortcutGlyphs keys={newAutomationShortcutKeys} />
          </kbd>
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
        <div className="space-y-0.5">
          {automations.map((automation) => {
            const isSelected = selectedAutomationId === automation.$id
            const row = (
              <div
                role="button"
                tabIndex={disabled ? -1 : 0}
                aria-disabled={disabled || undefined}
                aria-label={automation.name || t('Untitled automation')}
                onClick={() => {
                  if (disabled) return
                  onSelect?.(automation)
                }}
                onKeyDown={(event) => {
                  if (disabled) return
                  if (event.key !== 'Enter' && event.key !== ' ') return
                  event.preventDefault()
                  onSelect?.(automation)
                }}
                className={cn(
                  'flex w-full items-center gap-1 rounded-md border border-transparent px-1.5 py-1 text-start transition-colors',
                  disabled
                    ? 'pointer-events-none cursor-default opacity-60'
                    : 'cursor-pointer',
                  isSelected
                    ? 'border-border bg-accent'
                    : 'hover:border-border hover:bg-accent/60',
                )}
              >
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span className="min-w-0 truncate text-[12px] font-medium text-foreground">
                    {automation.name || t('Untitled automation')}
                  </span>
                  {automation.lastRunAt ? (
                    <span className="min-w-0 truncate text-[11px] text-muted-foreground">
                      <DateTooltip
                        date={automation.lastRunAt}
                        disableTooltip
                        live
                      />
                    </span>
                  ) : null}
                </div>
              </div>
            )

            if (disabled) return <div key={automation.$id}>{row}</div>

            return (
              <ContextMenu key={automation.$id}>
                <ContextMenuTrigger asChild>{row}</ContextMenuTrigger>
                <ContextMenuContent className="w-48">
                  <ContextMenuItem onSelect={() => onEdit?.(automation)}>
                    <ContextMenuIcon icon={Settings} />
                    {t('Settings')}
                  </ContextMenuItem>
                  <ContextMenuSeparator />
                  <ContextMenuItem onSelect={() => openDelete(automation)}>
                    <ContextMenuIcon icon={Trash2} />
                    {t('Delete')}
                  </ContextMenuItem>
                </ContextMenuContent>
              </ContextMenu>
            )
          })}
        </div>
      )}

      <Dialog
        open={deleteTarget !== null}
        onOpenChange={(open) => {
          if (!open) {
            closeDeleteDialog()
            return
          }
        }}
      >
        <DialogContent
          className="z-[140] sm:max-w-md p-0"
          overlayClassName="z-[140]"
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete automation')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('This permanently deletes the automation.')}{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isDeleting}
              onClick={closeDeleteDialog}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={isDeleting}
              onClick={() => void handleDelete()}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
