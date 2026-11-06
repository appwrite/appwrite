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
  FormDescription,
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

const createRecoverySchema = (t: Translator) =>
  z.object({
    email: z.string().email(t('Please enter a valid email address')),
  })

type RecoveryValues = z.infer<ReturnType<typeof createRecoverySchema>>

interface RecoveryProps {
  onSubmit: (data: { email: string }) => void
  isLoading?: boolean
  isSuccess?: boolean
  initialEmail?: string
  preview?: boolean
}

const illustration = <AuthFlowIllustrationColumn />

export function Recovery({
  onSubmit,
  isLoading,
  isSuccess,
  initialEmail,
  preview = false,
}: RecoveryProps) {
  const signInTo = preview ? '/debug/sign-in-preview' : '/sign-in'
  const t = useT()
  const form = useForm<RecoveryValues>({
    resolver: zodResolver(createRecoverySchema(t)),
    defaultValues: {
      email: initialEmail || '',
    },
  })

  const handleSubmit = (data: RecoveryValues) => {
    onSubmit(data)
  }

  if (isSuccess) {
    return (
      <AuthFlowIllustrationCard illustration={illustration}>
        <div className="space-y-6">
          <div className="space-y-2">
            <AuthFlowTitle>{t('Check your email')}</AuthFlowTitle>
            <AuthFlowDescription>
              {t("We've sent a password recovery link to your email address.")}
            </AuthFlowDescription>
          </div>
          <Link to={signInTo}>
            <Button variant="outline" className="w-full">
              {t('Back to sign in')}
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
              {t(
                "Enter your email address and we'll send you a link to reset your password.",
              )}
            </AuthFlowDescription>
          </div>

          <div className="space-y-4">
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
                  <FormDescription>
                    {t("We'll send a recovery link to this email address.")}
                  </FormDescription>
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
            {t('Send recovery link')}
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
