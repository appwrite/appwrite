import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useAccountIdentities, useMFAFactors } from '@/lib/react-query/hooks'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
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
} from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { AuthenticatorType, AuthenticationFactor } from '@appwrite.io/console'
import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'

// Dependencies for query invalidation
const Dependencies = {
  ACCOUNT: ['account', 'console'],
  IDENTITIES: ['identities', 'account'],
  FACTORS: ['factors', 'account'],
} as const

export function AccountOverview() {
  const { features } = useConsoleProfile()
  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
      <div className="space-y-6">
        <UpdateNameSection />
        <UpdateEmailSection />
        <UpdatePasswordSection />
        {features.accountIdentities && <IdentitiesSection />}
        {features.accountMfa && <MFASection />}
        <DeleteAccountSection />
      </div>
    </div>
  )
}

// ============================================================================
// UPDATE NAME SECTION
// ============================================================================

function UpdateNameSection() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const [name, setName] = useState('')

  useEffect(() => {
    if (account?.name) {
      setName(account.name)
    }
  }, [account])

  const updateNameMutation = useMutation({
    mutationFn: async (newName: string) => {
      return await sdk.forConsole.account.updateName({ name: newName })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.ACCOUNT })
      toast.success('Name has been updated')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update name')
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
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update name
        </h3>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-3">
            Update your account display name.
          </p>
          <div className="space-y-2">
            <Label htmlFor="name">Name</Label>
            <Input
              id="name"
              type="text"
              placeholder="Enter name"
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
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// ============================================================================
// UPDATE EMAIL SECTION
// ============================================================================

function UpdateEmailSection() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const [email, setEmail] = useState('')
  const [emailPassword, setEmailPassword] = useState('')

  useEffect(() => {
    if (account?.email) {
      setEmail(account.email)
    }
  }, [account])

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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.ACCOUNT })
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
      setEmailPassword('')
      toast.success('Email has been updated')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update email')
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
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-2">
          <h3 className="text-[15px] font-semibold text-foreground">
            Update email
          </h3>
          {account?.emailVerification && (
            <Badge variant="secondary" className="h-5 gap-1 px-1.5 text-[11px]">
              <CheckCircle2 className="h-3 w-3" />
              verified
            </Badge>
          )}
        </div>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-3">
            Update your account email address. Requires password verification
            when changing email.
          </p>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">Email</Label>
              <Input
                id="email"
                type="email"
                placeholder="Enter email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={updateEmailMutation.isPending}
                className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                required
              />
            </div>
            {showPassword && (
              <div className="space-y-2">
                <Label htmlFor="email-password">Password</Label>
                <Input
                  id="email-password"
                  type="password"
                  placeholder="Enter password"
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
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// ============================================================================
// UPDATE PASSWORD SECTION
// ============================================================================

function UpdatePasswordSection() {
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.ACCOUNT })
      setOldPassword('')
      setNewPassword('')
      toast.success('Password has been updated')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update password')
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
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update password
        </h3>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-3">
            Change your account password. Includes link to password recovery if
            forgotten.
          </p>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="old-password">Old password</Label>
              <Input
                id="old-password"
                type="password"
                placeholder="Enter password"
                value={oldPassword}
                onChange={(e) => setOldPassword(e.target.value)}
                disabled={updatePasswordMutation.isPending}
                className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="new-password">New password</Label>
              <Input
                id="new-password"
                type="password"
                placeholder="Enter password"
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
                className="text-primary hover:underline text-[13px]"
              >
                Forgot your password?
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
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// ============================================================================
// IDENTITIES SECTION
// ============================================================================

function IdentitiesSection() {
  const { data, isLoading } = useAccountIdentities()
  const queryClient = useQueryClient()
  const identities = data?.identities || []

  const deleteIdentityMutation = useMutation({
    mutationFn: async (identityId: string) => {
      return await sdk.forConsole.account.deleteIdentity({ identityId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.IDENTITIES })
      toast.success('Identity has been deleted')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete identity')
    },
  })

  const handleDelete = (identityId: string) => {
    if (confirm('Are you sure you want to delete this identity?')) {
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

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Identities
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="text-sm text-muted-foreground">Loading...</div>
        </div>
      </div>
    )
  }

  if (identities.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Identities
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="rounded-lg border border-border bg-muted/30 p-6 text-center">
            <p className="text-[14px] font-medium text-foreground mb-1">
              No identities are currently available.
            </p>
            <p className="text-[13px] text-muted-foreground">
              Once you sign in via GitHub, you'll see it here.
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Identities
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="rounded-lg border border-border overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                  Provider
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[250px]">
                  Email
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                  Created At
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                  Expiry Date
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[80px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {identities.map((identity) => (
                <TableRow key={identity.$id}>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <img
                        src={`/icons/${getProviderIcon(identity.provider)}`}
                        alt={identity.provider}
                        className="h-4 w-4"
                        onError={(e) => {
                          e.currentTarget.src = '/icons/empty.svg'
                        }}
                      />
                      <span className="text-[13px] font-medium">
                        {getProviderName(identity.provider)}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell>
                    <span className="text-[13px] text-muted-foreground">
                      {identity.providerEmail || '-'}
                    </span>
                  </TableCell>
                  <TableCell>
                    <DateTooltip
                      date={new Date(identity.$createdAt)}
                      className="text-[12px] text-muted-foreground"
                    />
                  </TableCell>
                  <TableCell>
                    {identity.providerAccessTokenExpiry ? (
                      <DateTooltip
                        date={new Date(identity.providerAccessTokenExpiry)}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                  <TableCell>
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
      </div>
    </div>
  )
}

// ============================================================================
// MFA SECTION
// ============================================================================

function MFASection() {
  const { account } = useAuth()
  const { data: factorsData } = useMFAFactors()
  const queryClient = useQueryClient()
  const [mfaEnabled, setMfaEnabled] = useState(account?.mfa || false)

  useEffect(() => {
    if (account?.mfa !== undefined) {
      setMfaEnabled(account.mfa)
    }
  }, [account])

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
    onSuccess: async (_, mfa) => {
      queryClient.invalidateQueries({ queryKey: Dependencies.ACCOUNT })
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
        `Multi-factor authentication has been ${mfa ? 'enabled' : 'disabled'}`,
      )
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update MFA')
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
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Multi-factor authentication
        </h3>
        <p className="text-[13px] text-muted-foreground mt-1">
          Enhance your account's security by requiring a second sign-in method
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
              Multi-factor authentication
            </Label>
            <p className="text-[12px] text-muted-foreground">
              {mfaEnabled
                ? 'MFA is currently enabled'
                : 'MFA is currently disabled'}
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
  const queryClient = useQueryClient()
  const [setupDialogOpen, setSetupDialogOpen] = useState(false)
  const [, setVerifyDialogOpen] = useState(false)
  const [qrCodeUrl, setQrCodeUrl] = useState<string | null>(null)
  const [secret, setSecret] = useState<string | null>(null)
  const [otp, setOtp] = useState('')
  const [step, setStep] = useState<'qr' | 'verify'>('qr')

  const createAuthenticatorMutation = useMutation({
    mutationFn: async () => {
      const mfaType = await sdk.forConsole.account.createMFAAuthenticator({
        type: AuthenticatorType.Totp,
      })

      // Generate QR code
      const qrUrl = sdk.forConsole.avatars.getQR({
        text: mfaType.uri,
        size: 384,
      })

      return { mfaType, qrUrl }
    },
    onSuccess: (data) => {
      setQrCodeUrl(data.qrUrl)
      setSecret(data.mfaType.secret || null)
      setStep('qr')
      setSetupDialogOpen(true)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to create authenticator')
    },
  })

  const verifyAuthenticatorMutation = useMutation({
    mutationFn: async (code: string) => {
      return await sdk.forConsole.account.updateMFAAuthenticator({
        type: AuthenticatorType.Totp,
        otp: code,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
      queryClient.invalidateQueries({ queryKey: Dependencies.ACCOUNT })
      setSetupDialogOpen(false)
      setVerifyDialogOpen(false)
      setOtp('')
      setQrCodeUrl(null)
      setSecret(null)
      toast.success('Authenticator app has been connected')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to verify authenticator')
    },
  })

  const deleteAuthenticatorMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.deleteMFAAuthenticator({
        type: AuthenticatorType.Totp,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
      toast.success('Authenticator app has been deleted')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete authenticator')
    },
  })

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
    if (confirm('Are you sure you want to delete the authenticator app?')) {
      deleteAuthenticatorMutation.mutate()
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
              Authenticator app
            </h4>
            {factors.totp && (
              <Badge
                variant="secondary"
                className="h-5 gap-1 px-1.5 text-[11px]"
              >
                <CheckCircle2 className="h-3 w-3" />
                connected
              </Badge>
            )}
          </div>
          <p className="text-[13px] text-muted-foreground">
            Use an authentication app to generate two-factor authentication
            codes.
          </p>
        </div>
        <div>
          {factors.totp ? (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteAuthenticatorMutation.isPending}
            >
              Delete
            </Button>
          ) : (
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => createAuthenticatorMutation.mutate()}
              disabled={createAuthenticatorMutation.isPending}
            >
              Add
            </Button>
          )}
        </div>
      </div>

      {/* Setup Dialog */}
      <Dialog open={setupDialogOpen} onOpenChange={setSetupDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>
              {step === 'qr' ? 'Scan QR code' : 'Enter verification code'}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {step === 'qr'
                ? 'Scan this QR code with your authenticator app, or enter the secret manually.'
                : 'Enter the 6-digit code from your authenticator app.'}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            {step === 'qr' ? (
              <div className="space-y-4">
                {qrCodeUrl && (
                  <div className="flex justify-center">
                    <img src={qrCodeUrl} alt="QR Code" className="w-64 h-64" />
                  </div>
                )}
                {secret && (
                  <div className="space-y-2">
                    <Label className="text-[12px]">Secret (manual entry)</Label>
                    <div className="flex items-center gap-2">
                      <Input
                        value={secret}
                        readOnly
                        className="font-mono text-[13px]"
                      />
                      <CopyButton text={secret} />
                    </div>
                  </div>
                )}
              </div>
            ) : (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="otp">Verification code</Label>
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
              onClick={() => {
                setSetupDialogOpen(false)
                setStep('qr')
                setOtp('')
                setQrCodeUrl(null)
                setSecret(null)
              }}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleContinue}
              disabled={
                (step === 'verify' && otp.length !== 6) ||
                verifyAuthenticatorMutation.isPending
              }
            >
              Continue
            </Button>
          </div>
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

  const createVerificationMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.createVerification({
        url: window.location.origin + window.location.pathname,
      })
    },
    onSuccess: () => {
      toast.success('Verification email has been sent')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to send verification email')
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
          <h4 className="text-[14px] font-semibold text-foreground">Email</h4>
          {account?.emailVerification && factors.email && (
            <Badge variant="secondary" className="h-5 gap-1 px-1.5 text-[11px]">
              <CheckCircle2 className="h-3 w-3" />
              verified
            </Badge>
          )}
          {!account?.emailVerification && (
            <Badge variant="warning" className="h-5 gap-1 px-1.5 text-[11px]">
              <XCircle className="h-3 w-3" />
              unverified
            </Badge>
          )}
        </div>
        <p className="text-[13px] text-muted-foreground">
          One-time codes will be sent to: {account?.email || '-'}
        </p>
      </div>
      {!account?.emailVerification && (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          onClick={handleVerify}
          disabled={createVerificationMutation.isPending}
        >
          Verify
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
  return (
    <div className="flex items-start gap-4 rounded-lg border border-border bg-card/50 p-4">
      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
        <Smartphone className="h-5 w-5 text-muted-foreground" />
      </div>
      <div className="flex-1">
        <div className="flex items-center gap-2 mb-1">
          <h4 className="text-[14px] font-semibold text-foreground">SMS</h4>
          {account?.phoneVerification && factors.phone && (
            <Badge variant="secondary" className="h-5 gap-1 px-1.5 text-[11px]">
              <CheckCircle2 className="h-3 w-3" />
              verified
            </Badge>
          )}
        </div>
        <p className="text-[13px] text-muted-foreground">
          One-time codes will be sent to: {account?.phone || '-'}
        </p>
      </div>
    </div>
  )
}

// Recovery Codes Method Component
function RecoveryCodesMethod({
  factors,
  hasAnyMfaMethod,
}: {
  factors: Models.MfaFactors
  hasAnyMfaMethod: boolean
}) {
  const queryClient = useQueryClient()
  const [codesDialogOpen, setCodesDialogOpen] = useState(false)
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])

  const createRecoveryCodesMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.createMFARecoveryCodes()
    },
    onSuccess: (data) => {
      setRecoveryCodes(
        ('codes' in data && Array.isArray(data.codes) ? data.codes : []) || [],
      )
      setCodesDialogOpen(true)
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to create recovery codes')
    },
  })

  const regenerateRecoveryCodesMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.updateMFARecoveryCodes()
    },
    onSuccess: (data) => {
      setRecoveryCodes(
        ('codes' in data && Array.isArray(data.codes) ? data.codes : []) || [],
      )
      setCodesDialogOpen(true)
      queryClient.invalidateQueries({ queryKey: Dependencies.FACTORS })
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to regenerate recovery codes')
    },
  })

  const handleView = async () => {
    try {
      const data = await sdk.forConsole.account.getMFARecoveryCodes()
      setRecoveryCodes(
        ('codes' in data && Array.isArray(data.codes) ? data.codes : []) || [],
      )
      setCodesDialogOpen(true)
    } catch (error: unknown) {
      // If codes don't exist, create them
      if (error.code === 404 || error.message?.includes('not found')) {
        createRecoveryCodesMutation.mutate()
      } else {
        toast.error(error.message || 'Failed to get recovery codes')
      }
    }
  }

  return (
    <>
      <div className="flex items-start gap-4 rounded-lg border border-border bg-card/50 p-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
          <LockOpen className="h-5 w-5 text-muted-foreground" />
        </div>
        <div className="flex-1">
          <h4 className="text-[14px] font-semibold text-foreground mb-1">
            Recovery codes
          </h4>
          <p className="text-[13px] text-muted-foreground">
            Use in case you can't receive two-factor authentication codes.
          </p>
        </div>
        <div className="flex gap-2">
          {factors.recoveryCode ? (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => regenerateRecoveryCodesMutation.mutate()}
              disabled={
                !hasAnyMfaMethod || regenerateRecoveryCodesMutation.isPending
              }
            >
              Regenerate
            </Button>
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleView}
              disabled={!hasAnyMfaMethod}
            >
              View
            </Button>
          )}
        </div>
      </div>

      {/* Recovery Codes Dialog */}
      <Dialog open={codesDialogOpen} onOpenChange={setCodesDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Recovery codes</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Save these codes in a safe place. You can use them to access your
              account if you lose access to your authenticator device.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-2">
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
              Close
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

function DeleteAccountSection() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const deleteAccountMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.delete()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.ACCOUNT })
      toast.success('Account was deleted')
      // User will be logged out by backend
      window.location.href = '/sign-in'
    },
    onError: (error: Error) => {
      setError(error.message || 'Failed to delete account')
    },
  })

  const handleDelete = () => {
    setError(null)
    deleteAccountMutation.mutate()
  }

  return (
    <>
      <div className="rounded-xl border border-red-500/30 bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-red-600 dark:text-red-400">
            Delete account
          </h3>
        </div>
        <div className="border-t border-red-500/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            Your account will be permanently deleted and access will be lost to
            any of your teams and data. This action is irreversible.
          </p>
          {/* Account Info Summary */}
          <div className="flex items-center gap-3 mt-4">
            <InitialsAvatar
              name={account?.name || account?.email || 'User'}
              size="md"
            />
            <div className="flex-1 min-w-0">
              <p className="text-[14px] font-medium text-foreground truncate">
                {account?.name || 'User'}
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
                Delete account
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-left">
                <DialogTitle>Delete account</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  Are you sure you want to delete your account? This action
                  cannot be undone.
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                {account && (
                  <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                    <div className="flex items-center gap-3">
                      <InitialsAvatar
                        name={account.name || account.email || 'User'}
                        size="sm"
                      />
                      <div>
                        <p className="text-[13px] font-medium text-foreground">
                          {account.name || 'User'}
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
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={deleteAccountMutation.isPending}
                  onClick={handleDelete}
                >
                  Delete account
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </>
  )
}
