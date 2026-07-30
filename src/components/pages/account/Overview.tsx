import { useState, useEffect, useRef, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import {
  clearConsoleAccountCache,
  fetchAccountIdentities,
  syncConsoleAccountAfterMutation,
  useAccountIdentities,
  useMFAFactors,
} from '@/lib/react-query/hooks'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Trash2,
  Mail,
  Smartphone,
  LockOpen,
  CheckCircle2,
  XCircle,
  Copy,
  Check,
  Loader2,
  AlertTriangle,
  Download,
} from 'lucide-react'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { AuthenticatorType, AuthenticationFactor } from '@appwrite.io/console'
import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import {
  fetchAuthenticatedImageBlobUrl,
  revokeAuthenticatedImageBlobUrl,
} from '@/lib/appwrite/fetch-authenticated-image'
import {
  MfaReauthForm,
  useMfaReauth,
  verifyMfaReauth,
} from '@/components/global/auth/MfaReauthForm'
import { useT } from '@/lib/i18n/translate'

// Dependencies for query invalidation
const Dependencies = {
  ACCOUNT: ['account', 'console'],
  IDENTITIES: ['identities', 'account'],
  FACTORS: ['factors', 'account'],
} as const

// ============================================================================
// ACCOUNT ID SECTION
// ============================================================================

export function AccountIdSection() {
  const { account } = useAuth()
  const t = useT()

  if (!account?.$id) return null

  return (
    <div
      data-card-id="account-id"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Account ID')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground mb-3">
          {t(
            'Use this ID when integrating with the Appwrite API or SDKs. Support may also ask for this ID when assisting with issues.', // pragma: allowlist secret
          )}
        </p>
        <CopyableId id={account.$id} size="md" maxWidth={240} />
      </div>
    </div>
  )
}

// ============================================================================
// UPDATE NAME SECTION
// ============================================================================

export function UpdateNameSection() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const t = useT()
  const [name, setName] = useState('')
  const accountName = account?.name

  useEffect(() => {
    if (!accountName) return
    setName((prev) => (prev === accountName ? prev : accountName))
  }, [accountName])

  const updateNameMutation = useMutation({
    mutationFn: async (newName: string) => {
      return await sdk.forConsole.account.updateName({ name: newName })
    },
    onSuccess: (updatedAccount, newName) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
        patch: { name: newName },
      })
      toast.success(t('Name has been updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update name'))
    },
  })

  const hasChanges = name !== (account?.name || '')
  const isDisabled = !name || !hasChanges || updateNameMutation.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updateNameMutation.mutate(name)
    }
  }

  return (
    <div
      data-card-id="name"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Update name')}
        </h3>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-3">
            {t('Update your account display name.')}
          </p>
          <div className="space-y-2">
            <Label htmlFor="name">{t('Name')}</Label>
            <Input
              id="name"
              type="text"
              placeholder={t('Enter name')}
              value={name}
              onChange={(e) => setName(e.target.value)}
              disabled={updateNameMutation.isPending}
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              required
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            {t('Update')}
          </Button>
        </div>
      </form>
    </div>
  )
}

// ============================================================================
// UPDATE EMAIL SECTION
// ============================================================================

export function UpdateEmailSection() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const t = useT()
  const [email, setEmail] = useState('')
  const [emailPassword, setEmailPassword] = useState('')
  const accountEmail = account?.email

  useEffect(() => {
    if (!accountEmail) return
    setEmail((prev) => (prev === accountEmail ? prev : accountEmail))
  }, [accountEmail])

  const updateEmailMutation = useMutation({
    mutationFn: async ({
      email,
      password,
    }: {
      email: string
      password: string
    }) => {
      return await sdk.forConsole.account.updateEmail({ email, password })
    },
    onSuccess: (updatedAccount, { email }) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
        patch: { email },
      })
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
      setEmailPassword('')
      toast.success(t('Email has been updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update email'))
    },
  })

  const emailChanged = email !== (account?.email || '')
  const showPassword = emailChanged && !!email
  const isDisabled =
    !email || !emailPassword || !emailChanged || updateEmailMutation.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updateEmailMutation.mutate({ email, password: emailPassword })
    }
  }

  return (
    <div
      data-card-id="email"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <div className="flex items-center gap-2">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Update email')}
          </h3>
          {account?.emailVerification && (
            <Badge variant="success" className="text-[10px] shrink-0 gap-1">
              <CheckCircle2 className="h-3 w-3" />
              {t('verified')}
            </Badge>
          )}
        </div>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-3">
            {t(
              'Update your account email address. Requires password verification when changing email.',
            )}
          </p>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">{t('Email')}</Label>
              <Input
                id="email"
                type="email"
                placeholder={t('Enter email')}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={updateEmailMutation.isPending}
                className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                required
              />
            </div>
            {showPassword && (
              <div className="space-y-2">
                <Label htmlFor="email-password">{t('Password')}</Label>
                <Input
                  id="email-password"
                  type="password"
                  placeholder={t('Enter password')}
                  value={emailPassword}
                  onChange={(e) => setEmailPassword(e.target.value)}
                  disabled={updateEmailMutation.isPending}
                  className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  required
                />
              </div>
            )}
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            {t('Update')}
          </Button>
        </div>
      </form>
    </div>
  )
}

