import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { InputTags } from '@/components/ui/input-tags'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { DistributionPlatform } from '@/lib/appwrite/distribution'
import {
  useAllDistributionConnections,
  useCreateDistributionAndroidSigningIdentity,
  useCreateDistributionIosSigningIdentity,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CredentialField } from './CredentialField'
import { PLATFORMS, PlatformIcon, platformLabel } from './platform'

const DEFAULT_NAMES: Record<DistributionPlatform, string> = {
  ios: 'Managed by Appwrite',
  android: 'Upload key',
}

interface CreateSigningIdentityProps {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CreateSigningIdentity({
  projectId,
  open,
  onOpenChange,
}: CreateSigningIdentityProps) {
  const t = useT()
  const createIos = useCreateDistributionIosSigningIdentity(projectId)
  const createAndroid = useCreateDistributionAndroidSigningIdentity(projectId)
  const pending = createIos.isPending || createAndroid.isPending

  const handleOpenChange = (next: boolean) => {
    if (pending) return
    if (!next) {
      createIos.reset()
      createAndroid.reset()
    }
    onOpenChange(next)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Generate signing identity')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Appwrite creates the signing material, keeps it encrypted, and renews what it can before it expires.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <Form
          projectId={projectId}
          createIos={createIos}
          createAndroid={createAndroid}
          onCancel={() => handleOpenChange(false)}
          onDone={() => {
            createIos.reset()
            createAndroid.reset()
            onOpenChange(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function Form({
  projectId,
  createIos,
  createAndroid,
  onCancel,
  onDone,
}: {
  projectId: string
  createIos: ReturnType<typeof useCreateDistributionIosSigningIdentity>
  createAndroid: ReturnType<typeof useCreateDistributionAndroidSigningIdentity>
  onCancel: () => void
  onDone: () => void
}) {
  const t = useT()
  const [platform, setPlatform] = useState<DistributionPlatform>('android')
  const [name, setName] = useState(DEFAULT_NAMES.android)
  const [connectionId, setConnectionId] = useState('')
  const [identifiers, setIdentifiers] = useState<string[]>([])
  const [submitted, setSubmitted] = useState(false)
  const {
    connections,
    isLoading,
    error: connectionsError,
  } = useAllDistributionConnections(projectId, 'apple')

  const ios = platform === 'ios'
  const create = ios ? createIos : createAndroid
  const selectedConnection =
    connectionId || (connections.length === 1 ? connections[0].$id : '')
  const missingConnection =
    ios && !isLoading && !connectionsError && connections.length === 0
  const blocked = missingConnection || (ios && !!connectionsError)

  const errors = {
    name: name.trim() ? null : t('Enter a name.'),
    connection:
      ios && !selectedConnection
        ? t('Choose an App Store Connect connection.')
        : null,
    identifiers:
      ios && identifiers.length === 0 ? t('Add at least one bundle ID.') : null,
  }
  const valid = Object.values(errors).every((error) => error === null)
  const shown = (error: string | null) => (submitted ? error : null)

  const choosePlatform = (next: DistributionPlatform) => {
    if (name === DEFAULT_NAMES[platform]) setName(DEFAULT_NAMES[next])
    setPlatform(next)
    setSubmitted(false)
    createIos.reset()
    createAndroid.reset()
  }

  const onSuccess = () => {
    toast.success(t('Signing identity generated'))
    onDone()
  }

  const handleGenerate = () => {
    setSubmitted(true)
    if (!valid) return
    if (ios) {
      createIos.mutate(
        {
          name: name.trim(),
          connectionId: selectedConnection,
          identifiers,
        },
        { onSuccess },
      )
      return
    }
    createAndroid.mutate({ name: name.trim() }, { onSuccess })
  }

  return (
    <>
      <div className="px-6 py-4 space-y-4">
        <RadioGroup
          value={platform}
          onValueChange={(value) => {
            const next = PLATFORMS.find((option) => option === value)
            if (next) choosePlatform(next)
          }}
          aria-label={t('Platform')}
          className="grid-cols-2 gap-2"
        >
          {PLATFORMS.map((option) => (
            <label
              key={option}
              htmlFor={`generate-signing-platform-${option}`}
              className={cn(
                'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-[13px] font-medium transition-colors',
                platform === option
                  ? 'border-foreground bg-muted/50'
                  : 'border-border hover:bg-muted/30',
              )}
            >
              <RadioGroupItem
                id={`generate-signing-platform-${option}`}
                value={option}
              />
              <PlatformIcon platform={option} />
              {platformLabel(option)}
            </label>
          ))}
        </RadioGroup>

        <p className="rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[12px] text-muted-foreground">
          {ios
            ? t(
                'Appwrite creates an Apple Distribution certificate through your App Store Connect connection, then an App Store provisioning profile for each bundle ID. This needs a team key with the Admin role. Appwrite keeps one managed certificate per Apple team.',
              )
            : t(
                'Appwrite generates an RSA 2048 upload key with a certificate valid for 30 years. For a new app, the first bundle signed with it becomes the upload key in Google Play. For an existing app, download upload-certificate.pem afterwards and request an upload key reset in Play Console.',
              )}
        </p>

        <CredentialField
          id="generate-signing-name"
          label={t('Name')}
          error={shown(errors.name)}
        >
          <Input
            id="generate-signing-name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            aria-invalid={shown(errors.name) ? true : undefined}
            autoComplete="off"
          />
        </CredentialField>

        {ios ? (
          connectionsError ? (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
            >
              {getErrorMessage(
                connectionsError,
                t('Failed to load connections'),
              )}
            </p>
          ) : missingConnection ? (
            <div className="rounded-lg border border-dashed border-border px-3 py-3 text-[12px] text-muted-foreground">
              <p>
                {t(
                  'Managed iOS signing needs an App Store Connect connection. Connect one first.',
                )}
              </p>
              <Button
                asChild
                variant="outline"
                size="sm"
                className="mt-2 h-8 text-[13px]"
              >
                <Link
                  to="/projects/$projectId/distribution/connections"
                  params={{ projectId }}
                >
                  {t('Go to connections')}
                </Link>
              </Button>
            </div>
          ) : (
            <>
              <CredentialField
                id="generate-signing-connection"
                label={t('App Store Connect connection')}
                error={shown(errors.connection)}
              >
                <Select
                  value={selectedConnection}
                  onValueChange={setConnectionId}
                >
                  <SelectTrigger
                    id="generate-signing-connection"
                    className="h-9 w-full text-[13px]"
                    aria-invalid={shown(errors.connection) ? true : undefined}
                  >
                    <SelectValue placeholder={t('Choose a connection')} />
                  </SelectTrigger>
                  <SelectContent>
                    {connections.map((connection) => (
                      <SelectItem
                        key={connection.$id}
                        value={connection.$id}
                        className="text-[13px]"
                      >
                        {connection.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </CredentialField>
              <CredentialField
                id="generate-signing-identifiers"
                label={t('Bundle IDs')}
                error={shown(errors.identifiers)}
                hint={t(
                  'Press Enter after each bundle ID. Include the IDs of app extensions.',
                )}
              >
                <InputTags
                  id="generate-signing-identifiers"
                  value={identifiers}
                  onChange={setIdentifiers}
                  placeholder="com.example.app"
                  splitOnComma
                />
              </CredentialField>
            </>
          )
        ) : null}

        {create.error ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
          >
            {getErrorMessage(
              create.error,
              t('The signing identity was not generated.'),
            )}
          </p>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button
          variant="outline"
          onClick={onCancel}
          disabled={create.isPending}
        >
          {t('Cancel')}
        </Button>
        <Button onClick={handleGenerate} disabled={create.isPending || blocked}>
          {t('Generate')}
        </Button>
      </div>
    </>
  )
}
