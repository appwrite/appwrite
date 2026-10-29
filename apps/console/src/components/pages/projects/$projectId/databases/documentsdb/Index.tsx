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
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { toast } from 'sonner'
import { X, Plus, type LucideIcon } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { CUSTOM_COLLECTION_INDEX_ATTRIBUTE_VALUE } from '@/lib/databases/collection-indexable-attributes'
import type { CollectionIndexType } from '@/lib/databases/collection-index-types'
import { getColumnIcon } from '@/lib/utils/column-icons'
import { IndexTypeSelect } from '../_components/IndexTypeSelect'

export type IndexType = CollectionIndexType

interface IndexAttributeEntry {
  column: string
  order: 'ASC' | 'DESC' | null
  length: number | null
}

export interface IndexFormData {
  key: string
  type: IndexType
  columns: IndexAttributeEntry[]
}

interface CollectionIndexDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (data: IndexFormData) => Promise<void>
  index?: {
    key?: string
    type?: string
    columns?: string[]
    orders?: Array<string | null>
    lengths?: Array<number | null>
  }
  availableAttributes?: Array<{
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

const SYSTEM_FIELDS = [
  { key: '$id', type: 'string', label: '$id' },
  { key: '$createdAt', type: 'datetime', label: '$createdAt' },
  { key: '$updatedAt', type: 'datetime', label: '$updatedAt' },
]

function getAttributeIcon(attr: { key: string; type: string }): LucideIcon {
  if (attr.key === '$id') return getColumnIcon('$id')
  return getColumnIcon(attr.type)
}

function isValidCustomAttributeKey(key: string): boolean {
  const trimmed = key.trim()
  if (!trimmed || trimmed.startsWith('$')) return false
  return /^[A-Za-z0-9][A-Za-z0-9._\-]*$/.test(trimmed)
}

export function IndexDrawer({
  open,
  onOpenChange,
  onSubmit,
  index,
  availableAttributes = [],
  existingIndexes = [],
  isLoading = false,
}: CollectionIndexDrawerProps) {
  const t = useT()
  const isEditMode = !!index
  const [formData, setFormData] = useState<IndexFormData>({
    key: '',
    type: 'key',
    columns: [{ column: '', order: 'ASC', length: null }],
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [suggestedKey, setSuggestedKey] = useState('')
  const [customAttributeRowIndexes, setCustomAttributeRowIndexes] = useState(
    () => new Set<number>(),
  )

  const filteredAttributes = useMemo(() => {
    if (formData.type === 'spatial') {
      return availableAttributes.filter(
        (attr) =>
          ['point', 'linestring', 'polygon'].includes(attr.type) && !attr.array,
      )
    }

    const regularAttributes = availableAttributes.filter(
      (attr) =>
        attr.type !== 'relationship' &&
        !['point', 'linestring', 'polygon'].includes(attr.type) &&
        !attr.array,
    )

    return [
      ...SYSTEM_FIELDS.map((field) => ({
        ...field,
        required: true,
        array: false,
      })),
      ...regularAttributes,
    ]
  }, [formData.type, availableAttributes])

  const presetAttributeKeys = useMemo(
    () => new Set(filteredAttributes.map((attr) => attr.key)),
    [filteredAttributes],
  )

  const isCustomAttributeRow = (rowIndex: number, attributeKey: string) =>
    customAttributeRowIndexes.has(rowIndex) ||
    (!!attributeKey && !presetAttributeKeys.has(attributeKey))

  const resetCustomAttributeRows = () => {
    setCustomAttributeRowIndexes(new Set())
  }

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

  useEffect(() => {
    if (index) {
      const columns: IndexAttributeEntry[] = (index.columns || []).map(
        (col, idx) => ({
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
      setFormData({
        key: '',
        type: 'key',
        columns: [{ column: '', order: 'ASC', length: null }],
      })
      setSuggestedKey('')
      setErrors({})
      resetCustomAttributeRows()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [index, open])

  useEffect(() => {
    if (!index) return
    const customRows = new Set<number>()
    ;(index.columns || []).forEach((col, idx) => {
      if (col && !presetAttributeKeys.has(col)) {
        customRows.add(idx)
      }
    })
    setCustomAttributeRowIndexes(customRows)
  }, [index, presetAttributeKeys])

  useEffect(() => {
    if (!isEditMode && open) {
      resetCustomAttributeRows()
      if (formData.type === 'spatial') {
        setFormData((prev) => ({
          ...prev,
          columns: [{ column: '', order: null, length: null }],
        }))
      } else {
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
        setFormData({
          key: '',
          type: 'key',
          columns: [{ column: '', order: 'ASC', length: null }],
        })
        setSuggestedKey('')
        setErrors({})
        resetCustomAttributeRows()
      }
    }
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

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

    if (formData.type === 'spatial') {
      const attributeKey = formData.columns[0]?.column?.trim() ?? ''
      if (formData.columns.length !== 1 || !attributeKey) {
        newErrors.columns = t(
          'Spatial index requires exactly one spatial column',
        )
      } else if (isCustomAttributeRow(0, attributeKey)) {
        newErrors.columns = t(
          'Selected column must be a spatial type (point, linestring, or polygon)',
        )
      } else {
        const attr = filteredAttributes.find((a) => a.key === attributeKey)
        if (!attr || !['point', 'linestring', 'polygon'].includes(attr.type)) {
          newErrors.columns = t(
            'Selected column must be a spatial type (point, linestring, or polygon)',
          )
        }
      }
    } else if (formData.columns.length === 0) {
      newErrors.columns = t('At least one attribute is required')
    } else {
      const incompleteColumns = formData.columns.filter((entry, rowIndex) => {
        const attributeKey = entry.column.trim()
        if (!attributeKey) return true
        if (isCustomAttributeRow(rowIndex, attributeKey)) {
          return !isValidCustomAttributeKey(attributeKey)
        }
        if (entry.order === null && formData.type !== 'spatial') return true
        return false
      })
      if (incompleteColumns.length > 0) {
        newErrors.columns = t(
          'All attributes must have a value and order selected',
        )
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
        columns: formData.columns
          .filter((entry) => entry.column.trim())
          .map((entry) => ({
            ...entry,
            column: entry.column.trim(),
          })),
      }

      await onSubmit(submitData)
      handleOpenChange(false)
      toast.success(
        isEditMode
          ? t('Index updated successfully')
          : t('Index created successfully'),
      )
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to save index'),
      )
    }
  }

  const addAttribute = () => {
    if (formData.type === 'spatial') {
      toast.error(t('Spatial indexes can only have one column'))
      return
    }

    const lastEntry = formData.columns[formData.columns.length - 1]
    if (!lastEntry.column.trim() || !lastEntry.order) {
      toast.error(
        t('Please complete the current attribute before adding another'),
      )
      return
    }

    setFormData((prev) => ({
      ...prev,
      columns: [...prev.columns, { column: '', order: 'ASC', length: null }],
    }))
  }

  const removeAttribute = (rowIndex: number) => {
    if (formData.columns.length === 1) {
      toast.error(t('At least one attribute is required'))
      return
    }
    setCustomAttributeRowIndexes((prev) => {
      const next = new Set<number>()
      prev.forEach((index) => {
        if (index < rowIndex) next.add(index)
        if (index > rowIndex) next.add(index - 1)
      })
      return next
    })
    setFormData((prev) => ({
      ...prev,
      columns: prev.columns.filter((_, i) => i !== rowIndex),
    }))
  }

  const updateAttribute = (
    rowIndex: number,
    field: keyof IndexAttributeEntry,
    value: unknown,
  ) => {
    setFormData((prev) => {
      const nextColumns = [...prev.columns]
      nextColumns[rowIndex] = { ...nextColumns[rowIndex], [field]: value }
      return { ...prev, columns: nextColumns }
    })
  }

  const handleAttributeSelectChange = (rowIndex: number, value: string) => {
    if (value === CUSTOM_COLLECTION_INDEX_ATTRIBUTE_VALUE) {
      setCustomAttributeRowIndexes((prev) => new Set(prev).add(rowIndex))
      updateAttribute(rowIndex, 'column', '')
      return
    }

    setCustomAttributeRowIndexes((prev) => {
      const next = new Set(prev)
      next.delete(rowIndex)
      return next
    })
    updateAttribute(rowIndex, 'column', value)
  }

  const isSpatial = formData.type === 'spatial'
  const showLength = formData.type === 'key'

  const attributeSelectItems = useMemo(
    () =>
      filteredAttributes.map((attr) => ({
        value: attr.key,
        label: attr.key,
        searchText: `${attr.key} ${attr.type}`,
        description: attr.type,
        inlineDescription: true,
        icon: getAttributeIcon(attr),
      })),
    [filteredAttributes],
  )

  const attributeSelectFooterItems = useMemo(() => {
    if (isSpatial) return undefined

    return [
      {
        value: CUSTOM_COLLECTION_INDEX_ATTRIBUTE_VALUE,
        label: t('Custom attribute'),
        searchText: t('Custom attribute'),
        icon: Plus,
      },
    ]
  }, [isSpatial, t])

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

            <div className="space-y-2">
              <Label htmlFor="index-type" className="text-[12px] font-medium">
                {t('Index Type')} <span className="text-destructive">*</span>
              </Label>
              <IndexTypeSelect
                id="index-type"
                value={formData.type}
                onChange={(type) => {
                  resetCustomAttributeRows()
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

            <div className="space-y-3">
              <Label className="text-[12px] font-medium">
                {t('Attributes')} <span className="text-destructive">*</span>
              </Label>
              {formData.columns.map((attributeEntry, rowIndex) => {
                const customRow = isCustomAttributeRow(
                  rowIndex,
                  attributeEntry.column,
                )
                const selectValue = customRow
                  ? CUSTOM_COLLECTION_INDEX_ATTRIBUTE_VALUE
                  : attributeEntry.column

                return (
                  <div
                    key={rowIndex}
                    className="flex items-start gap-2 rounded-lg border border-border p-3"
                  >
                    <div className="flex-1 space-y-3">
                      <div className="space-y-2">
                        <Label className="text-[11px] text-muted-foreground">
                          {t('Attribute')}
                        </Label>
                        <SearchableSelect
                          value={selectValue}
                          onValueChange={(value) =>
                            handleAttributeSelectChange(rowIndex, value)
                          }
                          items={attributeSelectItems}
                          footerItems={attributeSelectFooterItems}
                          placeholder={t('Select attribute')}
                          searchPlaceholder={t('Search attributes...')}
                          disabled={isLoading}
                          triggerClassName={
                            errors.columns ? 'border-destructive' : undefined
                          }
                        />
                      </div>

                      {customRow && (
                        <div className="space-y-2">
                          <Label className="text-[11px] text-muted-foreground">
                            {t('Attribute name')}
                          </Label>
                          <Input
                            value={attributeEntry.column}
                            onChange={(e) =>
                              updateAttribute(rowIndex, 'column', e.target.value)
                            }
                            placeholder={t('e.g. email, score, tags')}
                            autoComplete="off"
                            disabled={isLoading}
                          />
                          <p className="text-[11px] text-muted-foreground">
                            {t(
                              'Use preset columns for $id and other system fields. Custom names must not start with $.',
                            )}
                          </p>
                        </div>
                      )}

                      {!isSpatial && (
                        <div className="space-y-2">
                          <Label className="text-[11px] text-muted-foreground">
                            {t('Order')}
                          </Label>
                          <Select
                            value={attributeEntry.order || 'ASC'}
                            onValueChange={(value) => {
                              updateAttribute(
                                rowIndex,
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
                            value={attributeEntry.order || 'NONE'}
                            onValueChange={(value) => {
                              updateAttribute(
                                rowIndex,
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
                          const selectedAttribute = availableAttributes.find(
                            (attr) => attr.key === attributeEntry.column.trim(),
                          )
                          const isSizedStringAttribute =
                            selectedAttribute?.type === 'string' ||
                            selectedAttribute?.type === 'varchar'

                          if (!isSizedStringAttribute || customRow) {
                            return null
                          }

                          return (
                            <div className="space-y-2">
                              <Label className="text-[11px] text-muted-foreground">
                                {t('Length (Optional)')}
                              </Label>
                              <Input
                                type="number"
                                value={attributeEntry.length ?? ''}
                                onChange={(e) => {
                                  const value =
                                    e.target.value === ''
                                      ? null
                                      : parseInt(e.target.value, 10)
                                  updateAttribute(rowIndex, 'length', value)
                                }}
                                placeholder={t('Max 767')}
                                max={767}
                                min={1}
                                disabled={isLoading}
                              />
                              <p className="text-[10px] text-muted-foreground">
                                {t(
                                  'Only applicable to string and varchar columns',
                                )}
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
                        onClick={() => removeAttribute(rowIndex)}
                        disabled={isLoading}
                        aria-label={t('Remove attribute')}
                      >
                        <X className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                )
              })}

              {errors.columns && (
                <p className="text-[12px] text-destructive">{errors.columns}</p>
              )}

              {!isSpatial && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={addAttribute}
                  disabled={isLoading}
                  className="w-full"
                >
                  <Plus className="h-3.5 w-3.5 me-1.5" />
                  {t('Add attribute')}
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
