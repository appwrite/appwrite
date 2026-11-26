import { Info } from 'lucide-react'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

/** The select value for a classic Appwrite function (the API stores `''`). */
export const NO_FRAMEWORK = 'none'

/** The API value for a select value. */
export function frameworkParam(value: string): string {
  return value === NO_FRAMEWORK ? '' : value
}

/**
 * Picks the web framework a function runs, such as Express, or none for a
 * function written for the Appwrite runtime. With a framework, the app keeps its
 * own server: it listens on PORT, and events and schedules arrive as POST /.
 */
export function FrameworkSelect({
  frameworks,
  value,
  onChange,
  disabled,
}: {
  frameworks: Models.FunctionFramework[]
  value: string
  onChange: (value: string) => void
  disabled?: boolean
}) {
  const t = useT()
  const selected = frameworks.find((f) => f.key === value)

  if (frameworks.length === 0) return null

  return (
    <div className="space-y-2">
      <Label htmlFor="framework" className="text-[13px]">
        {t('Framework')}
      </Label>
      <Select value={value} onValueChange={onChange} disabled={disabled}>
        <SelectTrigger
          id="framework"
          className="h-9 text-[13px]"
          aria-label={t('Framework')}
        >
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NO_FRAMEWORK}>
            {t('None (Appwrite function)')}
          </SelectItem>
          {frameworks.map((framework) => (
            <SelectItem key={framework.key} value={framework.key}>
              {framework.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {selected ? (
        <p className="flex gap-1.5 text-[11px] text-muted-foreground">
          <Info className="mt-0.5 size-3 shrink-0" aria-hidden />
          <span>
            {t('Deploy your existing')} {selected.name}{' '}
            {t(
              'app as is. It must listen on process.env.PORT. Events and schedules arrive as POST /, with the x-appwrite-trigger header.',
            )}
          </span>
        </p>
      ) : null}
    </div>
  )
}
