import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type OAuth2ClientType = 'confidential' | 'public'

const CLIENT_TYPE_OPTIONS: Array<{
  value: OAuth2ClientType
  title: string
  description: string
}> = [
  {
    value: 'confidential',
    title: 'Confidential',
    description: 'Backend or SSR apps that can keep a client secret (Node, Next.js, Nuxt).',
  },
  {
    value: 'public',
    title: 'Public',
    description:
      'Native or static web apps that cannot store a client secret (iOS, Android, SPA).',
  },
]

type OAuth2ClientTypePickerProps = {
  value: string
  onChange: (value: OAuth2ClientType) => void
  disabled?: boolean
}

export function OAuth2ClientTypePicker({
  value,
  onChange,
  disabled = false,
}: OAuth2ClientTypePickerProps) {
  const t = useT()
  const selected = value === 'public' ? 'public' : 'confidential'

  return (
    <div className="space-y-2">
      <Label className="text-[12px] font-medium">{t('Client type')}</Label>
      <RadioGroup
        value={selected}
        onValueChange={(next) => onChange(next as OAuth2ClientType)}
        disabled={disabled}
        className="grid grid-cols-1 gap-2 sm:grid-cols-2"
      >
        {CLIENT_TYPE_OPTIONS.map((option) => {
          const id = `oauth2-client-type-${option.value}`
          const isSelected = selected === option.value
          return (
            <Label
              key={option.value}
              htmlFor={id}
              className={cn(
                'flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 transition-colors',
                isSelected
                  ? 'border-primary bg-primary/5'
                  : 'border-border bg-card/50 hover:bg-muted/20',
                disabled && 'cursor-not-allowed opacity-60',
              )}
            >
              <RadioGroupItem
                value={option.value}
                id={id}
                className="mt-0.5 shrink-0"
                disabled={disabled}
              />
              <div className="min-w-0">
                <span className="block text-[13px] font-medium text-foreground">
                  {t(option.title)}
                </span>
                <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
                  {t(option.description)}
                </p>
              </div>
            </Label>
          )
        })}
      </RadioGroup>
    </div>
  )
}

export const OAUTH2_DEVICE_FLOW_DESCRIPTION =
  'For TVs, CLIs on a remote server, and other devices where typing a password is difficult.'
