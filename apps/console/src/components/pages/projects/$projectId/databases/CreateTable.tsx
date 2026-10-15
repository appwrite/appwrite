import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { EmbeddingDimensionSelect } from '@/components/pages/projects/$projectId/databases/_components/EmbeddingDimensionSelect'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import {
  DEFAULT_EMBEDDING_DIMENSION_PRESET,
  EMBEDDING_DIMENSION_CUSTOM,
  resolveEmbeddingDimension,
  type EmbeddingDimensionPresetId,
} from '@/lib/databases/embedding-dimension-presets'
import { useT } from '@/lib/i18n/translate'

/**
 * Validates Appwrite table ID: 1–36 chars, alphanumeric, underscore, hyphen, period.
 * Must not start with a special char.
 */
function validateTableId(id: string): boolean {
  if (!id || id.length === 0) return true
  if (id.length > 36) return false
  if (!/^[a-zA-Z0-9_]/.test(id)) return false
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(id)
}

export type CreateTableVariant = 'tables' | 'documents' | 'vectors'

/** Maps URL/database product segment to the create dialog variant. */
export function createTableVariantForDbRoute(
  kind: DatabaseRouteKind,
): CreateTableVariant {
  if (kind === 'vectorsdb') return 'vectors'
  if (kind === 'documentsdb') return 'documents'
  return 'tables'
}

interface CreateTableProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (data: {
    tableId?: string
    name: string
    dimension?: number
  }) => void
  isLoading?: boolean
  variant?: CreateTableVariant
}

export function CreateTable({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
  variant = 'tables',
}: CreateTableProps) {
  const t = useT()
  const [tableId, setTableId] = useState<string | undefined>(undefined)
  const [name, setName] = useState('')
  const [embeddingPreset, setEmbeddingPreset] =
    useState<EmbeddingDimensionPresetId>(DEFAULT_EMBEDDING_DIMENSION_PRESET)
  const [customDimension, setCustomDimension] = useState<string>('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const isCollection = variant === 'documents' || variant === 'vectors'
  const resourceWord = isCollection ? 'collection' : 'table'
  const title = isCollection ? t('Create collection') : t('Create table')
  const idLabel = isCollection ? t('Collection ID') : t('Table ID')

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
      if (!newOpen) {
        resetForm()
      }
    }
  }

  const resetForm = () => {
    setTableId(undefined)
    setName('')
    setEmbeddingPreset(DEFAULT_EMBEDDING_DIMENSION_PRESET)
    setCustomDimension('')
    setErrors({})
  }

  useEffect(() => {
    if (!open) {
      resetForm()
    }
  }, [open])

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors.name = t('Name is required')
    }

    if (tableId && tableId.length > 0 && !validateTableId(tableId)) {
      newErrors.tableId = `${idLabel} ${t('must be 1–36 characters, alphanumeric, underscore, hyphen, or period. Cannot start with a special character.')}`
    }

    if (variant === 'vectors') {
      const resolvedDimension = resolveEmbeddingDimension(
        embeddingPreset,
        customDimension,
      )
      if (resolvedDimension == null) {
        newErrors.dimension = t(
          'Embedding dimension must be a positive integer',
        )
      }
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!validate()) {
      return
    }

    const payload: { tableId?: string; name: string; dimension?: number } = {
      tableId,
      name: name.trim(),
    }
    if (variant === 'vectors') {
      const resolvedDimension = resolveEmbeddingDimension(
        embeddingPreset,
        customDimension,
      )
      if (resolvedDimension != null) {
        payload.dimension = resolvedDimension
      }
    }

    onCreate(payload)
  }

  const description =
    variant === 'vectors'
      ? t('Create a collection with a fixed embedding dimension for vector similarity search.')
      : variant === 'documents'
        ? t('Create a collection to store JSON documents with flexible schemas.')
        : t('Create a new table to store structured data with columns and rows.')

  const namePlaceholder = isCollection
    ? t('Enter collection name')
    : t('Enter table name')

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {description}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">
                {t('Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                type="text"
                placeholder={namePlaceholder}
                value={name}
                onChange={(e) => {
                  setName(e.target.value)
                  if (errors.name) {
                    setErrors((prev) => ({ ...prev, name: '' }))
                  }
                }}
                disabled={isLoading}
                className={errors.name ? 'border-destructive' : ''}
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>

            {variant === 'vectors' && (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="embedding-model">
                    {t('Embedding model')}{' '}
                    <span className="text-destructive">*</span>
                  </Label>
                  <EmbeddingDimensionSelect
                    id="embedding-model"
                    value={embeddingPreset}
                    onChange={(nextPreset) => {
                      setEmbeddingPreset(nextPreset)
                      if (errors.dimension) {
                        setErrors((prev) => ({ ...prev, dimension: '' }))
                      }
                    }}
                    disabled={isLoading}
                  />
                </div>
                {embeddingPreset === EMBEDDING_DIMENSION_CUSTOM && (
                  <div className="space-y-2">
                    <Label htmlFor="custom-dimension">
                      {t('Embedding dimension')}{' '}
                      <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="custom-dimension"
                      type="number"
                      min={1}
                      step={1}
                      placeholder={t('Enter embedding dimension')}
                      value={customDimension}
                      onChange={(e) => {
                        setCustomDimension(e.target.value)
                        if (errors.dimension) {
                          setErrors((prev) => ({ ...prev, dimension: '' }))
                        }
                      }}
                      disabled={isLoading}
                      className={errors.dimension ? 'border-destructive' : ''}
                    />
                  </div>
                )}
                {errors.dimension && (
                  <p className="text-[12px] text-destructive">
                    {errors.dimension}
                  </p>
                )}
              </div>
            )}

            <div className="space-y-2">
              <Label htmlFor="table-id">{idLabel}</Label>
              <IdInput
                id="table-id"
                value={tableId}
                onChange={setTableId}
                maxLength={36}
                disabled={isLoading}
                placeholder={t('Leave blank to auto-generate')}
              />
              {errors.tableId && (
                <p className="text-[12px] text-destructive">{errors.tableId}</p>
              )}
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || !name.trim()}>
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
