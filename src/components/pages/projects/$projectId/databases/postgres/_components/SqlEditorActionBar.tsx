import type { ReactNode } from 'react'
import { Bookmark, Braces, ListTree, Loader2, Play, Redo2, Undo2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { formatDisplayKeys } from '@/lib/keyboard-shortcuts/display'
import {
  POSTGRES_SQL_EXPLAIN_SHORTCUT_RAW,
  POSTGRES_SQL_FORMAT_SHORTCUT_RAW,
  POSTGRES_SQL_REDO_SHORTCUT_RAW,
  POSTGRES_SQL_RUN_SHORTCUT_RAW,
  POSTGRES_SQL_SAVE_SHORTCUT_RAW,
  POSTGRES_SQL_UNDO_SHORTCUT_RAW,
} from '@/lib/postgres-sql-editor-shortcuts'
import { cn } from '@/lib/utils'
import {
  POSTGRES_SQL_EDITOR_SURFACE_CLASS,
  POSTGRES_RUN_QUERY_PLAY_ICON_CLASS,
} from './postgres-chrome'
import { useT } from '@/lib/i18n/translate'

type SqlEditorActionBarProps = {
  canUndo: boolean
  canRedo: boolean
  canSave: boolean
  canFormat: boolean
  canRun: boolean
  canExplain: boolean
  isRunning: boolean
  isExplaining: boolean
  onUndo: () => void
  onRedo: () => void
  onSave: () => void
  onFormat: () => void
  onRun: () => void
  onExplain: () => void
  runDisabledTooltip?: string
  explainDisabledTooltip?: string
}

function ActionDivider() {
  return <div className="mx-0.5 h-5 w-px shrink-0 bg-border/80" aria-hidden />
}

function formatShortcutLabel(raw: string, isMac: boolean) {
  return formatDisplayKeys(raw, isMac).join('')
}

function ShortcutTooltip({
  enabled,
  disabledReason,
  enabledLabel,
  children,
}: {
  enabled: boolean
  disabledReason?: string
  enabledLabel: string
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{children}</span>
      </TooltipTrigger>
      <TooltipContent side="top" sideOffset={6} className="text-[12px]">
        {enabled ? enabledLabel : disabledReason}
      </TooltipContent>
    </Tooltip>
  )
}

export function SqlEditorActionBar({
  canUndo,
  canRedo,
  canSave,
  canFormat,
  canRun,
  canExplain,
  isRunning,
  isExplaining,
  onUndo,
  onRedo,
  onSave,
  onFormat,
  onRun,
  onExplain,
  runDisabledTooltip,
  explainDisabledTooltip,
}: SqlEditorActionBarProps) {
  const t = useT()
  const { isMac } = usePlatform()
  const undoShortcut = formatShortcutLabel(POSTGRES_SQL_UNDO_SHORTCUT_RAW, isMac)
  const redoShortcut = formatShortcutLabel(POSTGRES_SQL_REDO_SHORTCUT_RAW, isMac)
  const saveShortcut = formatShortcutLabel(POSTGRES_SQL_SAVE_SHORTCUT_RAW, isMac)
  const formatShortcut = formatShortcutLabel(POSTGRES_SQL_FORMAT_SHORTCUT_RAW, isMac)
  const explainShortcut = formatShortcutLabel(
    POSTGRES_SQL_EXPLAIN_SHORTCUT_RAW,
    isMac,
  )
  const runShortcut = formatShortcutLabel(POSTGRES_SQL_RUN_SHORTCUT_RAW, isMac)

  return (
    <div
      className={cn(
        'shrink-0 border-t border-border px-4 py-2.5 sm:px-6',
        POSTGRES_SQL_EDITOR_SURFACE_CLASS,
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-0.5">
          <ShortcutTooltip
            enabled={canUndo}
            disabledReason={t('Nothing to undo.')}
            enabledLabel={`${t('Undo')} (${undoShortcut})`}
          >
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 text-muted-foreground hover:bg-muted/70 hover:text-foreground"
              onMouseDown={(event) => event.preventDefault()}
              onClick={onUndo}
              disabled={!canUndo}
              aria-label={t('Undo')}
            >
              <Undo2 className="h-3.5 w-3.5 shrink-0" />
            </Button>
          </ShortcutTooltip>

          <ShortcutTooltip
            enabled={canRedo}
            disabledReason={t('Nothing to redo.')}
            enabledLabel={`${t('Redo')} (${redoShortcut})`}
          >
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 text-muted-foreground hover:bg-muted/70 hover:text-foreground"
              onMouseDown={(event) => event.preventDefault()}
              onClick={onRedo}
              disabled={!canRedo}
              aria-label={t('Redo')}
            >
              <Redo2 className="h-3.5 w-3.5 shrink-0" />
            </Button>
          </ShortcutTooltip>
        </div>

        <div className={cn('flex items-center gap-1')}>
          <ShortcutTooltip
            enabled={canSave}
            disabledReason={t('Write SQL before saving a query.')}
            enabledLabel={`${t('Save query')} (${saveShortcut})`}
          >
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 gap-1.5 px-2.5 text-[12px] font-medium text-muted-foreground hover:bg-muted/70 hover:text-foreground"
              onClick={onSave}
              disabled={!canSave}
            >
              <Bookmark className="h-3.5 w-3.5 shrink-0" />
              {t('Save')}
            </Button>
          </ShortcutTooltip>

          <ActionDivider />

          <ShortcutTooltip
            enabled={canFormat}
            disabledReason={t('Write SQL before formatting.')}
            enabledLabel={`${t('Format SQL')} (${formatShortcut})`}
          >
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 gap-1.5 px-2.5 text-[12px] font-medium text-muted-foreground hover:bg-muted/70 hover:text-foreground"
              onClick={onFormat}
              disabled={!canFormat}
            >
              <Braces className="h-3.5 w-3.5 shrink-0" />
              {t('Format')}
            </Button>
          </ShortcutTooltip>

          <ActionDivider />

          <ShortcutTooltip
            enabled={canExplain}
            disabledReason={
              !canExplain && explainDisabledTooltip
                ? explainDisabledTooltip
                : isExplaining
                  ? t('Query explanation is running.')
                  : isRunning
                    ? t('Query is running.')
                    : t('Write SQL before explaining a query.')
            }
            enabledLabel={`${t('Explain')} (${explainShortcut})`}
          >
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 px-3 text-[12px] font-medium"
              onClick={onExplain}
              disabled={!canExplain}
            >
              {isExplaining ? (
                <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
              ) : (
                <ListTree className="h-3.5 w-3.5 shrink-0" />
              )}
              {t('Explain')}
            </Button>
          </ShortcutTooltip>

          <ActionDivider />

          <ShortcutTooltip
            enabled={canRun}
            disabledReason={
              !canRun && runDisabledTooltip
                ? runDisabledTooltip
                : isRunning
                  ? t('Query is running.')
                  : isExplaining
                    ? t('Query explanation is running.')
                    : t('Write SQL before running a query.')
            }
            enabledLabel={`${t('Run')} (${runShortcut})`}
          >
            <Button
              type="button"
              variant="brandCta"
              size="sm"
              className="h-8 gap-1.5 px-3 text-[12px] font-semibold shadow-sm"
              onClick={onRun}
              disabled={!canRun}
            >
              {isRunning ? (
                <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
              ) : (
                <Play className={POSTGRES_RUN_QUERY_PLAY_ICON_CLASS} />
              )}
              {t('Run')}
            </Button>
          </ShortcutTooltip>
        </div>
      </div>
    </div>
  )
}
