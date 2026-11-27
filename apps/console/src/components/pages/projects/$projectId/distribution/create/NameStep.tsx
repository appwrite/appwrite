import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useT } from '@/lib/i18n/translate'
import { StepSection } from './StepSection'

export const APP_NAME_MAX_LENGTH = 128

export function NameStep({
  name,
  onNameChange,
  disabled,
}: {
  name: string
  onNameChange: (name: string) => void
  disabled: boolean
}) {
  const t = useT()
  return (
    <StepSection
      title={t('Name your app')}
      description={t(
        'The name is shown in the Console. Each store keeps its own listing name.',
      )}
    >
      <div className="space-y-2">
        <Label htmlFor="distribution-app-name">
          {t('Name')} <span className="text-destructive">*</span>
        </Label>
        <Input
          id="distribution-app-name"
          value={name}
          onChange={(event) => onNameChange(event.target.value)}
          placeholder={t('My app')}
          maxLength={APP_NAME_MAX_LENGTH}
          disabled={disabled}
          autoComplete="off"
        />
      </div>
    </StepSection>
  )
}
