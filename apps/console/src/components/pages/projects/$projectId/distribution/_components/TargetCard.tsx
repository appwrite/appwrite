import { useState, type ReactNode } from 'react'
import { Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import type {
  DistributionConnection,
  DistributionSigningIdentity,
  DistributionTarget,
  DistributionTargetConfiguration,
  DistributionTargetSettingsParams,
  DistributionVersionStrategy,
} from '@/lib/appwrite/distribution'
import {
  identifierHint,
  identifierLabel,
  isValidIdentifier,
} from '@/lib/distribution/identifiers'
import { useT } from '@/lib/i18n/translate'
import {
  useDeleteDistributionTarget,
  useUpdateDistributionTarget,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PlatformIcon } from './platform'
import {
  ConnectionField,
  SigningIdentityField,
  TrackField,
} from './TargetFields'
import {
  CONFIGURATION_FIELDS,
  NONE,
  SPECIFICATIONS,
  TARGET_TITLES,
  VERSION_STRATEGIES,
  VERSION_STRATEGY_LABELS,
  specificationLabel,
  type ConfigurationKey,
} from './target-options'

type TargetForm = {
  identifier: string
  connectionId: string
  signingIdentityId: string
  track: string
  automatic: boolean
  versionStrategy: DistributionVersionStrategy
  installCommand: string
  buildCommand: string
  outputPath: string
  specification: string
  timeout: string
  configuration: Partial<Record<ConfigurationKey, string>>
}

type TextField =
  | 'identifier'
  | 'installCommand'
  | 'buildCommand'
  | 'outputPath'
  | 'specification'
  | 'connectionId'
  | 'signingIdentityId'
  | 'track'

const TEXT_FIELDS: readonly TextField[] = [
  'identifier',
  'connectionId',
  'signingIdentityId',
  'track',
  'installCommand',
  'buildCommand',
  'outputPath',
  'specification',
]

function toForm(target: DistributionTarget): TargetForm {
  return {
    identifier: target.identifier,
    connectionId: target.connectionId,
    signingIdentityId: target.signingIdentityId,
    track: target.track,
    automatic: target.automatic,
    versionStrategy: target.versionStrategy,
    installCommand: target.installCommand,
    buildCommand: target.buildCommand,
    outputPath: target.outputPath,
    specification: target.specification,
    timeout: String(target.timeout),
    configuration: Object.fromEntries(
      CONFIGURATION_FIELDS[target.platform].map(({ key }) => [
        key,
        target.configuration[key] ?? '',
      ]),
    ),
  }
}

function toConfiguration(
  target: DistributionTarget,
  form: TargetForm,
): DistributionTargetConfiguration | undefined {
  const changed = CONFIGURATION_FIELDS[target.platform].some(
    ({ key }) =>
      (form.configuration[key] ?? '').trim() !==
      (target.configuration[key] ?? ''),
  )
  if (!changed) return undefined
  const merged: Record<string, string | undefined> = {
    ...target.configuration,
  }
  for (const { key } of CONFIGURATION_FIELDS[target.platform]) {
    merged[key] = (form.configuration[key] ?? '').trim()
  }
  return Object.fromEntries(
    Object.entries(merged).filter(([, value]) => !!value),
  )
}

function toChanges(
  target: DistributionTarget,
  form: TargetForm,
): DistributionTargetSettingsParams {
  const changes: DistributionTargetSettingsParams = {}
  for (const field of TEXT_FIELDS) {
    if (form[field] === target[field]) continue
    const value = form[field].trim()
    if (value !== target[field]) changes[field] = value
  }
  if (form.automatic !== target.automatic) changes.automatic = form.automatic
  if (form.versionStrategy !== target.versionStrategy) {
    changes.versionStrategy = form.versionStrategy
  }
  const timeout = Number(form.timeout)
  if (timeout !== target.timeout) changes.timeout = timeout
  const configuration = toConfiguration(target, form)
  if (configuration) changes.configuration = configuration
  return changes
}

function isValidTimeout(value: string): boolean {
  const timeout = Number(value)
  return value.trim() !== '' && Number.isInteger(timeout) && timeout >= 0
}

type TargetCardProps = {
  projectId: string
  target: DistributionTarget
  canEdit: boolean
  initialConnections?: DistributionConnection[]
  initialSigningIdentities?: DistributionSigningIdentity[]
}

export function TargetCard({
  projectId,
  target,
  canEdit,
  initialConnections,
  initialSigningIdentities,
}: TargetCardProps) {
  const t = useT()
  const [form, setForm] = useSyncStateFromServer(toForm(target))
  const [removeOpen, setRemoveOpen] = useState(false)
  const updateTarget = useUpdateDistributionTarget(projectId)
  const deleteTarget = useDeleteDistributionTarget(projectId)

  const prefix = `target-${target.platform}`
  const disabled = !canEdit || updateTarget.isPending
  const changes = toChanges(target, form)
  const hasChanges = Object.keys(changes).length > 0
  const identifierValid =
    changes.identifier === undefined ||
    isValidIdentifier(target.platform, changes.identifier)
  const timeoutValid = isValidTimeout(form.timeout)
  const permissionMessage = t(
    "You don't have permission to perform this action.",
  )

  const setField = <K extends keyof TargetForm>(key: K, value: TargetForm[K]) =>
    setForm((current) => ({ ...current, [key]: value }))

  const setConfiguration = (key: ConfigurationKey, value: string) =>
    setForm((current) => ({
      ...current,
      configuration: { ...current.configuration, [key]: value },
    }))

  const handleUpdate = () => {
    updateTarget.mutate(
      { appId: target.appId, targetId: target.$id, ...changes },
      {
        onSuccess: () => toast.success(t('Target updated')),
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to update target'))),
      },
    )
  }

  const handleRemove = () => {
    deleteTarget.mutate(
      { appId: target.appId, targetId: target.$id },
      {
        onSuccess: () => {
          setRemoveOpen(false)
          toast.success(t('Target removed'))
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to remove target'))),
      },
    )
  }

  return (
    <section
      aria-labelledby={`${prefix}-title`}
      data-testid={`${prefix}-card`}
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="flex items-start justify-between gap-4 px-6 py-4">
        <div className="min-w-0">
          <h3
            id={`${prefix}-title`}
            className="flex items-center gap-2 text-[15px] font-semibold text-foreground"
          >
            <PlatformIcon platform={target.platform} />
            {t(TARGET_TITLES[target.platform])}
          </h3>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <CopyableId id={target.$id} size="xs" />
          </div>
        </div>
        <PermissionTooltip enabled={!canEdit} message={permissionMessage}>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-foreground"
            aria-label={t('Remove target')}
            disabled={!canEdit}
            onClick={() => setRemoveOpen(true)}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </PermissionTooltip>
      </div>

      <div className="border-t border-border" />
      <Section
        title={t('Store and signing')}
        description={t(
          'Where builds of this target are submitted and how they are signed.',
        )}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor={`${prefix}-identifier`} className="text-[13px]">
              {t(identifierLabel(target.platform))}
            </Label>
            <Input
              id={`${prefix}-identifier`}
              dir="ltr"
              value={form.identifier}
              onChange={(event) => setField('identifier', event.target.value)}
              placeholder="com.example.app"
              spellCheck={false}
              autoCapitalize="off"
              autoCorrect="off"
              autoComplete="off"
              disabled={disabled}
              aria-invalid={!identifierValid}
              aria-describedby={
                identifierValid ? undefined : `${prefix}-identifier-error`
              }
              className={cn(
                'h-9 font-mono text-[13px]',
                !identifierValid && 'border-destructive',
              )}
            />
            {identifierValid ? null : (
              <p
                id={`${prefix}-identifier-error`}
                className="text-[12px] text-destructive"
              >
                {t(identifierHint(target.platform))}
              </p>
            )}
          </div>
          {target.platform === 'ios' ? (
            <div className="space-y-2">
              <Label htmlFor={`${prefix}-store-app-id`} className="text-[13px]">
                {t('App Store app ID')}
              </Label>
              <Input
                id={`${prefix}-store-app-id`}
                dir="ltr"
                value={target.storeAppId}
                placeholder={t('Resolved by preflight')}
                readOnly
                disabled
                className="h-9 font-mono text-[13px]"
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Resolved by preflight from the bundle ID.')}
              </p>
            </div>
          ) : null}
          <ConnectionField
            id={`${prefix}-connection`}
            projectId={projectId}
            platform={target.platform}
            value={form.connectionId}
            onChange={(value) => setField('connectionId', value)}
            disabled={disabled}
            initialOptions={initialConnections}
          />
          <SigningIdentityField
            id={`${prefix}-signing-identity`}
            projectId={projectId}
            platform={target.platform}
            value={form.signingIdentityId}
            onChange={(value) => setField('signingIdentityId', value)}
            disabled={disabled}
            initialOptions={initialSigningIdentities}
          />
        </div>
      </Section>

      <div className="border-t border-border" />
      <Section
        title={t('Release')}
        description={t('How builds of this target are numbered and submitted.')}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <TrackField
            id={`${prefix}-track`}
            platform={target.platform}
            value={form.track}
            onChange={(value) => setField('track', value)}
            disabled={disabled}
          />
          <div className="space-y-2">
            <Label
              htmlFor={`${prefix}-version-strategy`}
              className="text-[13px]"
            >
              {t('Version strategy')}
            </Label>
            <Select
              value={form.versionStrategy}
              onValueChange={(value) =>
                setField(
                  'versionStrategy',
                  value as DistributionVersionStrategy,
                )
              }
              disabled={disabled}
            >
              <SelectTrigger
                id={`${prefix}-version-strategy`}
                className="h-9 w-full text-[13px]"
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {VERSION_STRATEGIES.map((strategy) => (
                  <SelectItem
                    key={strategy}
                    value={strategy}
                    className="text-[13px]"
                  >
                    {t(VERSION_STRATEGY_LABELS[strategy])}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-[12px] text-muted-foreground">
              {form.versionStrategy === 'automatic'
                ? t('Appwrite assigns the next build number to every build.')
                : t('Builds use the build number from your project.')}{' '}
              {t('Last build number:')} {target.versionCode}
            </p>
          </div>
        </div>
        <div className="mt-4 flex items-start justify-between gap-4 rounded-lg border border-border px-4 py-3">
          <div className="min-w-0">
            <Label htmlFor={`${prefix}-automatic`} className="text-[13px]">
              {t('Automatic submission')}
            </Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {t(
                'Submit every successful Git build of this target to the selected track.',
              )}
            </p>
          </div>
          <Switch
            id={`${prefix}-automatic`}
            checked={form.automatic}
            onCheckedChange={(checked) => setField('automatic', checked)}
            disabled={disabled}
          />
        </div>
      </Section>

      <div className="border-t border-border" />
      <Section
        title={t('Build')}
        description={t(
          'Override the framework defaults. Leave a field empty to use the default.',
        )}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <TextInput
            id={`${prefix}-install-command`}
            label={t('Install command')}
            value={form.installCommand}
            onChange={(value) => setField('installCommand', value)}
            placeholder={t('Framework default')}
            disabled={disabled}
          />
          <TextInput
            id={`${prefix}-build-command`}
            label={t('Build command')}
            value={form.buildCommand}
            onChange={(value) => setField('buildCommand', value)}
            placeholder={t('Framework default')}
            disabled={disabled}
          />
          <TextInput
            id={`${prefix}-output-path`}
            label={t('Output path')}
            value={form.outputPath}
            onChange={(value) => setField('outputPath', value)}
            placeholder={t('Framework default')}
            disabled={disabled}
          />
          {CONFIGURATION_FIELDS[target.platform].map((field) => (
            <TextInput
              key={field.key}
              id={`${prefix}-${field.key}`}
              label={t(field.label)}
              value={form.configuration[field.key] ?? ''}
              onChange={(value) => setConfiguration(field.key, value)}
              placeholder={field.placeholder}
              disabled={disabled}
            />
          ))}
          <div className="space-y-2">
            <Label htmlFor={`${prefix}-specification`} className="text-[13px]">
              {t('Specification')}
            </Label>
            <Select
              value={form.specification || NONE}
              onValueChange={(value) =>
                setField('specification', value === NONE ? '' : value)
              }
              disabled={disabled}
            >
              <SelectTrigger
                id={`${prefix}-specification`}
                className="h-9 w-full text-[13px]"
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE} className="text-[13px]">
                  {t('Default')}
                </SelectItem>
                {withSpecification(form.specification).map((specification) => (
                  <SelectItem
                    key={specification}
                    value={specification}
                    className="text-[13px]"
                  >
                    {t(specificationLabel(specification))}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor={`${prefix}-timeout`} className="text-[13px]">
              {t('Build timeout (seconds)')}
            </Label>
            <Input
              id={`${prefix}-timeout`}
              type="number"
              min={0}
              step={1}
              value={form.timeout}
              onChange={(event) => setField('timeout', event.target.value)}
              disabled={disabled}
              aria-invalid={!timeoutValid}
              className="h-9 text-[13px]"
            />
            <p className="text-[12px] text-muted-foreground">
              {t('Use 0 for the default timeout.')}
            </p>
          </div>
        </div>
      </Section>

      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <PermissionTooltip enabled={!canEdit} message={permissionMessage}>
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={
              disabled || !hasChanges || !identifierValid || !timeoutValid
            }
            onClick={handleUpdate}
          >
            {t('Update')}
          </Button>
        </PermissionTooltip>
      </div>

      <ConfirmActionDialog
        open={removeOpen}
        onOpenChange={setRemoveOpen}
        title={t('Remove target')}
        description={t(
          'Builds and submissions for this platform stop until you add the target again. This action cannot be undone.',
        )}
        confirmLabel={t('Remove')}
        confirmVariant="destructive"
        onConfirm={handleRemove}
        isConfirming={deleteTarget.isPending}
      />
    </section>
  )
}

function withSpecification(current: string): readonly string[] {
  return current && !SPECIFICATIONS.includes(current)
    ? [...SPECIFICATIONS, current]
    : SPECIFICATIONS
}

function Section({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children: ReactNode
}) {
  return (
    <div className="px-6 py-4">
      <h4 className="text-[13px] font-semibold text-foreground">{title}</h4>
      <p className="mt-1 mb-4 text-[12px] text-muted-foreground">
        {description}
      </p>
      {children}
    </div>
  )
}

function TextInput({
  id,
  label,
  value,
  onChange,
  placeholder,
  disabled,
}: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  placeholder: string
  disabled: boolean
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id} className="text-[13px]">
        {label}
      </Label>
      <Input
        id={id}
        dir="ltr"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        disabled={disabled}
        className="h-9 font-mono text-[13px]"
      />
    </div>
  )
}

function PermissionTooltip({
  enabled,
  message,
  children,
}: {
  enabled: boolean
  message: string
  children: ReactNode
}) {
  if (!enabled) return children
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{children}</span>
      </TooltipTrigger>
      <TooltipContent>{message}</TooltipContent>
    </Tooltip>
  )
}
