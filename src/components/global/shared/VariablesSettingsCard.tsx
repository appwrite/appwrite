/**
 * Variables Settings Card
 *
 * Shared card for managing environment variables (project, function, site).
 * Provides create, update, delete, secret, editor, and import .env flows.
 */

import { useState, useEffect } from 'react'
import { cn } from '@/lib/utils'
import {
  Loader2,
  Plus,
  Code,
  Upload,
  Eye,
  EyeOff,
  XCircle,
  MoreHorizontal,
  Copy,
  Check,
  Key,
} from 'lucide-react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { VariableEditor } from '@/components/global/shared/VariableEditor'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

export interface VariableRecord {
  $id: string
  key: string
  value: string
  secret?: boolean
}

function parseEnvFile(content: string): Record<string, string> {
  const result: Record<string, string> = {}
  const lines = content.split('\n')

  for (const line of lines) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) continue

    const match = trimmed.match(/^([^=:#]+?)[=:](.*)$/)
    if (match) {
      const key = match[1].trim()
      let value = match[2].trim()
      value = value.replace(/^["']|["']$/g, '')
      if (key) result[key] = value
    }
  }

  return result
}

function variablesToEnv(vars: VariableRecord[]): string {
  return vars
    .filter((v) => !v.secret)
    .map((v) => `${v.key}=${v.value}`)
    .join('\n')
}

function variablesToJson(vars: VariableRecord[]): string {
  const obj: Record<string, string> = {}
  vars
    .filter((v) => !v.secret)
    .forEach((v) => {
      obj[v.key] = v.value
    })
  return JSON.stringify(obj, null, 2)
}

function CopyableText({
  value,
  hideValue = false,
}: {
  value: string
  hideValue?: boolean
}) {
  const [copied, setCopied] = useState(false)
  const [showValue, setShowValue] = useState(false)

  const handleCopy = () => {
    navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  const displayValue = hideValue && !showValue ? '•'.repeat(20) : value

  return (
    <div className="flex items-center gap-2 group cursor-pointer">
      <span
        className={cn(
          'text-[13px] font-mono text-foreground truncate',
          hideValue && 'w-[200px]',
        )}
        title={value}
      >
        {displayValue}
      </span>
      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
        {hideValue && (
          <button
            type="button"
            onClick={() => setShowValue(!showValue)}
            className="text-muted-foreground hover:text-foreground cursor-pointer"
          >
            {showValue ? (
              <EyeOff className="h-3.5 w-3.5" />
            ) : (
              <Eye className="h-3.5 w-3.5" />
            )}
          </button>
        )}
        <button type="button" onClick={handleCopy} className="cursor-pointer">
          {copied ? (
            <Check className="h-3.5 w-3.5 text-emerald-500" />
          ) : (
            <Copy className="h-3.5 w-3.5 text-muted-foreground" />
          )}
        </button>
      </div>
    </div>
  )
}

export interface VariablesSettingsCardProps {
  title: string
  description: string
  variables: VariableRecord[]
  total: number
  isLoading: boolean
  createMutation: {
    mutateAsync: (args: {
      key: string
      value: string
      secret: boolean
    }) => Promise<unknown>
    isPending: boolean
  }
  updateMutation: {
    mutateAsync: (args: {
      variableId: string
      key: string
      value: string
      secret: boolean
    }) => Promise<unknown>
    isPending: boolean
  }
  deleteMutation: {
    mutateAsync: (variableId: string) => Promise<unknown>
    isPending: boolean
  }
  scopeLabel: string
  page?: number
  limit?: number
  onPageChange?: (page: number) => void
  itemLabel?: string
  isVariableEditable?: (v: VariableRecord) => boolean
  getVariableBadge?: (v: VariableRecord) => string | undefined
  emptyTitle?: string
  emptyDescription?: string
}

export function VariablesSettingsCard({
  title,
  description,
  variables,
  total,
  isLoading,
  createMutation,
  updateMutation,
  deleteMutation,
  scopeLabel,
  page = 0,
  limit = 10,
  onPageChange,
  itemLabel = 'variables',
  isVariableEditable = () => true,
  getVariableBadge,
  emptyTitle = 'No environment variables yet',
  emptyDescription = 'Add a variable above or import from a .env file.',
}: VariablesSettingsCardProps) {
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [showUpdateModal, setShowUpdateModal] = useState(false)
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [showSecretModal, setShowSecretModal] = useState(false)
  const [showEditorModal, setShowEditorModal] = useState(false)
  const [showImportModal, setShowImportModal] = useState(false)
  const [selectedVar, setSelectedVar] = useState<VariableRecord | null>(null)

  const [createPairs, setCreatePairs] = useState<
    Array<{ key: string; value: string }>
  >([{ key: '', value: '' }])
  const [createSecret, setCreateSecret] = useState(false)
  const [updateValue, setUpdateValue] = useState('')
  const [updateSecret, setUpdateSecret] = useState(false)
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importSecret, setImportSecret] = useState(false)
  const [importError, setImportError] = useState('')
  const [editorContent, setEditorContent] = useState('')
  const [editorFormat, setEditorFormat] = useState<'env' | 'json'>('env')
  const [editorError, setEditorError] = useState('')
  const [deleteError, setDeleteError] = useState('')

  const currentPage = page + 1
  const hasPagination = limit > 0 && total > limit && onPageChange

  const envToObject = (content: string) => parseEnvFile(content)
  const jsonToObject = (content: string) => {
    try {
      return JSON.parse(content)
    } catch {
      throw new Error('Invalid JSON format')
    }
  }

  useEffect(() => {
    if (showEditorModal && variables.length > 0) {
      const editableVars = variables.filter((v) => !v.secret)
      setEditorContent(
        editorFormat === 'env'
          ? variablesToEnv(editableVars)
          : variablesToJson(editableVars),
      )
    } else if (showEditorModal) {
      setEditorContent(editorFormat === 'env' ? '' : '{}')
    }
  }, [showEditorModal, editorFormat, variables])

  const handleCreate = async () => {
    for (const pair of createPairs) {
      if (!pair.key.trim()) {
        toast.error('All variable keys are required')
        return
      }
      if (pair.value.length > 8192) {
        toast.error(
          `Variable ${pair.key} is longer than 8192 allowed characters`,
        )
        return
      }
    }

    try {
      await Promise.all(
        createPairs
          .filter((p) => p.key.trim())
          .map((pair) =>
            createMutation.mutateAsync({
              key: pair.key.trim(),
              value: pair.value,
              secret: createSecret,
            }),
          ),
      )
      toast.success(`${scopeLabel} variable has been created.`)
      setShowCreateModal(false)
      setCreatePairs([{ key: '', value: '' }])
      setCreateSecret(false)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to create variable'))
    }
  }

  const handleUpdate = async () => {
    if (!selectedVar) return
    if (updateValue.length > 8192) {
      toast.error(`Variable value is longer than 8192 allowed characters`)
      return
    }

    try {
      await updateMutation.mutateAsync({
        variableId: selectedVar.$id,
        key: selectedVar.key,
        value: updateValue,
        secret: updateSecret,
      })
      toast.success(`${scopeLabel} variable has been updated.`)
      setShowUpdateModal(false)
      setSelectedVar(null)
      setUpdateValue('')
      setUpdateSecret(false)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to update variable'))
    }
  }

  const handleDelete = async () => {
    if (!selectedVar) return
    setDeleteError('')
    try {
      await deleteMutation.mutateAsync(selectedVar.$id)
      toast.success(`${scopeLabel} variable has been deleted.`)
      setShowDeleteModal(false)
      setSelectedVar(null)
    } catch (error: unknown) {
      setDeleteError(getErrorMessage(error, 'Failed to delete variable'))
    }
  }

  const handleMarkSecret = async () => {
    if (!selectedVar) return
    try {
      await updateMutation.mutateAsync({
        variableId: selectedVar.$id,
        key: selectedVar.key,
        value: selectedVar.value || '',
        secret: true,
      })
      toast.success(`${scopeLabel} variable has been marked as secret.`)
      setShowSecretModal(false)
      setSelectedVar(null)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to mark variable as secret'))
    }
  }

  const handleImport = async () => {
    if (!importFile) {
      setImportError('No file selected')
      return
    }
    setImportError('')
    try {
      const text = await importFile.text()
      const parsed = parseEnvFile(text)
      if (Object.keys(parsed).length === 0) {
        setImportError('No variables found')
        return
      }
      for (const [key, value] of Object.entries(parsed)) {
        if (value.length > 8192) {
          setImportError(
            `Variable ${key} is longer than 8192 allowed characters`,
          )
          return
        }
      }

      const existingKeys = new Set(variables.map((v) => v.key))
      const promises: Promise<unknown>[] = []

      for (const [key, value] of Object.entries(parsed)) {
        if (existingKeys.has(key)) {
          const existing = variables.find((v) => v.key === key)
          if (existing) {
            promises.push(
              updateMutation.mutateAsync({
                variableId: existing.$id,
                key,
                value,
                secret: importSecret,
              }),
            )
          }
        } else {
          promises.push(
            createMutation.mutateAsync({
              key,
              value,
              secret: importSecret,
            }),
          )
        }
      }

      await Promise.all(promises)
      toast.success('Variables have been uploaded.')
      setShowImportModal(false)
      setImportFile(null)
      setImportSecret(false)
    } catch (error: unknown) {
      setImportError(getErrorMessage(error, 'Failed to import variables'))
    }
  }

  const handleEditorSave = async () => {
    setEditorError('')
    try {
      const parsed: Record<string, string> =
        editorFormat === 'env'
          ? envToObject(editorContent)
          : jsonToObject(editorContent)

      for (const [key, value] of Object.entries(parsed)) {
        if (value.length > 8192) {
          setEditorError(
            `Variable ${key} is longer than 8192 allowed characters`,
          )
          return
        }
      }

      const editableVars = variables.filter((v) => !v.secret)
      const secretKeys = new Set(
        variables.filter((v) => v.secret).map((v) => v.key),
      )

      const updatePromises: Promise<unknown>[] = []
      const deletePromises: Promise<unknown>[] = []

      for (const variable of editableVars) {
        if (parsed[variable.key] === undefined) {
          deletePromises.push(deleteMutation.mutateAsync(variable.$id))
        } else if (parsed[variable.key] !== variable.value) {
          updatePromises.push(
            updateMutation.mutateAsync({
              variableId: variable.$id,
              key: variable.key,
              value: parsed[variable.key],
              secret: false,
            }),
          )
        }
      }

      const createPromises: Promise<unknown>[] = []
      for (const [key, value] of Object.entries(parsed)) {
        const existsInEditable = editableVars.some((v) => v.key === key)
        if (!existsInEditable && !secretKeys.has(key)) {
          createPromises.push(
            createMutation.mutateAsync({
              key,
              value,
              secret: false,
            }),
          )
        }
      }

      await Promise.all([
        ...updatePromises,
        ...deletePromises,
        ...createPromises,
      ])
      toast.success('Variables have been updated.')
      setShowEditorModal(false)
      setEditorContent('')
    } catch (error: unknown) {
      setEditorError(getErrorMessage(error, 'Failed to save variables'))
    }
  }

  const handleDownload = () => {
    const editableVars = variables.filter((v) => !v.secret)
    const content =
      editorFormat === 'env'
        ? variablesToEnv(editableVars)
        : variablesToJson(editableVars)
    const filename = editorFormat === 'env' ? 'variables.env' : 'variables.json'
    const blob = new Blob([content], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  }

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(editorContent)
      toast.success('Copied to clipboard')
    } catch {
      toast.error('Failed to copy to clipboard')
    }
  }

  const handleFormatSwitch = (format: 'env' | 'json') => {
    if (format === editorFormat) return
    try {
      const parsed: Record<string, string> =
        editorFormat === 'env'
          ? envToObject(editorContent)
          : jsonToObject(editorContent)
      setEditorContent(
        format === 'env'
          ? Object.entries(parsed)
              .map(([k, v]) => `${k}=${v}`)
              .join('\n')
          : JSON.stringify(parsed, null, 2),
      )
      setEditorFormat(format)
    } catch {
      const editableVars = variables.filter((v) => !v.secret)
      setEditorContent(
        format === 'env'
          ? variablesToEnv(editableVars)
          : variablesToJson(editableVars),
      )
      setEditorFormat(format)
    }
  }

  useEffect(() => {
    if (!showCreateModal) {
      setCreatePairs([{ key: '', value: '' }])
      setCreateSecret(false)
    }
  }, [showCreateModal])

  useEffect(() => {
    if (!showUpdateModal) {
      setSelectedVar(null)
      setUpdateValue('')
      setUpdateSecret(false)
    } else if (selectedVar) {
      setUpdateValue(selectedVar.secret ? '' : selectedVar.value || '')
      setUpdateSecret(selectedVar.secret || false)
    }
  }, [showUpdateModal, selectedVar])

  useEffect(() => {
    if (!showDeleteModal) {
      setSelectedVar(null)
      setDeleteError('')
    }
  }, [showDeleteModal])

  useEffect(() => {
    if (!showSecretModal) setSelectedVar(null)
  }, [showSecretModal])

  useEffect(() => {
    if (!showImportModal) {
      setImportFile(null)
      setImportSecret(false)
      setImportError('')
    }
  }, [showImportModal])

  useEffect(() => {
    if (!showEditorModal) {
      setEditorContent('')
      setEditorError('')
      setEditorFormat('env')
    }
  }, [showEditorModal])

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 @container">
          <div className="flex gap-6 @[600px]:flex-row flex-col">
            <div className="@[600px]:w-64 shrink-0">
              <p className="text-[13px] text-muted-foreground">{description}</p>
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center justify-between gap-2 mb-4">
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => setShowEditorModal(true)}
                    disabled={isLoading}
                  >
                    <Code className="mr-1.5 h-4 w-4" />
                    Editor
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => setShowImportModal(true)}
                    disabled={isLoading}
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
                  disabled={isLoading}
                >
                  <Plus className="mr-1.5 h-4 w-4" />
                  Create variable
                </Button>
              </div>

              {isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                </div>
              ) : total === 0 ? (
                <EmptyState
                  icon={Key}
                  title={emptyTitle}
                  description={emptyDescription}
                  isEmpty={true}
                  variant="card"
                />
              ) : (
                <>
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
                        {variables.map((variable) => {
                          const editable = isVariableEditable(variable)
                          const badge = getVariableBadge?.(variable)
                          return (
                            <TableRow key={variable.$id}>
                              <TableCell className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                  <CopyableText value={variable.key} />
                                  {badge && (
                                    <Badge
                                      variant="secondary"
                                      className="text-[11px]"
                                    >
                                      {badge}
                                    </Badge>
                                  )}
                                </div>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                {variable.secret ? (
                                  <Badge
                                    variant="secondary"
                                    className="text-[12px]"
                                  >
                                    Secret
                                  </Badge>
                                ) : (
                                  <CopyableText
                                    value={variable.value || ''}
                                    hideValue={true}
                                  />
                                )}
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                {editable && (
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
                                        onClick={() => {
                                          setSelectedVar(variable)
                                          setShowUpdateModal(true)
                                        }}
                                      >
                                        Update
                                      </DropdownMenuItem>
                                      {!variable.secret && (
                                        <DropdownMenuItem
                                          onClick={() => {
                                            setSelectedVar(variable)
                                            setShowSecretModal(true)
                                          }}
                                        >
                                          Secret
                                        </DropdownMenuItem>
                                      )}
                                      <DropdownMenuItem
                                        className="text-destructive"
                                        onClick={() => {
                                          setSelectedVar(variable)
                                          setShowDeleteModal(true)
                                        }}
                                      >
                                        Delete
                                      </DropdownMenuItem>
                                    </DropdownMenuContent>
                                  </DropdownMenu>
                                )}
                              </TableCell>
                            </TableRow>
                          )
                        })}
                      </TableBody>
                    </Table>
                  </div>

                  {hasPagination && (
                    <div className="mt-4">
                      <Pagination
                        currentPage={currentPage}
                        totalItems={total}
                        pageSize={limit}
                        pageSizeOptions={[limit]}
                        onPageChange={(newPage) => onPageChange!(newPage - 1)}
                        onPageSizeChange={() => {}}
                        itemLabel={itemLabel}
                      />
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Create Variable Modal */}
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
                    <Label htmlFor={`key-${index}`} className="text-[12px]">
                      Key <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id={`key-${index}`}
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
                    <Label htmlFor={`value-${index}`} className="text-[12px]">
                      Value <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id={`value-${index}`}
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
              disabled={createMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleCreate}
              disabled={
                createMutation.isPending ||
                createPairs.some((p) => !p.key.trim()) ||
                createPairs.some((p) => p.value.length > 8192)
              }
            >
              Create
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Update Variable Modal */}
      <Dialog open={showUpdateModal} onOpenChange={setShowUpdateModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Update variable</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Update the value of this variable. The key cannot be changed.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="update-key" className="text-[13px]">
                  Key
                </Label>
                <Input
                  id="update-key"
                  value={selectedVar?.key || ''}
                  disabled
                  className="font-mono text-[13px] bg-muted"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="update-value" className="text-[13px]">
                  Value <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="update-value"
                  type="password"
                  value={updateValue}
                  onChange={(e) => setUpdateValue(e.target.value)}
                  placeholder="Enter value"
                  className="font-mono text-[13px]"
                />
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="update-secret"
                  checked={updateSecret}
                  onCheckedChange={(c) => setUpdateSecret(c === true)}
                />
                <Label
                  htmlFor="update-secret"
                  className="text-[13px] cursor-pointer"
                >
                  Secret
                </Label>
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowUpdateModal(false)}
              disabled={updateMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleUpdate}
              disabled={
                updateMutation.isPending ||
                !updateValue.trim() ||
                updateValue.length > 8192
              }
            >
              Update
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete Variable Modal */}
      <Dialog open={showDeleteModal} onOpenChange={setShowDeleteModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete variable</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this variable? This action cannot
              be undone.
            </DialogDescription>
          </DialogHeader>
          {deleteError && (
            <div className="px-6">
              <div className="rounded-md bg-destructive/10 border border-destructive/20 p-3">
                <p className="text-[13px] text-destructive">{deleteError}</p>
              </div>
            </div>
          )}
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowDeleteModal(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Mark as Secret Modal */}
      <Dialog open={showSecretModal} onOpenChange={setShowSecretModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Mark as secret</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Once marked as secret, you and your team won't be able to read
              this variable's value. This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowSecretModal(false)}
              disabled={updateMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleMarkSecret}
              disabled={updateMutation.isPending}
            >
              Mark as secret
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Import Modal */}
      <Dialog open={showImportModal} onOpenChange={setShowImportModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Import .env file</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Upload a .env file to import variables. Existing variables with
              the same key will be updated.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="import-file" className="text-[13px]">
                  File
                </Label>
                <Input
                  id="import-file"
                  type="file"
                  accept=".env"
                  onChange={(e) => {
                    const file = e.target.files?.[0]
                    if (file) setImportFile(file)
                  }}
                  className="text-[13px]"
                />
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="import-secret"
                  checked={importSecret}
                  onCheckedChange={(c) => setImportSecret(c === true)}
                />
                <Label
                  htmlFor="import-secret"
                  className="text-[13px] cursor-pointer"
                >
                  Mark all as secret
                </Label>
              </div>
              {importError && (
                <div className="rounded-md bg-destructive/10 border border-destructive/20 p-3">
                  <p className="text-[13px] text-destructive">{importError}</p>
                </div>
              )}
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowImportModal(false)}
              disabled={createMutation.isPending || updateMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleImport}
              disabled={
                !importFile ||
                createMutation.isPending ||
                updateMutation.isPending
              }
            >
              Import
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <VariableEditor
        open={showEditorModal}
        onOpenChange={setShowEditorModal}
        content={editorContent}
        onContentChange={setEditorContent}
        format={editorFormat}
        onFormatChange={handleFormatSwitch}
        error={editorError}
        onSave={handleEditorSave}
        onCopy={handleCopy}
        onDownload={handleDownload}
        isSaving={
          createMutation.isPending ||
          updateMutation.isPending ||
          deleteMutation.isPending
        }
      />
    </>
  )
}
