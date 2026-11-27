import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type {
  DistributionConnection,
  DistributionPlatform,
  DistributionSigningIdentity,
} from '@/lib/appwrite/distribution'
import {
  useAllDistributionConnections,
  useAllDistributionSigningIdentities,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { PLATFORM_PROVIDERS } from './platform'
import {
  ConnectionStatusBadge,
  SigningIdentityStatusBadge,
} from './status-badges'
import { NONE, TRACKS, trackLabel } from './target-options'

type ReferenceFieldProps<T> = {
  id: string
  projectId: string
  platform: DistributionPlatform
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  initialOptions?: T[]
}

type Option = {
  id: string
  label: string
  badge?: ReactNode
}

function withCurrent(options: Option[], value: string): Option[] {
  if (!value || options.some((option) => option.id === value)) return options
  return [...options, { id: value, label: value }]
}

function ReferenceSelect({
  id,
  label,
  value,
  onChange,
  disabled,
  options,
  emptyMessage,
  manageLabel,
  manageTo,
  projectId,
}: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  options: Option[]
  emptyMessage: string
  manageLabel: string
  manageTo:
    | '/projects/$projectId/distribution/connections'
    | '/projects/$projectId/distribution/signing'
  projectId: string
}) {
  const t = useT()
  const choices = withCurrent(options, value)
  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <Label htmlFor={id} className="text-[13px]">
          {label}
        </Label>
        <Link
          to={manageTo}
          params={{ projectId }}
          className="text-[12px] text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
        >
          {manageLabel}
        </Link>
      </div>
      <Select
        value={value || NONE}
        onValueChange={(next) => onChange(next === NONE ? '' : next)}
        disabled={disabled}
      >
        <SelectTrigger id={id} className="h-9 w-full text-[13px]">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NONE} className="text-[13px]">
            {t('None')}
          </SelectItem>
          {choices.map((option) => (
            <SelectItem
              key={option.id}
              value={option.id}
              className="text-[13px]"
            >
              <span className="truncate">{option.label}</span>
              {option.badge}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {options.length === 0 ? (
        <p className="text-[12px] text-muted-foreground">{emptyMessage}</p>
      ) : null}
    </div>
  )
}

export function ConnectionField({
  id,
  projectId,
  platform,
  value,
  onChange,
  disabled,
  initialOptions,
}: ReferenceFieldProps<DistributionConnection>) {
  const t = useT()
  const provider = PLATFORM_PROVIDERS[platform]
  const { connections, isLoading } = useAllDistributionConnections(
    projectId,
    provider,
  )
  const available = isLoading && initialOptions ? initialOptions : connections
  const options = available
    .filter((connection) => connection.provider === provider)
    .map((connection) => ({
      id: connection.$id,
      label: connection.name,
      badge:
        connection.status === 'active' ? undefined : (
          <ConnectionStatusBadge status={connection.status} />
        ),
    }))
  return (
    <ReferenceSelect
      id={id}
      label={t('Store connection')}
      value={value}
      onChange={onChange}
      disabled={disabled}
      options={options}
      emptyMessage={t('No connections for this store yet.')}
      manageLabel={t('Manage connections')}
      manageTo="/projects/$projectId/distribution/connections"
      projectId={projectId}
    />
  )
}

export function SigningIdentityField({
  id,
  projectId,
  platform,
  value,
  onChange,
  disabled,
  initialOptions,
}: ReferenceFieldProps<DistributionSigningIdentity>) {
  const t = useT()
  const { signingIdentities, isLoading } = useAllDistributionSigningIdentities(
    projectId,
    platform,
  )
  const available =
    isLoading && initialOptions ? initialOptions : signingIdentities
  const options = available
    .filter((identity) => identity.platform === platform)
    .map((identity) => ({
      id: identity.$id,
      label: identity.name,
      badge:
        identity.status === 'active' ? undefined : (
          <SigningIdentityStatusBadge status={identity.status} />
        ),
    }))
  return (
    <ReferenceSelect
      id={id}
      label={t('Signing identity')}
      value={value}
      onChange={onChange}
      disabled={disabled}
      options={options}
      emptyMessage={t('No signing identities for this platform yet.')}
      manageLabel={t('Manage signing')}
      manageTo="/projects/$projectId/distribution/signing"
      projectId={projectId}
    />
  )
}

export function TrackField({
  id,
  platform,
  value,
  onChange,
  disabled,
}: {
  id: string
  platform: DistributionPlatform
  value: string
  onChange: (value: string) => void
  disabled?: boolean
}) {
  const t = useT()
  const tracks = TRACKS[PLATFORM_PROVIDERS[platform]]
  const choices = value && !tracks.includes(value) ? [...tracks, value] : tracks
  return (
    <div className="space-y-2">
      <Label htmlFor={id} className="text-[13px]">
        {t('Track')}
      </Label>
      <Select
        value={value || NONE}
        onValueChange={(next) => onChange(next === NONE ? '' : next)}
        disabled={disabled}
      >
        <SelectTrigger id={id} className="h-9 w-full text-[13px]">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NONE} className="text-[13px]">
            {t('None')}
          </SelectItem>
          {choices.map((track) => (
            <SelectItem key={track} value={track} className="text-[13px]">
              {t(trackLabel(track))}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}
