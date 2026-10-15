import { Copy, Download } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  WIZARD_DIALOG_OVERLAY_Z,
  wizardDialogContentClassName,
} from '@/lib/wizard-portal-z'

interface VariableEditorProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  content: string
  onContentChange: (content: string) => void
  format: 'env' | 'json'
  onFormatChange: (format: 'env' | 'json') => void
  error?: string
  onSave: () => void
  onCopy: () => void
  onDownload: () => void
  isSaving?: boolean
  /** When true, dialog appears above fullscreen wizards */
  elevatedForWizard?: boolean
}

export function VariableEditor({
  open,
  onOpenChange,
  content,
  onContentChange,
  format,
  onFormatChange,
  error,
  onSave,
  onCopy,
  onDownload,
  isSaving = false,
  elevatedForWizard = false,
}: VariableEditorProps) {
  const t = useT()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn(
          'sm:max-w-3xl p-0 max-h-[90dvh] flex flex-col',
          elevatedForWizard && wizardDialogContentClassName(),
        )}
        overlayClassName={elevatedForWizard ? WIZARD_DIALOG_OVERLAY_Z : undefined}
        onEscapeKeyDown={(e) => e.stopPropagation()}
      >
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Variable editor')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Edit all variables at once. Secret variables are not shown and will not be affected.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0 flex-1 flex flex-col min-h-0 overflow-hidden">
          <div className="flex items-center justify-between mb-4 shrink-0">
            <Tabs
              value={format}
              onValueChange={(v) => onFormatChange(v as 'env' | 'json')}
            >
              <TabsList>
                <TabsTrigger value="env">ENV</TabsTrigger>
                <TabsTrigger value="json">JSON</TabsTrigger>
              </TabsList>
            </Tabs>
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 text-[12px]"
                onClick={onCopy}
              >
                <Copy className="me-2 h-3.5 w-3.5" />
                {t('Copy')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 text-[12px]"
                onClick={onDownload}
              >
                <Download className="me-2 h-3.5 w-3.5" />
                {t('Download')}
              </Button>
            </div>
          </div>

          {error && (
            <Alert variant="destructive" className="mb-4 shrink-0">
              <AlertTriangle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}

          <div className="flex-1 min-h-0 flex flex-col overflow-hidden">
            <Label
              htmlFor="editor-content"
              className="text-[13px] mb-2 shrink-0"
            >
              {t('Content')}
            </Label>
            <div className="flex-1 min-h-0 overflow-hidden">
              <Textarea
                id="editor-content"
                value={content}
                onChange={(e) => onContentChange(e.target.value)}
                placeholder={
                  format === 'env'
                    ? 'SECRET_KEY=dQw4w9WgXcQ...'
                    : '{\n  "SECRET_KEY": "dQw4w9WgXcQ..."\n}'
                }
                spellCheck={false}
                className="font-mono text-[13px] h-full w-full resize-none overflow-x-auto overflow-y-auto break-all whitespace-pre-wrap"
                style={{ wordBreak: 'break-all', overflowWrap: 'anywhere' }}
              />
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
            disabled={isSaving}
          >
            {t('Cancel')}
          </Button>
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={onSave}
            disabled={isSaving}
          >
            {t('Update')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
