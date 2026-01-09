import { useState } from 'react'
import { useOnboarding } from '../OnboardingLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  ExternalLink,
  GitBranch,
  Globe,
  Link2,
  RefreshCw,
  Search,
  Settings,
  SkipForward,
} from 'lucide-react'
import { motion } from 'motion/react'
import { frameworks, gitProviders, mockRepositories } from '../data'

type DeployStep =
  | 'connect-repo'
  | 'select-framework'
  | 'configure-domain'
  | 'deploy'

export function DeploySitePath() {
  const { setSelectedIntent } = useOnboarding()
  const [currentStep, setCurrentStep] = useState<DeployStep>('connect-repo')
  const [selectedProvider, setSelectedProvider] = useState<string | null>(null)
  const [selectedRepo, setSelectedRepo] = useState<string | null>(null)
  const [selectedFramework, setSelectedFramework] = useState<string | null>(
    null,
  )
  const [searchQuery, setSearchQuery] = useState('')
  const [customDomain, setCustomDomain] = useState('')
  const [isDeploying, setIsDeploying] = useState(false)
  const [deployComplete, setDeployComplete] = useState(false)

  const filteredRepos = mockRepositories.filter((repo) =>
    repo.name.toLowerCase().includes(searchQuery.toLowerCase()),
  )

  const handleDeploy = () => {
    setIsDeploying(true)
    setTimeout(() => {
      setIsDeploying(false)
      setDeployComplete(true)
    }, 3000)
  }

  const renderStepContent = () => {
    switch (currentStep) {
      case 'connect-repo':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Connect your repository</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Import a Git repository to deploy your site.
              </p>
            </div>

            {/* Git providers */}
            <div className="space-y-3">
              <p className="text-sm font-medium">Select provider</p>
              <div className="flex gap-3">
                {gitProviders.map((provider) => (
                  <button
                    key={provider.id}
                    onClick={() => setSelectedProvider(provider.id)}
                    className={cn(
                      'flex flex-1 items-center justify-center gap-2 rounded-lg border py-3 transition-all',
                      selectedProvider === provider.id
                        ? 'border-[#f02e65] bg-[#f02e65]/5'
                        : 'border-border hover:border-muted-foreground/50',
                    )}
                  >
                    <span className="text-sm font-medium">{provider.name}</span>
                  </button>
                ))}
              </div>
            </div>

            {/* Repository list */}
            {selectedProvider && (
              <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                className="space-y-3"
              >
                <div className="flex items-center gap-3">
                  <div className="relative flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      placeholder="Search repositories..."
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      className="pl-9"
                    />
                  </div>
                  <Button variant="outline" size="icon">
                    <RefreshCw className="h-4 w-4" />
                  </Button>
                </div>

                <div className="max-h-[280px] space-y-1 overflow-y-auto rounded-lg border border-border">
                  {filteredRepos.map((repo) => (
                    <button
                      key={repo.id}
                      onClick={() => setSelectedRepo(repo.id)}
                      className={cn(
                        'flex w-full items-center gap-3 px-4 py-3 text-left transition-colors',
                        selectedRepo === repo.id
                          ? 'bg-accent'
                          : 'hover:bg-accent/50',
                      )}
                    >
                      <GitBranch className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <div className="flex-1 overflow-hidden">
                        <p className="truncate text-sm font-medium">
                          {repo.owner}/{repo.name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          Updated {repo.updatedAt}
                        </p>
                      </div>
                      {selectedRepo === repo.id && (
                        <Check className="h-4 w-4 text-[#f02e65]" />
                      )}
                    </button>
                  ))}
                </div>
              </motion.div>
            )}
          </div>
        )

      case 'select-framework':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Select framework</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Choose your build framework. We'll auto-detect settings when
                possible.
              </p>
            </div>

            <div className="grid grid-cols-5 gap-3">
              {frameworks.map((framework) => (
                <button
                  key={framework.id}
                  onClick={() => setSelectedFramework(framework.id)}
                  className={cn(
                    'flex flex-col items-center gap-2 rounded-lg border p-4 transition-all',
                    selectedFramework === framework.id
                      ? 'border-[#f02e65] bg-[#f02e65]/5'
                      : 'border-border hover:border-muted-foreground/50',
                  )}
                >
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-sm font-bold">
                    {framework.icon}
                  </div>
                  <span className="text-xs font-medium">{framework.name}</span>
                </button>
              ))}
            </div>

            {selectedFramework && (
              <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                className="rounded-lg border border-border bg-muted/30 p-4"
              >
                <div className="flex items-center gap-2 text-sm">
                  <Settings className="h-4 w-4 text-muted-foreground" />
                  <span className="font-medium">Build settings</span>
                </div>
                <div className="mt-3 space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Build command</span>
                    <code className="rounded bg-muted px-2 py-0.5 font-mono text-xs">
                      npm run build
                    </code>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">
                      Output directory
                    </span>
                    <code className="rounded bg-muted px-2 py-0.5 font-mono text-xs">
                      .next
                    </code>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">
                      Install command
                    </span>
                    <code className="rounded bg-muted px-2 py-0.5 font-mono text-xs">
                      npm install
                    </code>
                  </div>
                </div>
              </motion.div>
            )}
          </div>
        )

      case 'configure-domain':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Configure domain</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Set up your custom domain or use the default Appwrite subdomain.
              </p>
            </div>

            {/* Default domain */}
            <div className="rounded-lg border border-border p-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <Globe className="h-5 w-5" />
                </div>
                <div className="flex-1">
                  <p className="text-sm font-medium">Default domain</p>
                  <p className="font-mono text-xs text-muted-foreground">
                    my-nextjs-app.appwrite.network
                  </p>
                </div>
                <Check className="h-5 w-5 text-green-500" />
              </div>
            </div>

            {/* Custom domain */}
            <div className="space-y-3">
              <p className="text-sm font-medium">
                Add custom domain (optional)
              </p>
              <div className="flex gap-3">
                <div className="relative flex-1">
                  <Link2 className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    placeholder="example.com"
                    value={customDomain}
                    onChange={(e) => setCustomDomain(e.target.value)}
                    className="pl-9"
                  />
                </div>
                <Button variant="outline">Add domain</Button>
              </div>
              <p className="text-xs text-muted-foreground">
                You'll need to configure DNS records after deployment.
              </p>
            </div>

            {/* Environment variables */}
            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2 text-sm">
                  <Settings className="h-4 w-4 text-muted-foreground" />
                  <span className="font-medium">Environment variables</span>
                </div>
                <Button variant="ghost" size="sm" className="h-7 text-xs">
                  Add variable
                </Button>
              </div>
              <p className="mt-2 text-xs text-muted-foreground">
                No environment variables configured. Add them now or after
                deployment.
              </p>
            </div>
          </div>
        )

      case 'deploy':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Deploy your site</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Review your configuration and trigger the first deployment.
              </p>
            </div>

            {/* Summary */}
            <div className="space-y-3 rounded-lg border border-border p-4">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Repository</span>
                <span className="font-medium">username/my-nextjs-app</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Framework</span>
                <span className="font-medium">Next.js</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Branch</span>
                <span className="font-medium">main</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Domain</span>
                <span className="font-mono text-xs">
                  my-nextjs-app.appwrite.network
                </span>
              </div>
            </div>

            {/* Deploy status */}
            {isDeploying && (
              <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                className="rounded-lg border border-border bg-muted/30 p-4"
              >
                <div className="flex items-center gap-3">
                  <div className="h-4 w-4 animate-spin rounded-full border-2 border-[#f02e65] border-t-transparent" />
                  <span className="text-sm">Deploying...</span>
                </div>
                <div className="mt-3 space-y-1 font-mono text-xs text-muted-foreground">
                  <p>→ Cloning repository...</p>
                  <p>→ Installing dependencies...</p>
                  <p>→ Building application...</p>
                </div>
              </motion.div>
            )}

            {deployComplete && (
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
                      Deployment successful!
                    </p>
                    <p className="text-sm text-muted-foreground">
                      Your site is now live.
                    </p>
                  </div>
                </div>
                <div className="mt-4 flex gap-3">
                  <Button size="sm" className="gap-2">
                    <ExternalLink className="h-3.5 w-3.5" />
                    View site
                  </Button>
                  <Button variant="outline" size="sm">
                    View deployment
                  </Button>
                </div>
              </motion.div>
            )}

            {!isDeploying && !deployComplete && (
              <Button
                onClick={handleDeploy}
                className="w-full gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
              >
                Deploy now
                <ArrowRight className="h-4 w-4" />
              </Button>
            )}
          </div>
        )
    }
  }

  const steps: DeployStep[] = [
    'connect-repo',
    'select-framework',
    'configure-domain',
    'deploy',
  ]
  const currentStepIndex = steps.indexOf(currentStep)

  const canProceed = () => {
    switch (currentStep) {
      case 'connect-repo':
        return selectedProvider && selectedRepo
      case 'select-framework':
        return selectedFramework
      case 'configure-domain':
        return true
      case 'deploy':
        return deployComplete
      default:
        return false
    }
  }

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
          <Globe className="h-4 w-4 text-[#f02e65]" />
          <span className="text-sm font-medium">Deploy a site</span>
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
          {currentStep !== 'deploy' && (
            <Button
              variant="ghost"
              onClick={() => {
                if (currentStepIndex < steps.length - 1) {
                  setCurrentStep(steps[currentStepIndex + 1])
                }
              }}
              className="gap-2 text-muted-foreground"
            >
              <SkipForward className="h-4 w-4" />
              Skip
            </Button>
          )}
          {currentStepIndex < steps.length - 1 && (
            <Button
              onClick={() => setCurrentStep(steps[currentStepIndex + 1])}
              disabled={!canProceed()}
              className="gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
            >
              Continue
              <ArrowRight className="h-4 w-4" />
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
