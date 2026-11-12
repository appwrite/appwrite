import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { toast } from 'sonner'
import { X, Plus } from 'lucide-react'
import { Calendar, Hash } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { IndexTypeSelect } from '../_components/IndexTypeSelect'
import type { CollectionIndexType } from '@/lib/databases/collection-index-types'

export type IndexType = CollectionIndexType

interface IndexColumnEntry {
  column: string
  order: 'ASC' | 'DESC' | null
  length: number | null
}

export interface IndexFormData {
  key: string
  type: IndexType
  columns: IndexColumnEntry[]
}

interface IndexDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (data: IndexFormData) => Promise<void>
  index?: unknown // Existing index for edit mode
  availableColumns?: Array<{
    key: string
    type: string
    required?: boolean
    array?: boolean
  }>
  existingIndexes?: Array<{ key: string }>
  isLoading?: boolean
}

const ORDER_OPTIONS = [
  { value: 'ASC', label: 'ASC' },
  { value: 'DESC', label: 'DESC' },
]

const ORDER_OPTIONS_WITH_NONE = [
  { value: 'ASC', label: 'ASC' },
  { value: 'DESC', label: 'DESC' },
  { value: 'NONE', label: 'NONE' },
]

// System fields that can be indexed
const SYSTEM_FIELDS = [
  { key: '$id', type: 'string', label: '$id', icon: Hash },
  { key: '$createdAt', type: 'datetime', label: '$createdAt', icon: Calendar },
  { key: '$updatedAt', type: 'datetime', label: '$updatedAt', icon: Calendar },
]

