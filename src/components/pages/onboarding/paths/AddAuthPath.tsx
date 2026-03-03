import { useState } from 'react'
import { useOnboarding } from '../OnboardingLayout'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Copy,
  SkipForward,
  Users,
} from 'lucide-react'
import { motion } from 'motion/react'
import { sdks, codeSnippets } from '../data'

type AuthStep =
  | 'install-sdk'
  | 'init-client'
  | 'create-account'
  | 'create-session'
  | 'get-user'

const clientSdks = ['web', 'flutter', 'apple', 'android', 'react-native']

export function AddAuthPath() {
  const { state, setSelectedIntent, setSelectedSdk } = useOnboarding()
  const [currentStep, setCurrentStep] = useState<AuthStep>('install-sdk')
  const [copiedSnippet, setCopiedSnippet] = useState<string | null>(null)

  const filteredSdks = sdks.filter((sdk) => clientSdks.includes(sdk.id))

  const copyToClipboard = (text: string, id: string) => {
    navigator.clipboard.writeText(text)
    setCopiedSnippet(id)
    setTimeout(() => setCopiedSnippet(null), 2000)
  }

  const getSnippet = (snippetKey: keyof typeof codeSnippets) => {
    const snippets = codeSnippets[snippetKey] as Record<string, string>
    return snippets[state.selectedSdk] || snippets['web'] || ''
  }

  const renderStepContent = () => {
    switch (currentStep) {
      case 'install-sdk':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Install the SDK</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Add the Appwrite SDK to your project.
              </p>
            </div>

            {/* SDK selector */}
            <div className="space-y-3">
              <p className="text-sm font-medium">Select SDK</p>
              <div className="flex flex-wrap gap-2">
                {filteredSdks.map((sdk) => (
                  <button
                    key={sdk.id}
                    onClick={() => setSelectedSdk(sdk.id)}
                    className={cn(
                      'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-all',
                      state.selectedSdk === sdk.id
                        ? 'border-[#f02e65] bg-[#f02e65]/5'
                        : 'border-border hover:border-muted-foreground/50',
                    )}
                  >
                    {sdk.name}
                    {state.selectedSdk === sdk.id && (
                      <Check className="h-3.5 w-3.5 text-[#f02e65]" />
                    )}
                  </button>
                ))}
              </div>
            </div>

            {/* Install command */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Install command</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('install')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(getSnippet('install'), 'install')
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'install' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>
          </div>
        )

      case 'init-client':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Initialize the client</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Configure the Appwrite client with your project credentials.
              </p>
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <p className="text-sm font-medium">Client initialization</p>
                <span className="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                  {state.selectedSdk}
                </span>
              </div>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('initClient')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(getSnippet('initClient'), 'init')
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'init' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">Your project credentials</p>
              <div className="mt-3 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Project ID</span>
                  <code className="rounded bg-muted px-2 py-0.5 font-mono text-xs">
                    {state.projectId}
                  </code>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Endpoint</span>
                  <code className="rounded bg-muted px-2 py-0.5 font-mono text-xs">
                    {getBaseEndpoint()}
                  </code>
                </div>
              </div>
            </div>
          </div>
        )

      case 'create-account':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Create an account</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Implement user registration in your application.
              </p>
            </div>

            <div className="space-y-2">
              <p className="text-sm font-medium">Create account</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('createAccount')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(
                      getSnippet('createAccount'),
                      'createAccount',
                    )
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'createAccount' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
              <p className="text-sm font-medium text-amber-600 dark:text-amber-400">
                Note
              </p>
              <p className="mt-1 text-sm text-muted-foreground">
                Creating an account does not automatically create a session.
                Users need to log in after registration.
              </p>
            </div>
          </div>
        )

      case 'create-session':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Create a session</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Implement user login to create an authenticated session.
              </p>
            </div>

            <div className="space-y-2">
              <p className="text-sm font-medium">Email/password login</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('createSession')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(
                      getSnippet('createSession'),
                      'createSession',
                    )
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'createSession' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">
                Other authentication methods
              </p>
              <div className="mt-3 flex flex-wrap gap-2">
                {['OAuth', 'Magic URL', 'Phone', 'Anonymous'].map((method) => (
                  <span
                    key={method}
                    className="rounded bg-muted px-2 py-1 text-xs text-muted-foreground"
                  >
                    {method}
                  </span>
                ))}
              </div>
            </div>
          </div>
        )

      case 'get-user':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Get current user</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Fetch the authenticated user's information.
              </p>
            </div>

            <div className="space-y-2">
              <p className="text-sm font-medium">Get user</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('getUser')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(getSnippet('getUser'), 'getUser')
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'getUser' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            <motion.div
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              className="rounded-lg border border-green-500/30 bg-green-500/10 p-4"
            >
              <div className="flex items-center gap-3">
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-green-500">
                  <Check className="h-4 w-4 text-white" />
                </div>
                <div>
                  <p className="font-medium text-green-600 dark:text-green-400">
                    Authentication setup complete!
                  </p>
                  <p className="text-sm text-muted-foreground">
                    You can now authenticate users in your app.
                  </p>
                </div>
              </div>
            </motion.div>
          </div>
        )
    }
  }

  const steps: AuthStep[] = [
    'install-sdk',
    'init-client',
    'create-account',
    'create-session',
    'get-user',
  ]
  const currentStepIndex = steps.indexOf(currentStep)

  return (
    <div>
      {/* Header */}
      <div className="mb-8 flex items-center gap-4">
        <button
          onClick={() => setSelectedIntent(null)}
          className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Back
        </button>
        <div className="h-4 w-px bg-border" />
        <div className="flex items-center gap-2">
          <Users className="h-4 w-4 text-[#f02e65]" />
          <span className="text-sm font-medium">Add authentication</span>
        </div>
      </div>

      {/* Content */}
      <motion.div
        key={currentStep}
        initial={{ opacity: 0, x: 20 }}
        animate={{ opacity: 1, x: 0 }}
        transition={{ duration: 0.2 }}
      >
        {renderStepContent()}
      </motion.div>

      {/* Navigation */}
      <div className="mt-8 flex items-center justify-between border-t border-border pt-6">
        <Button
          variant="ghost"
          onClick={() => {
            if (currentStepIndex > 0) {
              setCurrentStep(steps[currentStepIndex - 1])
            }
          }}
          disabled={currentStepIndex === 0}
          className="gap-2"
        >
          <ArrowLeft className="h-4 w-4" />
          Previous
        </Button>

        <div className="flex gap-3">
          {currentStepIndex < steps.length - 1 && (
            <>
              <Button
                variant="ghost"
                onClick={() => setCurrentStep(steps[currentStepIndex + 1])}
                className="gap-2 text-muted-foreground"
              >
                <SkipForward className="h-4 w-4" />
                Skip
              </Button>
              <Button
                onClick={() => setCurrentStep(steps[currentStepIndex + 1])}
                className="gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
              >
                Continue
                <ArrowRight className="h-4 w-4" />
              </Button>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
