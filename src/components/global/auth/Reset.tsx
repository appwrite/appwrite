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
import { Card } from '@/components/ui/card'
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
}

export function Reset({ onSubmit, isLoading, isSuccess }: ResetProps) {
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
      <Card className="overflow-hidden py-0">
        <div className="grid md:grid-cols-2">
          <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
            <div className="space-y-6">
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {t('Password reset')}
                </h1>
                <p className="text-sm text-muted-foreground">
                  {t(
                    'Your password has been successfully reset. You can now sign in with your new password.',
                  )}
                </p>
              </div>
              <Link to="/sign-in">
                <Button className="w-full">{t('Sign in')}</Button>
              </Link>
            </div>
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
                  {t('Reset your password')}
                </h1>
                <p className="text-sm text-muted-foreground">
                  {t('Enter your new password below.')}
                </p>
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

              <Button type="submit" className="w-full" disabled={isLoading}>
                {t('Reset password')}
              </Button>

              <p className="text-center text-sm text-muted-foreground">
                {t('Remember your password?')}{' '}
                <Link to="/sign-in" className="link-neutral">
                  {t('Sign in')}
                </Link>
              </p>
            </form>
          </Form>
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
