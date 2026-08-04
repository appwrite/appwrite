import { useState, useEffect, useRef } from 'react'
import { Button } from '@/components/ui/button'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { toast } from 'sonner'
import { ArrowLeftRight, ArrowRight, Info, X, Plus } from 'lucide-react'
import { PointEditor, LineEditor, PolygonEditor } from './spatial/index'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Textarea } from '@/components/ui/textarea'
import { Progress } from '@/components/ui/progress'
import {
  formatInt64Bound,
  INT64_MAX,
  INT64_MIN,
  isValidInt64,
  parseInt64Value,
} from '@/lib/utils/database-columns'
import { useT } from '@/lib/i18n/translate'
import { toByteCount } from '@/lib/utils/byte-display-unit'

export type ColumnType =
  | 'text'
  | 'mediumtext'
  | 'longtext'
  | 'varchar'
  | 'integer'
  | 'bigint'
  | 'double'
  | 'boolean'
  | 'datetime'
  | 'email'
  | 'ip'
  | 'url'
  | 'enum'
  | 'relationship'
  | 'point'
  | 'linestring'
  | 'polygon'
  | 'string' // deprecated

export interface ColumnFormData {
  key: string
  type: ColumnType
  // String
  size?: number
  encrypt?: boolean
  // Integer/Float/Bigint
  min?: number | bigint
  max?: number | bigint
  // Enum
  elements?: string[]
  // Relationship
  relatedTableId?: string
  relationshipType?: 'oneToOne' | 'oneToMany' | 'manyToOne' | 'manyToMany'
  twoWay?: boolean
  twoWayKey?: string
  onDelete?: 'setNull' | 'cascade' | 'restrict'
  // Common
  required?: boolean
  array?: boolean
  xdefault?:
  | string
  | number
  | bigint
  | boolean
  | [number, number]
  | number[][]
  | number[][][]
  | null
}

const VARCHAR_SIZE_MIN = 1
const VARCHAR_SIZE_MAX = 16_383

/** Text-like column types that support encryption in the API */
const TEXT_TYPES_WITH_ENCRYPT: ColumnType[] = [
  'string',
  'text',
  'mediumtext',
  'longtext',
  'varchar',
]

const ENCRYPT_DESCRIPTION =
  'Values are encrypted at rest (AES-128-GCM). No plain text is stored. Encrypted columns cannot be used in filters or queries.'

interface ColumnDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (data: ColumnFormData) => Promise<void>
  column?: unknown // Existing column for edit mode
  availableTables?: Array<{ $id: string; name: string }>
  currentTableId?: string
  existingColumns?: Array<{ key: string }>
  isLoading?: boolean
  /** Table metadata for row size usage (varchar create only). Optional: bytesUsed, bytesMax. */
  table?: { bytesUsed?: number | bigint; bytesMax?: number | bigint }
}

const COLUMN_TYPES: { value: ColumnType; label: string }[] = [
  { value: 'text', label: 'Text' },
  { value: 'mediumtext', label: 'Mediumtext' },
  { value: 'longtext', label: 'Longtext' },
  { value: 'varchar', label: 'Varchar' },
  { value: 'integer', label: 'Integer' },
  { value: 'bigint', label: 'Bigint' },
  { value: 'double', label: 'Float' },
  { value: 'boolean', label: 'Boolean' },
  { value: 'datetime', label: 'Datetime' },
  { value: 'email', label: 'Email' },
  { value: 'ip', label: 'IP' },
  { value: 'url', label: 'URL' },
  { value: 'enum', label: 'Enum' },
  { value: 'relationship', label: 'Relationship' },
  { value: 'point', label: 'Point' },
  { value: 'linestring', label: 'Line' },
  { value: 'polygon', label: 'Polygon' },
  { value: 'string', label: 'String (deprecated)' },
]

const RELATIONSHIP_TYPES: {
  value: 'oneToOne' | 'oneToMany' | 'manyToOne' | 'manyToMany'
  label: string
}[] = [
    { value: 'oneToOne', label: 'One to one' },
    { value: 'oneToMany', label: 'One to many' },
    { value: 'manyToOne', label: 'Many to one' },
    { value: 'manyToMany', label: 'Many to many' },
  ]

const ON_DELETE_OPTIONS: {
  value: 'setNull' | 'cascade' | 'restrict'
  label: string
}[] = [
    {
      value: 'setNull',
      label: 'Set NULL - set row ID as NULL in all related rows',
    },
    { value: 'cascade', label: 'Cascade - delete all related rows' },
    { value: 'restrict', label: 'Restrict - row can not be deleted' },
  ]

function getRelationshipPreviewText(
  relationshipType: ColumnFormData['relationshipType'],
  sourceTableName: string,
  targetTableName: string,
  t: (text: string) => string,
) {
  switch (relationshipType) {
    case 'oneToOne':
      return {
        forward: `${sourceTableName} ${t('can contain one')} ${targetTableName}`,
        backward: `${targetTableName} ${t('can belong to one')} ${sourceTableName}`,
      }
    case 'oneToMany':
      return {
        forward: `${sourceTableName} ${t('can contain many')} ${targetTableName}`,
        backward: `${targetTableName} ${t('can belong to one')} ${sourceTableName}`,
      }
    case 'manyToOne':
      return {
        forward: `${sourceTableName} ${t('can contain one')} ${targetTableName}`,
        backward: `${targetTableName} ${t('can belong to many')} ${sourceTableName}`,
      }
    case 'manyToMany':
      return {
        forward: `${sourceTableName} ${t('can contain many')} ${targetTableName}`,
        backward: `${targetTableName} ${t('can belong to many')} ${sourceTableName}`,
      }
    default:
      return null
  }
}