// ============================================================================
// UPDATE PASSWORD SECTION
// ============================================================================

export function UpdatePasswordSection() {
  const t = useT()
  const [oldPassword, setOldPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const queryClient = useQueryClient()

  const updatePasswordMutation = useMutation({
    mutationFn: async ({
      password,
      oldPassword,
    }: {
      password: string
      oldPassword: string
    }) => {
      return await sdk.forConsole.account.updatePassword({
        password,
        oldPassword,
      })
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
      setOldPassword('')
      setNewPassword('')
      toast.success(t('Password has been updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update password'))
    },
  })

  const isDisabled =
    !newPassword || !oldPassword || updatePasswordMutation.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updatePasswordMutation.mutate({ password: newPassword, oldPassword })
    }
  }

  return (
    <div
      data-card-id="password"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Update password')}
        </h3>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-3">
            {t(
              'Change your account password. Includes link to password recovery if forgotten.',
            )}
          </p>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="old-password">{t('Old password')}</Label>
              <Input
                id="old-password"
                type="password"
                placeholder={t('Enter password')}
                value={oldPassword}
                onChange={(e) => setOldPassword(e.target.value)}
                disabled={updatePasswordMutation.isPending}
                className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="new-password">{t('New password')}</Label>
              <Input
                id="new-password"
                type="password"
                placeholder={t('Enter password')}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                disabled={updatePasswordMutation.isPending}
                className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                required
              />
            </div>
            <div className="text-sm">
              <Link
                to="/recovery"
                className="link-neutral text-[13px]"
              >
                {t('Forgot your password?')}
              </Link>
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            {t('Update')}
          </Button>
        </div>
      </form>
    </div>
  )
}

// ============================================================================
// IDENTITIES SECTION
// ============================================================================

