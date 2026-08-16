'use client'

import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import * as z from 'zod'
import { useEffect, useState } from 'react'
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
import { getLastLoginMethod } from '@/lib/utils/auth-storage'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT, type Translator } from '@/lib/i18n/translate'

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

interface SignInProps {
  mode?: 'sign-in' | 'sign-up'
  onSubmit: (data: FormValues, options?: SignInSubmitOptions) => void
  onGitHubLogin?: () => void
  isLoading?: boolean
  isGitHubLoading?: boolean
  redirect?: string // Optional redirect URL to preserve when switching between sign-in/sign-up
}

export function SignIn({
  mode = 'sign-in',
  onSubmit,
  onGitHubLogin,
  isLoading,
  isGitHubLoading,
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
  const [lastLoginMethod, setLastLoginMethod] = useState<
    'github' | 'email' | null
  >(null)
  const [showPassword, setShowPassword] = useState(false)

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

              {onGitHubLogin && (
                <>
                  <div className="relative">
                    {lastLoginMethod === 'github' && (
                      <span className="absolute -top-2 start-3 bg-foreground text-background text-[10px] font-medium px-1.5 py-0.5 rounded border border-border z-10">
                        {t('Last used')}
                      </span>
                    )}
                    <Button
                      variant="outline"
                      type="button"
                      className="w-full"
                      onClick={onGitHubLogin}
                      disabled={isGitHubLoading || formBusy}
                    >
                      <svg
                        className="me-1.5 h-4 w-4"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                      >
                        <path
                          fillRule="evenodd"
                          clipRule="evenodd"
                          d="M12 2C6.477 2 2 6.477 2 12c0 4.42 2.865 8.17 6.839 9.49.5.092.682-.217.682-.482 0-.237-.008-.866-.013-1.7-2.782.603-3.369-1.34-3.369-1.34-.454-1.156-1.11-1.463-1.11-1.463-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.11-4.555-4.943 0-1.091.39-1.984 1.029-2.683-.103-.253-.446-1.27.098-2.647 0 0 .84-.269 2.75 1.025A9.578 9.578 0 0112 6.836c.85.004 1.705.114 2.504.336 1.909-1.294 2.747-1.025 2.747-1.025.546 1.377.203 2.394.1 2.647.64.699 1.028 1.592 1.028 2.683 0 3.842-2.339 4.687-4.566 4.935.359.309.678.919.678 1.852 0 1.336-.012 2.415-.012 2.737 0 .267.18.578.688.48C19.138 20.167 22 16.418 22 12c0-5.523-4.477-10-10-10z"
                        />
                      </svg>
                      {mode === 'sign-up'
                        ? t('Sign up with GitHub')
                        : t('Login with GitHub')}
                    </Button>
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
                          placeholder="m@example.com"
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
                      <FormControl>
                        <div className="relative">
                          <Input
                            type={showPassword ? 'text' : 'password'}
                            className="pe-10"
                            {...field}
                          />
                          <button
                            type="button"
                            onClick={() => setShowPassword((current) => !current)}
                            className="absolute end-2 top-1/2 -translate-y-1/2 rounded-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label={
                              showPassword ? t('Hide password') : t('Show password')
                            }
                          >
                            {showPassword ? (
                              <EyeOff className="h-4 w-4" />
                            ) : (
                              <Eye className="h-4 w-4" />
                            )}
                          </button>
                        </div>
                      </FormControl>
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
                {lastLoginMethod === 'email' && (
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
            src="/cover.avif"
            width="600"
          />
        </div>
      </div>
    </Card>
  )
}
