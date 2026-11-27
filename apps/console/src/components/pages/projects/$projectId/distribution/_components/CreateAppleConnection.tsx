import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
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
import { useCreateDistributionAppleConnection } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ConnectionInstruction } from './ConnectionInstruction'
import { ConnectionSteps } from './ConnectionSteps'
import { ConsoleLink } from './ConsoleLink'
import { CredentialField } from './CredentialField'
import { CredentialFile } from './CredentialFile'
import type { CredentialFileValue } from './CredentialFile'
import {
  APP_STORE_CONNECT_KEYS_URL,
  appleIssuerIdError,
  appleKeyIdError,
  applePrivateKeyError,
} from '@/lib/distribution/credentials'

const PRIVATE_KEY_PLACEHOLDER =
  '-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----'

type Step = 'key' | 'details'

interface CreateAppleConnectionProps {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CreateAppleConnection({
  projectId,
  open,
  onOpenChange,
}: CreateAppleConnectionProps) {
  const t = useT()
  const create = useCreateDistributionAppleConnection(projectId)
  const [step, setStep] = useState<Step>('key')
  const [name, setName] = useState('App Store Connect')
  const [issuerId, setIssuerId] = useState('')
  const [keyId, setKeyId] = useState('')
  const [privateKey, setPrivateKey] = useState<CredentialFileValue[]>([])
  const [submitted, setSubmitted] = useState(false)

  const reset = () => {
    setStep('key')
    setName('App Store Connect')
    setIssuerId('')
    setKeyId('')
    setPrivateKey([])
    setSubmitted(false)
    create.reset()
  }

  const handleOpenChange = (next: boolean) => {
    if (create.isPending) return
    if (!next) reset()
    onOpenChange(next)
  }

  const translated = (error: string | null) => (error ? t(error) : null)
  const privateKeyText = privateKey[0]?.content ?? ''
  const errors = {
    name: name.trim() ? null : t('Enter a name.'),
    issuerId: translated(appleIssuerIdError(issuerId)),
    keyId: translated(appleKeyIdError(keyId)),
    privateKey: translated(applePrivateKeyError(privateKeyText)),
  }
  const valid = Object.values(errors).every((error) => error === null)
  const shown = (error: string | null) => (submitted ? error : null)

  const handleCreate = () => {
    setSubmitted(true)
    if (!valid) return
    create.mutate(
      {
        name: name.trim(),
        issuerId: issuerId.trim(),
        keyId: keyId.trim(),
        privateKey: privateKeyText.trim(),
      },
      {
        onSuccess: () => {
          toast.success(t('App Store Connect connected'))
          reset()
          onOpenChange(false)
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Connect App Store Connect')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Appwrite uses an App Store Connect API key to upload builds and manage signing. It never asks for your Apple ID or password.',
            )}
          </DialogDescription>
        </DialogHeader>
        <ConnectionSteps
          steps={[t('Create a team key'), t('Add the key')]}
          current={step === 'key' ? 0 : 1}
        />
        <div className="border-t border-border" />

        {step === 'key' ? (
          <div className="px-6 py-4 space-y-4">
            <ol className="space-y-3 text-[13px] text-foreground">
              <ConnectionInstruction
                index={1}
                title={t('The Account Holder must first request API access.')}
              >
                {t(
                  'Only the Account Holder can do this, once per team, in App Store Connect under Users and Access → Integrations.',
                )}
              </ConnectionInstruction>
              <ConnectionInstruction
                index={2}
                title={t('Generate a team key, not an individual key.')}
              >
                {t(
                  'Open Users and Access → Integrations → Team Keys and generate a key.',
                )}
              </ConnectionInstruction>
              <ConnectionInstruction
                index={3}
                title={t('Give the key the App Manager role.')}
              >
                {t(
                  'Choose Admin instead if Appwrite should create your certificates and provisioning profiles. A key role cannot be changed later.',
                )}
              </ConnectionInstruction>
              <ConnectionInstruction
                index={4}
                title={t(
                  'Download the .p8 file and note the key ID and issuer ID.',
                )}
              >
                {t('Apple lets you download a key only once.')}
              </ConnectionInstruction>
            </ol>
            <ConsoleLink
              href={APP_STORE_CONNECT_KEYS_URL}
              label={t('Open App Store Connect')}
            />
          </div>
        ) : (
          <div className="px-6 py-4 space-y-4">
            <CredentialField
              id="apple-connection-name"
              label={t('Name')}
              error={shown(errors.name)}
            >
              <Input
                id="apple-connection-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                aria-invalid={shown(errors.name) ? true : undefined}
                autoComplete="off"
              />
            </CredentialField>
            <CredentialField
              id="apple-connection-issuer"
              label={t('Issuer ID')}
              error={shown(errors.issuerId)}
              hint={t('Shown above the list of team keys.')}
            >
              <Input
                id="apple-connection-issuer"
                dir="ltr"
                value={issuerId}
                onChange={(event) => setIssuerId(event.target.value)}
                placeholder="69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1"
                className="font-mono text-[13px]"
                aria-invalid={shown(errors.issuerId) ? true : undefined}
                autoComplete="off"
                spellCheck={false}
              />
            </CredentialField>
            <CredentialField
              id="apple-connection-key"
              label={t('Key ID')}
              error={shown(errors.keyId)}
            >
              <Input
                id="apple-connection-key"
                dir="ltr"
                value={keyId}
                onChange={(event) => setKeyId(event.target.value)}
                placeholder="2X9R4HXF34"
                className="font-mono text-[13px]"
                aria-invalid={shown(errors.keyId) ? true : undefined}
                autoComplete="off"
                spellCheck={false}
              />
            </CredentialField>
            <CredentialFile
              id="apple-connection-private-key"
              label={t('Private key (.p8)')}
              extensions={['.p8']}
              encoding="text"
              files={privateKey}
              onChange={setPrivateKey}
              pastePlaceholder={PRIVATE_KEY_PLACEHOLDER}
              error={shown(errors.privateKey) ?? undefined}
            />
            {create.error ? (
              <p
                role="alert"
                className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
              >
                {getErrorMessage(
                  create.error,
                  t('App Store Connect rejected the key.'),
                )}
              </p>
            ) : null}
          </div>
        )}

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {step === 'key' ? (
            <>
              <Button variant="outline" onClick={() => handleOpenChange(false)}>
                {t('Cancel')}
              </Button>
              <Button onClick={() => setStep('details')}>
                {t('Next')}
                <ChevronRight className="ms-1.5 h-4 w-4 rtl:-scale-x-100" />
              </Button>
            </>
          ) : (
            <>
              <Button
                variant="outline"
                onClick={() => setStep('key')}
                disabled={create.isPending}
              >
                <ChevronLeft className="me-1.5 h-4 w-4 rtl:-scale-x-100" />
                {t('Back')}
              </Button>
              <Button onClick={handleCreate} disabled={create.isPending}>
                {t('Connect')}
              </Button>
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
