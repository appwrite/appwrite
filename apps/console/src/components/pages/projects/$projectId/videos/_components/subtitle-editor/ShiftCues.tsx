import { useEffect, useState } from 'react'
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { useT } from '@/lib/i18n/translate'
import { parseTimestampInput } from '@/lib/videos/subtitle-cues'

function parseOffset(value: string): number | null {
  const trimmed = value.trim()
  const negative = trimmed.startsWith('-')
  const magnitude = parseTimestampInput(trimmed.replace(/^[+-]/, ''))
  if (magnitude == null) return null
  return negative ? -magnitude : magnitude
}

export function ShiftCues({
  open,
  onOpenChange,
  selectedCount,
  onApply,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  selectedCount: number
  onApply: (offset: number, scope: 'selected' | 'all') => void
}) {
  const t = useT()
  const [value, setValue] = useState('')
  const [scope, setScope] = useState<'selected' | 'all'>('all')

  useEffect(() => {
    if (!open) return
    setValue('')
    setScope(selectedCount > 1 ? 'selected' : 'all')
  }, [open, selectedCount])

  const offset = parseOffset(value)
  const valid = offset != null && offset !== 0

  const apply = () => {
    if (!valid) return
    onApply(offset, scope)
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Shift timings')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Move cues earlier or later to fix a track that is out of sync. Cues never move before 0:00.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <form
          className="space-y-4 px-6 pb-4 pt-4"
          onSubmit={(event) => {
            event.preventDefault()
            apply()
          }}
        >
          <div className="space-y-2">
            <Label htmlFor="subtitle-shift-offset" className="text-[13px]">
              {t('Offset')}
            </Label>
            <Input
              id="subtitle-shift-offset"
              value={value}
              autoFocus
              dir="ltr"
              inputMode="decimal"
              placeholder="-1.5"
              aria-invalid={value.length > 0 && offset == null}
              onChange={(event) => setValue(event.target.value)}
              className="h-9 font-mono text-[13px]"
            />
            <p className="text-[12px] text-muted-foreground">
              {t(
                'Seconds or a timestamp. Use a negative value to show cues earlier, e.g. -1.5 or -0:02.000.',
              )}
            </p>
          </div>
          <RadioGroup
            value={scope}
            onValueChange={(next) => setScope(next as 'selected' | 'all')}
            className="gap-2"
          >
            <label className="flex items-center gap-2 text-[13px]">
              <RadioGroupItem value="all" />
              {t('All cues')}
            </label>
            <label className="flex items-center gap-2 text-[13px]">
              <RadioGroupItem value="selected" disabled={selectedCount === 0} />
              {t('Selected cues')}
              <span className="tabular-nums text-muted-foreground">
                ({selectedCount})
              </span>
            </label>
          </RadioGroup>
        </form>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('Cancel')}
          </Button>
          <Button onClick={apply} disabled={!valid}>
            {t('Shift')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
