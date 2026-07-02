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
import { Card } from '@/components/ui/card'
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
}

export function Recovery({
  onSubmit,
  isLoading,
  isSuccess,
  initialEmail,
}: RecoveryProps) {
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
      <Card className="overflow-hidden py-0">
        <div className="grid md:grid-cols-2">
          <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
            <div className="space-y-6">
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {t('Check your email')}
                </h1>
                <p className="text-sm text-muted-foreground">
                  {t("We've sent a password recovery link to your email address.")}
                </p>
              </div>
              <Link to="/sign-in">
                <Button variant="outline" className="w-full">
                  {t('Back to sign in')}
                </Button>
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
                  {t(
                    "Enter your email address and we'll send you a link to reset your password.",
                  )}
                </p>
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

              <Button type="submit" className="w-full" disabled={isLoading}>
                {t('Send recovery link')}
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
        <div className="hidden bg-background md:block">
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
