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

const resetPasswordSchema = z
  .object({
    password: z.string().min(8, 'Password must be at least 8 characters'),
    confirmPassword: z.string().min(8, 'Please confirm your password'),
  })
  .refine((data) => data.password === data.confirmPassword, {
    message: "Passwords don't match",
    path: ['confirmPassword'],
  })

interface ResetProps {
  onSubmit: (data: { password: string }) => void
  isLoading?: boolean
  isSuccess?: boolean
}

export function Reset({ onSubmit, isLoading, isSuccess }: ResetProps) {
  const form = useForm<z.infer<typeof resetPasswordSchema>>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: {
      password: '',
      confirmPassword: '',
    },
  })

  const handleSubmit = (data: z.infer<typeof resetPasswordSchema>) => {
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
                  Password reset
                </h1>
                <p className="text-sm text-muted-foreground">
                  Your password has been successfully reset. You can now sign in
                  with your new password.
                </p>
              </div>
              <Link to="/sign-in">
                <Button className="w-full">Sign in</Button>
              </Link>
            </div>
          </div>
          <div className="hidden bg-background md:block min-h-[600px]">
            <img
              alt="Image"
              className="h-full w-full object-cover"
              height="600"
              src="/cover.png"
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
                  Reset your password
                </h1>
                <p className="text-sm text-muted-foreground">
                  Enter your new password below.
                </p>
              </div>

              <div className="space-y-4">
                <FormField
                  control={form.control}
                  name="password"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>New Password</FormLabel>
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
                      <FormLabel>Confirm Password</FormLabel>
                      <FormControl>
                        <Input type="password" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>

              <Button type="submit" className="w-full" disabled={isLoading}>
                Reset password
              </Button>

              <p className="text-center text-sm text-muted-foreground">
                Remember your password?{' '}
                <Link to="/sign-in" className="link-neutral">
                  Sign in
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
            src="/cover.png"
            width="600"
          />
        </div>
      </div>
    </Card>
  )
}
