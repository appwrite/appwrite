import { useMutation } from '@tanstack/react-query'
import {
  createFileRoute,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { SignIn } from '@/components/global/auth/SignIn'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException, ID } from '@appwrite.io/console'
import { toast } from 'sonner'

// Helper function to validate that a redirect URL is relative (prevents redirect hijacking)
function isValidRelativeRedirect(url: string): boolean {
  try {
    // Must start with / and not contain :// (which would indicate a protocol)
    return url.startsWith('/') && !url.includes('://')
  } catch {
    return false
  }
}

const searchSchema = z.object({
  redirect: z.string().optional().refine(
    (val) => !val || isValidRelativeRedirect(val),
    { message: 'Redirect must be a relative URL' }
  ),
})

export const Route = createFileRoute('/_auth/sign-up')({
  component: SignUpPage,
  validateSearch: searchSchema,
})

function SignUpPage() {
  const search = useSearch({ from: '/_auth/sign-up' })
  const navigate = useNavigate()
  const router = useRouter()

  const signUpMutation = useMutation({
    mutationFn: async (data: { email: string; password: string }) => {
      try {
        // Create account
        await sdk.forConsole.account.create({
          userId: ID.unique(),
          email: data.email,
          password: data.password,
        })
        
        // Create session
        await sdk.forConsole.account.createEmailPasswordSession({
          email: data.email,
          password: data.password,
        })
      } catch (error) {
        if (error instanceof AppwriteException) {
          throw new Error(error.message || 'Failed to sign up')
        }
        throw error
      }
    },
    onSuccess: async () => {
      await router.invalidate()
      if (search.redirect && isValidRelativeRedirect(search.redirect)) {
        navigate({ to: search.redirect as any })
      } else {
        navigate({ to: '/' })
      }
    },
    onError: async (error: any) => {
      const errorMessage = error?.message || 'Failed to sign up'
      toast.error(errorMessage)
      console.error('Sign up error:', error)
    },
  })

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <SignIn
          mode="sign-up"
          onSubmit={(data) => signUpMutation.mutate(data)}
          isLoading={signUpMutation.isPending}
          redirect={search.redirect}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          By clicking continue, you agree to our{' '}
          <a href="#" className="underline underline-offset-4 hover:text-primary">
            Terms of Service
          </a>{' '}
          and{' '}
          <a href="#" className="underline underline-offset-4 hover:text-primary">
            Privacy Policy
          </a>
          .
        </p>
        <div className="mt-6 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
