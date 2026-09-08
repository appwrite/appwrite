'use client'

import { assetUrl } from '@/lib/asset-url'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import * as z from 'zod'
import { useEffect, useState, type ReactNode } from 'react'
import { useLocation } from '@tanstack/react-router'
import { AppwriteException, ID } from '@appwrite.io/console'
import { Bug, Eye, EyeOff } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Link } from '@tanstack/react-router'
import { Card } from '@/components/ui/card'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { sdk } from '@/lib/appwrite/sdk'
import {
  getLastLoginMethod,
  isOAuthLoginMethod,
  type LoginMethod,
  type OAuthLoginMethod,
} from '@/lib/utils/auth-storage'
import { DEFAULT_CONSOLE_OAUTH_LOGIN } from '@/lib/utils/console-oauth'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT, type Translator } from '@/lib/i18n/translate'
import { BitbucketIcon, GitHubIcon, GitLabIcon } from '@/lib/vcs/providers'

function GoogleIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M21.35 11.1h-9.17v2.96h5.27c-.23 1.24-1.4 3.64-5.27 3.64-3.17 0-5.76-2.62-5.76-5.85s2.59-5.85 5.76-5.85c1.8 0 3.01.77 3.7 1.43l2.52-2.43C16.18 3.55 14.23 2.7 12.18 2.7 6.99 2.7 2.78 6.92 2.78 12.15s4.21 9.45 9.4 9.45c5.43 0 9.02-3.81 9.02-9.18 0-.62-.07-1.07-.15-1.32z" />
    </svg>
  )
}

const DEMO_USER_EMAIL = 'dev@appwrite.io'
const DEMO_USER_PASSWORD = 'appwritedev'
const DEMO_USER_NAME = 'Demo'

function isAccountAlreadyExistsError(error: unknown): boolean {
  if (!(error instanceof AppwriteException)) return false
  return (
    error.code === 409 ||
    error.type === 'user_already_exists' ||
    error.type === 'user_email_already_exists'
  )
}

const createLoginSchema = (t: Translator) =>
  z.object({
    email: z.string().email(t('Please enter a valid email address')),
    password: z.string().min(1, t('Password is required')),
  })

const createSignUpSchema = (t: Translator) =>
  z.object({
    name: z.string().min(2, t('Name must be at least 2 characters')),
    email: z.string().email(t('Please enter a valid email address')),
    password: z.string().min(8, t('Password must be at least 8 characters')),
  })

type FormValues = {
  email: string
  password: string
  name?: string
}

export type SignInSubmitOptions = {
  skipAccountCreate?: boolean
}

const OAUTH_PROVIDERS: {
  id: OAuthLoginMethod
  Icon: (props: { className?: string }) => ReactNode
}[] = [
  { id: 'google', Icon: GoogleIcon },
  { id: 'github', Icon: GitHubIcon },
  { id: 'gitlab', Icon: GitLabIcon },
  { id: 'bitbucket', Icon: BitbucketIcon },
]

