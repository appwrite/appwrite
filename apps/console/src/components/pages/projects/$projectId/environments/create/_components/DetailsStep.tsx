import { IdInput } from '@/components/ui/id-input'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { PROJECT_NAME_MAX_LENGTH } from '@/lib/react-query/hooks'
import { PROJECT_ID_MAX_LENGTH } from '@/lib/project-id'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type EnvironmentStartFrom = 'empty' | 'project'

interface DetailsStepProps {
  name: string
  onNameChange: (name: string) => void
  nameError?: string
  environmentId: string | undefined
  onEnvironmentIdChange: (environmentId: string | undefined) => void
  startFrom: EnvironmentStartFrom
  onStartFromChange: (startFrom: EnvironmentStartFrom) => void
  disabled: boolean
}

function StartFromOption({
  value,
  selected,
  title,
  description,
  onSelect,
}: {
  value: EnvironmentStartFrom
  selected: boolean
  title: string
  description: string
  onSelect: () => void
}) {
  const id = `environment-start-${value}`
  return (
    <Label
      htmlFor={id}
      onClick={onSelect}
      className={cn(
        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
        selected
          ? 'border-primary/50 bg-muted/50'
          : 'border-border bg-card/50 hover:bg-muted/30',
      )}
    >
      <RadioGroupItem value={value} id={id} className="mt-0.5 shrink-0" />
      <span className="min-w-0 flex-1">
        <span className="block text-[13px] font-semibold text-foreground">
          {title}
        </span>
        {/* Breathing room between the title and its description, as the copy groups have */}
        <span className="mt-1 block text-[12px] leading-snug text-muted-foreground">
          {description}
        </span>
      </span>
    </Label>
  )
}

/** Name, id and where the environment starts from. */
export function DetailsStep({
  name,
  onNameChange,
  nameError,
  environmentId,
  onEnvironmentIdChange,
  startFrom,
  onStartFromChange,
  disabled,
}: DetailsStepProps) {
  const t = useT()

  return (
    <div className="space-y-5">
      <div className="space-y-2">
        <Label htmlFor="environment-name">
          {t('Name')} <span className="text-destructive">*</span>
        </Label>
        <Input
          id="environment-name"
          type="text"
          placeholder={t('Enter environment name')}
          value={name}
          onChange={(event) => onNameChange(event.target.value)}
          disabled={disabled}
          maxLength={PROJECT_NAME_MAX_LENGTH}
          className={nameError ? 'border-destructive' : ''}
        />
        {nameError && (
          <p className="text-[12px] text-destructive">{nameError}</p>
        )}
      </div>

      <div className="space-y-2">
        <Label htmlFor="environment-id">{t('Environment ID')}</Label>
        <IdInput
          id="environment-id"
          value={environmentId}
          onChange={onEnvironmentIdChange}
          idFormat="project"
          maxLength={PROJECT_ID_MAX_LENGTH}
          disabled={disabled}
          placeholder={t('Leave blank to auto-generate')}
        />
      </div>

      <div className="space-y-2">
        <Label>{t('Start from')}</Label>
        <RadioGroup
          value={startFrom}
          onValueChange={(value) =>
            onStartFromChange(value as EnvironmentStartFrom)
          }
          className="grid gap-2"
          disabled={disabled}
        >
          <StartFromOption
            value="empty"
            selected={startFrom === 'empty'}
            title={t('Empty environment')}
            description={t('Nothing is copied from the project.')}
            onSelect={() => onStartFromChange('empty')}
          />
          <StartFromOption
            value="project"
            selected={startFrom === 'project'}
            title={t('Copy of the project')}
            description={t(
              'Structure and settings are copied, data only where you ask for it. The copy runs after the environment is created and keeps the IDs of what it copies.',
            )}
            onSelect={() => onStartFromChange('project')}
          />
        </RadioGroup>
      </div>
    </div>
  )
}