export function ColumnDrawer({
  open,
  onOpenChange,
  onSubmit,
  column,
  availableTables = [],
  currentTableId,
  existingColumns = [],
  isLoading = false,
  table,
}: ColumnDrawerProps) {
  const t = useT()
  const isEditMode = !!column
  const relationshipTables = availableTables.filter(
    (table) => table.$id !== currentTableId,
  )
  const [formData, setFormData] = useState<ColumnFormData>({
    key: '',
    type: 'text',
    required: false,
    array: false,
  })

  const [enumElements, setEnumElements] = useState<string[]>([''])
  const [enumElementInput, setEnumElementInput] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  /** Text inputs for bigint min/max - avoids Number precision loss past MAX_SAFE_INTEGER. */
  const [bigintMinText, setBigintMinText] = useState('')
  const [bigintMaxText, setBigintMaxText] = useState('')
  // Ref to avoid stale state when user checks Encrypted then immediately submits (state may not have flushed)
  const encryptCheckedRef = useRef<boolean>(false)

  // Initialize form data from column
  useEffect(() => {
    if (column) {
      const data: ColumnFormData = {
        key: column.key || column.name || '',
        type: (column.type || 'string') as ColumnType,
        required: column.required || false,
        array: column.array || false,
        xdefault: column.default !== undefined ? column.default : null,
      }

      if (column.type === 'string') {
        data.size = column.size
        data.encrypt = column.encrypt || false
      } else if (column.type === 'varchar') {
        data.size = column.size ?? 255
        data.encrypt = column.encrypt || false
      } else if (
        column.type === 'text' ||
        column.type === 'mediumtext' ||
        column.type === 'longtext'
      ) {
        data.encrypt = column.encrypt || false
      } else if (
        column.type === 'integer' ||
        column.type === 'bigint' ||
        column.type === 'double'
      ) {
        data.min = column.min
        data.max = column.max
      } else if (column.type === 'enum') {
        data.elements = column.elements || []
        setEnumElements(column.elements || [''])
      } else if (column.type === 'relationship') {
        data.relatedTableId = column.relatedTableId || column.relatedTable
        data.relationshipType =
          column.relationshipType || column.relationType
        data.twoWay = column.twoWay || false
        data.twoWayKey = column.twoWayKey
        data.onDelete = column.onDelete || 'setNull'
      }

      setFormData(data)
      encryptCheckedRef.current = data.encrypt === true
      if (column.type === 'bigint') {
        setBigintMinText(formatInt64Bound(column.min))
        setBigintMaxText(formatInt64Bound(column.max))
      } else {
        setBigintMinText('')
        setBigintMaxText('')
      }
    } else {
      // Reset form for create mode
      setFormData({
        key: '',
        type: 'text',
        required: false,
        array: false,
      })
      setEnumElements([''])
      setEnumElementInput('')
      setErrors({})
      setBigintMinText('')
      setBigintMaxText('')
      encryptCheckedRef.current = false
    }
  }, [column, open])

  // Auto-generate key from related table name for relationships
  useEffect(() => {
    if (
      formData.type === 'relationship' &&
      formData.relatedTableId &&
      !isEditMode
    ) {
      const relatedTable = relationshipTables.find(
        (t) => t.$id === formData.relatedTableId,
      )
      if (relatedTable && !formData.key) {
        // Convert table name to camelCase
        const camelCase = relatedTable.name
          .replace(/(?:^\w|[A-Z]|\b\w)/g, (word, index) => {
            return index === 0 ? word.toLowerCase() : word.toUpperCase()
          })
          .replace(/\s+/g, '')
        setFormData((prev) => ({ ...prev, key: camelCase }))
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formData.type, formData.relatedTableId, relationshipTables, isEditMode])

  // Auto-generate two-way key from current table name
  useEffect(() => {
    if (
      formData.type === 'relationship' &&
      formData.twoWay &&
      !formData.twoWayKey &&
      !isEditMode
    ) {
      // We don't have current table name here, so we'll let user set it
      // This would need to be passed as a prop if needed
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formData.type, formData.twoWay, isEditMode])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
      if (!newOpen) {
        // Reset form when closing
        setFormData({
          key: '',
          type: 'text',
          required: false,
          array: false,
        })
        setEnumElements([''])
        setEnumElementInput('')
        setErrors({})
        setBigintMinText('')
        setBigintMaxText('')
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
      existingColumns.some((c) => c.key === formData.key)
    ) {
      newErrors.key = t('Column with this key already exists')
    }

    // Type-specific validation
    if (formData.type === 'string') {
      if (!formData.size || formData.size < 1) {
        newErrors.size = t('Size is required and must be at least 1')
      } else if (formData.encrypt && formData.size < 150) {
        newErrors.size = t(
          'Encrypted string columns require a minimum size of 150',
        )
      }
    } else if (formData.type === 'varchar') {
      if (
        formData.size === undefined ||
        formData.size < VARCHAR_SIZE_MIN ||
        formData.size > VARCHAR_SIZE_MAX
      ) {
        newErrors.size = `${t('Size is required and must be between')} ${VARCHAR_SIZE_MIN} ${t('and')} ${VARCHAR_SIZE_MAX}`
      } else if (formData.encrypt && formData.size < 150) {
        newErrors.size = t(
          'Encrypted varchar columns require a minimum size of 150',
        )
      } else if (
        !isEditMode &&
        table?.bytesUsed !== undefined &&
        table?.bytesMax !== undefined &&
        toByteCount(table.bytesMax) > 0
      ) {
        const newColumnBytes = formData.size * 4 + 2
        if (toByteCount(table.bytesUsed) + newColumnBytes > toByteCount(table.bytesMax)) {
          newErrors.size = t(
            'This column exceeds the remaining row space. Consider using text, mediumtext, or longtext instead.',
          )
        }
      }
    } else if (formData.type === 'enum') {
      const validElements = enumElements.filter((e) => e.trim().length > 0)
      if (validElements.length === 0) {
        newErrors.elements = t('At least one element is required')
      } else {
        const invalidElements = validElements.filter((e) => e.length > 255)
        if (invalidElements.length > 0) {
          newErrors.elements = t(
            'Enum elements have a maximum length of 255 characters',
          )
        }
      }
    } else if (formData.type === 'relationship') {
      if (!formData.relatedTableId) {
        newErrors.relatedTableId = t('Related table is required')
      }
      if (!formData.relationshipType) {
        newErrors.relationshipType = t('Relationship type is required')
      }
      if (!formData.onDelete) {
        newErrors.onDelete = t('On delete action is required')
      }
      if (formData.twoWay && !formData.twoWayKey) {
        newErrors.twoWayKey = t('Two-way key is required')
      }
    } else if (formData.type === 'bigint') {
      const minParsed = bigintMinText.trim()
        ? parseInt64Value(bigintMinText)
        : null
      const maxParsed = bigintMaxText.trim()
        ? parseInt64Value(bigintMaxText)
        : null
      const int64RangeHint = t(
        'Must be a signed 64-bit integer between -9,223,372,036,854,775,808 and 9,223,372,036,854,775,807',
      )

      if (bigintMinText.trim() && minParsed === null) {
        newErrors.min = int64RangeHint
      } else if (minParsed !== null && !isValidInt64(minParsed)) {
        newErrors.min = int64RangeHint
      }

      if (bigintMaxText.trim() && maxParsed === null) {
        newErrors.max = int64RangeHint
      } else if (maxParsed !== null && !isValidInt64(maxParsed)) {
        newErrors.max = int64RangeHint
      }

      if (
        minParsed !== null &&
        maxParsed !== null &&
        minParsed > maxParsed
      ) {
        newErrors.max = t('Max must be greater than or equal to min')
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
      const submitData: ColumnFormData = { ...formData }

      // Process enum elements
      if (formData.type === 'enum') {
        submitData.elements = enumElements.filter((e) => e.trim().length > 0)
      }

      // Handle default values
      if (formData.required || formData.array) {
        submitData.xdefault = null
      }

      // For spatial types, array is not supported
      if (['point', 'linestring', 'polygon'].includes(formData.type)) {
        submitData.array = false
      }

      // Explicit boolean for encryption so API always receives true/false.
      // Use ref so we get the latest checkbox value even if state hasn't flushed (e.g. user checks then immediately submits).
      if (TEXT_TYPES_WITH_ENCRYPT.includes(formData.type)) {
        submitData.encrypt = encryptCheckedRef.current === true
      }

      if (formData.type === 'bigint') {
        submitData.min = bigintMinText.trim()
          ? (parseInt64Value(bigintMinText) ?? undefined)
          : undefined
        submitData.max = bigintMaxText.trim()
          ? (parseInt64Value(bigintMaxText) ?? undefined)
          : undefined
      }

      await onSubmit(submitData)
      handleOpenChange(false)
      // Don't show toast for suggestions - parent handles it
      if (!(column as unknown)?.isSuggestion) {
        toast.success(
          isEditMode
            ? t('Column updated successfully')
            : t('Column created successfully'),
        )
      }
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to save column'),
      )
    }
  }

  const addEnumElement = () => {
    if (enumElementInput.trim()) {
      if (enumElementInput.length > 255) {
        toast.error(t('Enum elements have a maximum length of 255 characters'))
        return
      }
      setEnumElements([...enumElements, enumElementInput.trim()])
      setEnumElementInput('')
    }
  }

  const removeEnumElement = (index: number) => {
    setEnumElements(enumElements.filter((_, i) => i !== index))
  }

  const updateEnumElement = (index: number, value: string) => {
    if (value.length > 255) {
      toast.error(t('Enum elements have a maximum length of 255 characters'))
      return
    }
    const newElements = [...enumElements]
    newElements[index] = value
    setEnumElements(newElements)
  }

  const isSpatialType = ['point', 'linestring', 'polygon'].includes(
    formData.type,
  )
  const showDefaultValue =
    !formData.required && !formData.array && !isSpatialType
  const showDefaultValueCheckbox = isSpatialType && !formData.required
  const currentTableName =
    availableTables.find((table) => table.$id === currentTableId)?.name ||
    t('Current table')
  const relatedTableName =
    relationshipTables.find((table) => table.$id === formData.relatedTableId)
      ?.name || t('Related table')
  const relationshipPreview = getRelationshipPreviewText(
    formData.relationshipType,
    currentTableName,
    relatedTableName,
    t,
  )

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEditMode ? t('Update Column') : t('Create Column')}
      maxWidth="sm:max-w-2xl"
    >
      <>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
          <div className="flex-1 overflow-y-auto px-6 pb-4 pt-4 space-y-4">
            {/* Key */}
            <div className="space-y-2">
              <Label htmlFor="column-key" className="text-[12px] font-medium">
                {t('Key')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="column-key"
                value={formData.key}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, key: e.target.value }))
                }
                placeholder={t('Enter key')}
                disabled={
                  isLoading || (isEditMode && formData.type === 'relationship')
                }
                pattern="^[A-Za-z0-9][A-Za-z0-9._\-]*$"
                className={errors.key ? 'border-destructive' : ''}
              />
              {errors.key && (
                <p className="text-[12px] text-destructive">{errors.key}</p>
              )}
              {formData.type !== 'relationship' && (
                <p className="text-[11px] text-muted-foreground">
                  {t('Allowed characters: a-z, A-Z, 0-9, -, ., _')}
                </p>
              )}
            </div>

            {/* Type */}
            <div className="space-y-2">
              <Label htmlFor="column-type" className="text-[12px] font-medium">
                Type <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.type}
                onValueChange={(value) => {
                  const newType = value as ColumnType
                  setFormData((prev) => {
                    const keepEncrypt =
                      TEXT_TYPES_WITH_ENCRYPT.includes(newType)
                    const nextEncrypt = keepEncrypt
                      ? (prev.encrypt ?? false)
                      : false
                    encryptCheckedRef.current = nextEncrypt
                    if (newType !== 'bigint') {
                      setBigintMinText('')
                      setBigintMaxText('')
                    }
                    return {
                      ...prev,
                      type: newType,
                      // Reset type-specific fields
                      size: newType === 'varchar' ? 255 : undefined,
                      encrypt: nextEncrypt,
                      min: undefined,
                      max: undefined,
                      elements: undefined,
                      relatedTableId: undefined,
                      relationshipType: undefined,
                      twoWay: false,
                      twoWayKey: undefined,
                      onDelete: undefined,
                      array:
                        newType === 'point' ||
                          newType === 'linestring' ||
                          newType === 'polygon'
                          ? false
                          : prev.array,
                    }
                  })
                  if (value === 'enum') {
                    setEnumElements([''])
                  }
                }}
                disabled={isLoading || isEditMode}
              >
                <SelectTrigger
                  id="column-type"
                  className={errors.type ? 'border-destructive' : ''}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {COLUMN_TYPES.map((type) => (
                    <SelectItem key={type.value} value={type.value}>
                      {t(type.label)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.type && (
                <p className="text-[12px] text-destructive">{errors.type}</p>
              )}
            </div>

            {/* String-specific fields */}
            {formData.type === 'string' && (
              <>
                <div className="space-y-2">
                  <Label
                    htmlFor="column-size"
                    className="text-[12px] font-medium"
                  >
                    Size <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="column-size"
                    type="number"
                    value={formData.size || ''}
                    onChange={(e) => {
                      const size = parseInt(e.target.value) || 0
                      setFormData((prev) => ({ ...prev, size }))
                    }}
                    placeholder={t('Enter size')}
                    min={formData.encrypt ? 150 : 1}
                    disabled={isLoading}
                    className={errors.size ? 'border-destructive' : ''}
                  />
                  {errors.size && (
                    <p className="text-[12px] text-destructive">
                      {errors.size}
                    </p>
                  )}
                  {formData.encrypt && (
                    <p className="text-[11px] text-muted-foreground">
                      {t('Encrypted string columns require a minimum size of 150.')}
                    </p>
                  )}
                </div>
              </>
            )}

            {/* Varchar-specific fields */}
            {formData.type === 'varchar' && (
              <>
                <div className="space-y-2">
                  <Label
                    htmlFor="column-size-varchar"
                    className="text-[12px] font-medium"
                  >
                    Size <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="column-size-varchar"
                    type="number"
                    value={formData.size ?? ''}
                    onChange={(e) => {
                      const size = parseInt(e.target.value) || 0
                      setFormData((prev) => ({
                        ...prev,
                        size: size || undefined,
                      }))
                    }}
                    placeholder="255"
                    min={formData.encrypt ? 150 : VARCHAR_SIZE_MIN}
                    max={VARCHAR_SIZE_MAX}
                    disabled={isLoading}
                    className={errors.size ? 'border-destructive' : ''}
                  />
                  {errors.size && (
                    <p className="text-[12px] text-destructive">
                      {errors.size}
                    </p>
                  )}
                  {formData.encrypt && (
                    <p className="text-[11px] text-muted-foreground">
                      {t('Encrypted varchar columns require a minimum size of 150.')}
                    </p>
                  )}
                  <p className="text-[11px] text-muted-foreground">
                    Between {VARCHAR_SIZE_MIN.toLocaleString()} and{' '}
                    {VARCHAR_SIZE_MAX.toLocaleString()} characters. Stored in
                    row (counts toward 64 KB row limit).
                  </p>
                </div>

                {!isEditMode &&
                  table?.bytesUsed !== undefined &&
                  table?.bytesMax !== undefined &&
                  toByteCount(table.bytesMax) > 0 && (
                    <div className="space-y-2 rounded-lg border border-border bg-muted/30 p-3">
                      <p className="text-[11px] font-medium text-foreground">
                        {t('Row size usage')}
                      </p>
                      <p className="text-[11px] text-muted-foreground">
                        {t('Database rows have a maximum size of 64 KB. varchar columns use 4 bytes per character plus a small overhead. text, mediumtext, and longtext columns only use ~20 bytes regardless of content length.')}
                      </p>
                      <Progress
                        value={
                          toByteCount(table.bytesMax) > 0
                            ? Math.min(
                              100,
                              (toByteCount(table.bytesUsed) /
                                toByteCount(table.bytesMax)) *
                                100,
                            )
                            : 0
                        }
                        className="h-2"
                      />
                      <p className="text-[11px] text-muted-foreground">
                        Current: {(toByteCount(table.bytesUsed) / 1024).toFixed(1)}{' '}
                        KB / {(toByteCount(table.bytesMax) / 1024).toFixed(1)} KB
                        {formData.size
                          ? ` · New column: ~${((formData.size * 4 + 2) / 1024).toFixed(1)} KB`
                          : ''}
                      </p>
                    </div>
                  )}
              </>
            )}

            {/* Text / Mediumtext / Longtext: static hints + encryption */}
            {formData.type === 'text' && (
              <>
                <p className="text-[11px] text-muted-foreground">
                  {t('Maximum size: 16,383 characters.')}
                </p>
              </>
            )}
            {formData.type === 'mediumtext' && (
              <>
                <p className="text-[11px] text-muted-foreground">
                  {t('Maximum size: 4,194,303 characters.')}
                </p>
              </>
            )}
            {formData.type === 'longtext' && (
              <>
                <p className="text-[11px] text-muted-foreground">
                  {t('Maximum size: 1,073,741,823 characters.')}
                </p>
              </>
            )}

            {/* Integer/Float/Bigint-specific fields */}
            {(formData.type === 'integer' ||
              formData.type === 'bigint' ||
              formData.type === 'double') && (
              <>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label
                      htmlFor="column-min"
                      className="text-[12px] font-medium"
                    >
                      {t('Min')}
                    </Label>
                    {formData.type === 'bigint' ? (
                      <Input
                        id="column-min"
                        type="text"
                        inputMode="numeric"
                        value={bigintMinText}
                        onChange={(e) => {
                          setBigintMinText(e.target.value)
                          if (errors.min) {
                            setErrors((prev) => {
                              const next = { ...prev }
                              delete next.min
                              return next
                            })
                          }
                        }}
                        placeholder={t('Enter min')}
                        disabled={isLoading}
                        aria-invalid={!!errors.min}
                      />
                    ) : (
                      <Input
                        id="column-min"
                        type="number"
                        value={formData.min ?? ''}
                        onChange={(e) => {
                          const min =
                            e.target.value === ''
                              ? undefined
                              : formData.type === 'double'
                                ? parseFloat(e.target.value)
                                : parseInt(e.target.value, 10)
                          setFormData((prev) => ({ ...prev, min }))
                        }}
                        placeholder={t('Enter min')}
                        step={formData.type === 'double' ? 0.1 : 1}
                        disabled={isLoading}
                      />
                    )}
                    {errors.min && (
                      <p className="text-[11px] text-destructive">{errors.min}</p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label
                      htmlFor="column-max"
                      className="text-[12px] font-medium"
                    >
                      {t('Max')}
                    </Label>
                    {formData.type === 'bigint' ? (
                      <Input
                        id="column-max"
                        type="text"
                        inputMode="numeric"
                        value={bigintMaxText}
                        onChange={(e) => {
                          setBigintMaxText(e.target.value)
                          if (errors.max) {
                            setErrors((prev) => {
                              const next = { ...prev }
                              delete next.max
                              return next
                            })
                          }
                        }}
                        placeholder={t('Enter max')}
                        disabled={isLoading}
                        aria-invalid={!!errors.max}
                      />
                    ) : (
                      <Input
                        id="column-max"
                        type="number"
                        value={formData.max ?? ''}
                        onChange={(e) => {
                          const max =
                            e.target.value === ''
                              ? undefined
                              : formData.type === 'double'
                                ? parseFloat(e.target.value)
                                : parseInt(e.target.value, 10)
                          setFormData((prev) => ({ ...prev, max }))
                        }}
                        placeholder={t('Enter max')}
                        step={formData.type === 'double' ? 0.1 : 1}
                        disabled={isLoading}
                      />
                    )}
                    {errors.max && (
                      <p className="text-[11px] text-destructive">{errors.max}</p>
                    )}
                  </div>
                </div>
                {formData.type === 'bigint' && (
                  <p className="text-[11px] text-muted-foreground">
                    Signed 64-bit range: {INT64_MIN.toString()} to{' '}
                    {INT64_MAX.toString()}. Enter the full value (do not use the
                    number input - large values lose precision in JavaScript).
                  </p>
                )}
              </>
            )}

            {/* Enum-specific fields */}
            {formData.type === 'enum' && (
              <div className="space-y-2">
                <Label className="text-[12px] font-medium">
                  Elements <span className="text-destructive">*</span>
                </Label>
                <div className="space-y-2">
                  {enumElements.map((element, index) => (
                    <div key={index} className="flex items-center gap-2">
                      <Input
                        value={element}
                        onChange={(e) =>
                          updateEnumElement(index, e.target.value)
                        }
                        placeholder={t('Add elements here')}
                        maxLength={255}
                        disabled={isLoading}
                      />
                      {enumElements.length > 1 && (
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="h-9 w-9 shrink-0"
                          onClick={() => removeEnumElement(index)}
                          disabled={isLoading}
                          aria-label={t('Remove enum value')}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      )}
                    </div>
                  ))}
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addEnumElement}
                    disabled={isLoading || !enumElementInput.trim()}
                    className="w-full"
                  >
                    <Plus className="h-3.5 w-3.5 me-1.5" />
                    {t('Add element')}
                  </Button>
                </div>
                {errors.elements && (
                  <p className="text-[12px] text-destructive">
                    {errors.elements}
                  </p>
                )}
                <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-3">
                  <Info className="h-4 w-4 shrink-0 text-muted-foreground mt-0.5" />
                  <p className="text-[11px] text-muted-foreground">
                    {t('Enum elements have a maximum length of 255 characters. This limit can not be exceeded.')}
                  </p>
                </div>
                <div className="flex gap-2">
                  <Input
                    value={enumElementInput}
                    onChange={(e) => setEnumElementInput(e.target.value)}
                    placeholder={t('Type to add element')}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault()
                        addEnumElement()
                      }
                    }}
                    disabled={isLoading}
                  />
                </div>
              </div>
            )}

            {/* Relationship-specific fields */}
            {formData.type === 'relationship' && (
              <>
                <div className="space-y-2">
                  <Label className="text-[12px] font-medium">
                    {t('Relationship Type')}
                  </Label>
                  <RadioGroup
                    value={formData.twoWay ? 'two' : 'one'}
                    onValueChange={(value) => {
                      setFormData((prev) => ({
                        ...prev,
                        twoWay: value === 'two',
                        twoWayKey: value === 'two' ? prev.twoWayKey : undefined,
                      }))
                    }}
                    disabled={isLoading || isEditMode}
                    className="grid grid-cols-1 gap-4 sm:grid-cols-2"
                  >
                    <div className="flex items-start gap-3 rounded-lg border border-border p-3">
                      <RadioGroupItem value="one" id="one-way" />
                      <Label
                        htmlFor="one-way"
                        className="flex flex-1 flex-col items-start gap-1 leading-normal cursor-pointer"
                      >
                        <div className="font-medium text-[12px]">
                          {t('One-way relationship')}
                        </div>
                        <div className="text-[11px] text-muted-foreground">
                          {t('One Relation column within this table')}
                        </div>
                      </Label>
                    </div>
                    <div className="flex items-start gap-3 rounded-lg border border-border p-3">
                      <RadioGroupItem value="two" id="two-way" />
                      <Label
                        htmlFor="two-way"
                        className="flex flex-1 flex-col items-start gap-1 leading-normal cursor-pointer"
                      >
                        <div className="font-medium text-[12px]">
                          {t('Two-way relationship')}
                        </div>
                        <div className="text-[11px] text-muted-foreground">
                          {t('One Relation column within this table and another within the related table')}
                        </div>
                      </Label>
                    </div>
                  </RadioGroup>
                </div>

                <div className="space-y-2">
                  <Label
                    htmlFor="related-table"
                    className="text-[12px] font-medium"
                  >
                    {t('Related table')}{' '}
                    <span className="text-destructive">*</span>
                  </Label>
                  <Select
                    value={formData.relatedTableId || ''}
                    onValueChange={(value) => {
                      setFormData((prev) => ({
                        ...prev,
                        relatedTableId: value,
                      }))
                    }}
                    disabled={isLoading || isEditMode}
                  >
                    <SelectTrigger
                      id="related-table"
                      className={
                        errors.relatedTableId ? 'border-destructive' : ''
                      }
                    >
                      <SelectValue placeholder={t('Select a table')} />
                    </SelectTrigger>
                    <SelectContent>
                      {relationshipTables.map((table) => (
                        <SelectItem key={table.$id} value={table.$id}>
                          {table.name} ({table.$id})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.relatedTableId && (
                    <p className="text-[12px] text-destructive">
                      {errors.relatedTableId}
                    </p>
                  )}
                </div>

                {formData.relatedTableId && (
                  <>
                    <div className="space-y-2">
                      <Label
                        htmlFor="column-key-relationship"
                        className="text-[12px] font-medium"
                      >
                        {t('Column key')}{' '}
                        <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id="column-key-relationship"
                        value={formData.key}
                        onChange={(e) =>
                          setFormData((prev) => ({
                            ...prev,
                            key: e.target.value,
                          }))
                        }
                        placeholder={t('Enter key')}
                        disabled={isLoading}
                        pattern="^[A-Za-z0-9][A-Za-z0-9._\-]*$"
                        className={errors.key ? 'border-destructive' : ''}
                      />
                      {errors.key && (
                        <p className="text-[12px] text-destructive">
                          {errors.key}
                        </p>
                      )}
                      <p className="text-[11px] text-muted-foreground">
                        {t('Allowed characters: a-z, A-Z, 0-9, -, ., _')}
                      </p>
                    </div>

                    {formData.twoWay && (
                      <div className="space-y-2">
                        <Label
                          htmlFor="two-way-key"
                          className="text-[12px] font-medium"
                        >
                          {t('Column key (related table)')}{' '}
                          <span className="text-destructive">*</span>
                        </Label>
                        <Input
                          id="two-way-key"
                          value={formData.twoWayKey || ''}
                          onChange={(e) =>
                            setFormData((prev) => ({
                              ...prev,
                              twoWayKey: e.target.value,
                            }))
                          }
                          placeholder={t('Enter key')}
                          disabled={isLoading || isEditMode}
                          pattern="^[A-Za-z0-9][A-Za-z0-9._\-]*$"
                          className={
                            errors.twoWayKey ? 'border-destructive' : ''
                          }
                        />
                        {errors.twoWayKey && (
                          <p className="text-[12px] text-destructive">
                            {errors.twoWayKey}
                          </p>
                        )}
                        <p className="text-[11px] text-muted-foreground">
                          {t('Allowed characters: a-z, A-Z, 0-9, -, ., _. Once created, column key cannot be adjusted to maintain data integrity.')}
                        </p>
                      </div>
                    )}

                    <div className="space-y-2">
                      <Label
                        htmlFor="relationship-type"
                        className="text-[12px] font-medium"
                      >
                        Relation <span className="text-destructive">*</span>
                      </Label>
                      <Select
                        value={formData.relationshipType || ''}
                        onValueChange={(value) => {
                          setFormData((prev) => ({
                            ...prev,
                            relationshipType: value as unknown,
                          }))
                        }}
                        disabled={isLoading || isEditMode}
                      >
                        <SelectTrigger
                          id="relationship-type"
                          className={
                            errors.relationshipType ? 'border-destructive' : ''
                          }
                        >
                          <SelectValue placeholder={t('Select a relation')} />
                        </SelectTrigger>
                        <SelectContent>
                          {RELATIONSHIP_TYPES.map((type) => (
                            <SelectItem key={type.value} value={type.value}>
                              {t(type.label)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      {errors.relationshipType && (
                        <p className="text-[12px] text-destructive">
                          {errors.relationshipType}
                        </p>
                      )}
                    </div>

                    {formData.relationshipType && formData.relatedTableId && (
                      <div className="space-y-3 rounded-xl border border-border bg-card/50 p-3">
                        <div className="flex items-center justify-center gap-3 rounded-lg border border-border bg-background/70 px-3 py-4 text-center">
                          <span className="text-base font-medium text-foreground">
                            {currentTableName}
                          </span>
                          {formData.twoWay ? (
                            <ArrowLeftRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                          ) : (
                            <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                          )}
                          <span className="text-base font-medium text-foreground">
                            {relatedTableName}
                          </span>
                        </div>
                        {relationshipPreview && (
                          <div className="space-y-1 text-center text-[13px] leading-snug text-muted-foreground">
                            <p>
                              <span className="font-semibold text-foreground">
                                {currentTableName}
                              </span>{' '}
                              {relationshipPreview.forward.replace(
                                `${currentTableName} `,
                                '',
                              )}
                            </p>
                            <p>
                              <span className="font-semibold text-foreground">
                                {relatedTableName}
                              </span>{' '}
                              {relationshipPreview.backward.replace(
                                `${relatedTableName} `,
                                '',
                              )}
                            </p>
                          </div>
                        )}
                      </div>
                    )}

                    <div className="space-y-2">
                      <Label
                        htmlFor="on-delete"
                        className="text-[12px] font-medium"
                      >
                        On deleting a row{' '}
                        <span className="text-destructive">*</span>
                      </Label>
                      <Select
                        value={formData.onDelete || ''}
                        onValueChange={(value) => {
                          setFormData((prev) => ({
                            ...prev,
                            onDelete: value as unknown,
                          }))
                        }}
                        disabled={isLoading}
                      >
                        <SelectTrigger
                          id="on-delete"
                          className={
                            errors.onDelete ? 'border-destructive' : ''
                          }
                        >
                          <SelectValue placeholder={t('Select a deletion method')} />
                        </SelectTrigger>
                        <SelectContent>
                          {ON_DELETE_OPTIONS.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                              {t(option.label)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      {errors.onDelete && (
                        <p className="text-[12px] text-destructive">
                          {errors.onDelete}
                        </p>
                      )}
                    </div>
                  </>
                )}
              </>
            )}

            {/* Spatial types - Point, Line, Polygon */}
            {isSpatialType && (
              <div className="space-y-4">
                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="spatial-required"
                    checked={formData.required || false}
                    onCheckedChange={(checked) => {
                      setFormData((prev) => ({
                        ...prev,
                        required: checked as boolean,
                        xdefault: checked ? null : prev.xdefault,
                      }))
                    }}
                    disabled={isLoading}
                  />
                  <Label
                    htmlFor="spatial-required"
                    className="text-[12px] font-normal cursor-pointer"
                  >
                    {t('Required')}
                  </Label>
                </div>

                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="spatial-default"
                    checked={
                      showDefaultValueCheckbox &&
                      formData.xdefault !== null &&
                      formData.xdefault !== undefined
                    }
                    onCheckedChange={(checked) => {
                      setFormData((prev) => ({
                        ...prev,
                        xdefault: checked
                          ? formData.type === 'point'
                            ? [0, 0]
                            : formData.type === 'linestring'
                              ? [
                                [0, 0],
                                [0, 0],
                              ]
                              : [
                                [
                                  [0, 0],
                                  [0, 0],
                                  [0, 0],
                                ],
                              ]
                          : null,
                        required: checked ? false : prev.required,
                      }))
                    }}
                    disabled={isLoading || formData.required}
                  />
                  <Label
                    htmlFor="spatial-default"
                    className="text-[12px] font-normal cursor-pointer"
                  >
                    {t('Default value')}
                  </Label>
                </div>

                {/* Spatial default value editors */}
                {showDefaultValueCheckbox &&
                  formData.xdefault !== null &&
                  formData.xdefault !== undefined && (
                    <div className="space-y-3">
                      {formData.type === 'point' && (
                        <PointEditor
                          value={formData.xdefault as [number, number] | null}
                          onChange={(val: [number, number] | null) =>
                            setFormData((prev) => ({ ...prev, xdefault: val }))
                          }
                          isRequired={false}
                          disabled={isLoading}
                          showNullCheckbox={false}
                        />
                      )}
                      {formData.type === 'linestring' && (
                        <LineEditor
                          value={formData.xdefault as number[][] | null}
                          onChange={(val: number[][] | null) =>
                            setFormData((prev) => ({ ...prev, xdefault: val }))
                          }
                          isRequired={false}
                          disabled={isLoading}
                          showNullCheckbox={false}
                        />
                      )}
                      {formData.type === 'polygon' && (
                        <PolygonEditor
                          value={formData.xdefault as number[][][] | null}
                          onChange={(val: number[][][] | null) =>
                            setFormData((prev) => ({ ...prev, xdefault: val }))
                          }
                          isRequired={false}
                          disabled={isLoading}
                          showNullCheckbox={false}
                        />
                      )}
                    </div>
                  )}
              </div>
            )}

            {/* Common fields - Required and Array */}
            {!isSpatialType && (
              <>
                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="column-required"
                    checked={formData.required || false}
                    onCheckedChange={(checked) => {
                      setFormData((prev) => ({
                        ...prev,
                        required: checked as boolean,
                        xdefault: checked ? null : prev.xdefault,
                      }))
                    }}
                    disabled={isLoading}
                  />
                  <Label
                    htmlFor="column-required"
                    className="text-[12px] font-normal cursor-pointer"
                  >
                    {t('Required')}
                  </Label>
                </div>

                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="column-array"
                    checked={formData.array || false}
                    onCheckedChange={(checked) => {
                      setFormData((prev) => ({
                        ...prev,
                        array: checked as boolean,
                        xdefault: checked ? null : prev.xdefault,
                      }))
                    }}
                    disabled={isLoading}
                  />
                  <Label
                    htmlFor="column-array"
                    className="text-[12px] font-normal cursor-pointer"
                  >
                    {t('Array')}
                  </Label>
                </div>
              </>
            )}

            {/* Default value - for non-spatial types */}
            {showDefaultValue && (
              <div className="space-y-2">
                <Label
                  htmlFor="column-default"
                  className="text-[12px] font-medium"
                >
                  {t('Default value')}
                </Label>
                {formData.type === 'boolean' ? (
                  <Select
                    value={
                      formData.xdefault === null
                        ? 'null'
                        : String(formData.xdefault)
                    }
                    onValueChange={(value) => {
                      setFormData((prev) => ({
                        ...prev,
                        xdefault: value === 'null' ? null : value === 'true',
                      }))
                    }}
                    disabled={isLoading}
                  >
                    <SelectTrigger id="column-default">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="null">NULL</SelectItem>
                      <SelectItem value="true">{t('True')}</SelectItem>
                      <SelectItem value="false">{t('False')}</SelectItem>
                    </SelectContent>
                  </Select>
                ) : formData.type === 'datetime' ? (
                  <DateTimePicker
                    id="column-default"
                    value={
                      formData.xdefault
                        ? String(formData.xdefault)
                        : null
                    }
                    onChange={(value) => {
                      setFormData((prev) => ({
                        ...prev,
                        xdefault: value,
                      }))
                    }}
                    disabled={isLoading}
                    clearable
                  />
                ) : formData.type === 'enum' ? (
                  <Select
                    value={
                      formData.xdefault ? String(formData.xdefault) : 'null'
                    }
                    onValueChange={(value) => {
                      setFormData((prev) => ({
                        ...prev,
                        xdefault: value === 'null' ? null : value,
                      }))
                    }}
                    disabled={isLoading}
                  >
                    <SelectTrigger id="column-default">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="null">NULL</SelectItem>
                      {enumElements
                        .filter((e) => e.trim())
                        .map((element) => (
                          <SelectItem key={element} value={element}>
                            {element}
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                ) : formData.type === 'integer' ||
                  formData.type === 'bigint' ||
                  formData.type === 'double' ? (
                  <Input
                    id="column-default"
                    type={formData.type === 'bigint' ? 'text' : 'number'}
                    inputMode="numeric"
                    value={
                      formData.xdefault !== null &&
                        formData.xdefault !== undefined
                        ? String(formData.xdefault)
                        : ''
                    }
                    onChange={(e) => {
                      const value =
                        e.target.value === ''
                          ? null
                          : formData.type === 'bigint'
                            ? (() => {
                                try {
                                  return BigInt(e.target.value)
                                } catch {
                                  return null
                                }
                              })()
                            : formData.type === 'integer'
                              ? parseInt(e.target.value, 10)
                              : parseFloat(e.target.value)
                      setFormData((prev) => ({ ...prev, xdefault: value }))
                    }}
                    min={
                      formData.type === 'bigint' ? undefined : formData.min
                    }
                    max={
                      formData.type === 'bigint' ? undefined : formData.max
                    }
                    step={formData.type === 'double' ? 0.1 : 1}
                    placeholder={t('Enter value')}
                    disabled={isLoading}
                  />
                ) : formData.type === 'varchar' ? (
                  (formData.size ?? 255) < 50 ? (
                    <Input
                      id="column-default"
                      type="text"
                      value={formData.xdefault ? String(formData.xdefault) : ''}
                      onChange={(e) => {
                        const value = e.target.value || null
                        setFormData((prev) => ({ ...prev, xdefault: value }))
                      }}
                      placeholder={t('Enter value')}
                      maxLength={formData.size ?? 255}
                      disabled={isLoading}
                    />
                  ) : (
                    <Textarea
                      id="column-default"
                      value={formData.xdefault ? String(formData.xdefault) : ''}
                      onChange={(e) => {
                        const value = e.target.value || null
                        setFormData((prev) => ({ ...prev, xdefault: value }))
                      }}
                      placeholder={t('Enter value')}
                      maxLength={formData.size ?? 255}
                      disabled={isLoading}
                      className="min-h-[80px]"
                    />
                  )
                ) : ['text', 'mediumtext', 'longtext'].includes(
                  formData.type,
                ) ? (
                  <Textarea
                    id="column-default"
                    value={formData.xdefault ? String(formData.xdefault) : ''}
                    onChange={(e) => {
                      const value = e.target.value || null
                      setFormData((prev) => ({ ...prev, xdefault: value }))
                    }}
                    placeholder={t('Enter value')}
                    disabled={isLoading}
                    className="min-h-[80px]"
                  />
                ) : (
                  <Input
                    id="column-default"
                    type={
                      formData.type === 'email'
                        ? 'email'
                        : formData.type === 'url'
                          ? 'url'
                          : 'text'
                    }
                    value={formData.xdefault ? String(formData.xdefault) : ''}
                    onChange={(e) => {
                      const value = e.target.value || null
                      setFormData((prev) => ({ ...prev, xdefault: value }))
                    }}
                    placeholder={t('Enter value')}
                    maxLength={
                      formData.type === 'string' ? formData.size : undefined
                    }
                    disabled={isLoading}
                  />
                )}
              </div>
            )}

            {!isEditMode && TEXT_TYPES_WITH_ENCRYPT.includes(formData.type) && (
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {t('At-rest encryption')}
                  </h3>
                  <p className="text-[13px] text-muted-foreground mt-2">
                    {t(ENCRYPT_DESCRIPTION)}
                  </p>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <div className="flex items-center space-x-2">
                    <Checkbox
                      id="column-encrypt"
                      checked={formData.encrypt || false}
                      onCheckedChange={(checked) => {
                        const value = checked === true
                        encryptCheckedRef.current = value
                        setFormData((prev) => ({
                          ...prev,
                          encrypt: value,
                          ...((formData.type === 'string' ||
                            formData.type === 'varchar') &&
                            value &&
                            (!prev.size || prev.size < 150)
                            ? { size: 150 }
                            : {}),
                        }))
                      }}
                      disabled={isLoading}
                    />
                    <Label
                      htmlFor="column-encrypt"
                      className="text-[13px] font-medium cursor-pointer"
                    >
                      {t('Enable')}
                    </Label>
                  </div>
                </div>
              </div>
            )}
          </div>

          <div className="flex-shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start">
            <Button type="submit" disabled={isLoading}>
              {isEditMode ? t('Update') : t('Create Column')}
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
