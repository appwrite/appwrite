import { useState, useEffect } from 'react'
import { Download, Copy, Check, FileJson, FileText, Code } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { toast } from 'sonner'
import type { DatabaseSchema } from '@/lib/utils/database-schema-export'
import { useT } from '@/lib/i18n/translate'
import {
  formatSchemaAsJSON,
  formatSchemaAsMarkdown,
  formatSchemaAsTypeScript,
  downloadAsFile,
} from '@/lib/utils/database-schema-export'

interface SchemaExportDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  schema: DatabaseSchema | null
  isLoading: boolean
}

type ExportFormat = 'json' | 'markdown' | 'typescript'

export function SchemaExportDialog({
  open,
  onOpenChange,
  schema,
  isLoading,
}: SchemaExportDialogProps) {
  const t = useT()
  const [format, setFormat] = useState<ExportFormat>('json')
  const [copied, setCopied] = useState(false)
  const [exportContent, setExportContent] = useState<string>('')

  // Generate export content when schema or format changes
  useEffect(() => {
    if (!schema) {
      setExportContent('')
      return
    }

    let content = ''
    switch (format) {
      case 'json':
        content = formatSchemaAsJSON(schema)
        break
      case 'markdown':
        content = formatSchemaAsMarkdown(schema)
        break
      case 'typescript':
        content = formatSchemaAsTypeScript(schema)
        break
    }
    setExportContent(content)
  }, [schema, format])

  const handleCopy = async () => {
    if (!exportContent) return

    try {
      await navigator.clipboard.writeText(exportContent)
      setCopied(true)
      toast.success(t('Schema copied to clipboard'))
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy to clipboard'))
    }
  }

  const handleDownload = () => {
    if (!exportContent || !schema) return

    const extensions = {
      json: 'json',
      markdown: 'md',
      typescript: 'ts',
    }

    const extension = extensions[format]
    const filename = `${schema.database.name.replace(/[^a-zA-Z0-9]/g, '_')}_schema.${extension}`

    const mimeTypes = {
      json: 'application/json',
      markdown: 'text/markdown',
      typescript: 'text/typescript',
    }

    downloadAsFile(exportContent, filename, mimeTypes[format])
    toast.success(t('Schema downloaded'))
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-3xl p-0 max-h-[90dvh] flex flex-col">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Export Database Schema')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Export your database structure in a format suitable for AI agents and IDEs like Cursor or Lovable.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0 flex-1 min-h-0 overflow-hidden flex flex-col">
          {isLoading ? (
            <div className="flex h-64 items-center justify-center">
              <div className="text-muted-foreground">{t('Loading schema...')}</div>
            </div>
          ) : !schema ? (
            <div className="flex h-64 items-center justify-center">
              <div className="text-muted-foreground">
                {t('No schema data available')}
              </div>
            </div>
          ) : (
            <div className="space-y-4 flex-1 min-h-0 flex flex-col">
              {/* Format Selection */}
              <Tabs
                value={format}
                onValueChange={(value) => setFormat(value as ExportFormat)}
              >
                <TabsList className="grid w-full grid-cols-3">
                  <TabsTrigger
                    value="json"
                    className="flex items-center gap-1.5"
                  >
                    <FileJson className="h-3.5 w-3.5" />
                    JSON
                  </TabsTrigger>
                  <TabsTrigger
                    value="markdown"
                    className="flex items-center gap-1.5"
                  >
                    <FileText className="h-3.5 w-3.5" />
                    Markdown
                  </TabsTrigger>
                  <TabsTrigger
                    value="typescript"
                    className="flex items-center gap-1.5"
                  >
                    <Code className="h-3.5 w-3.5" />
                    TypeScript
                  </TabsTrigger>
                </TabsList>

                <TabsContent
                  value={format}
                  className="mt-4 flex-1 min-h-0 flex flex-col"
                >
                  <div className="space-y-3 flex-1 min-h-0 flex flex-col">
                    <div className="flex items-center justify-between">
                      <span className="text-[13px] text-muted-foreground">
                        {format === 'json'
                          ? t('JSON format')
                          : format === 'markdown'
                            ? t('Markdown format')
                            : t('TypeScript format')}
                      </span>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-8 gap-1.5 text-[13px]"
                          onClick={handleCopy}
                          disabled={!exportContent}
                        >
                          {copied ? (
                            <>
                              <Check className="h-3.5 w-3.5" />
                              {t('Copied')}
                            </>
                          ) : (
                            <>
                              <Copy className="h-3.5 w-3.5" />
                              {t('Copy')}
                            </>
                          )}
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-8 gap-1.5 text-[13px]"
                          onClick={handleDownload}
                          disabled={!exportContent}
                        >
                          <Download className="h-3.5 w-3.5" />
                          {t('Download')}
                        </Button>
                      </div>
                    </div>
                    <div className="relative flex-1 min-h-0">
                      <Textarea
                        value={exportContent}
                        readOnly
                        className="h-full font-mono text-[12px] resize-none overflow-auto"
                        placeholder={t('Export content will appear here...')}
                      />
                    </div>
                  </div>
                </TabsContent>
              </Tabs>
            </div>
          )}
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
          >
            {t('Close')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