const OAUTH_EASE = 'cubic-bezier(0.22, 1, 0.36, 1)'
const OAUTH_ACCORDION_STYLES = `
.oauth-login-row {
  display: flex;
  width: 100%;
  gap: 0.5rem;
}
.oauth-login-row > * {
  flex: 0 1 2.25rem;
  min-width: 2.25rem;
  overflow: visible;
  transition: flex-grow 480ms ${OAUTH_EASE};
}
.oauth-login-row > .is-expanded {
  flex-grow: 1;
}
.oauth-login-label {
  display: grid;
  min-width: 0;
  grid-template-columns: 0fr;
  transition: grid-template-columns 480ms ${OAUTH_EASE} 160ms;
}
.oauth-login-label > span {
  min-width: 0;
  overflow: hidden;
}
.oauth-login-label-text {
  display: block;
  white-space: nowrap;
  padding-inline-start: 0.375rem;
  opacity: 0;
  transition-property: opacity;
  transition-duration: 160ms;
  transition-timing-function: ease;
  transition-delay: 0s;
}
.oauth-login-row > .is-expanded .oauth-login-label {
  grid-template-columns: 1fr;
  transition: grid-template-columns 480ms ${OAUTH_EASE};
}
.oauth-login-row > .is-expanded .oauth-login-label-text {
  opacity: 1;
  transition-property: opacity;
  transition-duration: 240ms;
  transition-timing-function: ease;
  transition-delay: 320ms;
}
.oauth-last-used {
  position: absolute;
  inset: 0;
  z-index: 10;
  pointer-events: none;
}
.oauth-last-used-dot,
.oauth-last-used-pill {
  position: absolute;
  transition: opacity 180ms ease;
}
.oauth-last-used-dot {
  top: -3px;
  inset-inline-end: -3px;
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--foreground);
  box-shadow: 0 0 0 2px var(--card);
  opacity: 1;
}
.oauth-last-used-pill {
  top: -8px;
  inset-inline-start: 6px;
  padding: 2px 6px;
  border-radius: 4px;
  border: 1px solid var(--border);
  background: var(--foreground);
  color: var(--background);
  font-size: 10px;
  font-weight: 500;
  line-height: 1.2;
  white-space: nowrap;
  opacity: 0;
}
.oauth-login-row > .is-expanded [data-last-used] .oauth-last-used-dot {
  opacity: 0;
}
.oauth-login-row > .is-expanded [data-last-used] .oauth-last-used-pill {
  opacity: 1;
}
@media (prefers-reduced-motion: reduce) {
  .oauth-login-row > *,
  .oauth-login-label,
  .oauth-login-label-text,
  .oauth-last-used-dot,
  .oauth-last-used-pill {
    transition: none;
  }
}
`

function oauthProviderLabel(
  provider: OAuthLoginMethod,
  mode: 'sign-in' | 'sign-up',
  t: Translator,
) {
  if (mode === 'sign-up') {
    if (provider === 'google') return t('Sign up with Google')
    if (provider === 'gitlab') return t('Sign up with GitLab')
    if (provider === 'bitbucket') return t('Sign up with Bitbucket')
    return t('Sign up with GitHub')
  }
  if (provider === 'google') return t('Login with Google')
  if (provider === 'gitlab') return t('Login with GitLab')
  if (provider === 'bitbucket') return t('Login with Bitbucket')
  return t('Login with GitHub')
}

interface SignInProps {
  mode?: 'sign-in' | 'sign-up'
  onSubmit: (data: FormValues, options?: SignInSubmitOptions) => void
  onOAuthLogin?: (provider: OAuthLoginMethod) => void
  isLoading?: boolean
  oauthLoading?: OAuthLoginMethod | null
  redirect?: string // Optional redirect URL to preserve when switching between sign-in/sign-up
}