export function IdentitiesSection({
  initialData,
}: {
  initialData?: Awaited<ReturnType<typeof fetchAccountIdentities>>
} = {}) {
  const t = useT()
  const { data, isFetched } = useAccountIdentities()
  const queryClient = useQueryClient()
  const identities = data?.identities ?? initialData?.identities ?? []
  const hasResolvedData = isFetched || initialData !== undefined

  const deleteIdentityMutation = useMutation({
    mutationFn: async (identityId: string) => {
      return await sdk.forConsole.account.deleteIdentity({ identityId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.IDENTITIES })
      toast.success(t('Identity has been deleted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete identity'))
    },
  })

  const handleDelete = (identityId: string) => {
    if (confirm(t('Are you sure you want to delete this identity?'))) {
      deleteIdentityMutation.mutate(identityId)
    }
  }

  const getProviderIcon = (provider: string) => {
    // Map provider keys to icon names
    const providerMap: Record<string, string> = {
      github: 'github.svg',
      google: 'google.svg',
      apple: 'apple.svg',
      facebook: 'facebook.svg',
      // Add more as needed
    }
    return providerMap[provider.toLowerCase()] || 'empty.svg'
  }

  const getProviderName = (provider: string) => {
    const nameMap: Record<string, string> = {
      github: 'GitHub',
      google: 'Google',
      apple: 'Apple',
      facebook: 'Facebook',
    }
    return nameMap[provider.toLowerCase()] || provider
  }

  if (!hasResolvedData) {
    return null
  }

  if (identities.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Identities')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="rounded-lg border border-border bg-muted/30 p-6 text-center">
            <p className="text-[14px] font-medium text-foreground mb-1">
              {t('No identities are currently available.')}
            </p>
            <p className="text-[13px] text-muted-foreground">
              {t("Once you sign in via GitHub, you'll see it here.")}
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div
      data-card-id="identities"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Identities')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
              {t('Provider')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[250px]">
              {t('Email')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
              {t('Created At')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
              {t('Expiry Date')}
            </TableHead>
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[80px]" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {identities.map((identity) => (
            <TableRow key={identity.$id}>
              <TableCell className="px-6 py-3">
                <Badge
                  variant="info"
                  className="text-[10px] shrink-0 gap-1.5 font-medium"
                >
                  <img
                    src={`/icons/${getProviderIcon(identity.provider)}`}
                    alt={identity.provider}
                    className={`h-3.5 w-3.5 ${PUBLIC_ICON_MUTED_CLASSES}`}
                    onError={(e) => {
                      e.currentTarget.src = '/icons/empty.svg'
                    }}
                  />
                  {getProviderName(identity.provider)}
                </Badge>
              </TableCell>
              <TableCell className="px-4 py-3">
                <span className="text-[13px] text-muted-foreground">
                  {identity.providerEmail || '-'}
                </span>
              </TableCell>
              <TableCell className="px-4 py-3">
                <DateTooltip
                  date={new Date(identity.$createdAt)}
                  className="text-[12px] text-muted-foreground"
                />
              </TableCell>
              <TableCell className="px-4 py-3">
                {identity.providerAccessTokenExpiry ? (
                  <DateTooltip
                    date={new Date(identity.providerAccessTokenExpiry)}
                    className="text-[12px] text-muted-foreground"
                  />
                ) : (
                  <span className="text-[12px] text-muted-foreground">-</span>
                )}
              </TableCell>
              <TableCell className="px-6 py-3">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0"
                  onClick={() => handleDelete(identity.$id)}
                  disabled={deleteIdentityMutation.isPending}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

// ============================================================================
// MFA SECTION
// ============================================================================

export function MFASection() {
  const t = useT()
  const { account } = useAuth()
  const { data: factorsData } = useMFAFactors()
  const queryClient = useQueryClient()
  const [mfaEnabled, setMfaEnabled] = useState(account?.mfa || false)
  const accountMfa = account?.mfa

  useEffect(() => {
    if (accountMfa === undefined) return
    setMfaEnabled((prev) => (prev === accountMfa ? prev : accountMfa))
  }, [accountMfa])

  const factors = factorsData || {
    totp: false,
    email: false,
    phone: false,
    recoveryCode: false,
  }

  const updateMFAMutation = useMutation({
    mutationFn: async (mfa: boolean) => {
      return await sdk.forConsole.account.updateMFA({ mfa })
    },
    onSuccess: async (updatedAccount, mfa) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
        patch: { mfa },
      })
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })

      // Auto-setup email MFA if enabling MFA and email is verified but email MFA not set up
      if (mfa && account?.emailVerification && !factors.email) {
        try {
          await sdk.forConsole.account.createMFAChallenge({
            factor: AuthenticationFactor.Email,
          })
          queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
        } catch (error) {
          console.error('Failed to auto-setup email MFA:', error)
        }
      }

      toast.success(
        mfa
          ? t('Multi-factor authentication has been enabled')
          : t('Multi-factor authentication has been disabled'),
      )
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update MFA'))
      // Revert on error
      setMfaEnabled(account?.mfa || false)
    },
  })

  const handleMfaToggle = (checked: boolean) => {
    setMfaEnabled(checked)
    updateMFAMutation.mutate(checked)
  }

  const hasAnyMfaMethod = factors.totp || factors.email || factors.phone

  return (
    <div
      data-card-id="mfa"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Multi-factor authentication')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-1">
          {t(
            "Enhance your account's security by requiring a second sign-in method",
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-6">
        <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-4">
          <div className="space-y-0.5">
            <Label
              htmlFor="mfa-toggle"
              className="text-[13px] font-semibold text-foreground cursor-pointer"
            >
              {t('Multi-factor authentication')}
            </Label>
            <p className="text-[12px] text-muted-foreground">
              {mfaEnabled
                ? t('MFA is currently enabled')
                : t('MFA is currently disabled')}
            </p>
          </div>
          <Switch
            id="mfa-toggle"
            checked={mfaEnabled}
            onCheckedChange={handleMfaToggle}
            disabled={updateMFAMutation.isPending}
          />
        </div>

        {mfaEnabled && (
          <div className="space-y-4">
            <TOTPMethod factors={factors} />
            <EmailMFAMethod factors={factors} account={account} />
            {factors.phone && (
              <SMSMFAMethod factors={factors} account={account} />
            )}
            <RecoveryCodesMethod
              factors={factors}
              hasAnyMfaMethod={hasAnyMfaMethod}
            />
          </div>
        )}
      </div>
    </div>
  )
}

// TOTP Method Component
function TOTPMethod({ factors }: { factors: Models.MfaFactors }) {
  const t = useT()
  const queryClient = useQueryClient()
  const [setupDialogOpen, setSetupDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const deleteReauth = useMfaReauth({ factors, open: deleteDialogOpen })
  const [, setVerifyDialogOpen] = useState(false)
  const [qrCodeUrl, setQrCodeUrl] = useState<string | null>(null)
  const [secret, setSecret] = useState<string | null>(null)
  const [otp, setOtp] = useState('')
  const [step, setStep] = useState<'qr' | 'verify'>('qr')
  const qrBlobUrlRef = useRef<string | null>(null)

  const resetSetupState = () => {
    revokeAuthenticatedImageBlobUrl(qrBlobUrlRef.current)
    qrBlobUrlRef.current = null
    setQrCodeUrl(null)
    setSecret(null)
    setOtp('')
    setStep('qr')
  }

  useEffect(() => {
    return () => {
      revokeAuthenticatedImageBlobUrl(qrBlobUrlRef.current)
    }
  }, [])

  const createAuthenticatorMutation = useMutation({
    mutationFn: async () => {
      const mfaType = await sdk.forConsole.account.createMFAAuthenticator({
        type: AuthenticatorType.Totp,
      })

      const qrApiUrl = sdk.forConsole.avatars.getQR({
        text: mfaType.uri,
        size: 192 * 2,
      })
      const qrBlobUrl = await fetchAuthenticatedImageBlobUrl(qrApiUrl)

      return { mfaType, qrBlobUrl }
    },
    onSuccess: (data) => {
      revokeAuthenticatedImageBlobUrl(qrBlobUrlRef.current)
      qrBlobUrlRef.current = data.qrBlobUrl
      setQrCodeUrl(data.qrBlobUrl)
      setSecret(data.mfaType.secret || null)
      setStep('qr')
      setSetupDialogOpen(true)
    },
    onError: (error: Error) => {
      setSetupDialogOpen(false)
      resetSetupState()
      toast.error(error.message || t('Failed to create authenticator'))
    },
  })

  const verifyAuthenticatorMutation = useMutation({
    mutationFn: async (code: string) => {
      return await sdk.forConsole.account.updateMFAAuthenticator({
        type: AuthenticatorType.Totp,
        otp: code,
      })
    },
    onSuccess: (updatedAccount) => {
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
      setSetupDialogOpen(false)
      setVerifyDialogOpen(false)
      resetSetupState()
      toast.success(t('Authenticator app has been connected'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to verify authenticator'))
    },
  })

  const handleDeleteSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setDeleteError(null)
    setIsDeleting(true)

    try {
      const otp = readOtpFromForm(event.currentTarget, deleteReauth.code)
      await deleteReauth.verify(otp)
      await sdk.forConsole.account.deleteMFAAuthenticator({
        type: AuthenticatorType.Totp,
      })
      setDeleteDialogOpen(false)
      deleteReauth.reset()
      toast.success(t('Authenticator app has been deleted'))
      await queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
    } catch (error: unknown) {
      const message =
        error instanceof Error
          ? error.message
          : t('Failed to delete authenticator')
      setDeleteError(message)
      deleteReauth.setCode('')
    } finally {
      setIsDeleting(false)
    }
  }

  const handleStartSetup = () => {
    resetSetupState()
    setSetupDialogOpen(true)
    createAuthenticatorMutation.mutate()
  }

  const handleSetupDialogOpenChange = (open: boolean) => {
    setSetupDialogOpen(open)
    if (!open) {
      resetSetupState()
    }
  }

  const handleContinue = () => {
    if (step === 'qr') {
      setStep('verify')
    } else {
      if (otp.length === 6) {
        verifyAuthenticatorMutation.mutate(otp)
      }
    }
  }

  const handleDelete = () => {
    setDeleteError(null)
    setDeleteDialogOpen(true)
  }

  const handleDeleteDialogOpenChange = (open: boolean) => {
    setDeleteDialogOpen(open)
    if (!open) {
      setDeleteError(null)
    }
  }

  return (
    <>
      <div className="flex items-start gap-4 rounded-lg border border-border bg-card/50 p-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
          <Smartphone className="h-5 w-5 text-muted-foreground" />
        </div>
        <div className="flex-1">
          <div className="flex items-center gap-2 mb-1">
            <h4 className="text-[14px] font-semibold text-foreground">
              {t('Authenticator app')}
            </h4>
            {factors.totp && (
              <Badge variant="success" className="text-[10px] shrink-0 gap-1">
                <CheckCircle2 className="h-3 w-3" />
                {t('connected')}
              </Badge>
            )}
          </div>
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Use an authentication app to generate two-factor authentication codes.',
            )}
          </p>
        </div>
        <div>
          {factors.totp ? (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={isDeleting}
            >
              {t('Delete')}
            </Button>
          ) : (
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleStartSetup}
              disabled={createAuthenticatorMutation.isPending}
            >
              {t('Add')}
            </Button>
          )}
        </div>
      </div>

      {/* Setup Dialog */}
      <Dialog open={setupDialogOpen} onOpenChange={handleSetupDialogOpenChange}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>
              {step === 'qr' ? t('Scan QR code') : t('Enter verification code')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {step === 'qr'
                ? t(
                    'Install an authenticator app on your mobile device, open it and scan the provided QR code or enter it manually.',
                  )
                : t('Enter the 6-digit one-time code generated by the app.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            {step === 'qr' ? (
              <div className="space-y-4">
                {createAuthenticatorMutation.isPending ? (
                  <div className="flex h-[220px] items-center justify-center">
                    <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                  </div>
                ) : qrCodeUrl ? (
                  <div className="flex justify-center">
                    <div className="rounded-lg bg-white p-4">
                      <img
                        src={qrCodeUrl}
                        alt={t('MFA QR Code')}
                        className="mx-auto block aspect-square w-full max-w-[192px]"
                      />
                    </div>
                  </div>
                ) : null}
                {secret && !createAuthenticatorMutation.isPending && (
                  <>
                    <div className="relative py-2">
                      <div className="absolute inset-0 flex items-center">
                        <span className="w-full border-t border-border" />
                      </div>
                      <div className="relative flex justify-center text-[11px] uppercase tracking-wider">
                        <span className="bg-background px-2 text-muted-foreground">
                          {t('or')}
                        </span>
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label className="text-[12px]">
                        {t('Manual entry code')}
                      </Label>
                      <p className="text-[12px] text-muted-foreground">
                        {t(
                          'Manually enter the following code into the authenticator app',
                        )}
                      </p>
                      <div className="flex items-center gap-2">
                        <Input
                          value={secret}
                          readOnly
                          className="font-mono text-[13px]"
                        />
                        <CopyButton text={secret} />
                      </div>
                    </div>
                  </>
                )}
              </div>
            ) : (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="otp">{t('Verification code')}</Label>
                  <Input
                    id="otp"
                    type="text"
                    placeholder="000000"
                    value={otp}
                    onChange={(e) => {
                      const value = e.target.value
                        .replace(/\D/g, '')
                        .slice(0, 6)
                      setOtp(value)
                    }}
                    maxLength={6}
                    className="text-center text-2xl tracking-widest font-mono"
                    autoFocus
                  />
                </div>
              </div>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => handleSetupDialogOpenChange(false)}
            >
              {t('Cancel')}
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleContinue}
              disabled={
                (step === 'qr' &&
                  (createAuthenticatorMutation.isPending || !qrCodeUrl)) ||
                (step === 'verify' && otp.length !== 6) ||
                verifyAuthenticatorMutation.isPending
              }
            >
              {t('Continue')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog
        open={deleteDialogOpen}
        onOpenChange={handleDeleteDialogOpenChange}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete authenticator app')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('This removes authenticator app codes from your account.')}{' '}
              {t('To continue, verify your identity with a one-time code.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <form onSubmit={handleDeleteSubmit}>
            <div className="px-6 py-4">
              <MfaReauthForm reauth={deleteReauth} />
              {deleteError && (
                <p className="mt-3 text-[13px] text-destructive">
                  {deleteError}
                </p>
              )}
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => handleDeleteDialogOpenChange(false)}
                disabled={isDeleting}
              >
                {t('Cancel')}
              </Button>
              <Button
                type="submit"
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  !deleteReauth.isCodeValid ||
                  !deleteReauth.isChallengeReady ||
                  isDeleting
                }
              >
                {t('Delete')}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </>
  )
}

// Email MFA Method Component
function EmailMFAMethod({
  factors,
  account,
}: {
  factors: Models.MfaFactors
  account: Models.User | undefined
}) {
  useQueryClient()
  const t = useT()

  const createVerificationMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.createVerification({
        url: window.location.origin + window.location.pathname,
      })
    },
    onSuccess: () => {
      toast.success(t('Verification email has been sent'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to send verification email'))
    },
  })

  const handleVerify = () => {
    createVerificationMutation.mutate()
  }

  return (
    <div className="flex items-start gap-4 rounded-lg border border-border bg-card/50 p-4">
      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
        <Mail className="h-5 w-5 text-muted-foreground" />
      </div>
      <div className="flex-1">
        <div className="flex items-center gap-2 mb-1">
          <h4 className="text-[14px] font-semibold text-foreground">
            {t('Email')}
          </h4>
          {account?.emailVerification && factors.email && (
            <Badge variant="success" className="text-[10px] shrink-0 gap-1">
              <CheckCircle2 className="h-3 w-3" />
              {t('verified')}
            </Badge>
          )}
          {!account?.emailVerification && (
            <Badge variant="warning" className="text-[10px] shrink-0 gap-1">
              <XCircle className="h-3 w-3" />
              {t('unverified')}
            </Badge>
          )}
        </div>
        <p className="text-[13px] text-muted-foreground">
          {t('One-time codes will be sent to:')} {account?.email || '-'}
        </p>
      </div>
      {!account?.emailVerification && (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          onClick={handleVerify}
          disabled={createVerificationMutation.isPending}
        >
          {t('Verify')}
        </Button>
      )}
    </div>
  )
}

// SMS MFA Method Component
function SMSMFAMethod({
  factors,
  account,
}: {
  factors: Models.MfaFactors
  account: Models.User | undefined
}) {
  const t = useT()
  return (
    <div className="flex items-start gap-4 rounded-lg border border-border bg-card/50 p-4">
      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
        <Smartphone className="h-5 w-5 text-muted-foreground" />
      </div>
      <div className="flex-1">
        <div className="flex items-center gap-2 mb-1">
          <h4 className="text-[14px] font-semibold text-foreground">SMS</h4>
          {account?.phoneVerification && factors.phone && (
            <Badge variant="success" className="text-[10px] shrink-0 gap-1">
              <CheckCircle2 className="h-3 w-3" />
              {t('verified')}
            </Badge>
          )}
        </div>
        <p className="text-[13px] text-muted-foreground">
          {t('One-time codes will be sent to:')} {account?.phone || '-'}
        </p>
      </div>
    </div>
  )
}

// Recovery Codes Method Component
function readOtpFromForm(form: HTMLFormElement, fallback: string) {
  const otpInput = form.querySelector(
    'input[data-input-otp]',
  ) as HTMLInputElement | null
  return (otpInput?.value ?? fallback).trim()
}

function parseRecoveryCodes(data: Models.MfaRecoveryCodes) {
  return data.recoveryCodes ?? []
}

function RecoveryCodesMethod({
  factors,
  hasAnyMfaMethod,
}: {
  factors: Models.MfaFactors
  hasAnyMfaMethod: boolean
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const [codesDialogOpen, setCodesDialogOpen] = useState(false)
  const [regenerateDialogOpen, setRegenerateDialogOpen] = useState(false)
  const [regenerateError, setRegenerateError] = useState<string | null>(null)
  const [isRegenerating, setIsRegenerating] = useState(false)
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [viewDialogOpen, setViewDialogOpen] = useState(false)
  const [viewError, setViewError] = useState<string | null>(null)
  const [isViewVerifying, setIsViewVerifying] = useState(false)
  const reauth = useMfaReauth({
    factors,
    excludeRecoveryCode: true,
    open: regenerateDialogOpen,
  })
  const viewReauth = useMfaReauth({
    factors,
    open: viewDialogOpen,
  })

  const createRecoveryCodesMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.createMFARecoveryCodes()
    },
    onSuccess: (data) => {
      setRecoveryCodes(parseRecoveryCodes(data))
      setCodesDialogOpen(true)
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create recovery codes'))
    },
  })

  const handleRegenerateSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setRegenerateError(null)
    setIsRegenerating(true)

    try {
      const otp = readOtpFromForm(event.currentTarget, reauth.code)
      await verifyMfaReauth(reauth.challenge, otp)
      const data = await sdk.forConsole.account.updateMFARecoveryCodes()
      setRecoveryCodes(parseRecoveryCodes(data))
      setRegenerateDialogOpen(false)
      reauth.reset()
      setCodesDialogOpen(true)
      await queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
    } catch (error: unknown) {
      const message =
        error instanceof Error
          ? error.message
          : t('Failed to regenerate recovery codes')
      setRegenerateError(message)
      reauth.setCode('')
    } finally {
      setIsRegenerating(false)
    }
  }

  const handleView = async () => {
    try {
      const data = await sdk.forConsole.account.getMFARecoveryCodes()
      setRecoveryCodes(parseRecoveryCodes(data))
      setCodesDialogOpen(true)
    } catch (error: unknown) {
      const err = error as { code?: number; type?: string; message?: string }
      if (err.code === 404 || err.message?.includes('not found')) {
        createRecoveryCodesMutation.mutate()
      } else if (err.type === 'user_challenge_required') {
        setViewError(null)
        setViewDialogOpen(true)
      } else {
        toast.error(err.message || t('Failed to get recovery codes'))
      }
    }
  }

  const handleViewDialogOpenChange = (open: boolean) => {
    setViewDialogOpen(open)
    if (!open) {
      setViewError(null)
    }
  }

  const handleViewSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setViewError(null)
    setIsViewVerifying(true)

    try {
      const otp = readOtpFromForm(event.currentTarget, viewReauth.code)
      await viewReauth.verify(otp)
      let data: Models.MfaRecoveryCodes
      try {
        data = await sdk.forConsole.account.getMFARecoveryCodes()
      } catch (error: unknown) {
        const err = error as { code?: number; message?: string }
        if (err.code === 404 || err.message?.includes('not found')) {
          data = await sdk.forConsole.account.createMFARecoveryCodes()
          await queryClient.invalidateQueries({
            queryKey: Dependencies.FACTORS,
          })
        } else {
          throw error
        }
      }
      setRecoveryCodes(parseRecoveryCodes(data))
      setViewDialogOpen(false)
      viewReauth.reset()
      setCodesDialogOpen(true)
    } catch (error: unknown) {
      const message =
        error instanceof Error
          ? error.message
          : t('Failed to get recovery codes')
      setViewError(message)
      viewReauth.setCode('')
    } finally {
      setIsViewVerifying(false)
    }
  }

  const handleRegenerateDialogOpenChange = (open: boolean) => {
    setRegenerateDialogOpen(open)
    if (!open) {
      setRegenerateError(null)
    }
  }

  const recoveryCodesText = recoveryCodes.join('\n')

  const handleCopyAll = async () => {
    await navigator.clipboard.writeText(recoveryCodesText)
    toast.success(t('Recovery codes copied'))
  }

  const handleDownload = () => {
    const blob = new Blob([recoveryCodesText], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'recovery-codes.txt'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  }

  return (
    <>
      <div className="flex items-start gap-4 rounded-lg border border-border bg-card/50 p-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
          <LockOpen className="h-5 w-5 text-muted-foreground" />
        </div>
        <div className="flex-1">
          <h4 className="text-[14px] font-semibold text-foreground mb-1">
            {t('Recovery codes')}
          </h4>
          <p className="text-[13px] text-muted-foreground">
            {t("Use in case you can't receive two-factor authentication codes.")}
          </p>
        </div>
        <div className="flex gap-2">
          {factors.recoveryCode ? (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => handleRegenerateDialogOpenChange(true)}
              disabled={!hasAnyMfaMethod}
            >
              {t('Regenerate')}
            </Button>
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleView}
              disabled={!hasAnyMfaMethod}
            >
              {t('View')}
            </Button>
          )}
        </div>
      </div>

      <Dialog
        open={regenerateDialogOpen}
        onOpenChange={handleRegenerateDialogOpenChange}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Regenerate recovery codes')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to regenerate all recovery codes? All previously generated recovery codes will become invalid.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <form onSubmit={handleRegenerateSubmit}>
            <div className="px-6 py-4">
              <MfaReauthForm reauth={reauth} />
              {regenerateError && (
                <p className="mt-3 text-[13px] text-destructive">
                  {regenerateError}
                </p>
              )}
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => handleRegenerateDialogOpenChange(false)}
              >
                {t('Cancel')}
              </Button>
              <Button
                type="submit"
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  !reauth.isCodeValid ||
                  !reauth.isChallengeReady ||
                  isRegenerating
                }
              >
                {t('Regenerate')}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={viewDialogOpen} onOpenChange={handleViewDialogOpenChange}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Verify your identity')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Verification is required to view your recovery codes.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <form onSubmit={handleViewSubmit}>
            <div className="px-6 py-4">
              <MfaReauthForm reauth={viewReauth} />
              {viewError && (
                <p className="mt-3 text-[13px] text-destructive">{viewError}</p>
              )}
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => handleViewDialogOpenChange(false)}
                disabled={isViewVerifying}
              >
                {t('Cancel')}
              </Button>
              <Button
                type="submit"
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  !viewReauth.isCodeValid ||
                  !viewReauth.isChallengeReady ||
                  isViewVerifying
                }
              >
                {t('Verify')}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* Recovery Codes Dialog */}
      <Dialog open={codesDialogOpen} onOpenChange={setCodesDialogOpen}>
        <DialogContent className="sm:max-w-lg p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Recovery codes')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Use these codes to access your account if you lose your authenticator.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="flex gap-3 rounded-lg border border-orange-500/30 bg-orange-500/10 p-3">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-orange-600 dark:text-orange-400" />
              <div className="space-y-1">
                <p className="text-[13px] font-medium text-orange-700 dark:text-orange-300">
                  {t("Save these recovery codes now. They won't be shown again.")}
                </p>
                <p className="text-[13px] text-muted-foreground">
                  {t('Each code can only be used once.')}
                </p>
              </div>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="ghost"
                size="sm"
                className="h-9 justify-start text-[13px]"
                onClick={handleCopyAll}
                disabled={recoveryCodes.length === 0}
              >
                <Copy className="me-1.5 h-4 w-4" />
                {t('Copy all')}
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="h-9 justify-start text-[13px]"
                onClick={handleDownload}
                disabled={recoveryCodes.length === 0}
              >
                <Download className="me-1.5 h-4 w-4" />
                {t('Download .txt')}
              </Button>
            </div>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {recoveryCodes.map((code, index) => (
                <div
                  key={index}
                  className="flex items-center justify-between rounded-md border border-border bg-muted/30 px-3 py-2"
                >
                  <code className="font-mono text-[13px]">{code}</code>
                  <CopyButton text={code} />
                </div>
              ))}
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setCodesDialogOpen(false)}
            >
              {t('Close')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

// Copy Button Component
function CopyButton({ text }: { text: string }) {
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    await navigator.clipboard.writeText(text)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <Button
      variant="ghost"
      size="sm"
      className="h-7 w-7 p-0"
      onClick={handleCopy}
    >
      {copied ? (
        <Check className="h-4 w-4 text-green-600" />
      ) : (
        <Copy className="h-4 w-4" />
      )}
    </Button>
  )
}