export function IndexDrawer({
  open,
  onOpenChange,
  onSubmit,
  index,
  availableColumns = [],
  existingIndexes = [],
  isLoading = false,
}: IndexDrawerProps) {
  const t = useT()
  const isEditMode = !!index
  const [formData, setFormData] = useState<IndexFormData>({
    key: '',
    type: 'key',
    columns: [{ column: '', order: 'ASC', length: null }],
  })

  const [errors, setErrors] = useState<Record<string, string>>({})
  const [suggestedKey, setSuggestedKey] = useState('')

  // Get filtered columns based on index type
  const filteredColumns = useMemo(() => {
    if (formData.type === 'spatial') {
      // Only spatial columns (non-array)
      return availableColumns.filter(
        (col) =>
          ['point', 'linestring', 'polygon'].includes(col.type) && !col.array,
      )
    } else {
      // All non-relationship, non-spatial, non-array columns + system fields
      const regularColumns = availableColumns.filter(
        (col) =>
          col.type !== 'relationship' &&
          !['point', 'linestring', 'polygon'].includes(col.type) &&
          !col.array, // Exclude array columns - not supported for indexes
      )
      return [
        ...SYSTEM_FIELDS.map((sf) => ({ ...sf, required: true, array: false })),
        ...regularColumns,
      ]
    }
  }, [formData.type, availableColumns])

  // Generate suggested key
  useEffect(() => {
    if (
      !isEditMode &&
      formData.columns.length > 0 &&
      formData.columns[0].column
    ) {
      const baseKey = formData.columns[0].column
        .replace(/^\$/, '')
        .replace(/[^a-zA-Z0-9]/g, '_')
      let counter = 1
      let suggested = `index_${baseKey}`

      // Check if key already exists
      while (existingIndexes.some((idx) => idx.key === suggested)) {
        suggested = `index_${baseKey}_${counter}`
        counter++
      }

      setSuggestedKey(suggested)
      if (!formData.key) {
        setFormData((prev) => ({ ...prev, key: suggested }))
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formData.columns, existingIndexes, isEditMode])

  // Initialize form data from index
  useEffect(() => {
    if (index) {
      const columns: IndexColumnEntry[] = (index.columns || []).map(
        (col: string, idx: number) => ({
          column: col,
          order:
            (index.orders && index.orders[idx]) ||
            (formData.type === 'spatial' ? null : 'ASC'),
          length: (index.lengths && index.lengths[idx]) || null,
        }),
      )

      setFormData({
        key: index.key || '',
        type: (index.type || 'key') as IndexType,
        columns:
          columns.length > 0
            ? columns
            : [{ column: '', order: 'ASC', length: null }],
      })
    } else {
      // Reset form for create mode
      setFormData({
        key: '',
        type: 'key',
        columns: [{ column: '', order: 'ASC', length: null }],
      })
      setSuggestedKey('')
      setErrors({})
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [index, open])

  // Reset columns when type changes
  useEffect(() => {
    if (!isEditMode && open) {
      if (formData.type === 'spatial') {
        // Spatial: single column only
        setFormData((prev) => ({
          ...prev,
          columns: [{ column: '', order: null, length: null }],
        }))
      } else {
        // Other types: reset to single column with ASC order
        setFormData((prev) => ({
          ...prev,
          columns:
            prev.columns.length === 1 && prev.columns[0].column === ''
              ? prev.columns
              : [{ column: '', order: 'ASC', length: null }],
        }))
      }
    }
  }, [formData.type, isEditMode, open])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
      if (!newOpen) {
        // Reset form when closing
        setFormData({
          key: '',
          type: 'key',
          columns: [{ column: '', order: 'ASC', length: null }],
        })
        setSuggestedKey('')
        setErrors({})
      }
    }
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    // Key validation
    if (!formData.key.trim()) {
      newErrors.key = t('Key is required')
    } else if (!/^[A-Za-z0-9][A-Za-z0-9._\-]*$/.test(formData.key)) {
      newErrors.key = t('Invalid key format. Allowed: a-z, A-Z, 0-9, -, ., _')
    } else if (
      !isEditMode &&
      existingIndexes.some((idx) => idx.key === formData.key)
    ) {
      newErrors.key = t('Index with this key already exists')
    }

    // Column validation
    if (formData.type === 'spatial') {
      // Spatial: exactly one column required
      if (formData.columns.length !== 1 || !formData.columns[0].column) {
        newErrors.columns = t(
          'Spatial index requires exactly one spatial column',
        )
      } else {
        const col = filteredColumns.find(
          (c) => c.key === formData.columns[0].column,
        )
        if (!col || !['point', 'linestring', 'polygon'].includes(col.type)) {
          newErrors.columns = t(
            'Selected column must be a spatial type (point, linestring, or polygon)',
          )
        }
      }
    } else {
      // Other types: at least one column required
      if (formData.columns.length === 0) {
        newErrors.columns = t('At least one column is required')
      } else {
        const incompleteColumns = formData.columns.filter(
          (col) =>
            !col.column || (col.order === null && formData.type !== 'spatial'),
        )
        if (incompleteColumns.length > 0) {
          newErrors.columns = t(
            'All columns must have a value and order selected',
          )
        }
      }
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!validate()) {
      return
    }

    try {
      const submitData: IndexFormData = {
        key: formData.key,
        type: formData.type,
        columns: formData.columns.filter((col) => col.column), // Remove empty columns
      }

      await onSubmit(submitData)
      handleOpenChange(false)
      // Don't show toast for suggestions - parent handles it
      if (!(index as unknown)?.isSuggestion) {
        toast.success(
          isEditMode
            ? t('Index updated successfully')
            : t('Index created successfully'),
        )
      }
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to save index'),
      )
    }
  }

  const addColumn = () => {
    if (formData.type === 'spatial') {
      toast.error(t('Spatial indexes can only have one column'))
      return
    }

    const lastColumn = formData.columns[formData.columns.length - 1]
    if (!lastColumn.column || !lastColumn.order) {
      toast.error(t('Please complete the current column before adding another'))
      return
    }

    setFormData((prev) => ({
      ...prev,
      columns: [...prev.columns, { column: '', order: 'ASC', length: null }],
    }))
  }

  const removeColumn = (index: number) => {
    if (formData.columns.length === 1) {
      toast.error(t('At least one column is required'))
      return
    }
    setFormData((prev) => ({
      ...prev,
      columns: prev.columns.filter((_, i) => i !== index),
    }))
  }

  const updateColumn = (
    index: number,
    field: keyof IndexColumnEntry,
    value: unknown,
  ) => {
    setFormData((prev) => {
      const newColumns = [...prev.columns]
      newColumns[index] = { ...newColumns[index], [field]: value }
      return { ...prev, columns: newColumns }
    })
  }

  const getColumnLabel = (columnKey: string) => {
    if (columnKey.startsWith('$')) {
      const systemField = SYSTEM_FIELDS.find((sf) => sf.key === columnKey)
      if (systemField) {
        const Icon = systemField.icon
        return (
          <div className="flex items-center gap-1.5">
            <Icon className="h-3.5 w-3.5 text-muted-foreground" />
            <span>{systemField.label}</span>
          </div>
        )
      }
    }
    return columnKey
  }

  const isSpatial = formData.type === 'spatial'
  const showLength = formData.type === 'key'

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEditMode ? t('Update Index') : t('Create Index')}
      maxWidth="sm:max-w-2xl"
    >
      <>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
          <div className="flex-1 overflow-y-auto px-6 pb-4 pt-4 space-y-4">
            {/* Key */}
            <div className="space-y-2">
              <Label htmlFor="index-key" className="text-[12px] font-medium">
                {t('Index Key')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="index-key"
                value={formData.key}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, key: e.target.value }))
                }
                placeholder={t('Enter Key')}
                disabled={isLoading}
                pattern="^[A-Za-z0-9][A-Za-z0-9._\-]*$"
                className={errors.key ? 'border-destructive' : ''}
              />
              {errors.key && (
                <p className="text-[12px] text-destructive">{errors.key}</p>
              )}
              {suggestedKey && !formData.key && (
                <p className="text-[11px] text-muted-foreground">
                  {t('Suggested:')}{' '}
                  <button
                    type="button"
                    onClick={() =>
                      setFormData((prev) => ({ ...prev, key: suggestedKey }))
                    }
                    className="link-neutral"
                  >
                    {suggestedKey}
                  </button>
                </p>
              )}
              <p className="text-[11px] text-muted-foreground">
                {t('Allowed characters: a-z, A-Z, 0-9, -, ., _')}
              </p>
            </div>

            {/* Type */}
            <div className="space-y-2">
              <Label htmlFor="index-type" className="text-[12px] font-medium">
                {t('Index Type')} <span className="text-destructive">*</span>
              </Label>
              <IndexTypeSelect
                id="index-type"
                value={formData.type}
                onChange={(type) => {
                  setFormData((prev) => ({
                    ...prev,
                    type,
                    columns:
                      type === 'spatial'
                        ? [{ column: '', order: null, length: null }]
                        : [{ column: '', order: 'ASC', length: null }],
                  }))
                }}
                disabled={isLoading || isEditMode}
              />
            </div>

            {/* Columns */}
            <div className="space-y-3">
              <Label className="text-[12px] font-medium">
                Columns <span className="text-destructive">*</span>
              </Label>
              {formData.columns.map((columnEntry, index) => (
                <div
                  key={index}
                  className="flex items-start gap-2 rounded-lg border border-border p-3"
                >
                  <div className="flex-1 space-y-3">
                    <div className="space-y-2">
                      <Label className="text-[11px] text-muted-foreground">
                        {t('Column')}
                      </Label>
                      <Select
                        value={columnEntry.column}
                        onValueChange={(value) =>
                          updateColumn(index, 'column', value)
                        }
                        disabled={isLoading}
                      >
                        <SelectTrigger
                          className={errors.columns ? 'border-destructive' : ''}
                        >
                          <SelectValue placeholder={t('Select column')} />
                        </SelectTrigger>
                        <SelectContent>
                          {filteredColumns.map((col) => (
                            <SelectItem key={col.key} value={col.key}>
                              {getColumnLabel(col.key)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    {!isSpatial && (
                      <div className="space-y-2">
                        <Label className="text-[11px] text-muted-foreground">
                          {t('Order')}
                        </Label>
                        <Select
                          value={columnEntry.order || 'ASC'}
                          onValueChange={(value) => {
                            updateColumn(
                              index,
                              'order',
                              value === 'NONE' ? null : value,
                            )
                          }}
                          disabled={isLoading}
                        >
                          <SelectTrigger>
                            <SelectValue placeholder={t('Select order')} />
                          </SelectTrigger>
                          <SelectContent>
                            {ORDER_OPTIONS.map((opt) => (
                              <SelectItem key={opt.value} value={opt.value}>
                                {opt.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}

                    {isSpatial && (
                      <div className="space-y-2">
                        <Label className="text-[11px] text-muted-foreground">
                          {t('Order (Optional)')}
                        </Label>
                        <Select
                          value={columnEntry.order || 'NONE'}
                          onValueChange={(value) => {
                            updateColumn(
                              index,
                              'order',
                              value === 'NONE' ? null : value,
                            )
                          }}
                          disabled={isLoading}
                        >
                          <SelectTrigger>
                            <SelectValue placeholder={t('Select order')} />
                          </SelectTrigger>
                          <SelectContent>
                            {ORDER_OPTIONS_WITH_NONE.map((opt) => (
                              <SelectItem key={opt.value} value={opt.value}>
                                {opt.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}

                    {showLength &&
                      (() => {
                        // Only show length for string and varchar columns
                        const selectedColumn = availableColumns?.find(
                          (col) => col.key === columnEntry.column,
                        )
                        const isSizedStringColumn =
                          selectedColumn?.type === 'string' ||
                          selectedColumn?.type === 'varchar'

                        if (!isSizedStringColumn) {
                          return null
                        }

                        return (
                          <div className="space-y-2">
                            <Label className="text-[11px] text-muted-foreground">
                              {t('Length (Optional)')}
                            </Label>
                            <Input
                              type="number"
                              value={columnEntry.length ?? ''}
                              onChange={(e) => {
                                const value =
                                  e.target.value === ''
                                    ? null
                                    : parseInt(e.target.value)
                                updateColumn(index, 'length', value)
                              }}
                              placeholder={t('Max 767')}
                              max={767}
                              min={1}
                              disabled={isLoading}
                            />
                            <p className="text-[10px] text-muted-foreground">
                              {t('Only applicable to string and varchar columns')}
                            </p>
                          </div>
                        )
                      })()}
                  </div>

                  {formData.columns.length > 1 && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-9 w-9 shrink-0 mt-6"
                      onClick={() => removeColumn(index)}
                      disabled={isLoading}
                      aria-label={t('Remove column')}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              ))}

              {errors.columns && (
                <p className="text-[12px] text-destructive">{errors.columns}</p>
              )}

              {!isSpatial && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={addColumn}
                  disabled={isLoading}
                  className="w-full"
                >
                  <Plus className="h-3.5 w-3.5 me-1.5" />
                  {t('Add column')}
                </Button>
              )}
            </div>
          </div>

          <div className="flex-shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button type="submit" disabled={isLoading}>
              {isEditMode ? t('Update') : t('Create Index')}
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
