import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Plus, X } from 'lucide-react'
import { toast } from 'sonner'

/**
 * Line Editor Component
 *
 * Used for editing linestring spatial data (array of coordinate points)
 * Can be used in row editing, default values, and geo queries
 */
export interface LineEditorProps {
  value: number[][] | null
  onChange: (value: number[][] | null) => void
  isRequired?: boolean
  disabled?: boolean
  showNullCheckbox?: boolean
}

export function LineEditor({
  value,
  onChange,
  isRequired = false,
  disabled = false,
  showNullCheckbox = true,
}: LineEditorProps) {
  const [points, setPoints] = useState<number[][]>(value || [])

  useEffect(() => {
    setPoints(value || [])
  }, [value])

  const emitPoints = (nextPoints: number[][]) => {
    setPoints(nextPoints)
    onChange(nextPoints.length === 0 && !isRequired ? null : nextPoints)
  }

  const updatePoint = (index: number, coordIndex: 0 | 1, newValue: string) => {
    const newPoints = [...points]
    if (!newPoints[index]) {
      newPoints[index] = [0, 0]
    }
    const numValue = newValue === '' ? 0 : parseFloat(newValue)
    newPoints[index][coordIndex] = isNaN(numValue) ? 0 : numValue
    emitPoints(newPoints)
  }

  const addPoint = () => {
    const newPoints = [...points, [0, 0]]
    emitPoints(newPoints)
  }

  const removePoint = (index: number) => {
    if (points.length <= 2) {
      toast.error('Line must have at least 2 points')
      return
    }
    const newPoints = points.filter((_, i) => i !== index)
    emitPoints(newPoints)
  }

  return (
    <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
      <div className="flex items-center justify-between">
        <Label className="text-[12px] font-medium">Coordinate Points</Label>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={addPoint}
          disabled={disabled}
          className="h-7 text-[11px]"
        >
          <Plus className="h-3 w-3 mr-1" />
          Add Point
        </Button>
      </div>
      {points.length === 0 ? (
        <p className="text-[12px] text-muted-foreground py-2 text-center">
          No points. Click "Add Point" to add coordinates (minimum 2 required).
        </p>
      ) : (
        <div className="space-y-2">
          {points.map((point, index) => (
            <div
              key={index}
              className="flex items-center gap-2 rounded-md border border-border bg-background p-2"
            >
              <div className="flex-1 grid grid-cols-2 gap-2">
                <div className="space-y-1">
                  <Label className="text-[10px] text-muted-foreground">
                    Lon
                  </Label>
                  <Input
                    type="number"
                    step="any"
                    value={point[0] ?? 0}
                    onChange={(e) => updatePoint(index, 0, e.target.value)}
                    placeholder="0.0"
                    disabled={disabled}
                    className="h-8 text-[12px]"
                  />
                </div>
                <div className="space-y-1">
                  <Label className="text-[10px] text-muted-foreground">
                    Lat
                  </Label>
                  <Input
                    type="number"
                    step="any"
                    value={point[1] ?? 0}
                    onChange={(e) => updatePoint(index, 1, e.target.value)}
                    placeholder="0.0"
                    disabled={disabled}
                    className="h-8 text-[12px]"
                  />
                </div>
              </div>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-7 w-7 shrink-0"
                onClick={() => removePoint(index)}
                disabled={disabled || points.length <= 2}
              >
                <X className="h-3.5 w-3.5" />
              </Button>
            </div>
          ))}
          {points.length < 2 && (
            <p className="text-[10px] text-amber-600 dark:text-amber-400">
              Add at least 2 points to form a line
            </p>
          )}
        </div>
      )}
      {!isRequired && showNullCheckbox && (
        <div className="flex items-center gap-1.5 pt-2 border-t border-border">
          <Checkbox
            id="line-null"
            checked={value === null}
            onCheckedChange={(checked) => {
              const nextValue = checked
                ? null
                : [
                    [0, 0],
                    [0, 0],
                  ]
              setPoints(nextValue || [])
              onChange(
                checked
                  ? null
                  : [
                      [0, 0],
                      [0, 0],
                    ],
              )
            }}
            disabled={disabled}
            className="h-4 w-4"
          />
          <label
            htmlFor="line-null"
            className="text-[11px] text-muted-foreground cursor-pointer select-none"
          >
            Set to NULL
          </label>
        </div>
      )}
    </div>
  )
}
