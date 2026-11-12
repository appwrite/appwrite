'use client'

import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import * as z from 'zod'
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
import {
  AuthFlowDescription,
  AuthFlowIllustrationCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowIllustrationColumn } from '@/components/global/auth/AuthFlowShell'
import { useT, type Translator } from '@/lib/i18n/translate'

const createResetPasswordSchema = (t: Translator) =>
  z
    .object({
      password: z.string().min(8, t('Password must be at least 8 characters')),
      confirmPassword: z.string().min(8, t('Please confirm your password')),
    })
    .refine((data) => data.password === data.confirmPassword, {
      message: t("Passwords don't match"),
      path: ['confirmPassword'],
    })

type ResetPasswordValues = z.infer<ReturnType<typeof createResetPasswordSchema>>

interface ResetProps {
  onSubmit: (data: { password: string }) => void
  isLoading?: boolean
  isSuccess?: boolean
  preview?: boolean
}

const illustration = <AuthFlowIllustrationColumn />

export function Reset({
  onSubmit,
  isLoading,
  isSuccess,
  preview = false,
}: ResetProps) {
  const signInTo = preview ? '/debug/sign-in-preview' : '/sign-in'
  const t = useT()
  const form = useForm<ResetPasswordValues>({
    resolver: zodResolver(createResetPasswordSchema(t)),
    defaultValues: {
      password: '',
      confirmPassword: '',
    },
  })

  const handleSubmit = (data: ResetPasswordValues) => {
    onSubmit({ password: data.password })
  }

  if (isSuccess) {
    return (
      <AuthFlowIllustrationCard illustration={illustration}>
        <div className="space-y-6">
          <div className="space-y-2">
            <AuthFlowTitle>{t('Password reset')}</AuthFlowTitle>
            <AuthFlowDescription>
              {t(
                'Your password has been successfully reset. You can now sign in with your new password.',
              )}
            </AuthFlowDescription>
          </div>
          <Link to={signInTo}>
            <Button variant="brandCta" className="w-full">
              {t('Sign in')}
            </Button>
          </Link>
        </div>
      </AuthFlowIllustrationCard>
    )
  }

  return (
    <AuthFlowIllustrationCard illustration={illustration}>
      <Form {...form}>
        <form
          onSubmit={form.handleSubmit(handleSubmit)}
          className="space-y-6"
        >
          <div className="space-y-2">
            <AuthFlowTitle>{t('Reset your password')}</AuthFlowTitle>
            <AuthFlowDescription>
              {t('Enter your new password below.')}
            </AuthFlowDescription>
          </div>

          <div className="space-y-4">
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('New Password')}</FormLabel>
                  <FormControl>
                    <Input type="password" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="confirmPassword"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('Confirm Password')}</FormLabel>
                  <FormControl>
                    <Input type="password" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>

          <Button
            type="submit"
            variant="brandCta"
            className="w-full"
            disabled={isLoading}
          >
            {t('Reset password')}
          </Button>

          <AuthFlowDescription className="text-center">
            {t('Remember your password?')}{' '}
            <Link to={signInTo} className="link-neutral">
              {t('Sign in')}
            </Link>
          </AuthFlowDescription>
        </form>
      </Form>
    </AuthFlowIllustrationCard>
  )
}
