/**
 * Environment Variables Card
 *
 * Accordion card for managing env vars: table, import .env, editor.
 * Reused by sites and functions create wizards.
 */

import { useState, useRef } from 'react'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Plus, Trash2, Eye, EyeOff, Upload, Code, Key } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { VariableEditor } from '@/components/global/shared/VariableEditor'
import { EmptyState } from '@/components/global/shared/EmptyState'

export interface EnvVariable {
  key: string
  value: string
  secret: boolean
}

interface EnvironmentVariablesCardProps {
  variables: EnvVariable[]
  onChange: (variables: EnvVariable[]) => void
  disabled?: boolean
  className?: string
  defaultOpen?: boolean
}

export function EnvironmentVariablesCard({
  variables,
  onChange,
  disabled = false,
  className,
  defaultOpen = false,
}: EnvironmentVariablesCardProps) {
  const [newKey, setNewKey] = useState('')
  const [newValue, setNewValue] = useState('')
  const [newSecret, setNewSecret] = useState(false)
  const [showSecrets, setShowSecrets] = useState<Set<string>>(new Set())
  const [editorOpen, setEditorOpen] = useState(false)
  const [editorContent, setEditorContent] = useState('')
  const [editorFormat, setEditorFormat] = useState<'env' | 'json'>('env')
  const [editorError, setEditorError] = useState<string | undefined>()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const newKeyInputRef = useRef<HTMLInputElement>(null)

  const handleAddVariable = () => {
    if (!newKey.trim()) {
      toast.error('Variable key is required')
      return
    }
    if (variables.some((v) => v.key === newKey.trim())) {
      toast.error('Variable key already exists')
      return
    }
    onChange([
      ...variables,
      { key: newKey.trim(), value: newValue, secret: newSecret },
    ])
    setNewKey('')
    setNewValue('')
    setNewSecret(false)
    setTimeout(() => newKeyInputRef.current?.focus(), 0)
  }

  const handleRemoveVariable = (index: number) => {
    onChange(variables.filter((_, i) => i !== index))
  }

  const handleUpdateVariable = (key: string, updates: Partial<EnvVariable>) => {
    onChange(variables.map((v) => (v.key === key ? { ...v, ...updates } : v)))
  }

  const handleToggleSecret = (key: string) => {
    const variable = variables.find((v) => v.key === key)
    if (variable) handleUpdateVariable(key, { secret: !variable.secret })
  }

  const handleToggleShowSecret = (key: string) => {
    setShowSecrets((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const handleFileImport = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    const reader = new FileReader()
    reader.onload = (event) => {
      const content = (event.target?.result as string) || ''
      const lines = content.split('\n')
      const newVars: EnvVariable[] = []
      for (const line of lines) {
        const trimmed = line.trim()
        if (!trimmed || trimmed.startsWith('#')) continue
        const eq = trimmed.indexOf('=')
        if (eq > 0) {
          const key = trimmed.substring(0, eq).trim()
          let value = trimmed.substring(eq + 1).trim()
          if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
          ) {
            value = value.slice(1, -1)
          }
          if (
            !variables.some((v) => v.key === key) &&
            !newVars.some((v) => v.key === key)
          ) {
            newVars.push({ key, value, secret: false })
          }
        }
      }
      if (newVars.length > 0) {
        onChange([...variables, ...newVars])
        toast.success(`Imported ${newVars.length} variable(s)`)
      } else {
        toast.info('No new variables to import')
      }
    }
    reader.readAsText(file)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const handleOpenEditor = () => {
    const nonSecret = variables.filter((v) => !v.secret)
    if (editorFormat === 'env') {
      setEditorContent(nonSecret.map((v) => `${v.key}=${v.value}`).join('\n'))
    } else {
      const obj: Record<string, string> = {}
      nonSecret.forEach((v) => {
        obj[v.key] = v.value
      })
      setEditorContent(JSON.stringify(obj, null, 2))
    }
    setEditorError(undefined)
    setEditorOpen(true)
  }

  const handleEditorFormatChange = (format: 'env' | 'json') => {
    const nonSecret = variables.filter((v) => !v.secret)
    if (format === 'env') {
      setEditorContent(nonSecret.map((v) => `${v.key}=${v.value}`).join('\n'))
    } else {
      const obj: Record<string, string> = {}
      nonSecret.forEach((v) => {
        obj[v.key] = v.value
      })
      setEditorContent(JSON.stringify(obj, null, 2))
    }
    setEditorFormat(format)
    setEditorError(undefined)
  }

  const handleEditorSave = () => {
    try {
      const newVars: EnvVariable[] = []
      const secretVars = variables.filter((v) => v.secret)
      if (editorFormat === 'env') {
        for (const line of editorContent.split('\n')) {
          const t = line.trim()
          if (!t || t.startsWith('#')) continue
          const eq = t.indexOf('=')
          if (eq > 0) {
            newVars.push({
              key: t.substring(0, eq).trim(),
              value: t.substring(eq + 1).trim(),
              secret: false,
            })
          }
        }
      } else {
        const parsed = JSON.parse(editorContent)
        if (typeof parsed !== 'object' || parsed === null)
          throw new Error('JSON must be an object')
        for (const [key, value] of Object.entries(parsed)) {
          if (typeof value === 'string')
            newVars.push({ key, value, secret: false })
        }
      }
      onChange([...secretVars, ...newVars])
      setEditorOpen(false)
      toast.success('Variables updated')
    } catch (err: unknown) {
      setEditorError((err as Error).message || 'Invalid format')
    }
  }

  const handleEditorCopy = () => {
    navigator.clipboard.writeText(editorContent)
    toast.success('Copied to clipboard')
  }

  const handleEditorDownload = () => {
    const blob = new Blob([editorContent], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = editorFormat === 'env' ? '.env' : 'variables.json'
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <>
      <Accordion
        type="single"
        collapsible
        defaultValue={defaultOpen ? 'env-vars' : undefined}
        className={cn(
          'rounded-xl border border-border bg-card/50 overflow-hidden',
          className,
        )}
      >
        <AccordionItem value="env-vars" className="border-none">
          <AccordionTrigger className="px-6 py-4 hover:no-underline hover:bg-transparent cursor-pointer">
            <span className="text-[15px] font-semibold text-foreground">
              Environment variables
              {variables.length > 0 && (
                <span className="ml-2 font-normal text-muted-foreground">
                  ({variables.length})
                </span>
              )}
            </span>
          </AccordionTrigger>
          <AccordionContent className="px-6 pb-4 pt-0 border-t border-border">
            <div className="space-y-4 pt-4">
              <div className="flex items-center gap-2">
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".env,.txt"
                  onChange={handleFileImport}
                  className="hidden"
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => fileInputRef.current?.click()}
                  disabled={disabled}
                  className="h-8 text-[12px]"
                >
                  <Upload className="mr-1.5 h-3.5 w-3.5" />
                  Import .env
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleOpenEditor}
                  disabled={disabled}
                  className="h-8 text-[12px]"
                >
                  <Code className="mr-1.5 h-3.5 w-3.5" />
                  Editor
                </Button>
              </div>
              {variables.length === 0 ? (
                <EmptyState
                  icon={Key}
                  title="No environment variables yet"
                  description="Add a variable below or import from a .env file."
                  isEmpty={true}
                  variant="card"
                />
              ) : (
                <div className="rounded-lg border border-border overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-2 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Key
                        </TableHead>
                        <TableHead className="px-4 py-2 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Value
                        </TableHead>
                        <TableHead className="px-4 py-2 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[80px]">
                          Secret
                        </TableHead>
                        <TableHead className="px-4 py-2 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px] text-right" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {variables.map((variable, index) => (
                        <TableRow
                          key={`${variable.key}-${index}`}
                          className="border-b border-border/50"
                        >
                          <TableCell className="px-4 py-2">
                            <code className="text-[12px] font-mono">
                              {variable.key}
                            </code>
                          </TableCell>
                          <TableCell className="px-4 py-2">
                            <div className="flex items-center gap-2">
                              <code className="text-[12px] font-mono text-muted-foreground max-w-[200px] truncate">
                                {variable.secret &&
                                !showSecrets.has(variable.key)
                                  ? '••••••••'
                                  : variable.value || '(empty)'}
                              </code>
                              {variable.secret && (
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() =>
                                    handleToggleShowSecret(variable.key)
                                  }
                                  className="h-6 w-6 p-0"
                                >
                                  {showSecrets.has(variable.key) ? (
                                    <EyeOff className="h-3.5 w-3.5" />
                                  ) : (
                                    <Eye className="h-3.5 w-3.5" />
                                  )}
                                </Button>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="px-4 py-2">
                            <Checkbox
                              checked={variable.secret}
                              onCheckedChange={() =>
                                handleToggleSecret(variable.key)
                              }
                              disabled={disabled}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-2 text-right">
                            <TooltipProvider delayDuration={0}>
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 w-8 p-0 text-muted-foreground hover:text-destructive"
                                    disabled={disabled}
                                    onClick={() => handleRemoveVariable(index)}
                                  >
                                    <Trash2 className="h-4 w-4" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent side="left">
                                  <p>Delete variable</p>
                                </TooltipContent>
                              </Tooltip>
                            </TooltipProvider>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1.5">
                    <label className="text-[12px] font-medium text-muted-foreground">
                      Key
                    </label>
                    <Input
                      ref={newKeyInputRef}
                      value={newKey}
                      onChange={(e) =>
                        setNewKey(
                          e.target.value
                            .toUpperCase()
                            .replace(/[^A-Z0-9_]/g, ''),
                        )
                      }
                      placeholder="VARIABLE_NAME"
                      disabled={disabled}
                      className="h-9 font-mono text-[13px]"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <label className="text-[12px] font-medium text-muted-foreground">
                      Value
                    </label>
                    <Input
                      value={newValue}
                      onChange={(e) => setNewValue(e.target.value)}
                      placeholder="Enter value"
                      disabled={disabled}
                      className="h-9 text-[13px]"
                    />
                  </div>
                </div>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Checkbox
                      id="new-secret"
                      checked={newSecret}
                      onCheckedChange={(c) => setNewSecret(c === true)}
                      disabled={disabled}
                    />
                    <label
                      htmlFor="new-secret"
                      className="text-[12px] text-muted-foreground cursor-pointer"
                    >
                      Mark as secret
                    </label>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={handleAddVariable}
                    disabled={disabled || !newKey.trim()}
                    className="h-8 text-[12px]"
                  >
                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                    Add variable
                  </Button>
                </div>
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
      </Accordion>
      <VariableEditor
        open={editorOpen}
        onOpenChange={setEditorOpen}
        content={editorContent}
        onContentChange={setEditorContent}
        format={editorFormat}
        onFormatChange={handleEditorFormatChange}
        error={editorError}
        onSave={handleEditorSave}
        onCopy={handleEditorCopy}
        onDownload={handleEditorDownload}
      />
    </>
  )
}
