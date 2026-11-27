import { useState } from 'react'
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
import { useCreateDistributionIosSigningIdentity } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CredentialField } from './CredentialField'
import { CredentialFile } from './CredentialFile'
import type { CredentialFileValue } from './CredentialFile'

interface ImportIosSigningIdentityProps {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function ImportIosSigningIdentity({
  projectId,
  open,
  onOpenChange,
}: ImportIosSigningIdentityProps) {
  const t = useT()
  const create = useCreateDistributionIosSigningIdentity(projectId)

  const handleOpenChange = (next: boolean) => {
    if (create.isPending) return
    if (!next) create.reset()
    onOpenChange(next)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Import iOS signing identity')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Upload an Apple Distribution certificate with its private key and the App Store provisioning profiles that include it. Files are encrypted before they are stored.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <Form
          create={create}
          onCancel={() => handleOpenChange(false)}
          onDone={() => {
            create.reset()
            onOpenChange(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function Form({
  create,
  onCancel,
  onDone,
}: {
  create: ReturnType<typeof useCreateDistributionIosSigningIdentity>
  onCancel: () => void
  onDone: () => void
}) {
  const t = useT()
  const [name, setName] = useState('')
  const [certificate, setCertificate] = useState<CredentialFileValue[]>([])
  const [password, setPassword] = useState('')
  const [profiles, setProfiles] = useState<CredentialFileValue[]>([])
  const [submitted, setSubmitted] = useState(false)

  const errors = {
    name: name.trim() ? null : t('Enter a name.'),
    certificate: certificate.length > 0 ? null : t('Add the .p12 certificate.'),
    profiles:
      profiles.length > 0
        ? null
        : t('Add at least one App Store provisioning profile.'),
  }
  const valid = Object.values(errors).every((error) => error === null)
  const shown = (error: string | null) => (submitted ? error : null)

  const handleImport = () => {
    setSubmitted(true)
    if (!valid) return
    create.mutate(
      {
        name: name.trim(),
        certificate: certificate[0].content,
        password,
        profiles: profiles.map((profile) => profile.content),
      },
      {
        onSuccess: () => {
          toast.success(t('Signing identity imported'))
          onDone()
        },
      },
    )
  }

  return (
    <>
      <div className="px-6 py-4 space-y-4">
        <CredentialField
          id="ios-signing-name"
          label={t('Name')}
          error={shown(errors.name)}
        >
          <Input
            id="ios-signing-name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Apple Distribution 2026"
            aria-invalid={shown(errors.name) ? true : undefined}
            autoComplete="off"
          />
        </CredentialField>
        <CredentialFile
          id="ios-signing-certificate"
          label={t('Certificate (.p12)')}
          extensions={['.p12']}
          encoding="base64"
          files={certificate}
          onChange={setCertificate}
          error={shown(errors.certificate) ?? undefined}
          hint={t(
            'In Keychain Access, export the Apple Distribution certificate together with its private key.',
          )}
        />
        <CredentialField
          id="ios-signing-password"
          label={t('Certificate password')}
          hint={t('Leave empty if the .p12 file has no password.')}
        >
          <Input
            id="ios-signing-password"
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="new-password"
          />
        </CredentialField>
        <CredentialFile
          id="ios-signing-profiles"
          label={t('Provisioning profiles (.mobileprovision)')}
          extensions={['.mobileprovision']}
          encoding="base64"
          files={profiles}
          onChange={setProfiles}
          multiple
          error={shown(errors.profiles) ?? undefined}
          hint={t(
            'Add one App Store profile for each bundle ID, including app extensions. Each profile must contain this certificate.',
          )}
        />
        {create.error ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
          >
            {getErrorMessage(
              create.error,
              t('The signing identity was not imported.'),
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
        <Button onClick={handleImport} disabled={create.isPending}>
          {t('Import')}
        </Button>
      </div>
    </>
  )
}
