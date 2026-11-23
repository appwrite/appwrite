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
import { useCreateDistributionGoogleConnection } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ConnectionInstruction } from './ConnectionInstruction'
import { ConnectionSteps } from './ConnectionSteps'
import { ConsoleLink } from './ConsoleLink'
import { CredentialField } from './CredentialField'
import { CredentialFile } from './CredentialFile'
import type { CredentialFileValue } from './CredentialFile'
import {
  ANDROID_PUBLISHER_API_URL,
  PLAY_CONSOLE_URL,
  SERVICE_ACCOUNTS_URL,
  parseServiceAccount,
} from '@/lib/distribution/credentials'

const SERVICE_ACCOUNT_PLACEHOLDER =
  '{\n  "type": "service_account",\n  "client_email": "...",\n  "private_key": "..."\n}'

const STEPS = ['account', 'invite', 'key'] as const

type Step = (typeof STEPS)[number]

interface CreateGoogleConnectionProps {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CreateGoogleConnection({
  projectId,
  open,
  onOpenChange,
}: CreateGoogleConnectionProps) {
  const t = useT()
  const create = useCreateDistributionGoogleConnection(projectId)
  const [step, setStep] = useState<Step>('account')
  const [name, setName] = useState('Google Play')
  const [key, setKey] = useState<CredentialFileValue[]>([])
  const [submitted, setSubmitted] = useState(false)

  const reset = () => {
    setStep('account')
    setName('Google Play')
    setKey([])
    setSubmitted(false)
    create.reset()
  }

  const handleOpenChange = (next: boolean) => {
    if (create.isPending) return
    if (!next) reset()
    onOpenChange(next)
  }

  const parsed = parseServiceAccount(key[0]?.content ?? '')
  const errors = {
    name: name.trim() ? null : t('Enter a name.'),
    key: parsed.error ? t(parsed.error) : null,
  }
  const valid = errors.name === null && parsed.serviceAccount !== null
  const shown = (error: string | null) => (submitted ? error : null)
  const index = STEPS.indexOf(step)

  const handleCreate = () => {
    setSubmitted(true)
    if (!valid || !parsed.serviceAccount) return
    create.mutate(
      { name: name.trim(), serviceAccount: parsed.serviceAccount },
      {
        onSuccess: () => {
          toast.success(t('Google Play connected'))
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
          <DialogTitle>{t('Connect Google Play')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Appwrite uses a service account from your own Google Cloud project to upload bundles to Google Play.',
            )}
          </DialogDescription>
        </DialogHeader>
        <ConnectionSteps
          steps={[
            t('Create a service account'),
            t('Invite it to Play Console'),
            t('Add the key'),
          ]}
          current={index}
        />
        <div className="border-t border-border" />

        {step === 'account' ? (
          <div className="px-6 py-4 space-y-4">
            <ol className="space-y-3 text-[13px] text-foreground">
              <ConnectionInstruction
                index={1}
                title={t('Enable the Google Play Android Developer API.')}
              >
                {t(
                  'Turn it on in the Google Cloud project that will own the service account.',
                )}
              </ConnectionInstruction>
              <ConnectionInstruction
                index={2}
                title={t('Create a service account.')}
              >
                {t(
                  'In IAM and admin → Service accounts, create an account. It needs no Google Cloud roles.',
                )}
              </ConnectionInstruction>
              <ConnectionInstruction index={3} title={t('Create a JSON key.')}>
                {t(
                  'Open the account, then Keys → Add key → Create new key, and choose JSON. Keep the downloaded file.',
                )}
              </ConnectionInstruction>
            </ol>
            <div className="flex flex-wrap gap-2">
              <ConsoleLink
                href={ANDROID_PUBLISHER_API_URL}
                label={t('Open the API library')}
              />
              <ConsoleLink
                href={SERVICE_ACCOUNTS_URL}
                label={t('Open service accounts')}
              />
            </div>
          </div>
        ) : step === 'invite' ? (
          <div className="px-6 py-4 space-y-4">
            <ol className="space-y-3 text-[13px] text-foreground">
              <ConnectionInstruction
                index={1}
                title={t('Invite the service account email.')}
              >
                {t(
                  'In Play Console, open Users and permissions → Invite new users and enter the email of the service account.',
                )}
              </ConnectionInstruction>
              <ConnectionInstruction
                index={2}
                title={t('Grant access to your apps.')}
              >
                {t(
                  'Add the apps Appwrite will publish, with permission to release to the testing tracks and to production that you plan to use.',
                )}
              </ConnectionInstruction>
              <ConnectionInstruction
                index={3}
                title={t('Send the invitation.')}
              >
                {t(
                  'A service account accepts it straight away. Permission changes can take a few minutes to apply.',
                )}
              </ConnectionInstruction>
            </ol>
            <ConsoleLink
              href={PLAY_CONSOLE_URL}
              label={t('Open Play Console')}
            />
          </div>
        ) : (
          <div className="px-6 py-4 space-y-4">
            <CredentialField
              id="google-connection-name"
              label={t('Name')}
              error={shown(errors.name)}
            >
              <Input
                id="google-connection-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                aria-invalid={shown(errors.name) ? true : undefined}
                autoComplete="off"
              />
            </CredentialField>
            <CredentialFile
              id="google-connection-key"
              label={t('Service account key (JSON)')}
              extensions={['.json']}
              encoding="text"
              files={key}
              onChange={setKey}
              pastePlaceholder={SERVICE_ACCOUNT_PLACEHOLDER}
              error={shown(errors.key) ?? undefined}
              hint={
                parsed.serviceAccount?.client_email
                  ? `${t('Service account')}: ${parsed.serviceAccount.client_email}`
                  : undefined
              }
            />
            {create.error ? (
              <p
                role="alert"
                className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-[12px] text-destructive"
              >
                {getErrorMessage(
                  create.error,
                  t('Google Play rejected the service account.'),
                )}
              </p>
            ) : null}
          </div>
        )}

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {index === 0 ? (
            <Button variant="outline" onClick={() => handleOpenChange(false)}>
              {t('Cancel')}
            </Button>
          ) : (
            <Button
              variant="outline"
              onClick={() => setStep(STEPS[index - 1])}
              disabled={create.isPending}
            >
              <ChevronLeft className="me-1.5 h-4 w-4 rtl:-scale-x-100" />
              {t('Back')}
            </Button>
          )}
          {step === 'key' ? (
            <Button onClick={handleCreate} disabled={create.isPending}>
              {t('Connect')}
            </Button>
          ) : (
            <Button onClick={() => setStep(STEPS[index + 1])}>
              {t('Next')}
              <ChevronRight className="ms-1.5 h-4 w-4 rtl:-scale-x-100" />
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
