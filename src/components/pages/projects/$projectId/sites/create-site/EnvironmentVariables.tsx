/**
 * Environment Variables Component
 *
 * Provides environment variable management with table view, import from .env file,
 * and code editor for bulk editing.
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Plus, MoreHorizontal, Trash2, Eye, EyeOff, Upload, Code } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { VariableEditor } from '@/components/global/shared/VariableEditor'
import type { WizardVariable } from './WizardContext'

interface EnvironmentVariablesProps {
  variables: WizardVariable[]
  onChange: (variables: WizardVariable[]) => void
  disabled?: boolean
  className?: string
  defaultOpen?: boolean
}

export function EnvironmentVariables({
  variables,
  onChange,
  disabled = false,
  className,
  defaultOpen = false,
}: EnvironmentVariablesProps) {
  const [newKey, setNewKey] = useState('')
  const [newValue, setNewValue] = useState('')
  const [newSecret, setNewSecret] = useState(false)
  const [showSecrets, setShowSecrets] = useState<Set<string>>(new Set())
  const [editorOpen, setEditorOpen] = useState(false)
  const [editorContent, setEditorContent] = useState('')
  const [editorFormat, setEditorFormat] = useState<'env' | 'json'>('env')
  const [editorError, setEditorError] = useState<string | undefined>()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const handleAddVariable = () => {
    if (!newKey.trim()) {
      toast.error('Variable key is required')
      return
    }

    // Check for duplicate key
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
  }

  const handleRemoveVariable = (key: string) => {
    onChange(variables.filter((v) => v.key !== key))
  }

  const handleUpdateVariable = (
    key: string,
    updates: Partial<WizardVariable>,
  ) => {
    onChange(
      variables.map((v) => (v.key === key ? { ...v, ...updates } : v)),
    )
  }

  const handleToggleSecret = (key: string) => {
    const variable = variables.find((v) => v.key === key)
    if (variable) {
      handleUpdateVariable(key, { secret: !variable.secret })
    }
  }

  const handleToggleShowSecret = (key: string) => {
    setShowSecrets((prev) => {
      const newSet = new Set(prev)
      if (newSet.has(key)) {
        newSet.delete(key)
      } else {
        newSet.add(key)
      }
      return newSet
    })
  }

  const handleFileImport = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return

    const reader = new FileReader()
    reader.onload = (event) => {
      const content = event.target?.result as string
      const lines = content.split('\n')
      const newVariables: WizardVariable[] = []

      for (const line of lines) {
        const trimmedLine = line.trim()
        // Skip empty lines and comments
        if (!trimmedLine || trimmedLine.startsWith('#')) continue

        const equalIndex = trimmedLine.indexOf('=')
        if (equalIndex > 0) {
          const key = trimmedLine.substring(0, equalIndex).trim()
          let value = trimmedLine.substring(equalIndex + 1).trim()
          // Remove surrounding quotes if present
          if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
          ) {
            value = value.slice(1, -1)
          }

          // Skip if key already exists
          if (!variables.some((v) => v.key === key) && !newVariables.some((v) => v.key === key)) {
            newVariables.push({ key, value, secret: false })
          }
        }
      }

      if (newVariables.length > 0) {
        onChange([...variables, ...newVariables])
        toast.success(`Imported ${newVariables.length} variable(s)`)
      } else {
        toast.info('No new variables to import')
      }
    }
    reader.readAsText(file)

    // Reset input
    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }

  const handleOpenEditor = () => {
    // Convert variables to format
    const nonSecretVars = variables.filter((v) => !v.secret)
    if (editorFormat === 'env') {
      setEditorContent(
        nonSecretVars.map((v) => `${v.key}=${v.value}`).join('\n'),
      )
    } else {
      const obj: Record<string, string> = {}
      nonSecretVars.forEach((v) => {
        obj[v.key] = v.value
      })
      setEditorContent(JSON.stringify(obj, null, 2))
    }
    setEditorError(undefined)
    setEditorOpen(true)
  }

  const handleEditorFormatChange = (format: 'env' | 'json') => {
    const nonSecretVars = variables.filter((v) => !v.secret)
    if (format === 'env') {
      setEditorContent(
        nonSecretVars.map((v) => `${v.key}=${v.value}`).join('\n'),
      )
    } else {
      const obj: Record<string, string> = {}
      nonSecretVars.forEach((v) => {
        obj[v.key] = v.value
      })
      setEditorContent(JSON.stringify(obj, null, 2))
    }
    setEditorFormat(format)
    setEditorError(undefined)
  }

  const handleEditorSave = () => {
    try {
      const newVariables: WizardVariable[] = []
      const secretVars = variables.filter((v) => v.secret)

      if (editorFormat === 'env') {
        const lines = editorContent.split('\n')
        for (const line of lines) {
          const trimmedLine = line.trim()
          if (!trimmedLine || trimmedLine.startsWith('#')) continue

          const equalIndex = trimmedLine.indexOf('=')
          if (equalIndex > 0) {
            const key = trimmedLine.substring(0, equalIndex).trim()
            const value = trimmedLine.substring(equalIndex + 1).trim()
            newVariables.push({ key, value, secret: false })
          }
        }
      } else {
        const parsed = JSON.parse(editorContent)
        if (typeof parsed !== 'object' || parsed === null) {
          throw new Error('JSON must be an object')
        }
        for (const [key, value] of Object.entries(parsed)) {
          if (typeof value === 'string') {
            newVariables.push({ key, value, secret: false })
          }
        }
      }

      // Merge with secret variables (preserve them)
      onChange([...secretVars, ...newVariables])
      setEditorOpen(false)
      toast.success('Variables updated')
    } catch (err: any) {
      setEditorError(err.message || 'Invalid format')
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
        className={className}
      >
        <AccordionItem value="env-vars" className="border-none">
          <AccordionTrigger className="py-0 hover:no-underline">
            <span className="text-[13px] font-medium text-foreground">
              Environment variables
              {variables.length > 0 && (
                <span className="ml-2 text-muted-foreground">
                  ({variables.length})
                </span>
              )}
            </span>
          </AccordionTrigger>
          <AccordionContent className="pt-4 pb-0">
            <div className="space-y-4">
              {/* Actions */}
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

              {/* Variables Table */}
              {variables.length > 0 && (
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
                        <TableHead className="px-4 py-2 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[60px]">
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {variables.map((variable) => (
                        <TableRow key={variable.key} className="border-b border-border/50">
                          <TableCell className="px-4 py-2">
                            <code className="text-[12px] font-mono">
                              {variable.key}
                            </code>
                          </TableCell>
                          <TableCell className="px-4 py-2">
                            <div className="flex items-center gap-2">
                              <code className="text-[12px] font-mono text-muted-foreground max-w-[200px] truncate">
                                {variable.secret && !showSecrets.has(variable.key)
                                  ? '••••••••'
                                  : variable.value || '(empty)'}
                              </code>
                              {variable.secret && (
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleToggleShowSecret(variable.key)}
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
                              onCheckedChange={() => handleToggleSecret(variable.key)}
                              disabled={disabled}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-2">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  className="h-8 w-8 p-0"
                                  disabled={disabled}
                                >
                                  <MoreHorizontal className="h-4 w-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                  onClick={() => handleRemoveVariable(variable.key)}
                                  className="text-destructive focus:text-destructive"
                                >
                                  <Trash2 className="mr-2 h-4 w-4" />
                                  Delete
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}

              {/* Add New Variable */}
              <div className="flex items-end gap-2">
                <div className="flex-1">
                  <Input
                    value={newKey}
                    onChange={(e) => setNewKey(e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, ''))}
                    placeholder="KEY"
                    disabled={disabled}
                    className="h-9 font-mono text-[13px]"
                  />
                </div>
                <div className="flex-1">
                  <Input
                    value={newValue}
                    onChange={(e) => setNewValue(e.target.value)}
                    placeholder="Value"
                    disabled={disabled}
                    className="h-9 text-[13px]"
                  />
                </div>
                <div className="flex items-center gap-2">
                  <Checkbox
                    id="new-secret"
                    checked={newSecret}
                    onCheckedChange={(checked) => setNewSecret(checked === true)}
                    disabled={disabled}
                  />
                  <label htmlFor="new-secret" className="text-[12px] text-muted-foreground">
                    Secret
                  </label>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleAddVariable}
                  disabled={disabled || !newKey.trim()}
                  className="h-9"
                >
                  <Plus className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
      </Accordion>

      {/* Variable Editor Dialog */}
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
