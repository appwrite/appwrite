import { useState, useEffect } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { useT } from '@/lib/i18n/translate'

/**
 * Point Editor Component
 *
 * Used for editing point spatial data (longitude, latitude coordinates)
 * Can be used in row editing, default values, and geo queries
 */
export interface PointEditorProps {
  value: [number, number] | null
  onChange: (value: [number, number] | null) => void
  isRequired?: boolean
  disabled?: boolean
  showNullCheckbox?: boolean
}

export function PointEditor({
  value,
  onChange,
  isRequired = false,
  disabled = false,
  showNullCheckbox = true,
}: PointEditorProps) {
  const t = useT()
  const [longitude, setLongitude] = useState<string>(
    value ? String(value[0]) : '',
  )
  const [latitude, setLatitude] = useState<string>(
    value ? String(value[1]) : '',
  )

  useEffect(() => {
    if (value) {
      setLongitude(String(value[0]))
      setLatitude(String(value[1]))
    } else {
      setLongitude('')
      setLatitude('')
    }
  }, [value])

  const handleLongitudeChange = (val: string) => {
    setLongitude(val)
    const lon = val === '' ? null : parseFloat(val)
    const lat = latitude === '' ? null : parseFloat(latitude)
    if (lon !== null && lat !== null && !isNaN(lon) && !isNaN(lat)) {
      onChange([lon, lat])
    } else if (lon === null && lat === null && !isRequired) {
      onChange(null)
    }
  }

  const handleLatitudeChange = (val: string) => {
    setLatitude(val)
    const lon = longitude === '' ? null : parseFloat(longitude)
    const lat = val === '' ? null : parseFloat(val)
    if (lon !== null && lat !== null && !isNaN(lon) && !isNaN(lat)) {
      onChange([lon, lat])
    } else if (lon === null && lat === null && !isRequired) {
      onChange(null)
    }
  }

  return (
    <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
      <div className="flex items-end gap-2 rounded-md border border-border/60 bg-background px-3 py-2">
        <span className="pb-2 font-mono text-[12px] text-muted-foreground">
          [
        </span>
        <div className="flex-1 space-y-1.5">
          <Label className="text-[11px] text-muted-foreground">{t('Longitude')}</Label>
          <Input
            type="number"
            step="any"
            value={longitude}
            onChange={(e) => handleLongitudeChange(e.target.value)}
            placeholder="0.0"
            disabled={disabled}
            className="h-9 text-[13px]"
          />
        </div>
        <span className="pb-2 font-mono text-[12px] text-muted-foreground">
          ,
        </span>
        <div className="flex-1 space-y-1.5">
          <Label className="text-[11px] text-muted-foreground">{t('Latitude')}</Label>
          <Input
            type="number"
            step="any"
            value={latitude}
            onChange={(e) => handleLatitudeChange(e.target.value)}
            placeholder="0.0"
            disabled={disabled}
            className="h-9 text-[13px]"
          />
        </div>
        <span className="pb-2 font-mono text-[12px] text-muted-foreground">
          ]
        </span>
      </div>
      {!isRequired && showNullCheckbox && (
        <div className="flex items-center gap-1.5">
          <Checkbox
            id="point-null"
            checked={value === null}
            onCheckedChange={(checked) => {
              onChange(checked ? null : [0, 0])
            }}
            disabled={disabled}
            className="h-4 w-4"
          />
          <label
            htmlFor="point-null"
            className="text-[11px] text-muted-foreground cursor-pointer select-none"
          >
            {t('Set to NULL')}
          </label>
        </div>
      )}
    </div>
  )
}
