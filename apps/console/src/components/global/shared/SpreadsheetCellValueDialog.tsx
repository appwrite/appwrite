import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { formatSpreadsheetCellValueForDialog } from '@/lib/spreadsheet-cell-formatting'
import type { SpreadsheetCellValueDialogState } from '@/components/global/shared/SpreadsheetCellContextMenu'
import { useT } from '@/lib/i18n/translate'

type SpreadsheetCellValueDialogProps = {
  state: SpreadsheetCellValueDialogState | null
  onOpenChange: (open: boolean) => void
}

function resolveDialogLanguage(content: string): CodeBlockLanguage {
  const trimmed = content.trim()
  if (
    (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
    (trimmed.startsWith('[') && trimmed.endsWith(']'))
  ) {
    try {
      JSON.parse(trimmed)
      return 'json'
    } catch {
      return 'plaintext'
    }
  }
  return 'plaintext'
}

export function SpreadsheetCellValueDialog({
  state,
  onOpenChange,
}: SpreadsheetCellValueDialogProps) {
  const t = useT()
  const content = state
    ? formatSpreadsheetCellValueForDialog(state.value, state.full)
    : ''
  const language = resolveDialogLanguage(content)

  return (
    <Dialog open={state !== null} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[min(85dvh,720px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
        <DialogHeader className="shrink-0 px-6 pb-4 pt-6 text-start">
          <DialogTitle>{state?.columnLabel ?? t('Cell value')}</DialogTitle>
          {state ? (
            <DialogDescription className="mt-2 text-[13px]">
              {t('Row')} {state.rowNumber}
            </DialogDescription>
          ) : null}
        </DialogHeader>
        {state ? (
          <div className="min-h-0 flex-1 overflow-hidden border-t border-border">
            <ConnectCodeExample
              code={content}
              language={language}
              headless
              className="h-full max-h-[min(60dvh,520px)] rounded-none border-0"
              fixedHeight="100%"
            />
          </div>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
