import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Plus, X } from 'lucide-react'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

/**
 * Polygon Editor Component
 *
 * Used for editing polygon spatial data (array of closed rings)
 * Can be used in row editing, default values, and geo queries
 */
export interface PolygonEditorProps {
  value: number[][][] | null
  onChange: (value: number[][][] | null) => void
  isRequired?: boolean
  disabled?: boolean
  showNullCheckbox?: boolean
}

export function PolygonEditor({
  value,
  onChange,
  isRequired = false,
  disabled = false,
  showNullCheckbox = true,
}: PolygonEditorProps) {
  const t = useT()
  const rings = value || []

  const updatePoint = (
    ringIndex: number,
    pointIndex: number,
    coordIndex: 0 | 1,
    newValue: string,
  ) => {
    const newRings = rings.map((ring, ri) => {
      if (ri !== ringIndex) return ring
      const newRing = ring.map((point, pi) => {
        if (pi !== pointIndex) return point
        const newPoint = [...point]
        const numValue = newValue === '' ? 0 : parseFloat(newValue)
        newPoint[coordIndex] = isNaN(numValue) ? 0 : numValue
        return newPoint as [number, number]
      })
      // Ensure ring is closed (first point = last point)
      if (newRing.length > 0 && pointIndex === newRing.length - 1) {
        newRing[0] = [...newRing[newRing.length - 1]] as [number, number]
      } else if (newRing.length > 0 && pointIndex === 0) {
        newRing[newRing.length - 1] = [...newRing[0]] as [number, number]
      }
      return newRing
    })
    onChange(newRings)
  }

  const addPointToRing = (ringIndex: number) => {
    const newRings = rings.map((ring, ri) => {
      if (ri !== ringIndex) return ring
      // Add point before the last point (which is a copy of the first)
      const newRing = [...ring]
      const lastPoint = newRing[newRing.length - 1]
      newRing.splice(newRing.length - 1, 0, [...lastPoint] as [number, number])
      return newRing
    })
    onChange(newRings)
  }

  const removePointFromRing = (ringIndex: number, pointIndex: number) => {
    const newRings = rings.map((ring, ri) => {
      if (ri !== ringIndex) return ring
      if (ring.length <= 3) {
        toast.error(t('Ring must have at least 3 points'))
        return ring
      }
      const newRing = ring.filter((_, pi) => pi !== pointIndex)
      // If we removed the first or last point, update the closure
      if (pointIndex === 0) {
        newRing[newRing.length - 1] = [...newRing[0]] as [number, number]
      } else if (pointIndex === newRing.length) {
        newRing[0] = [...newRing[newRing.length - 1]] as [number, number]
      }
      return newRing
    })
    onChange(newRings)
  }

  const addRing = () => {
    const newRing: [number, number][] = [
      [0, 0],
      [0, 0],
      [0, 0],
      [0, 0],
    ] // Closed ring (first = last)
    onChange([...rings, newRing])
  }

  const removeRing = (ringIndex: number) => {
    const newRings = rings.filter((_, ri) => ri !== ringIndex)
    onChange(newRings.length > 0 ? newRings : null)
  }

  return (
    <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
      <div className="flex items-center justify-between">
        <Label className="text-[12px] font-medium">{t('Rings')}</Label>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={addRing}
          disabled={disabled}
          className="h-7 text-[11px]"
        >
          <Plus className="h-3 w-3 me-1" />
          {t('Add Ring')}
        </Button>
      </div>
      {rings.length === 0 ? (
        <p className="text-[12px] text-muted-foreground py-2 text-center">
          {t('No rings. Click "Add Ring" to add a ring (minimum 3 points per ring required).')}
        </p>
      ) : (
        <div className="space-y-4">
          {rings.map((ring, ringIndex) => (
            <div
              key={ringIndex}
              className="space-y-2 rounded-md border border-border bg-background p-3"
            >
              <div className="flex items-center justify-between">
                <Label className="text-[11px] font-medium">
                  {t('Ring')} {ringIndex + 1}
                </Label>
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => addPointToRing(ringIndex)}
                    disabled={disabled}
                    className="h-6 text-[10px]"
                  >
                    <Plus className="h-3 w-3 me-1" />
                    {t('Add Point')}
                  </Button>
                  {rings.length > 1 && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-6 w-6"
                      onClick={() => removeRing(ringIndex)}
                      disabled={disabled}
                      aria-label={t('Remove ring')}
                    >
                      <X className="h-3 w-3" />
                    </Button>
                  )}
                </div>
              </div>
              {ring.length === 0 ? (
                <p className="text-[11px] text-muted-foreground py-1 text-center">
                  {t('No points. Add at least 3 points to form a ring.')}
                </p>
              ) : (
                <div className="space-y-2 rounded-md border border-border/50 bg-muted/10 p-2">
                  <div className="font-mono text-[12px] text-muted-foreground">
                    [
                  </div>
                  {ring.map((point, pointIndex) => {
                    const isLastPoint = pointIndex === ring.length - 1
                    const isFirstPoint = pointIndex === 0
                    return (
                      <div
                        key={pointIndex}
                        className="flex items-center gap-2 rounded border border-border/50 bg-muted/20 p-2"
                      >
                        <div className="flex flex-1 items-end gap-2">
                          <span className="pb-2 font-mono text-[12px] text-muted-foreground">
                            [
                          </span>
                          <div className="flex-1 space-y-1">
                            <Label className="text-[10px] text-muted-foreground">
                              {t('Lon')}
                            </Label>
                            <Input
                              type="number"
                              step="any"
                              value={point[0] ?? 0}
                              onChange={(e) =>
                                updatePoint(
                                  ringIndex,
                                  pointIndex,
                                  0,
                                  e.target.value,
                                )
                              }
                              placeholder="0.0"
                              disabled={
                                disabled || (isLastPoint && isFirstPoint)
                              }
                              className="h-8 text-[12px]"
                            />
                          </div>
                          <span className="pb-2 font-mono text-[12px] text-muted-foreground">
                            ,
                          </span>
                          <div className="flex-1 space-y-1">
                            <Label className="text-[10px] text-muted-foreground">
                              {t('Lat')}
                            </Label>
                            <Input
                              type="number"
                              step="any"
                              value={point[1] ?? 0}
                              onChange={(e) =>
                                updatePoint(
                                  ringIndex,
                                  pointIndex,
                                  1,
                                  e.target.value,
                                )
                              }
                              placeholder="0.0"
                              disabled={
                                disabled || (isLastPoint && isFirstPoint)
                              }
                              className="h-8 text-[12px]"
                            />
                          </div>
                          <span className="pb-2 font-mono text-[12px] text-muted-foreground">
                            ]
                            {pointIndex < ring.length - 1 ? ',' : ''}
                          </span>
                        </div>
                        {isLastPoint && isFirstPoint ? (
                          <div className="flex items-center px-2">
                            <span className="text-[10px] text-muted-foreground">
                              {t('(closed)')}
                            </span>
                          </div>
                        ) : (
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7 shrink-0"
                            onClick={() =>
                              removePointFromRing(ringIndex, pointIndex)
                            }
                            disabled={disabled || ring.length <= 3}
                            aria-label={t('Remove point')}
                          >
                            <X className="h-3.5 w-3.5" />
                          </Button>
                        )}
                      </div>
                    )
                  })}
                  <div className="font-mono text-[12px] text-muted-foreground">
                    ]
                  </div>
                  {ring.length < 3 && (
                    <p className="text-[10px] text-amber-600 dark:text-amber-400">
                      {t('Ring must have at least 3 points')}
                    </p>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
      {!isRequired && showNullCheckbox && (
        <div className="flex items-center gap-1.5 pt-2 border-t border-border">
          <Checkbox
            id="polygon-null"
            checked={value === null}
            onCheckedChange={(checked) => {
              onChange(
                checked
                  ? null
                  : [
                      [
                        [0, 0],
                        [0, 0],
                        [0, 0],
                        [0, 0],
                      ],
                    ],
              )
            }}
            disabled={disabled}
            className="h-4 w-4"
          />
          <label
            htmlFor="polygon-null"
            className="text-[11px] text-muted-foreground cursor-pointer select-none"
          >
            {t('Set to NULL')}
          </label>
        </div>
      )}
    </div>
  )
}
