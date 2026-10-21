/**
 * Variables card - settings (API) and wizard (local state).
 *
 * - `variant="settings"` (default): project / function / site variables via mutations.
 * - `variant="wizard"`: create flows; updates `onChange` only (never calls API).
 */

import { useState, useEffect, useRef } from 'react'
import { Link } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  Loader2,
  Plus,
  Code,
  Upload,
  Eye,
  EyeOff,
  XCircle,
  Copy,
  Check,
  Key,
  AlertTriangle,
  Lock,
  Pencil,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { VariableRowContextMenu } from '@/components/global/shared/VariableRowContextMenu'
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
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  WIZARD_DIALOG_OVERLAY_Z,
  wizardDialogContentClassName,
  wizardDropdownContentClassName,
} from '@/lib/wizard-portal-z'
import { getVariableValueError, validateVariables } from '@/lib/variables'

/** Local variable for create wizards (no server id). */
export interface EnvVariable {
  key: string
  value: string
  secret: boolean
}

export interface VariableRecord {
  $id: string
  key: string
  value: string
  secret?: boolean
}

type VarLike = { key: string; value: string; secret?: boolean }

function isWizardProps(
  props: VariablesSettingsCardProps,
): props is VariablesSettingsCardWizardProps {
  return props.variant === 'wizard'
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

function variablesToEnv(vars: VarLike[]): string {
  return vars
    .filter((v) => !v.secret)
    .map((v) => `${v.key}=${v.value}`)
    .join('\n')
}

function variablesToJson(vars: VarLike[]): string {
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

function RevealableValueInput({
  id,
  value,
  onChange,
  placeholder,
  autoComplete,
}: {
  id: string
  value: string
  onChange: (value: string) => void
  placeholder?: string
  autoComplete?: string
}) {
  const t = useT()
  const [revealed, setRevealed] = useState(false)

  useEffect(() => {
    setRevealed(false)
  }, [id])

  return (
    <div className="relative">
      <Input
        id={id}
        type={revealed ? 'text' : 'password'}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        autoComplete={autoComplete}
        className="font-mono text-[13px] pe-10"
      />
      <button
        type="button"
        onClick={() => setRevealed((current) => !current)}
        className="absolute end-2 top-1/2 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        aria-label={revealed ? t('Hide value') : t('Show value')}
        title={revealed ? t('Hide value') : t('Show value')}
      >
        {revealed ? (
          <EyeOff className="h-4 w-4" />
        ) : (
          <Eye className="h-4 w-4" />
        )}
      </button>
    </div>
  )
}

type VariablesCardSharedProps = {
  title?: string
  emptyTitle?: string
  emptyDescription?: string
  className?: string
}

export type VariablesSettingsCardSettingsProps = VariablesCardSharedProps & {
  variant?: 'settings'
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
  /** When set with pagination, enables per-page size options (10 / 25 / 50 / 100). */
  onPageSizeChange?: (limit: number) => void
  itemLabel?: string
  isVariableEditable?: (v: VariableRecord) => boolean
  getVariableBadge?: (v: VariableRecord) => string | undefined
  /** When set, rows whose key exists in this set show a warning (e.g. same name as a project variable). */
  projectVariableKeysForWarning?: Set<string>
  /** Tooltip for the duplicate project key warning; defaults to a message using `scopeLabel`. */
  duplicateProjectKeyTooltip?: string
  /**
   * When set (function/site variables), show a left-column shortcut to project-level variables.
   * Omit on the project variables page itself.
   */
  projectVariablesProjectId?: string | null
}

export type VariablesSettingsCardWizardProps = VariablesCardSharedProps & {
  variant: 'wizard'
  variables: EnvVariable[]
  onChange: (variables: EnvVariable[]) => void
  disabled?: boolean
}

export type VariablesSettingsCardProps =
  | VariablesSettingsCardSettingsProps
  | VariablesSettingsCardWizardProps

export function VariablesSettingsCard(props: VariablesSettingsCardProps) {
  const t = useT()
  const isWizard = isWizardProps(props)
  const {
    emptyTitle = 'No environment variables yet',
    emptyDescription = 'Add a variable above or import from a .env file.',
    className,
  } = props

  const title = props.title ?? 'Environment variables'
  const description = isWizard ? undefined : props.description
  const wizardVariables = isWizard ? props.variables : []
  const onWizardChange = isWizard ? props.onChange : undefined
  const wizardDisabled = isWizard ? (props.disabled ?? false) : false

  const variables = isWizard ? [] : props.variables
  const total = isWizard ? wizardVariables.length : props.total
  const isLoading = isWizard ? false : props.isLoading
  const createMutation = isWizard ? undefined : props.createMutation
  const updateMutation = isWizard ? undefined : props.updateMutation
  const deleteMutation = isWizard ? undefined : props.deleteMutation
  const scopeLabel = isWizard ? '' : props.scopeLabel
  const page = isWizard ? 0 : (props.page ?? 0)
  const limit = isWizard ? 0 : (props.limit ?? 10)
  const onPageChange = isWizard ? undefined : props.onPageChange
  const onPageSizeChange = isWizard ? undefined : props.onPageSizeChange
  const itemLabel = isWizard ? 'variables' : (props.itemLabel ?? 'variables')
  const isVariableEditable = isWizard
    ? () => true
    : (props.isVariableEditable ?? (() => true))
  const getVariableBadge = isWizard ? undefined : props.getVariableBadge
  const projectVariableKeysForWarning = isWizard
    ? undefined
    : props.projectVariableKeysForWarning
  const duplicateProjectKeyTooltip = isWizard
    ? undefined
    : props.duplicateProjectKeyTooltip
  const projectVariablesProjectId = isWizard
    ? undefined
    : props.projectVariablesProjectId

  const actionsDisabled = isWizard ? wizardDisabled : isLoading
  const dialogContentClass = (extra?: string) =>
    isWizard ? wizardDialogContentClassName(extra) : cn(extra)
  const dialogOverlayClass = isWizard ? WIZARD_DIALOG_OVERLAY_Z : undefined
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
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importSecret, setImportSecret] = useState(false)
  const [importError, setImportError] = useState('')
  const [editorContent, setEditorContent] = useState('')
  const [editorFormat, setEditorFormat] = useState<'env' | 'json'>('env')
  const [editorError, setEditorError] = useState('')
  const [deleteError, setDeleteError] = useState('')
  const [showSecrets, setShowSecrets] = useState<Set<string>>(new Set())
  const fileInputRef = useRef<HTMLInputElement>(null)
  const createKeyRefs = useRef<Map<number, HTMLInputElement>>(new Map())

  const focusCreateKeyInput = (index: number) => {
    requestAnimationFrame(() => {
      createKeyRefs.current.get(index)?.focus()
    })
  }

  const handleAddCreatePair = () => {
    const newIndex = createPairs.length
    setCreatePairs([...createPairs, { key: '', value: '' }])
    requestAnimationFrame(() => {
      requestAnimationFrame(() => focusCreateKeyInput(newIndex))
    })
  }

  const currentPage = page + 1
  const hasPagination = limit > 0 && !!onPageChange

  const defaultDuplicateProjectKeyTooltip = `This key is also set on the project. The value in this row overwrites the project default for this ${scopeLabel.toLowerCase()}-only the value here is used in this context.`

  const envToObject = (content: string) => parseEnvFile(content)
  const jsonToObject = (content: string) => {
    try {
      return JSON.parse(content)
    } catch {
      throw new Error(t('Invalid JSON format'))
    }
  }

  useEffect(() => {
    if (!showEditorModal || isWizard) return
    if (variables.length > 0) {
      const editableVars = variables.filter((v) => !v.secret)
      setEditorContent(
        editorFormat === 'env'
          ? variablesToEnv(editableVars)
          : variablesToJson(editableVars),
      )
    } else {
      setEditorContent(editorFormat === 'env' ? '' : '{}')
    }
  }, [showEditorModal, editorFormat, variables, isWizard])

  const handleWizardCreate = () => {
    if (!onWizardChange) return
    for (const pair of createPairs) {
      if (!pair.key.trim()) {
        toast.error(t('All variable keys are required'))
        return
      }
      if (wizardVariables.some((v) => v.key === pair.key.trim())) {
        toast.error(
          `${t('Variable')} ${pair.key.trim()} ${t('already exists')}`,
        )
        return
      }
    }

    const validationError = validateVariables(
      createPairs.map((pair) => ({ key: pair.key.trim(), value: pair.value })),
    )
    if (validationError) {
      toast.error(validationError)
      return
    }
    const newVars = createPairs
      .filter((p) => p.key.trim())
      .map((pair) => ({
        key: pair.key.trim(),
        value: pair.value,
        secret: createSecret,
      }))
    if (newVars.length > 0) {
      onWizardChange([...wizardVariables, ...newVars])
    }
    setShowCreateModal(false)
    setCreatePairs([{ key: '', value: '' }])
    setCreateSecret(false)
  }

  const handleWizardRemove = (index: number) => {
    onWizardChange?.(wizardVariables.filter((_, i) => i !== index))
  }

  const handleWizardToggleSecret = (key: string) => {
    const variable = wizardVariables.find((v) => v.key === key)
    if (!variable || !onWizardChange) return
    onWizardChange(
      wizardVariables.map((v) =>
        v.key === key ? { ...v, secret: !v.secret } : v,
      ),
    )
  }

  const handleWizardToggleShowSecret = (key: string) => {
    setShowSecrets((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const handleWizardFileImport = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file || !onWizardChange) return
    const reader = new FileReader()
    reader.onload = (event) => {
      const content = (event.target?.result as string) || ''
      const parsed = parseEnvFile(content)
      const newVars: EnvVariable[] = []
      for (const [key, value] of Object.entries(parsed)) {
        if (
          !wizardVariables.some((v) => v.key === key) &&
          !newVars.some((v) => v.key === key)
        ) {
          newVars.push({ key, value, secret: false })
        }
      }
      const validationError = validateVariables(newVars)
      if (validationError) {
        toast.error(validationError)
        return
      }
      if (newVars.length > 0) {
        onWizardChange([...wizardVariables, ...newVars])
      }
    }
    reader.readAsText(file)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const handleWizardEditorSave = () => {
    if (!onWizardChange) return
    setEditorError('')
    try {
      const parsed: Record<string, string> =
        editorFormat === 'env'
          ? envToObject(editorContent)
          : jsonToObject(editorContent)
      const secretVars = wizardVariables.filter((v) => v.secret)
      const newVars: EnvVariable[] = Object.entries(parsed).map(
        ([key, value]) => ({ key, value, secret: false }),
      )
      const validationError = validateVariables(newVars)
      if (validationError) {
        setEditorError(validationError)
        return
      }
      onWizardChange([...secretVars, ...newVars])
      setShowEditorModal(false)
    } catch (error: unknown) {
      setEditorError(getErrorMessage(error, t('Invalid format')))
    }
  }

  const handleOpenEditor = () => {
    const list = isWizard ? wizardVariables : variables
    const nonSecret = list.filter((v) => !v.secret)
    setEditorContent(
      editorFormat === 'env'
        ? variablesToEnv(nonSecret)
        : variablesToJson(nonSecret),
    )
    setEditorError('')
    setShowEditorModal(true)
  }

  const handleCreate = async () => {
    if (isWizard) {
      handleWizardCreate()
      return
    }
    if (!createMutation) return
    for (const pair of createPairs) {
      if (!pair.key.trim()) {
        toast.error(t('All variable keys are required'))
        return
      }
    }

    const validationError = validateVariables(
      createPairs.map((pair) => ({ key: pair.key.trim(), value: pair.value })),
    )
    if (validationError) {
      toast.error(validationError)
      return
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
      toast.success(t(`${scopeLabel} variable has been created.`))
      setShowCreateModal(false)
      setCreatePairs([{ key: '', value: '' }])
      setCreateSecret(false)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, t('Failed to create variable')))
    }
  }

  const handleUpdate = async () => {
    if (isWizard || !updateMutation || !selectedVar) return
    if (updateValue.length > 8192) {
      toast.error(t('Variable value is longer than 8192 allowed characters'))
      return
    }

    try {
      await updateMutation.mutateAsync({
        variableId: selectedVar.$id,
        key: selectedVar.key,
        value: updateValue,
        secret: selectedVar.secret || false,
      })
      toast.success(t(`${scopeLabel} variable has been updated.`))
      setShowUpdateModal(false)
      setSelectedVar(null)
      setUpdateValue('')
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, t('Failed to update variable')))
    }
  }

  const handleDelete = async () => {
    if (isWizard || !deleteMutation || !selectedVar) return
    setDeleteError('')
    closeDialogBeforeOverlayUnmount(() => {
      setShowDeleteModal(false)
      setSelectedVar(null)
    })
    try {
      await deleteMutation.mutateAsync(selectedVar.$id)
      toast.success(t(`${scopeLabel} variable has been deleted.`))
    } catch (error: unknown) {
      setDeleteError(getErrorMessage(error, t('Failed to delete variable')))
    }
  }

  const handleMarkSecret = async () => {
    if (isWizard || !updateMutation || !selectedVar) return
    try {
      await updateMutation.mutateAsync({
        variableId: selectedVar.$id,
        key: selectedVar.key,
        value: selectedVar.value || '',
        secret: true,
      })
      toast.success(t(`${scopeLabel} variable has been marked as secret.`))
      setShowSecretModal(false)
      setSelectedVar(null)
    } catch (error: unknown) {
      toast.error(
        getErrorMessage(error, t('Failed to mark variable as secret')),
      )
    }
  }

  const handleImport = async () => {
    if (isWizard) return
    if (!createMutation || !updateMutation) return
    if (!importFile) {
      setImportError(t('No file selected'))
      return
    }
    setImportError('')
    try {
      const text = await importFile.text()
      const parsed = parseEnvFile(text)
      if (Object.keys(parsed).length === 0) {
        setImportError(t('No variables found'))
        return
      }
      const existingKeys = new Set(variables.map((v) => v.key))
      const entries = Object.entries(parsed)

      // Only the keys being created are format-checked. An existing key is
      // sent back unchanged, so a variable stored before the identifier rule
      // has to stay updatable.
      const keyError = validateVariables(
        entries
          .filter(([key]) => !existingKeys.has(key))
          .map(([key, value]) => ({ key, value })),
      )
      if (keyError) {
        setImportError(keyError)
        return
      }
      for (const [key, value] of entries) {
        const valueError = getVariableValueError(key, value)
        if (valueError) {
          setImportError(valueError)
          return
        }
      }

      const promises: Promise<unknown>[] = []

      for (const [key, value] of Object.entries(parsed)) {
        if (existingKeys.has(key)) {
          const existing = variables.find((v) => v.key === key)
          if (existing?.$id) {
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
      toast.success(t('Variables have been uploaded.'))
      setShowImportModal(false)
      setImportFile(null)
      setImportSecret(false)
    } catch (error: unknown) {
      setImportError(getErrorMessage(error, t('Failed to import variables')))
    }
  }

  const handleEditorSave = async () => {
    if (isWizard) {
      handleWizardEditorSave()
      return
    }
    if (!createMutation || !updateMutation || !deleteMutation) return
    setEditorError('')
    try {
      const parsed: Record<string, string> =
        editorFormat === 'env'
          ? envToObject(editorContent)
          : jsonToObject(editorContent)

      const editableVars = variables.filter((v) => !v.secret)
      const secretKeys = new Set(
        variables.filter((v) => v.secret).map((v) => v.key),
      )

      // Only the keys being created are format-checked. An existing key is
      // sent back unchanged, so a variable stored before the identifier rule
      // has to stay updatable.
      const keyError = validateVariables(
        Object.entries(parsed)
          .filter(
            ([key]) =>
              !editableVars.some((v) => v.key === key) && !secretKeys.has(key),
          )
          .map(([key, value]) => ({ key, value })),
      )
      if (keyError) {
        setEditorError(keyError)
        return
      }
      for (const [key, value] of Object.entries(parsed)) {
        const valueError = getVariableValueError(key, value)
        if (valueError) {
          setEditorError(valueError)
          return
        }
      }

      const updatePromises: Promise<unknown>[] = []
      const deletePromises: Promise<unknown>[] = []

      for (const variable of editableVars) {
        if (!variable.$id) continue
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
      toast.success(t('Variables have been updated.'))
      setShowEditorModal(false)
      setEditorContent('')
    } catch (error: unknown) {
      setEditorError(getErrorMessage(error, t('Failed to save variables')))
    }
  }

  const handleDownload = () => {
    const editableVars = (isWizard ? wizardVariables : variables).filter(
      (v) => !v.secret,
    )
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
      toast.success(t('Copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy to clipboard'))
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
      const editableVars = (isWizard ? wizardVariables : variables).filter(
        (v) => !v.secret,
      )
      setEditorContent(
        format === 'env'
          ? variablesToEnv(editableVars)
          : variablesToJson(editableVars),
      )
      setEditorFormat(format)
    }
  }

  const isEmpty = isWizard ? wizardVariables.length === 0 : total === 0
  const listVariables = isWizard ? wizardVariables : variables

  useEffect(() => {
    if (!showCreateModal) {
      setCreatePairs([{ key: '', value: '' }])
      setCreateSecret(false)
      createKeyRefs.current.clear()
    }
  }, [showCreateModal])

  useEffect(() => {
    if (!showUpdateModal) {
      setUpdateValue('')
    } else if (selectedVar) {
      setUpdateValue(selectedVar.secret ? '' : selectedVar.value || '')
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
      <div
        className={cn(
          'rounded-xl border border-border bg-card/50 overflow-hidden',
          className,
        )}
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t(title)}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className={cn('px-6 py-4', !isWizard && '@container')}>
          <div
            className={cn(!isWizard && 'flex gap-6 @[600px]:flex-row flex-col')}
          >
            {description || projectVariablesProjectId ? (
              <div className="@[600px]:w-64 shrink-0 space-y-4">
                {description ? (
                  <p className="text-[13px] text-muted-foreground">
                    {t(description)}
                  </p>
                ) : null}
                {projectVariablesProjectId ? (
                  <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="h-9 w-full text-[13px]"
                  >
                    <Link
                      to="/projects/$projectId/settings/variables"
                      params={{ projectId: projectVariablesProjectId }}
                    >
                      {t('Manage project variables')}
                    </Link>
                  </Button>
                ) : null}
              </div>
            ) : null}
            <div className="flex-1 min-w-0">
              <div className="flex items-center justify-between gap-2 mb-4">
                <div className="flex items-center gap-2">
                  {isWizard ? (
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept=".env,.txt"
                      onChange={handleWizardFileImport}
                      className="hidden"
                    />
                  ) : null}
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={handleOpenEditor}
                    disabled={actionsDisabled}
                  >
                    <Code className="me-1.5 h-4 w-4" />
                    {t('Editor')}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() =>
                      isWizard
                        ? fileInputRef.current?.click()
                        : setShowImportModal(true)
                    }
                    disabled={actionsDisabled}
                  >
                    <Upload className="me-1.5 h-4 w-4" />
                    {t('Import .env')}
                  </Button>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setShowCreateModal(true)}
                  disabled={actionsDisabled}
                >
                  <Plus className="me-1.5 h-4 w-4" />
                  {t('Create variable')}
                </Button>
              </div>

              {!isWizard && isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                </div>
              ) : isEmpty ? (
                <EmptyState
                  icon={Key}
                  title={emptyTitle}
                  description={emptyDescription}
                  isEmpty={true}
                  variant="card"
                />
              ) : (
                <>
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[200px] max-w-[400px]">
                          {t('Key')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[200px] max-w-[400px]">
                          {t('Value')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]" />
                      </TableRow>
                    </TableHeader>
                      <TableBody>
                        {listVariables.map((variable, index) => {
                          if (isWizard) {
                            const w = variable as EnvVariable
                            return (
                              <VariableRowContextMenu
                                key={`${w.key}-${index}`}
                                variant="wizard"
                                variable={w}
                                onToggleSecret={() => handleWizardToggleSecret(w.key)}
                                onDelete={() => handleWizardRemove(index)}
                              >
                              <TableRow
                                className="border-b border-border/50"
                              >
                                <TableCell className="px-4 py-3">
                                  <code className="text-[12px] font-mono">
                                    {w.key}
                                  </code>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {w.secret ? (
                                    <div className="flex items-center gap-2">
                                      {showSecrets.has(w.key) ? (
                                        <>
                                          <code className="text-[12px] font-mono text-muted-foreground">
                                            {w.value || t('(empty)')}
                                          </code>
                                          <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                              handleWizardToggleShowSecret(
                                                w.key,
                                              )
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
                                            {t('Secret')}
                                          </Badge>
                                          <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                              handleWizardToggleShowSecret(
                                                w.key,
                                              )
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
                                      {w.value || t('(empty)')}
                                    </code>
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {!wizardDisabled && (
                                    <DropdownMenu>
                                      <DropdownMenuTrigger asChild>
                                        <RowActionsMenuTrigger
                                          compact
                                          onClick={(e) => e.stopPropagation()}
                                        />
                                      </DropdownMenuTrigger>
                                      <DropdownMenuContent
                                        align="end"
                                        className={wizardDropdownContentClassName()}
                                      >
                                        <DropdownMenuItem
                                          onClick={() =>
                                            handleWizardToggleSecret(w.key)
                                          }
                                        >
                                          <MenuItemContent
                                            icon={w.secret ? Eye : Lock}
                                          >
                                            {w.secret
                                              ? t('Unmark secret')
                                              : t('Secret')}
                                          </MenuItemContent>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                          onClick={() =>
                                            handleWizardRemove(index)
                                          }
                                        >
                                          <MenuItemContent icon={Trash2}>
                                            {t('Delete')}
                                          </MenuItemContent>
                                        </DropdownMenuItem>
                                      </DropdownMenuContent>
                                    </DropdownMenu>
                                  )}
                                </TableCell>
                              </TableRow>
                              </VariableRowContextMenu>
                            )
                          }

                          const record = variable as VariableRecord
                          const editable = isVariableEditable(record)
                          const badge = getVariableBadge?.(record)
                          const showProjectKeyWarning =
                            projectVariableKeysForWarning?.has(record.key) ??
                            false
                          return (
                            <VariableRowContextMenu
                              key={record.$id}
                              variant="settings"
                              variable={record}
                              onUpdate={() => {
                                openDialogAfterOverlayCloses(() => {
                                  setSelectedVar(record)
                                  setShowUpdateModal(true)
                                })
                              }}
                              onMarkSecret={
                                !record.secret
                                  ? () => {
                                      openDialogAfterOverlayCloses(() => {
                                        setSelectedVar(record)
                                        setShowSecretModal(true)
                                      })
                                    }
                                  : undefined
                              }
                              onDelete={() => {
                                openDialogAfterOverlayCloses(() => {
                                  setSelectedVar(record)
                                  setShowDeleteModal(true)
                                })
                              }}
                            >
                            <TableRow>
                              <TableCell className="px-4 py-3">
                                <div className="flex items-center gap-2 min-w-0">
                                  <CopyableText value={record.key} />
                                  {showProjectKeyWarning && (
                                    <Tooltip>
                                      <TooltipTrigger asChild>
                                        <button
                                          type="button"
                                          className="inline-flex shrink-0 text-amber-600 hover:text-amber-700 cursor-help rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                          aria-label={t(
                                            'Same name as a project variable',
                                          )}
                                        >
                                          <AlertTriangle className="h-3.5 w-3.5" />
                                        </button>
                                      </TooltipTrigger>
                                      <TooltipContent
                                        side="top"
                                        className="max-w-[280px] text-[12px]"
                                      >
                                        {t(
                                          duplicateProjectKeyTooltip ??
                                            defaultDuplicateProjectKeyTooltip,
                                        )}
                                      </TooltipContent>
                                    </Tooltip>
                                  )}
                                  {badge && (
                                    <Badge
                                      variant="secondary"
                                      className="text-[11px]"
                                    >
                                      {t(badge)}
                                    </Badge>
                                  )}
                                </div>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                {record.secret ? (
                                  <Badge
                                    variant="secondary"
                                    className="text-[12px]"
                                  >
                                    {t('Secret')}
                                  </Badge>
                                ) : (
                                  <CopyableText
                                    value={record.value || ''}
                                    hideValue={true}
                                  />
                                )}
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                {editable && (
                                  <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                      <RowActionsMenuTrigger
                                        compact
                                        onClick={(e) => e.stopPropagation()}
                                      />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                      <DropdownMenuItem
                                        onSelect={() => {
                                          openDialogAfterOverlayCloses(() => {
                                            setSelectedVar(record)
                                            setShowUpdateModal(true)
                                          })
                                        }}
                                      >
                                        <MenuItemContent icon={Pencil}>
                                          {t('Update')}
                                        </MenuItemContent>
                                      </DropdownMenuItem>
                                      {!record.secret && (
                                        <DropdownMenuItem
                                          onSelect={() => {
                                            openDialogAfterOverlayCloses(() => {
                                              setSelectedVar(record)
                                              setShowSecretModal(true)
                                            })
                                          }}
                                        >
                                          <MenuItemContent icon={Lock}>
                                            {t('Secret')}
                                          </MenuItemContent>
                                        </DropdownMenuItem>
                                      )}
                                      <DropdownMenuItem
                                        onSelect={() => {
                                          openDialogAfterOverlayCloses(() => {
                                            setSelectedVar(record)
                                            setShowDeleteModal(true)
                                          })
                                        }}
                                      >
                                        <MenuItemContent icon={Trash2}>
                                          {t('Delete')}
                                        </MenuItemContent>
                                      </DropdownMenuItem>
                                    </DropdownMenuContent>
                                  </DropdownMenu>
                                )}
                              </TableCell>
                            </TableRow>
                            </VariableRowContextMenu>
                          )
                        })}
                      </TableBody>
                    </Table>

                  {!isWizard && hasPagination && (
                    <div className="mt-4">
                      <Pagination
                        currentPage={currentPage}
                        totalItems={total}
                        pageSize={limit}
                        pageSizeOptions={[10, 25, 50, 100]}
                        onPageChange={(newPage) => onPageChange!(newPage - 1)}
                        onPageSizeChange={onPageSizeChange ?? (() => {})}
                        showPageSizeSelector={!!onPageSizeChange}
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
        <DialogContent
          className={dialogContentClass('sm:max-w-2xl p-0')}
          overlayClassName={dialogOverlayClass}
          onOpenAutoFocus={(e) => {
            e.preventDefault()
            focusCreateKeyInput(0)
          }}
        >
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Create variable')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Add one or more environment variables. You can add multiple variables at once.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0 max-h-[60dvh] overflow-y-auto">
            <div className="space-y-4">
              {createPairs.map((pair, index) => (
                <div
                  key={index}
                  className="space-y-3 p-4 border border-border rounded-lg"
                >
                  <div className="flex items-center justify-between">
                    <Label className="text-[13px] font-medium">
                      {t('Variable')} {index + 1}
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
                      {t('Key')} <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id={`key-${index}`}
                      ref={(el) => {
                        if (el) createKeyRefs.current.set(index, el)
                        else createKeyRefs.current.delete(index)
                      }}
                      value={pair.key}
                      onChange={(e) => {
                        const newPairs = [...createPairs]
                        newPairs[index].key = e.target.value
                        setCreatePairs(newPairs)
                      }}
                      placeholder="ENTER_KEY"
                      autoComplete="off"
                      className="font-mono text-[13px]"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor={`value-${index}`} className="text-[12px]">
                      {t('Value')} <span className="text-destructive">*</span>
                    </Label>
                    <RevealableValueInput
                      id={`value-${index}`}
                      value={pair.value}
                      onChange={(nextValue) => {
                        const newPairs = [...createPairs]
                        newPairs[index].value = nextValue
                        setCreatePairs(newPairs)
                      }}
                      placeholder={t('Enter value')}
                    />
                  </div>
                </div>
              ))}

              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleAddCreatePair}
                disabled={!createPairs[createPairs.length - 1]?.key}
              >
                <Plus className="me-1.5 h-4 w-4" />
                {t('Add variable')}
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
                  {t('Secret')}
                </Label>
              </div>
              <p className="text-[12px] text-muted-foreground -mt-2">
                {t(
                  "If selected, you and your team won't be able to read the values after creation.",
                )}
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowCreateModal(false)}
              disabled={createMutation?.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleCreate}
              disabled={
                createMutation?.isPending ||
                createPairs.some((p) => !p.key.trim()) ||
                createPairs.some((p) => p.value.length > 8192)
              }
            >
              {t('Create')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Update Variable Modal (settings only) */}
      {!isWizard && (
      <Dialog
        open={showUpdateModal}
        onOpenChange={(open) => {
          setShowUpdateModal(open)
          if (!open) setSelectedVar(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Update variable')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Update the value of this variable. The key cannot be changed.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="update-key" className="text-[13px]">
                  {t('Key')}
                </Label>
                <Input
                  id="update-key"
                  value={selectedVar?.key || ''}
                  disabled
                  className="font-mono text-[13px] bg-muted"
                />
              </div>
              <div className="space-y-2">
                <Label
                  htmlFor={`update-value-${selectedVar?.$id ?? 'new'}`}
                  className="text-[13px]"
                >
                  {t('Value')} <span className="text-destructive">*</span>
                </Label>
                <RevealableValueInput
                  id={`update-value-${selectedVar?.$id ?? 'new'}`}
                  value={updateValue}
                  onChange={setUpdateValue}
                  placeholder={t('Enter value')}
                  autoComplete="off"
                />
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                setShowUpdateModal(false)
                setSelectedVar(null)
              }}
              disabled={updateMutation?.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleUpdate}
              disabled={
                updateMutation?.isPending ||
                !updateValue.trim() ||
                updateValue.length > 8192
              }
            >
              {t('Update')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      )}

      {/* Delete Variable Modal (settings only) */}
      {!isWizard && (
      <Dialog open={showDeleteModal} onOpenChange={setShowDeleteModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete variable')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to delete this variable? This action cannot be undone.',
              )}
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
              disabled={deleteMutation?.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation?.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      )}

      {/* Mark as Secret Modal (settings only) */}
      {!isWizard && (
      <Dialog open={showSecretModal} onOpenChange={setShowSecretModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Mark as secret')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                "Once marked as secret, you and your team won't be able to read this variable's value. This action cannot be undone.",
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowSecretModal(false)}
              disabled={updateMutation?.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleMarkSecret}
              disabled={updateMutation?.isPending}
            >
              {t('Mark as secret')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      )}

      {/* Import Modal (settings only) */}
      {!isWizard && (
      <Dialog open={showImportModal} onOpenChange={setShowImportModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Import .env file')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Upload a .env file to import variables. Existing variables with the same key will be updated.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="import-file" className="text-[13px]">
                  {t('File')}
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
                  {t('Mark all as secret')}
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
              disabled={
                createMutation?.isPending || updateMutation?.isPending
              }
            >
              {t('Cancel')}
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleImport}
              disabled={
                !importFile ||
                createMutation?.isPending ||
                updateMutation?.isPending
              }
            >
              {t('Import')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      )}

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
        elevatedForWizard={isWizard}
        isSaving={
          !isWizard &&
          !!(
            createMutation?.isPending ||
            updateMutation?.isPending ||
            deleteMutation?.isPending
          )
        }
      />
    </>
  )
}
