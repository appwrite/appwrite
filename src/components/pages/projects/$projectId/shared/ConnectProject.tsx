/**
 * Connect to your project – simplified modal for project credentials and SDK setup.
 * Adapted from Supabase-style connect flow; tailored to Appwrite (endpoint, project ID, API keys).
 */

import { useState, useMemo, useEffect } from 'react'
import { Copy, Check, ExternalLink, Key } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useProject } from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { toast } from 'sonner'

const APPWRITE_DOCS_URL = 'https://appwrite.io/docs'

const SDK_OPTIONS = [
  { id: 'web', platform: 'web', label: 'Web' },
  { id: 'node', platform: 'web', label: 'Node.js' },
  { id: 'flutter', platform: 'flutter', label: 'Flutter' },
  { id: 'apple', platform: 'apple', label: 'Apple (Swift)' },
  { id: 'android', platform: 'android', label: 'Android (Kotlin)' },
] as const

function getSnippet(
  sdkId: string,
  endpoint: string,
  projectId: string,
): string {
  switch (sdkId) {
    case 'web':
      return `import { Client } from 'appwrite'

const client = new Client()
  .setEndpoint('${endpoint}')
  .setProject('${projectId}')

// Use client with your API key (server-side only or scoped key)
// client.setKey('your-api-key')`
    case 'node':
      return `import { Client } from 'node-appwrite'

const client = new Client()
  .setEndpoint('${endpoint}')
  .setProject('${projectId}')
  .setKey(process.env.APPWRITE_API_KEY)`
    case 'flutter':
      return `final client = Client()
  ..setEndpoint('${endpoint}')
  ..setProject('${projectId}');`
    case 'apple':
      return `let client = Client()
  .setEndpoint("${endpoint}")
  .setProject("${projectId}")`
    case 'android':
      return `val client = Client()
  .setEndpoint("${endpoint}")
  .setProject("${projectId}")`
    default:
      return getSnippet('web', endpoint, projectId)
  }
}

function getEnvExample(sdkId: string, endpoint: string, projectId: string): string {
  const envPrefix = sdkId === 'web' ? 'VITE_' : ''
  if (sdkId === 'web') {
    return `${envPrefix}APPWRITE_ENDPOINT=${endpoint}\n${envPrefix}APPWRITE_PROJECT_ID=${projectId}`
  }
  return `APPWRITE_ENDPOINT=${endpoint}\nAPPWRITE_PROJECT_ID=${projectId}\nAPPWRITE_API_KEY=your-api-key`
}

interface ConnectProjectProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** Optional initial SDK to preselect (e.g. 'web', 'flutter') */
  initialSdk?: string
}

export function ConnectProject({
  open,
  onOpenChange,
  projectId,
  initialSdk = 'web',
}: ConnectProjectProps) {
  const navigate = useNavigate()
  const [activeTab, setActiveTab] = useState('quickstart')
  const [sdkId, setSdkId] = useState(initialSdk)
  const [copiedBlock, setCopiedBlock] = useState<string | null>(null)

  useEffect(() => {
    if (open) setSdkId(initialSdk)
  }, [open, initialSdk])

  const { project } = useProject(projectId)
  const endpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  const snippet = useMemo(
    () => getSnippet(sdkId, endpoint, projectId),
    [sdkId, endpoint, projectId],
  )
  const envExample = useMemo(
    () => getEnvExample(sdkId, endpoint, projectId),
    [sdkId, endpoint, projectId],
  )

  const handleCopy = (text: string, blockId: string) => {
    navigator.clipboard.writeText(text)
    setCopiedBlock(blockId)
    toast.success('Copied to clipboard')
    setTimeout(() => setCopiedBlock(null), 2000)
  }

  const handleViewApiKeys = () => {
    onOpenChange(false)
    navigate({ to: '/projects/$projectId/api-keys', params: { projectId } })
  }

  if (!project) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 gap-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>Connect to your project</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Get your project credentials and code snippets to integrate
            Appwrite into your app.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <Tabs
          value={activeTab}
          onValueChange={setActiveTab}
          className="w-full"
        >
          <div className="px-6 pt-4">
            <TabsList className="w-full grid grid-cols-2">
              <TabsTrigger value="quickstart" className="text-[13px]">
                Quick start
              </TabsTrigger>
              <TabsTrigger value="api-keys" className="text-[13px]">
                API keys
              </TabsTrigger>
            </TabsList>
          </div>
          <TabsContent value="quickstart" className="mt-0 px-6 pb-4">
            <div className="space-y-4 pt-4">
              <div>
                <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                  SDK / Platform
                </label>
                <Select value={sdkId} onValueChange={setSdkId}>
                  <SelectTrigger className="w-full h-9 text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {SDK_OPTIONS.map((opt) => (
                      <SelectItem
                        key={opt.id}
                        value={opt.id}
                        className="text-[13px]"
                      >
                        <span className="flex items-center gap-1.5">
                          <PlatformIcon platform={opt.platform} size="sm" />
                          {opt.label}
                        </span>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <p className="text-[13px] text-muted-foreground">
                Add the following to your app. Use environment variables for
                endpoint and project ID in production.
              </p>
              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    Code
                  </span>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1 text-[12px] text-muted-foreground"
                    onClick={() => handleCopy(snippet, 'snippet')}
                  >
                    {copiedBlock === 'snippet' ? (
                      <Check className="h-3.5 w-3.5" />
                    ) : (
                      <Copy className="h-3.5 w-3.5" />
                    )}
                    Copy
                  </Button>
                </div>
                <pre className="rounded-lg border border-border bg-muted/30 p-4 text-[12px] font-mono text-foreground overflow-x-auto whitespace-pre">
                  {snippet}
                </pre>
              </div>
              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    Environment variables
                  </span>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1 text-[12px] text-muted-foreground"
                    onClick={() => handleCopy(envExample, 'env')}
                  >
                    {copiedBlock === 'env' ? (
                      <Check className="h-3.5 w-3.5" />
                    ) : (
                      <Copy className="h-3.5 w-3.5" />
                    )}
                    Copy
                  </Button>
                </div>
                <pre className="rounded-lg border border-border bg-muted/30 p-4 text-[12px] font-mono text-foreground overflow-x-auto whitespace-pre">
                  {envExample}
                </pre>
              </div>
              <a
                href={APPWRITE_DOCS_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-[13px] text-primary hover:underline"
              >
                Read the docs
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
            </div>
          </TabsContent>
          <TabsContent value="api-keys" className="mt-0 px-6 pb-4">
            <div className="space-y-4 pt-4">
              <p className="text-[13px] text-muted-foreground">
                Create and manage API keys from the project API keys page.
                Use keys with appropriate scopes for your app or server.
              </p>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px] gap-1.5"
                onClick={handleViewApiKeys}
              >
                <Key className="h-4 w-4" />
                View API keys
              </Button>
              <a
                href={`${APPWRITE_DOCS_URL}/getting-started-for-server`}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-[13px] text-primary hover:underline"
              >
                Server setup guide
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
            </div>
          </TabsContent>
        </Tabs>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