// ============================================================================
// DELETE ACCOUNT SECTION
// ============================================================================

export function DeleteAccountSection() {
  const t = useT()
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const deleteAccountMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.delete()
    },
    onSuccess: () => {
      clearConsoleAccountCache()
      queryClient.removeQueries({ queryKey: Dependencies.ACCOUNT })
      toast.success(t('Account was deleted'))
      // User will be logged out by backend
      window.location.href = '/sign-in'
    },
    onError: (error: Error) => {
      setError(error.message || t('Failed to delete account'))
    },
  })

  const handleDelete = () => {
    setError(null)
    deleteAccountMutation.mutate()
  }

  return (
    <>
      <div
        data-card-id="delete-account"
        className="rounded-xl border border-red-500/30 bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-red-600 dark:text-red-400">
            {t('Delete account')}
          </h3>
        </div>
        <div className="border-t border-red-500/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Your account will be permanently deleted and access will be lost to any of your teams and data. This action is irreversible.',
            )}
          </p>
          {/* Account Info Summary */}
          <div className="flex items-center gap-3 mt-4">
            <InitialsAvatar
              name={account?.name || account?.email || t('User')}
              size="md"
            />
            <div className="flex-1 min-w-0">
              <p className="text-[14px] font-medium text-foreground truncate">
                {account?.name || t('User')}
              </p>
              {account?.email && (
                <p className="text-[12px] text-muted-foreground">
                  {account.email}
                </p>
              )}
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-red-500/20 bg-red-500/5">
          <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
            <DialogTrigger asChild>
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
              >
                {t('Delete account')}
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-start">
                <DialogTitle>{t('Delete account')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t(
                    'Are you sure you want to delete your account? This action cannot be undone.',
                  )}
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                {account && (
                  <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                    <div className="flex items-center gap-3">
                      <InitialsAvatar
                        name={account.name || account.email || t('User')}
                        size="sm"
                      />
                      <div>
                        <p className="text-[13px] font-medium text-foreground">
                          {account.name || t('User')}
                        </p>
                        {account.email && (
                          <p className="text-[11px] text-muted-foreground">
                            {account.email}
                          </p>
                        )}
                      </div>
                    </div>
                  </div>
                )}
                {error && (
                  <div className="rounded-md bg-destructive/10 border border-destructive/20 p-3 mb-4">
                    <p className="text-[13px] text-destructive">{error}</p>
                  </div>
                )}
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => {
                    setDeleteDialogOpen(false)
                    setError(null)
                  }}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={deleteAccountMutation.isPending}
                  onClick={handleDelete}
                >
                  {t('Delete account')}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </>
  )
}
