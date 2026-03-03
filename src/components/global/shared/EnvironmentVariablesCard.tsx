/**
 * Environment Variables Card
 *
 * Card for managing env vars: table, import .env, editor, create modal.
 * Reused by sites and functions create wizards. Matches VariablesSettingsCard layout.
 */

import { useState, useRef, useEffect } from 'react'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Plus,
  Eye,
  EyeOff,
  Upload,
  Code,
  Key,
  XCircle,
  MoreHorizontal,
} from 'lucide-react'
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
  title?: string
  emptyTitle?: string
  emptyDescription?: string
}

export function EnvironmentVariablesCard({
  variables,
  onChange,
  disabled = false,
  className,
  title = 'Environment variables',
  emptyTitle = 'No environment variables yet',
  emptyDescription = 'Add a variable above or import from a .env file.',
}: EnvironmentVariablesCardProps) {
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [createPairs, setCreatePairs] = useState<
    Array<{ key: string; value: string }>
  >([{ key: '', value: '' }])
  const [createSecret, setCreateSecret] = useState(false)
  const [showSecrets, setShowSecrets] = useState<Set<string>>(new Set())
  const [editorOpen, setEditorOpen] = useState(false)
  const [editorContent, setEditorContent] = useState('')
  const [editorFormat, setEditorFormat] = useState<'env' | 'json'>('env')
  const [editorError, setEditorError] = useState<string | undefined>()
  const fileInputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!showCreateModal) {
      setCreatePairs([{ key: '', value: '' }])
      setCreateSecret(false)
    }
  }, [showCreateModal])

  const handleCreateFromModal = () => {
    for (const pair of createPairs) {
      if (!pair.key.trim()) {
        toast.error('All variable keys are required')
        return
      }
      if (variables.some((v) => v.key === pair.key.trim())) {
        toast.error(`Variable ${pair.key.trim()} already exists`)
        return
      }
      if (pair.value.length > 8192) {
        toast.error(
          `Variable ${pair.key.trim()} is longer than 8192 allowed characters`,
        )
        return
      }
    }
    const newVars = createPairs
      .filter((p) => p.key.trim())
      .map((pair) => ({
        key: pair.key.trim(),
        value: pair.value,
        secret: createSecret,
      }))
    if (newVars.length > 0) {
      onChange([...variables, ...newVars])
      toast.success(
        `${newVars.length} variable${newVars.length > 1 ? 's' : ''} added`,
      )
    }
    setShowCreateModal(false)
    setCreatePairs([{ key: '', value: '' }])
    setCreateSecret(false)
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
      <div
        className={cn(
          'rounded-xl border border-border bg-card/50 overflow-hidden',
          className,
        )}
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-center justify-between gap-2 mb-4">
            <div className="flex items-center gap-2">
              <input
                ref={fileInputRef}
                type="file"
                accept=".env,.txt"
                onChange={handleFileImport}
                className="hidden"
              />
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleOpenEditor}
                disabled={disabled}
              >
                <Code className="mr-1.5 h-4 w-4" />
                Editor
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => fileInputRef.current?.click()}
                disabled={disabled}
              >
                <Upload className="mr-1.5 h-4 w-4" />
                Import .env
              </Button>
            </div>
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowCreateModal(true)}
              disabled={disabled}
            >
              <Plus className="mr-1.5 h-4 w-4" />
              Create variable
            </Button>
          </div>
          {variables.length === 0 ? (
            <EmptyState
              icon={Key}
              title={emptyTitle}
              description={emptyDescription}
              isEmpty={true}
              variant="card"
            />
          ) : (
            <div className="rounded-lg border border-border overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[200px] max-w-[400px]">
                      Key
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[200px] max-w-[400px]">
                      Value
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {variables.map((variable, index) => (
                    <TableRow
                      key={`${variable.key}-${index}`}
                      className="border-b border-border/50"
                    >
                      <TableCell className="px-4 py-3">
                        <code className="text-[12px] font-mono">
                          {variable.key}
                        </code>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {variable.secret ? (
                          <div className="flex items-center gap-2">
                            {showSecrets.has(variable.key) ? (
                              <>
                                <code className="text-[12px] font-mono text-muted-foreground">
                                  {variable.value || '(empty)'}
                                </code>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() =>
                                    handleToggleShowSecret(variable.key)
                                  }
                                  className="h-6 w-6 p-0"
                                >
                                  <EyeOff className="h-4 w-4" />
                                </Button>
                              </>
                            ) : (
                              <>
                                <Badge
                                  variant="secondary"
                                  className="text-[12px]"
                                >
                                  Secret
                                </Badge>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() =>
                                    handleToggleShowSecret(variable.key)
                                  }
                                  className="h-6 w-6 p-0"
                                >
                                  <Eye className="h-4 w-4" />
                                </Button>
                              </>
                            )}
                          </div>
                        ) : (
                          <code className="text-[12px] font-mono text-muted-foreground">
                            {variable.value || '(empty)'}
                          </code>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {!disabled && (
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 w-7 p-0"
                                onClick={(e) => e.stopPropagation()}
                              >
                                <MoreHorizontal className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() => handleToggleSecret(variable.key)}
                              >
                                {variable.secret ? 'Unmark secret' : 'Secret'}
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                className="text-destructive"
                                onClick={() => handleRemoveVariable(index)}
                              >
                                Delete
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </div>
      </div>
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

      <Dialog open={showCreateModal} onOpenChange={setShowCreateModal}>
        <DialogContent className="sm:max-w-2xl p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Create variable</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Add one or more environment variables. You can add multiple
              variables at once.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0 max-h-[60vh] overflow-y-auto">
            <div className="space-y-4">
              {createPairs.map((pair, index) => (
                <div
                  key={index}
                  className="space-y-3 p-4 border border-border rounded-lg"
                >
                  <div className="flex items-center justify-between">
                    <Label className="text-[13px] font-medium">
                      Variable {index + 1}
                    </Label>
                    {createPairs.length > 1 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() =>
                          setCreatePairs(
                            createPairs.filter((_, i) => i !== index),
                          )
                        }
                        disabled={
                          createPairs.length === 1 && !pair.key && !pair.value
                        }
                      >
                        <XCircle className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label
                      htmlFor={`create-key-${index}`}
                      className="text-[12px]"
                    >
                      Key <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id={`create-key-${index}`}
                      value={pair.key}
                      onChange={(e) => {
                        const newPairs = [...createPairs]
                        newPairs[index].key = e.target.value
                        setCreatePairs(newPairs)
                      }}
                      placeholder="ENTER_KEY"
                      autoFocus={index === 0}
                      autoComplete="off"
                      className="font-mono text-[13px]"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label
                      htmlFor={`create-value-${index}`}
                      className="text-[12px]"
                    >
                      Value <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id={`create-value-${index}`}
                      type="password"
                      value={pair.value}
                      onChange={(e) => {
                        const newPairs = [...createPairs]
                        newPairs[index].value = e.target.value
                        setCreatePairs(newPairs)
                      }}
                      placeholder="Enter value"
                      className="font-mono text-[13px]"
                    />
                  </div>
                </div>
              ))}

              <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-full h-9 text-[13px]"
                onClick={() =>
                  setCreatePairs([...createPairs, { key: '', value: '' }])
                }
                disabled={!createPairs[createPairs.length - 1]?.key}
              >
                <Plus className="mr-2 h-4 w-4" />
                Add variable
              </Button>

              <div className="flex items-center space-x-2 pt-2">
                <Checkbox
                  id="create-secret"
                  checked={createSecret}
                  onCheckedChange={(c) => setCreateSecret(c === true)}
                />
                <Label
                  htmlFor="create-secret"
                  className="text-[13px] cursor-pointer"
                >
                  Secret
                </Label>
              </div>
              <p className="text-[12px] text-muted-foreground -mt-2">
                If selected, you and your team won't be able to read the values
                after creation.
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowCreateModal(false)}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleCreateFromModal}
              disabled={
                createPairs.some((p) => !p.key.trim()) ||
                createPairs.some((p) => p.value.length > 8192)
              }
            >
              Create
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
