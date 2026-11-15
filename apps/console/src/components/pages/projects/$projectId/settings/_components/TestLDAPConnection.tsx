import { useEffect, useState } from 'react'
import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useTestLDAP } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

type DialogPhase = 'form' | 'testing' | 'success' | 'error'

interface TestLDAPConnectionProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** The form holds edits the test does not see: it runs against the saved configuration. */
  hasUnsavedChanges?: boolean
}

export function TestLDAPConnection({
  open,
  onOpenChange,
  projectId,
  hasUnsavedChanges = false,
}: TestLDAPConnectionProps) {
  const t = useT()
  const testLDAPMutation = useTestLDAP(projectId)
  const [phase, setPhase] = useState<DialogPhase>('form')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  // What the last run checked, so the result names the steps that passed.
  const [checked, setChecked] = useState({ user: false, password: false })

  useEffect(() => {
    if (!open) return
    setPhase('form')
    setErrorMessage('')
    setUsername('')
    setPassword('')
  }, [open])

  const handleOpenChange = (next: boolean) => {
    if (testLDAPMutation.isPending) return
    onOpenChange(next)
  }

  const handleTest = async () => {
    const user = username.trim()
    const secret = user ? password : ''
    setChecked({ user: user !== '', password: secret !== '' })
    setPhase('testing')
    setErrorMessage('')
    try {
      await testLDAPMutation.mutateAsync({ username: user, password: secret })
      setPhase('success')
    } catch (error: unknown) {
      setErrorMessage(
        error instanceof Error
          ? error.message
          : t('Failed to test the LDAP connection'),
      )
      setPhase('error')
    }
  }

  const isTesting = phase === 'testing' || testLDAPMutation.isPending

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Test LDAP connection')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Checks the configuration saved on this project: it connects to the directory, binds as the service account and reads the base DN.',
            )}
            {hasUnsavedChanges
              ? ` ${t('Unsaved changes in the form are not included: update first to test them.')}`
              : null}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          {phase === 'form' ? (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label
                  htmlFor="ldap-test-username"
                  className="text-[12px] font-medium"
                >
                  {t('Username (optional)')}
                </Label>
                <Input
                  id="ldap-test-username"
                  placeholder="jane.doe"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  autoComplete="off"
                  className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
                <p className="text-[11px] text-muted-foreground">
                  {t('Also looks this user up and checks the required group.')}
                </p>
              </div>
              <div className="space-y-2">
                <Label
                  htmlFor="ldap-test-password"
                  className="text-[12px] font-medium"
                >
                  {t('Password (optional)')}
                </Label>
                <Input
                  id="ldap-test-password"
                  type="password"
                  placeholder={t('Enter password')}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={username.trim() === ''}
                  autoComplete="new-password"
                  className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
                <p className="text-[11px] text-muted-foreground">
                  {t('Also verifies the password of that user.')}
                </p>
              </div>
            </div>
          ) : null}

          {phase === 'testing' ? (
            <div className="flex flex-col items-center justify-center gap-3 py-4">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-[13px] text-muted-foreground text-center">
                {t('Testing the connection…')}
              </p>
            </div>
          ) : null}

          {phase === 'success' ? (
            <div className="flex flex-col items-center gap-3 text-center">
              <CheckCircle2 className="h-10 w-10 text-green-600" />
              <div className="space-y-1">
                <p className="text-[13px] text-foreground">
                  {t('Connection, service account and base DN look good.')}
                </p>
                {checked.user ? (
                  <p className="text-[13px] text-foreground">
                    {checked.password
                      ? t('User found, group check passed, password accepted.')
                      : t('User found, group check passed.')}
                  </p>
                ) : null}
              </div>
            </div>
          ) : null}

          {phase === 'error' ? (
            <Alert variant="destructive" className="border-destructive/30">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {errorMessage}
              </AlertDescription>
            </Alert>
          ) : null}
        </div>

        {phase !== 'testing' ? (
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {phase === 'form' ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => handleOpenChange(false)}
                  disabled={isTesting}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleTest}
                  disabled={isTesting}
                >
                  {t('Run test')}
                </Button>
              </>
            ) : null}

            {phase === 'success' ? (
              <Button
                type="button"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => handleOpenChange(false)}
              >
                {t('Close')}
              </Button>
            ) : null}

            {phase === 'error' ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => handleOpenChange(false)}
                >
                  {t('Close')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setPhase('form')}
                >
                  {t('Try again')}
                </Button>
              </>
            ) : null}
          </div>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