export function SignIn({
  mode = 'sign-in',
  onSubmit,
  onOAuthLogin,
  isLoading,
  oauthLoading,
  redirect,
}: SignInProps) {
  const t = useT()
  const { isDebugModeOpen } = useDebugMode()
  const schema =
    mode === 'sign-in' ? createLoginSchema(t) : createSignUpSchema(t)
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      email: '',
      password: '',
      name: '',
    },
  })
  const [isCreatingDemo, setIsCreatingDemo] = useState(false)

  // Watch email value to pass it to recovery page
  const emailValue = form.watch('email')

  const location = useLocation()
  const [lastLoginMethod, setLastLoginMethod] = useState<LoginMethod | null>(
    () => getLastLoginMethod(),
  )
  const [showPassword, setShowPassword] = useState(false)
  const [expandedOAuth, setExpandedOAuth] = useState<OAuthLoginMethod>(() => {
    const last = getLastLoginMethod()
    return isOAuthLoginMethod(last) ? last : DEFAULT_CONSOLE_OAUTH_LOGIN
  })

  // Function to update last login method from storage
  const updateLastLoginMethod = () => {
    setLastLoginMethod(getLastLoginMethod())
  }

  useEffect(() => {
    // Read last login method on mount
    updateLastLoginMethod()

    // Also re-read when page becomes visible (e.g., returning from OAuth)
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        updateLastLoginMethod()
      }
    }

    // Re-read when window gains focus (e.g., returning from OAuth)
    const handleFocus = () => {
      updateLastLoginMethod()
    }

    document.addEventListener('visibilitychange', handleVisibilityChange)
    window.addEventListener('focus', handleFocus)

    return () => {
      document.removeEventListener('visibilitychange', handleVisibilityChange)
      window.removeEventListener('focus', handleFocus)
    }
  }, [])

  // Re-read when location changes (e.g., navigating back to sign-in page)
  useEffect(() => {
    updateLastLoginMethod()
  }, [location.pathname])

  const handleSubmit = (data: z.infer<typeof schema>) => {
    onSubmit(data)
  }

  const formBusy = isLoading || isCreatingDemo

  const handleCreateDemoUser = async () => {
    form.setValue('email', DEMO_USER_EMAIL, { shouldDirty: true, shouldValidate: true })
    form.setValue('password', DEMO_USER_PASSWORD, {
      shouldDirty: true,
      shouldValidate: true,
    })
    if (mode === 'sign-up') {
      form.setValue('name', DEMO_USER_NAME, { shouldDirty: true, shouldValidate: true })
    }
    setShowPassword(true)

    toast.message('Creating demo user', {
      description: `Email: ${DEMO_USER_EMAIL}. Password: ${DEMO_USER_PASSWORD}`,
    })

    setIsCreatingDemo(true)
    try {
      try {
        await sdk.forConsole.account.create({
          userId: ID.unique(),
          email: DEMO_USER_EMAIL,
          password: DEMO_USER_PASSWORD,
          name: DEMO_USER_NAME,
        })
      } catch (error: unknown) {
        if (!isAccountAlreadyExistsError(error)) {
          toast.error(getErrorMessage(error, 'Failed to create demo user'))
          return
        }
      }

      onSubmit(
        {
          email: DEMO_USER_EMAIL,
          password: DEMO_USER_PASSWORD,
          name: DEMO_USER_NAME,
        },
        { skipAccountCreate: true },
      )
    } finally {
      setIsCreatingDemo(false)
    }
  }

  return (
    <Card className="overflow-hidden py-0">
      <div className="grid md:grid-cols-2">
        <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
          <Form {...form}>
            <form
              onSubmit={form.handleSubmit(handleSubmit)}
              className="space-y-6"
            >
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {mode === 'sign-in'
                    ? t('Welcome back')
                    : t('Create an account')}
                </h1>
                <p className="text-sm text-muted-foreground">
                  {mode === 'sign-in'
                    ? t('Login to your account')
                    : t('Enter your details to create a new account')}
                </p>
              </div>

              {onOAuthLogin && (
                <>
                  <style>{OAUTH_ACCORDION_STYLES}</style>
                  <div className="oauth-login-row">
                    {OAUTH_PROVIDERS.map(({ id, Icon }) => {
                      const label = oauthProviderLabel(id, mode, t)
                      const isLastUsed =
                        mode === 'sign-in' && lastLoginMethod === id
                      const isExpanded = expandedOAuth === id
                      return (
                        <div
                          key={id}
                          className={
                            isExpanded
                              ? 'relative min-w-0 is-expanded'
                              : 'relative min-w-0'
                          }
                          onMouseEnter={() => setExpandedOAuth(id)}
                        >
                          {isLastUsed && (
                            <span
                              data-last-used={id}
                              className="oauth-last-used"
                            >
                              <span className="oauth-last-used-dot" />
                              <span className="oauth-last-used-pill">
                                {t('Last used')}
                              </span>
                            </span>
                          )}
                          <Button
                            variant="outline"
                            type="button"
                            data-provider={id}
                            aria-label={
                              isLastUsed ? `${label}. ${t('Last used')}` : label
                            }
                            className="h-9 w-full min-w-0 justify-center gap-0 overflow-hidden px-0 has-[>svg]:px-0"
                            onClick={() => onOAuthLogin(id)}
                            onFocus={() => setExpandedOAuth(id)}
                            disabled={!!oauthLoading || formBusy}
                          >
                            <Icon className="size-4 shrink-0" />
                            <span className="oauth-login-label">
                              <span>
                                <span className="oauth-login-label-text">
                                  {label}
                                </span>
                              </span>
                            </span>
                          </Button>
                        </div>
                      )
                    })}
                  </div>

                  <div className="relative">
                    <div className="absolute inset-0 flex items-center">
                      <span className="w-full border-t" />
                    </div>
                    <div className="relative flex justify-center text-xs uppercase">
                      <span className="bg-card px-2 text-muted-foreground">
                        {t('Or continue with')}
                      </span>
                    </div>
                  </div>
                </>
              )}

              <div className="space-y-4">
                {mode === 'sign-up' && (
                  <FormField
                    control={form.control}
                    name="name"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>{t('Name')}</FormLabel>
                        <FormControl>
                          <Input placeholder={t('Your name')} {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )}

                <FormField
                  control={form.control}
                  name="email"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('Email')}</FormLabel>
                      <FormControl>
                        <Input
                          type="email"
                          placeholder={t('Your email')}
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="password"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('Password')}</FormLabel>
                      <div className="relative">
                        <FormControl>
                          <Input
                            type={showPassword ? 'text' : 'password'}
                            placeholder={t('Your password')}
                            autoComplete={
                              mode === 'sign-up'
                                ? 'new-password'
                                : 'current-password'
                            }
                            className="pe-10"
                            {...field}
                          />
                        </FormControl>
                        <button
                          type="button"
                          onClick={() => setShowPassword((current) => !current)}
                          className="absolute end-2 top-1/2 -translate-y-1/2 rounded-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                          aria-label={
                            showPassword
                              ? t('Hide password')
                              : t('Show password')
                          }
                        >
                          {showPassword ? (
                            <EyeOff className="h-4 w-4" />
                          ) : (
                            <Eye className="h-4 w-4" />
                          )}
                        </button>
                      </div>
                      <FormMessage />
                      {mode === 'sign-in' && (
                        <Link
                          to="/recovery"
                          search={
                            emailValue ? { email: emailValue } : undefined
                          }
                          className="link-neutral text-sm"
                        >
                          {t('Forgot your password?')}
                        </Link>
                      )}
                    </FormItem>
                  )}
                />
              </div>

              <div className="relative">
                {mode === 'sign-in' && lastLoginMethod === 'email' && (
                  <span className="absolute -top-2 start-3 bg-foreground text-background text-[10px] font-medium px-1.5 py-0.5 rounded border border-border z-10">
                    {t('Last used')}
                  </span>
                )}
                <Button type="submit" className="w-full" disabled={formBusy}>
                  {mode === 'sign-in' ? t('Login') : t('Sign up')}
                </Button>
              </div>

              <p className="text-center text-sm text-muted-foreground">
                {mode === 'sign-in'
                  ? t("Don't have an account?")
                  : t('Already have an account?')}{' '}
                <Link
                  to={mode === 'sign-in' ? '/sign-up' : '/sign-in'}
                  search={redirect ? { redirect } : undefined}
                  className="link-neutral"
                >
                  {mode === 'sign-in' ? t('Sign up') : t('Sign in')}
                </Link>
              </p>
            </form>
          </Form>
          {isDebugModeOpen ? (
            <div
              dir="ltr"
              lang="en"
              data-analytics-track="false"
              className="mt-6 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 p-3 text-left"
            >
              <p className="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-[var(--network-globe-edge)]/75">
                <Bug className="h-3 w-3 shrink-0 text-[var(--network-globe-edge)]" />
                Debug
              </p>
              <p className="mt-1 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
                Create a demo user and sign in with these credentials.
              </p>
              <dl className="mt-2 space-y-1 font-mono text-[11px]">
                <div className="flex gap-2">
                  <dt className="shrink-0 text-[var(--network-globe-edge)]/70">
                    Email
                  </dt>
                  <dd className="min-w-0 break-all text-foreground">
                    {DEMO_USER_EMAIL}
                  </dd>
                </div>
                <div className="flex gap-2">
                  <dt className="shrink-0 text-[var(--network-globe-edge)]/70">
                    Password
                  </dt>
                  <dd className="min-w-0 break-all text-foreground">
                    {DEMO_USER_PASSWORD}
                  </dd>
                </div>
              </dl>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-3 h-7 border-[color-mix(in_srgb,var(--network-globe-edge)_30%,var(--border))] bg-transparent text-[12px] text-foreground hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
                disabled={formBusy}
                onClick={() => {
                  void handleCreateDemoUser()
                }}
              >
                Create demo user
              </Button>
            </div>
          ) : null}
        </div>
        <div className="hidden bg-background md:block min-h-[600px]">
          <img
            alt="Image"
            className="h-full w-full object-cover"
            height="600"
            src={assetUrl("/cover.avif")}
            width="600"
          />
        </div>
      </div>
    </Card>
  )
}
