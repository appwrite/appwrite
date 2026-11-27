import { Plus, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  RELEASE_NOTES_LOCALE,
  noteRow,
  notesError,
  type NoteRow,
} from '@/lib/distribution/releases'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type ReleaseNotesFieldsProps = {
  id: string
  rows: NoteRow[]
  onChange: (rows: NoteRow[]) => void
  disabled?: boolean
}

export function ReleaseNotesFields({
  id,
  rows,
  onChange,
  disabled,
}: ReleaseNotesFieldsProps) {
  const t = useT()
  const error = notesError(rows)
  const update = (key: number, changes: Partial<NoteRow>) =>
    onChange(
      rows.map((row) => (row.key === key ? { ...row, ...changes } : row)),
    )
  return (
    <div className="space-y-2">
      <Label className="text-[13px]">{t('Release notes')}</Label>
      <p className="text-[12px] text-muted-foreground">
        {t(
          'What changed in this version, per store locale. The stores show these notes to users.',
        )}
      </p>
      <div className="space-y-3">
        {rows.map((row, index) => (
          <div
            key={row.key}
            className="flex items-start gap-2"
            data-testid={`${id}-note-${index}`}
          >
            <Input
              id={`${id}-locale-${index}`}
              aria-label={t('Locale')}
              dir="ltr"
              value={row.locale}
              onChange={(event) =>
                update(row.key, { locale: event.target.value })
              }
              placeholder={RELEASE_NOTES_LOCALE}
              spellCheck={false}
              autoCapitalize="off"
              autoCorrect="off"
              autoComplete="off"
              disabled={disabled}
              className={cn(
                'h-9 w-28 shrink-0 font-mono text-[13px]',
                row.text.trim() && !row.locale.trim() && 'border-destructive',
              )}
            />
            <Textarea
              id={`${id}-text-${index}`}
              aria-label={t('Notes')}
              value={row.text}
              onChange={(event) =>
                update(row.key, { text: event.target.value })
              }
              placeholder={t('Bug fixes and performance improvements.')}
              rows={3}
              disabled={disabled}
              className="min-h-9 flex-1 text-[13px]"
            />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-9 w-9 shrink-0 p-0 text-muted-foreground hover:text-foreground"
              aria-label={t('Remove notes')}
              onClick={() =>
                onChange(rows.filter((item) => item.key !== row.key))
              }
              disabled={disabled}
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
        ))}
      </div>
      {error ? (
        <p className="text-[12px] text-destructive">{t(error)}</p>
      ) : null}
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-8 gap-1.5 text-[13px]"
        onClick={() =>
          onChange([
            ...rows,
            noteRow(rows.length === 0 ? RELEASE_NOTES_LOCALE : '', ''),
          ])
        }
        disabled={disabled}
      >
        <Plus className="h-3.5 w-3.5" />
        {t('Add locale')}
      </Button>
    </div>
  )
}
