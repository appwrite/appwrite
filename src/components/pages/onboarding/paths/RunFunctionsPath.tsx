import { useState } from 'react'
import { useOnboarding } from '../OnboardingLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Copy,
  Play,
  SkipForward,
  Zap,
} from 'lucide-react'
import { motion } from 'motion/react'
import { codeSnippets } from '../data'

type FunctionStep =
  | 'create-function'
  | 'write-code'
  | 'deploy-function'
  | 'execute-function'

const runtimes = [
  { id: 'node-18', name: 'Node.js 18', icon: 'N' },
  { id: 'node-20', name: 'Node.js 20', icon: 'N' },
  { id: 'python-3.11', name: 'Python 3.11', icon: 'P' },
  { id: 'python-3.12', name: 'Python 3.12', icon: 'P' },
  { id: 'php-8.2', name: 'PHP 8.2', icon: 'P' },
  { id: 'ruby-3.2', name: 'Ruby 3.2', icon: 'R' },
  { id: 'deno-1.35', name: 'Deno 1.35', icon: 'D' },
  { id: 'bun-1.0', name: 'Bun 1.0', icon: 'B' },
]

export function RunFunctionsPath() {
  const { state, setSelectedIntent } = useOnboarding()
  const [currentStep, setCurrentStep] =
    useState<FunctionStep>('create-function')
  const [copiedSnippet, setCopiedSnippet] = useState<string | null>(null)
  const [functionName, setFunctionName] = useState('')
  const [selectedRuntime, setSelectedRuntime] = useState('node-18')
  const [isExecuting, setIsExecuting] = useState(false)
  const [executionResult, setExecutionResult] = useState<string | null>(null)

  const copyToClipboard = (text: string, id: string) => {
    navigator.clipboard.writeText(text)
    setCopiedSnippet(id)
    setTimeout(() => setCopiedSnippet(null), 2000)
  }

  const getSnippet = (snippetKey: keyof typeof codeSnippets) => {
    const snippets = codeSnippets[snippetKey] as Record<string, string>
    return snippets[state.selectedSdk] || snippets['web'] || ''
  }

  const handleExecute = () => {
    setIsExecuting(true)
    setTimeout(() => {
      setIsExecuting(false)
      setExecutionResult(
        JSON.stringify(
          { message: 'Hello from Appwrite Functions!', timestamp: Date.now() },
          null,
          2,
        ),
      )
    }, 2000)
  }

  const renderStepContent = () => {
    switch (currentStep) {
      case 'create-function':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Create a function</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Serverless functions run on-demand without managing
                infrastructure.
              </p>
            </div>

            {/* Function name */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Function name</label>
              <Input
                placeholder="my-function"
                value={functionName}
                onChange={(e) => setFunctionName(e.target.value)}
              />
            </div>

            {/* Runtime selector */}
            <div className="space-y-3">
              <p className="text-sm font-medium">Runtime</p>
              <div className="grid grid-cols-4 gap-2">
                {runtimes.map((runtime) => (
                  <button
                    key={runtime.id}
                    onClick={() => setSelectedRuntime(runtime.id)}
                    className={cn(
                      'flex flex-col items-center gap-2 rounded-lg border p-3 transition-all',
                      selectedRuntime === runtime.id
                        ? 'border-[#f02e65] bg-[#f02e65]/5'
                        : 'border-border hover:border-muted-foreground/50',
                    )}
                  >
                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-sm font-bold">
                      {runtime.icon}
                    </div>
                    <span className="text-xs font-medium">{runtime.name}</span>
                  </button>
                ))}
              </div>
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Code</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('createFunction')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(
                      getSnippet('createFunction'),
                      'createFunction',
                    )
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'createFunction' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>
          </div>
        )

      case 'write-code':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Write your function</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Implement your function logic. Functions receive context and can
                return responses.
              </p>
            </div>

            {/* Code editor placeholder */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <p className="text-sm font-medium">main.js</p>
                <span className="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                  {selectedRuntime}
                </span>
              </div>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{`export default async ({ req, res, log, error }) => {
  // Log incoming request
  log('Request received: ' + req.method);
  
  // Parse request body if present
  const body = req.body ? JSON.parse(req.body) : {};
  
  // Your function logic here
  const result = {
    message: 'Hello from Appwrite Functions!',
    timestamp: Date.now(),
    input: body
  };
  
  // Return JSON response
  return res.json(result);
};`}</code>
                </pre>
                <button
                  onClick={() => copyToClipboard('...', 'functionCode')}
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'functionCode' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            {/* Context object */}
            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">Context object</p>
              <div className="mt-3 space-y-2 text-sm">
                <div className="flex justify-between">
                  <code className="text-muted-foreground">req</code>
                  <span>Request object (headers, body, method, url)</span>
                </div>
                <div className="flex justify-between">
                  <code className="text-muted-foreground">res</code>
                  <span>Response helpers (json, text, redirect, empty)</span>
                </div>
                <div className="flex justify-between">
                  <code className="text-muted-foreground">log</code>
                  <span>Log messages to execution logs</span>
                </div>
                <div className="flex justify-between">
                  <code className="text-muted-foreground">error</code>
                  <span>Log errors to execution logs</span>
                </div>
              </div>
            </div>
          </div>
        )

      case 'deploy-function':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Deploy your function</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Deploy your function code to make it available for execution.
              </p>
            </div>

            {/* Deployment methods */}
            <div className="space-y-3">
              <p className="text-sm font-medium">Deployment method</p>
              <div className="grid gap-3">
                {[
                  {
                    id: 'cli',
                    title: 'Appwrite CLI',
                    desc: 'Deploy from your terminal',
                    command: 'appwrite deploy function',
                  },
                  {
                    id: 'git',
                    title: 'Git integration',
                    desc: 'Auto-deploy on push',
                    command: null,
                  },
                  {
                    id: 'manual',
                    title: 'Manual upload',
                    desc: 'Upload a tarball or zip',
                    command: null,
                  },
                ].map((method) => (
                  <div
                    key={method.id}
                    className="rounded-lg border border-border p-4"
                  >
                    <p className="text-sm font-medium">{method.title}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {method.desc}
                    </p>
                    {method.command && (
                      <code className="mt-2 block rounded bg-muted px-2 py-1 font-mono text-xs">
                        {method.command}
                      </code>
                    )}
                  </div>
                ))}
              </div>
            </div>

            {/* Environment variables */}
            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <div className="flex items-center justify-between">
                <p className="text-sm font-medium">Environment variables</p>
                <Button variant="ghost" size="sm" className="h-7 text-xs">
                  Add variable
                </Button>
              </div>
              <p className="mt-2 text-xs text-muted-foreground">
                Add secrets and configuration that your function can access at
                runtime.
              </p>
            </div>
          </div>
        )

      case 'execute-function':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Execute your function</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Trigger your function and view the response.
              </p>
            </div>

            {/* Execute button */}
            <div className="rounded-lg border border-border p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium">Test execution</p>
                  <p className="text-xs text-muted-foreground">
                    Run your function with sample data
                  </p>
                </div>
                <Button
                  onClick={handleExecute}
                  disabled={isExecuting}
                  className="gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
                >
                  <Play className="h-4 w-4" />
                  Execute
                </Button>
              </div>
            </div>

            {/* Execution result */}
            {executionResult && (
              <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                className="space-y-2"
              >
                <p className="text-sm font-medium">Response</p>
                <pre className="overflow-x-auto rounded-lg border border-green-500/30 bg-green-500/5 p-4 font-mono text-sm">
                  <code>{executionResult}</code>
                </pre>
              </motion.div>
            )}

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Execute from code</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('executeFunction')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(
                      getSnippet('executeFunction'),
                      'executeFunction',
                    )
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'executeFunction' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            {executionResult && (
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
                      Functions setup complete!
                    </p>
                    <p className="text-sm text-muted-foreground">
                      You can now run serverless code in your app.
                    </p>
                  </div>
                </div>
              </motion.div>
            )}
          </div>
        )
    }
  }

  const steps: FunctionStep[] = [
    'create-function',
    'write-code',
    'deploy-function',
    'execute-function',
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
          <Zap className="h-4 w-4 text-[#f02e65]" />
          <span className="text-sm font-medium">Run serverless functions</span>
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
