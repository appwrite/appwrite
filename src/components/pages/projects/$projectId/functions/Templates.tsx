import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import {
  FileCode,
  Search,
  X,
  Download,
  Github,
  ExternalLink,
  Bell,
  Clock,
  Key,
  ArrowRight,
} from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetClose,
} from '@/components/ui/sheet'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import {
  useFunctionTemplates,
  fetchFunctionTemplates,
  useProject,
} from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { resolveTemplateVariables } from '@/lib/template-placeholders'
import { Separator } from '@/components/ui/separator'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'

// Runtime language definitions
const runtimesMap = {
  node: { name: 'Node.js', short: 'JS' },
  python: { name: 'Python', short: 'PY' },
  php: { name: 'PHP', short: 'PHP' },
  ruby: { name: 'Ruby', short: 'RB' },
  dart: { name: 'Dart', short: 'DT' },
  swift: { name: 'Swift', short: 'SW' },
  kotlin: { name: 'Kotlin', short: 'KT' },
  java: { name: 'Java', short: 'JV' },
  deno: { name: 'Deno', short: 'DN' },
  bun: { name: 'Bun', short: 'BN' },
  go: { name: 'Go', short: 'GO' },
  dotnet: { name: '.NET', short: 'C#' },
} as const

type RuntimeKey = keyof typeof runtimesMap

const runtimeKeys = Object.keys(runtimesMap) as RuntimeKey[]

type Category =
  | 'Starter'
  | 'Communication'
  | 'Payments'
  | 'Media'
  | 'Data'
  | 'Integration'
  | 'Security'
  | 'Analytics'
  | 'Automation'

interface Template {
  id: string
  name: string
  description: string
  longDescription: string
  instructions: string // HTML instructions from API
  icon: typeof FileCode
  category: Category
  runtimes: RuntimeKey[]
  scopes: string[] // Required scopes
  events: string[] // Function trigger events
  cron: string // Function execution schedule in CRON format
  variables: Models.TemplateVariable[] // Required variables
  author: string
  version: string
  downloads: number
  lastUpdated: string
  repoUrl: string
}

/**
 * Maps runtime name from API to RuntimeKey
 * API returns names like "node-20.0", "python-3.12", etc.
 * We extract the base name to match our runtime keys
 */
function mapRuntimeNameToKey(runtimeName: string): RuntimeKey | null {
  const baseName = runtimeName.split('-')[0].toLowerCase()
  if (baseName in runtimesMap) {
    return baseName as RuntimeKey
  }
  // Handle special cases
  if (baseName === 'csharp' || baseName === 'c#') {
    return 'dotnet'
  }
  return null
}

/**
 * Maps RuntimeKey to actual runtime names from API
 * Returns all runtime names that match the key (e.g., "node" -> ["node-20.0", "node-18.0"])
 */
function mapRuntimeKeyToApiNames(
  runtimeKey: RuntimeKey,
  allTemplates: Models.TemplateFunction[],
): string[] {
  const apiNames = new Set<string>()

  for (const template of allTemplates) {
    for (const runtime of template.runtimes) {
      const key = mapRuntimeNameToKey(runtime.name)
      if (key === runtimeKey) {
        apiNames.add(runtime.name)
      }
    }
  }

  return Array.from(apiNames)
}

/**
 * Maps Category to useCases
 * Returns useCases that match the category based on our mapping logic
 */
function mapCategoryToUseCases(
  category: Category,
  allTemplates: Models.TemplateFunction[],
): string[] {
  const useCases = new Set<string>()

  for (const template of allTemplates) {
    const templateCategory = mapUseCaseToCategory(template.useCases || [])
    if (templateCategory === category) {
      template.useCases?.forEach((uc) => useCases.add(uc))
    }
  }

  return Array.from(useCases)
}

// GitHub Circle Icon Component
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

/**
 * Maps use case to category
 * Uses the first use case or defaults to 'Starter'
 */
function mapUseCaseToCategory(useCases: string[]): Category {
  if (useCases.length === 0) {
    return 'Starter'
  }

  const useCaseLower = useCases[0].toLowerCase()

  // Map common use cases to categories
  if (
    useCaseLower.includes('email') ||
    useCaseLower.includes('sms') ||
    useCaseLower.includes('notification')
  ) {
    return 'Communication'
  }
  if (
    useCaseLower.includes('payment') ||
    useCaseLower.includes('stripe') ||
    useCaseLower.includes('paypal')
  ) {
    return 'Payments'
  }
  if (
    useCaseLower.includes('image') ||
    useCaseLower.includes('media') ||
    useCaseLower.includes('video') ||
    useCaseLower.includes('pdf')
  ) {
    return 'Media'
  }
  if (
    useCaseLower.includes('database') ||
    useCaseLower.includes('backup') ||
    useCaseLower.includes('export') ||
    useCaseLower.includes('data')
  ) {
    return 'Data'
  }
  if (
    useCaseLower.includes('webhook') ||
    useCaseLower.includes('integration') ||
    useCaseLower.includes('api')
  ) {
    return 'Integration'
  }
  if (
    useCaseLower.includes('auth') ||
    useCaseLower.includes('security') ||
    useCaseLower.includes('oauth')
  ) {
    return 'Security'
  }
  if (
    useCaseLower.includes('analytics') ||
    useCaseLower.includes('log') ||
    useCaseLower.includes('event')
  ) {
    return 'Analytics'
  }
  if (
    useCaseLower.includes('schedule') ||
    useCaseLower.includes('cron') ||
    useCaseLower.includes('automation') ||
    useCaseLower.includes('queue')
  ) {
    return 'Automation'
  }

  return 'Starter'
}

/**
 * Converts API TemplateFunction to our Template interface
 */
function mapApiTemplateToTemplate(
  apiTemplate: Models.TemplateFunction,
): Template {
  const mappedRuntimes = apiTemplate.runtimes
    .map((rt) => mapRuntimeNameToKey(rt.name))
    .filter((key): key is RuntimeKey => key !== null)

  // Remove duplicates
  const uniqueRuntimes = Array.from(new Set(mappedRuntimes))

  // Build repo URL from VCS provider info
  const repoUrl =
    apiTemplate.vcsProvider &&
    apiTemplate.providerOwner &&
    apiTemplate.providerRepositoryId
      ? `https://${apiTemplate.vcsProvider === 'github' ? 'github.com' : apiTemplate.vcsProvider}.com/${apiTemplate.providerOwner}/${apiTemplate.providerRepositoryId}`
      : 'https://github.com/appwrite/templates'

  return {
    id: apiTemplate.id,
    name: apiTemplate.name,
    description: apiTemplate.tagline || apiTemplate.name,
    longDescription: apiTemplate.tagline || 'No description available.',
    instructions: apiTemplate.instructions || '',
    icon: FileCode,
    category: mapUseCaseToCategory(apiTemplate.useCases || []),
    runtimes: uniqueRuntimes.length > 0 ? uniqueRuntimes : ['node'], // Default to node if no runtimes match
    scopes: apiTemplate.scopes || [],
    events: apiTemplate.events || [],
    cron: apiTemplate.cron || '',
    variables: apiTemplate.variables || [],
    author: apiTemplate.providerOwner || 'Appwrite',
    version: apiTemplate.providerVersion || '1.0.0',
    downloads: 0, // Not available in API
    lastUpdated: new Date().toISOString().split('T')[0], // Not available in API, use current date
    repoUrl,
  }
}

// Legacy mock data - kept for reference but not used
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const mockTemplates: Template[] = [
  {
    id: '507f1f77bcf86cd799439800',
    name: 'Starter Function',
    description: 'A basic function template to get started',
    longDescription:
      'Get started quickly with this minimal function template. It includes the basic structure and configuration needed to deploy your first serverless function. Perfect for learning the fundamentals or as a starting point for custom functions.',
    instructions: '',
    icon: FileCode,
    category: 'Starter',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: [
      'node',
      'python',
      'php',
      'ruby',
      'dart',
      'swift',
      'kotlin',
      'java',
      'deno',
      'bun',
      'go',
      'dotnet',
    ],
    author: 'Appwrite',
    version: '1.0.0',
    downloads: 12500,
    lastUpdated: '2024-01-15',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439801',
    name: 'Send Email',
    description: 'Send transactional emails with SMTP or API',
    longDescription:
      'Send transactional emails using popular email providers like SendGrid, Mailgun, or custom SMTP servers. Includes templates for common email types like welcome emails, password resets, and notifications.',
    instructions: '',
    icon: FileCode,
    category: 'Communication',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go'],
    author: 'Appwrite',
    version: '2.1.0',
    downloads: 8420,
    lastUpdated: '2024-02-20',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439802',
    name: 'Process Payment',
    description: 'Handle Stripe or other payment webhooks',
    longDescription:
      'Securely process payment webhooks from Stripe, PayPal, or other payment providers. Includes signature verification, event handling, and database updates for order management.',
    instructions: '',
    icon: FileCode,
    category: 'Payments',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'ruby', 'go', 'dotnet'],
    author: 'Appwrite',
    version: '1.5.2',
    downloads: 6230,
    lastUpdated: '2024-03-01',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439803',
    name: 'Image Resize',
    description: 'Resize and optimize images on upload',
    longDescription:
      'Automatically resize, crop, and optimize images when uploaded to storage. Supports multiple output formats, quality settings, and can generate thumbnails for responsive images.',
    instructions: '',
    icon: FileCode,
    category: 'Media',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go'],
    author: 'Appwrite',
    version: '1.2.0',
    downloads: 5100,
    lastUpdated: '2024-01-28',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439804',
    name: 'Database Backup',
    description: 'Scheduled database backup to storage',
    longDescription:
      'Create automated backups of your database collections to cloud storage. Supports incremental backups, compression, and retention policies for efficient storage management.',
    instructions: '',
    icon: FileCode,
    category: 'Data',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go'],
    author: 'Appwrite',
    version: '1.0.3',
    downloads: 3890,
    lastUpdated: '2024-02-10',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439805',
    name: 'Webhook Handler',
    description: 'Generic webhook receiver and processor',
    longDescription:
      'A flexible webhook handler that can receive and process webhooks from any service. Includes request validation, payload parsing, and customizable response handling.',
    instructions: '',
    icon: FileCode,
    category: 'Integration',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'ruby', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '2.0.0',
    downloads: 7650,
    lastUpdated: '2024-03-05',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439806',
    name: 'Generate PDF',
    description: 'Create PDF documents from HTML or templates',
    longDescription:
      'Generate PDF documents from HTML templates, markdown, or structured data. Perfect for invoices, reports, certificates, and other document generation needs.',
    instructions: '',
    icon: FileCode,
    category: 'Media',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go'],
    author: 'Appwrite',
    version: '1.3.0',
    downloads: 4520,
    lastUpdated: '2024-02-15',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439807',
    name: 'Scheduled Task',
    description: 'Run scheduled tasks with cron expressions',
    longDescription:
      'Execute functions on a schedule using cron expressions. Perfect for periodic data processing, cleanup tasks, report generation, and automated workflows.',
    instructions: '',
    icon: FileCode,
    category: 'Automation',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '1.1.0',
    downloads: 6780,
    lastUpdated: '2024-01-20',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439808',
    name: 'API Rate Limiter',
    description: 'Implement rate limiting for API endpoints',
    longDescription:
      'Add rate limiting to your API endpoints to prevent abuse and ensure fair usage. Supports multiple strategies including token bucket, sliding window, and fixed window algorithms.',
    instructions: '',
    icon: FileCode,
    category: 'Security',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '1.4.0',
    downloads: 3240,
    lastUpdated: '2024-02-28',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439809',
    name: 'Data Validation',
    description: 'Validate and sanitize input data',
    longDescription:
      'Comprehensive data validation and sanitization for user inputs. Includes schema validation, type checking, and security sanitization to prevent injection attacks.',
    instructions: '',
    icon: FileCode,
    category: 'Security',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '1.2.5',
    downloads: 5890,
    lastUpdated: '2024-03-10',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439810',
    name: 'Log Aggregator',
    description: 'Collect and aggregate application logs',
    longDescription:
      'Centralized log collection and aggregation from multiple sources. Supports log parsing, filtering, and forwarding to external logging services.',
    instructions: '',
    icon: FileCode,
    category: 'Analytics',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go'],
    author: 'Appwrite',
    version: '1.0.8',
    downloads: 4120,
    lastUpdated: '2024-02-05',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439811',
    name: 'File Converter',
    description: 'Convert files between different formats',
    longDescription:
      'Convert files between various formats including images, documents, and media files. Supports batch processing and multiple output formats.',
    instructions: '',
    icon: FileCode,
    category: 'Media',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go'],
    author: 'Appwrite',
    version: '1.1.2',
    downloads: 3560,
    lastUpdated: '2024-01-30',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439812',
    name: 'SMS Notifications',
    description: 'Send SMS messages via Twilio or other providers',
    longDescription:
      'Send SMS notifications using popular providers like Twilio, Vonage, or custom providers. Includes message templating and delivery tracking.',
    instructions: '',
    icon: FileCode,
    category: 'Communication',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go'],
    author: 'Appwrite',
    version: '1.3.5',
    downloads: 4780,
    lastUpdated: '2024-02-22',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439813',
    name: 'Data Export',
    description: 'Export data to CSV, JSON, or Excel',
    longDescription:
      'Export database records to various formats including CSV, JSON, Excel, and PDF. Supports filtering, sorting, and custom formatting.',
    instructions: '',
    icon: FileCode,
    category: 'Data',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go'],
    author: 'Appwrite',
    version: '1.0.5',
    downloads: 5210,
    lastUpdated: '2024-03-08',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439814',
    name: 'OAuth Handler',
    description: 'Handle OAuth authentication flows',
    longDescription:
      'Complete OAuth 2.0 implementation for popular providers like Google, GitHub, Facebook, and more. Includes token management and user profile retrieval.',
    instructions: '',
    icon: FileCode,
    category: 'Security',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'php', 'go', 'ruby'],
    author: 'Appwrite',
    version: '2.2.0',
    downloads: 8930,
    lastUpdated: '2024-03-12',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439815',
    name: 'Event Logger',
    description: 'Track and log application events',
    longDescription:
      'Comprehensive event logging system for tracking user actions, system events, and custom metrics. Includes event aggregation and analytics.',
    instructions: '',
    icon: FileCode,
    category: 'Analytics',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '1.1.8',
    downloads: 6340,
    lastUpdated: '2024-02-18',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439816',
    name: 'Queue Processor',
    description: 'Process background jobs from a queue',
    longDescription:
      'Process background jobs from message queues. Supports retry logic, priority queues, and job scheduling for reliable asynchronous processing.',
    instructions: '',
    icon: FileCode,
    category: 'Automation',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '1.5.0',
    downloads: 7120,
    lastUpdated: '2024-03-15',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439817',
    name: 'Cache Manager',
    description: 'Manage application caching',
    longDescription:
      'Intelligent caching system with TTL support, cache invalidation, and multi-level caching strategies. Perfect for improving application performance.',
    instructions: '',
    icon: FileCode,
    category: 'Data',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '1.2.3',
    downloads: 4450,
    lastUpdated: '2024-02-25',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439818',
    name: 'Push Notifications',
    description: 'Send push notifications to mobile devices',
    longDescription:
      'Send push notifications to iOS and Android devices using Firebase Cloud Messaging or Apple Push Notification service. Includes device token management.',
    instructions: '',
    icon: FileCode,
    category: 'Communication',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go'],
    author: 'Appwrite',
    version: '1.4.2',
    downloads: 5670,
    lastUpdated: '2024-03-03',
    repoUrl: 'https://github.com/appwrite/templates',
  },
  {
    id: '507f1f77bcf86cd799439819',
    name: 'API Gateway',
    description: 'Route and proxy API requests',
    longDescription:
      'API gateway for routing, load balancing, and request transformation. Includes authentication, rate limiting, and request/response modification.',
    instructions: '',
    icon: FileCode,
    category: 'Integration',
    scopes: [],
    events: [],
    cron: '',
    variables: [],
    runtimes: ['node', 'python', 'go', 'deno', 'bun'],
    author: 'Appwrite',
    version: '2.1.5',
    downloads: 8230,
    lastUpdated: '2024-03-18',
    repoUrl: 'https://github.com/appwrite/templates',
  },
]

export function TemplatesView() {
  const { projectId } = useParams({ strict: false })
  useNavigate()
  const { project } = useProject(projectId)

  const [templateSearchValue, setTemplateSearchValue] = useState<string>('')
  const [selectedCategories, setSelectedCategories] = useState<Category[]>([])
  const [selectedRuntimes, setSelectedRuntimes] = useState<RuntimeKey[]>([])
  const [selectedTemplate, setSelectedTemplate] = useState<Template | null>(
    null,
  )
  const [templatesPage, setTemplatesPage] = useState(0) // 0-indexed like org view
  const [templatesPageSize, setTemplatesPageSize] = useState(12)

  // Resolve template variable placeholders for display (apiEndpoint, projectId, projectName)
  const resolvedTemplateVariables = useMemo(() => {
    if (!selectedTemplate?.variables?.length) return []
    const apiEndpoint = getApiEndpoint(project?.region)
    const context = {
      apiEndpoint,
      projectId: projectId ?? '',
      projectName: project?.name ?? '',
    }
    return resolveTemplateVariables(selectedTemplate.variables, context)
  }, [selectedTemplate?.variables, project?.region, project?.name, projectId])

  const requiredVariables = useMemo(
    () => resolvedTemplateVariables.filter((v) => v.required),
    [resolvedTemplateVariables],
  )
  const optionalVariables = useMemo(
    () => resolvedTemplateVariables.filter((v) => !v.required),
    [resolvedTemplateVariables],
  )

  // Fetch all templates only when we need them for mapping (when filters are active)
  const needsAllTemplates =
    selectedRuntimes.length > 0 || selectedCategories.length > 0

  // Fetch all templates for mapping (only when filters are active)
  // Use a separate query with enabled flag to prevent unnecessary calls
  // Use a different query key prefix to avoid conflicts with paginated queries
  const { data: allApiTemplatesForMapping } = useQuery({
    queryKey: [
      'function-templates',
      'all-for-mapping', // Different prefix to avoid cache conflicts
      'project',
      projectId,
    ],
    queryFn: () =>
      fetchFunctionTemplates(projectId!, undefined, undefined, 100, 0, false),
    enabled: !!projectId && needsAllTemplates,
    staleTime: 5 * 60 * 1000,
  })

  // Use all templates for mapping when filters are active, otherwise use empty array
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const allApiTemplates = needsAllTemplates
    ? allApiTemplatesForMapping?.templates || []
    : []

  // Map runtime keys to API runtime names (only when runtimes are selected)
  const apiRuntimeNames = useMemo(() => {
    if (selectedRuntimes.length === 0 || allApiTemplates.length === 0)
      return undefined

    const allNames = new Set<string>()
    selectedRuntimes.forEach((key) => {
      const names = mapRuntimeKeyToApiNames(key, allApiTemplates)
      names.forEach((name) => allNames.add(name))
    })

    return Array.from(allNames)
  }, [selectedRuntimes, allApiTemplates])

  // Map categories to useCases (only when categories are selected)
  const apiUseCases = useMemo(() => {
    if (selectedCategories.length === 0 || allApiTemplates.length === 0)
      return undefined

    const allUseCases = new Set<string>()
    selectedCategories.forEach((category) => {
      const useCases = mapCategoryToUseCases(category, allApiTemplates)
      useCases.forEach((uc) => allUseCases.add(uc))
    })

    return Array.from(allUseCases)
  }, [selectedCategories, allApiTemplates])

  // Fetch templates from API with pagination - matches org view pattern
  const {
    templates: apiTemplates,
    total,
    data,
    isPending,
    error,
  } = useFunctionTemplates(
    projectId,
    templatesPage, // 0-indexed page
    templatesPageSize,
    apiRuntimeNames,
    apiUseCases,
  )

  // Map API templates to our Template interface
  const templates = useMemo(() => {
    return apiTemplates.map(mapApiTemplateToTemplate)
  }, [apiTemplates])

  // Apply client-side search filter only (API doesn't support search)
  // This filters the current page results only
  const filteredTemplates = useMemo(() => {
    if (!templateSearchValue.trim()) {
      return templates
    }

    const searchLower = templateSearchValue.toLowerCase()
    return templates.filter(
      (t) =>
        t.name.toLowerCase().includes(searchLower) ||
        t.description.toLowerCase().includes(searchLower),
    )
  }, [templates, templateSearchValue])

  // Use filtered templates directly - no additional pagination
  const paginatedTemplates = filteredTemplates

  // Extract unique categories from all available templates
  // Note: We use allApiTemplates when available, otherwise apiTemplates
  // Counts are not shown as they can't be accurate without fetching all templates
  const templatesForCategories =
    allApiTemplates.length > 0 ? allApiTemplates : apiTemplates

  const availableCategories = useMemo(() => {
    const cats = new Set<Category>()
    templatesForCategories.forEach((t: Models.TemplateFunction) => {
      const category = mapUseCaseToCategory(t.useCases || [])
      cats.add(category)
    })
    return Array.from(cats)
  }, [templatesForCategories])

  const toggleCategory = (category: Category) => {
    setSelectedCategories((prev) =>
      prev.includes(category)
        ? prev.filter((c) => c !== category)
        : [...prev, category],
    )
    setTemplatesPage(0) // Reset to first page when filter changes (0-indexed)
  }

  const toggleRuntime = (runtime: RuntimeKey) => {
    setSelectedRuntimes((prev) =>
      prev.includes(runtime)
        ? prev.filter((r) => r !== runtime)
        : [...prev, runtime],
    )
    setTemplatesPage(0) // Reset to first page when filter changes (0-indexed)
  }

  const clearFilters = () => {
    setSelectedCategories([])
    setSelectedRuntimes([])
    setTemplateSearchValue('')
    setTemplatesPage(0) // Reset to first page (0-indexed)
  }

  const handleSearchChange = (value: string) => {
    setTemplateSearchValue(value)
    setTemplatesPage(0) // Reset to first page when search changes (0-indexed)
  }

  const hasActiveFilters =
    selectedCategories.length > 0 ||
    selectedRuntimes.length > 0 ||
    templateSearchValue.length > 0

  // Loading state - only show if we don't have any data yet
  // This prevents showing loading when we have cached data from route loader
  // Check if data exists (from cache or fresh) - if it does, we should render it even if isPending is briefly true
  if (isPending && !data) {
    return (
      <div className="flex h-full items-center justify-center py-16">
        <div className="text-center">
          <p className="text-sm text-muted-foreground">Loading templates...</p>
        </div>
      </div>
    )
  }

  // Show error state
  if (error) {
    return (
      <div className="flex h-full items-center justify-center py-16">
        <div className="text-center">
          <p className="mb-1 text-sm font-medium text-foreground">
            Failed to load templates
          </p>
          <p className="text-sm text-muted-foreground">
            {error instanceof Error ? error.message : 'An error occurred'}
          </p>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="flex gap-8 pt-6">
        {/* Vertical Filter Sidebar */}
        <div className="hidden w-56 shrink-0 lg:block">
          <div className="sticky top-6 space-y-6">
            {/* Search Input */}
            <div>
              <div className="relative">
                <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  type="text"
                  placeholder="Search templates..."
                  value={templateSearchValue}
                  onChange={(e) => handleSearchChange(e.target.value)}
                  className="h-9 pl-8 text-[13px]"
                />
                {templateSearchValue && (
                  <button
                    onClick={() => handleSearchChange('')}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  >
                    <X className="h-3.5 w-3.5" />
                  </button>
                )}
              </div>
            </div>

            {/* Categories Filter */}
            <div>
              <h4 className="mb-3 text-[12px] font-medium uppercase tracking-wide text-muted-foreground">
                Categories
              </h4>
              <div className="space-y-2">
                {availableCategories.map((category) => (
                  <label
                    key={category}
                    className="flex cursor-pointer items-center gap-2"
                  >
                    <Checkbox
                      checked={selectedCategories.includes(category)}
                      onCheckedChange={() => toggleCategory(category)}
                      className="h-4 w-4"
                    />
                    <span className="text-[13px] text-foreground">
                      {category}
                    </span>
                  </label>
                ))}
              </div>
            </div>

            {/* Runtimes Filter */}
            <div>
              <h4 className="mb-3 text-[12px] font-medium uppercase tracking-wide text-muted-foreground">
                Runtimes
              </h4>
              <div className="space-y-2">
                {runtimeKeys.map((runtimeKey) => {
                  const runtime = runtimesMap[runtimeKey]
                  return (
                    <label
                      key={runtimeKey}
                      className="flex cursor-pointer items-center gap-2"
                    >
                      <Checkbox
                        checked={selectedRuntimes.includes(runtimeKey)}
                        onCheckedChange={() => toggleRuntime(runtimeKey)}
                        className="h-4 w-4"
                      />
                      <LanguageIcon
                        language={runtimeKey}
                        size="sm"
                        className="h-5 w-5"
                      />
                      <span className="text-[13px] text-foreground">
                        {runtime.name}
                      </span>
                    </label>
                  )
                })}
              </div>
            </div>

            {/* Clear All Button */}
            {hasActiveFilters && (
              <button
                onClick={clearFilters}
                className="flex w-full items-center justify-center gap-1.5 rounded-md border border-border px-3 py-2 text-[12px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <X className="h-3 w-3" />
                Clear all filters
              </button>
            )}
          </div>
        </div>

        {/* Templates Grid */}
        <div className="min-w-0 flex-1">
          {/* Active Filters Pills */}
          {hasActiveFilters && (
            <div className="mb-4 flex flex-wrap items-center gap-2">
              {templateSearchValue && (
                <button
                  onClick={() => handleSearchChange('')}
                  className="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-[12px] font-medium text-primary hover:bg-primary/20"
                >
                  "{templateSearchValue}"
                  <X className="h-3 w-3" />
                </button>
              )}
              {selectedCategories.map((category) => (
                <button
                  key={category}
                  onClick={() => toggleCategory(category)}
                  className="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-[12px] font-medium text-primary hover:bg-primary/20"
                >
                  {category}
                  <X className="h-3 w-3" />
                </button>
              ))}
              {selectedRuntimes.map((runtimeKey) => (
                <button
                  key={runtimeKey}
                  onClick={() => toggleRuntime(runtimeKey)}
                  className="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-[12px] font-medium text-primary hover:bg-primary/20"
                >
                  {runtimesMap[runtimeKey].name}
                  <X className="h-3 w-3" />
                </button>
              ))}
            </div>
          )}

          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <TooltipProvider delayDuration={200}>
              {paginatedTemplates.map((template) => {
                const IconComponent = template.icon
                const maxVisible = 5
                const visibleRuntimes = template.runtimes.slice(0, maxVisible)
                const remainingCount = template.runtimes.length - maxVisible

                return (
                  <div
                    key={template.id}
                    className="group cursor-pointer rounded-lg border border-border bg-card p-4 transition-all hover:border-border hover:bg-accent/50"
                    onClick={() => setSelectedTemplate(template)}
                  >
                    <div className="flex items-start gap-3">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <IconComponent className="h-5 w-5" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <h3 className="truncate text-[14px] font-medium text-foreground">
                            {template.name}
                          </h3>
                          <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                            {template.category}
                          </span>
                        </div>
                        <p className="mt-1 text-[12px] text-muted-foreground line-clamp-2">
                          {template.description}
                        </p>
                        {/* Runtime language avatars */}
                        <div className="mt-3 flex items-center gap-1.5">
                          {visibleRuntimes.map((runtimeKey) => {
                            const runtime = runtimesMap[runtimeKey]
                            return (
                              <Tooltip key={runtimeKey}>
                                <TooltipTrigger asChild>
                                  <div className="flex h-6 w-6 items-center justify-center">
                                    <LanguageIcon
                                      language={runtimeKey}
                                      size="sm"
                                      className="h-6 w-6"
                                    />
                                  </div>
                                </TooltipTrigger>
                                <TooltipContent
                                  side="bottom"
                                  className="text-xs"
                                >
                                  {runtime.name}
                                </TooltipContent>
                              </Tooltip>
                            )
                          })}
                          {remainingCount > 0 && (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <div className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-[9px] font-semibold text-muted-foreground">
                                  +{remainingCount}
                                </div>
                              </TooltipTrigger>
                              <TooltipContent side="bottom" className="text-xs">
                                {template.runtimes
                                  .slice(maxVisible)
                                  .map((key) => runtimesMap[key].name)
                                  .join(', ')}
                              </TooltipContent>
                            </Tooltip>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                )
              })}
            </TooltipProvider>

            {paginatedTemplates.length === 0 && (
              <div className="col-span-full py-12 text-center">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
                  <FileCode className="h-5 w-5 text-muted-foreground" />
                </div>
                <p className="mb-1 text-[14px] font-medium text-foreground">
                  No templates found
                </p>
                <p className="text-[13px] text-muted-foreground">
                  Try adjusting your filters
                </p>
              </div>
            )}
          </div>

          {/* Pagination */}
          <Pagination
            currentPage={templatesPage + 1} // Pagination component expects 1-indexed
            totalItems={total}
            pageSize={templatesPageSize}
            pageSizeOptions={[12, 24, 48, 96]}
            onPageChange={(page) => setTemplatesPage(page - 1)} // Convert back to 0-indexed
            onPageSizeChange={(size) => {
              setTemplatesPageSize(size)
              setTemplatesPage(0) // Reset to first page (0-indexed)
            }}
            itemLabel="templates"
            className="mt-2"
          />

          {/* Partners Callout Card */}
          <div className="mt-6 rounded-lg border border-border bg-gradient-to-br from-card via-card to-muted/30 p-6 transition-all hover:border-border">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0 flex-1">
                <h3 className="mb-1 text-[15px] font-semibold text-foreground">
                  Become a Technology Partner
                </h3>
                <p className="text-[13px] leading-relaxed text-muted-foreground">
                  Add your function templates to Appwrite and help developers
                  build faster. Join our Partners Program to distribute your
                  templates, get co-marketing opportunities, and access
                  dedicated support from our engineering team.
                </p>
              </div>
              <Button
                variant="default"
                size="sm"
                className="gap-1.5 shrink-0"
                asChild
              >
                <a
                  href="https://appwrite.io/partners"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Learn more about partnerships
                  <ArrowRight className="h-3.5 w-3.5" />
                </a>
              </Button>
            </div>
          </div>
        </div>
      </div>

      {/* Template Detail Drawer */}
      <Sheet
        open={!!selectedTemplate}
        onOpenChange={(open) => !open && setSelectedTemplate(null)}
      >
        <SheetContent
          className="w-full overflow-y-auto sm:max-w-lg"
          side="right"
          showCloseButton={false}
        >
          {selectedTemplate &&
            (() => {
              return (
                <>
                  <SheetHeader className="px-6 pt-6 text-left shrink-0">
                    <div className="flex items-center justify-between gap-4">
                      <SheetTitle className="text-[15px]">
                        {selectedTemplate.name}
                      </SheetTitle>
                      <SheetClose asChild>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 p-0 shrink-0 cursor-pointer"
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </SheetClose>
                    </div>
                  </SheetHeader>
                  <div className="border-t border-border shrink-0" />

                  <div className="space-y-6 px-6 pb-6">
                    {/* Meta info */}
                    <div className="flex flex-wrap items-center gap-3">
                      <Badge variant="secondary">
                        {selectedTemplate.category}
                      </Badge>
                      <div className="flex items-center gap-2">
                        <GitHubIcon className="h-4 w-4 text-muted-foreground" />
                        <span className="text-[13px] text-muted-foreground">
                          by {selectedTemplate.author}
                        </span>
                      </div>
                    </div>

                    {/* Instructions (HTML) */}
                    {selectedTemplate.instructions && (
                      <div>
                        <h4 className="mb-2 text-[13px] font-medium text-foreground">
                          Instructions
                        </h4>
                        <div
                          className="text-[13px] leading-relaxed text-muted-foreground [&_p]:mb-2 [&_ul]:list-disc [&_ul]:ml-4 [&_ul]:mb-2 [&_ol]:list-decimal [&_ol]:ml-4 [&_ol]:mb-2 [&_li]:mb-1 [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:rounded [&_code]:text-[12px] [&_code]:font-mono [&_pre]:bg-muted [&_pre]:p-2 [&_pre]:rounded [&_pre]:overflow-x-auto [&_pre]:text-[12px] [&_pre]:font-mono [&_a]:text-primary [&_a]:underline [&_h1]:text-[16px] [&_h1]:font-semibold [&_h1]:mb-2 [&_h2]:text-[15px] [&_h2]:font-semibold [&_h2]:mb-2 [&_h3]:text-[14px] [&_h3]:font-semibold [&_h3]:mb-2"
                          dangerouslySetInnerHTML={{
                            __html: selectedTemplate.instructions,
                          }}
                        />
                      </div>
                    )}

                    {/* Schedule (Cron) */}
                    {selectedTemplate.cron && (
                      <div>
                        <h4 className="mb-3 text-[13px] font-medium text-foreground">
                          Schedule
                        </h4>
                        <div className="flex items-center gap-2 rounded-lg bg-muted px-3 py-2">
                          <Clock className="h-4 w-4 text-muted-foreground" />
                          <code className="text-[12px] font-mono text-foreground">
                            {selectedTemplate.cron}
                          </code>
                        </div>
                      </div>
                    )}

                    {/* Configuration Details Accordion */}
                    <Accordion type="single" collapsible className="w-full">
                      {/* Required Scopes */}
                      <AccordionItem value="scopes">
                        <AccordionTrigger className="text-[13px] font-medium py-3 hover:no-underline">
                          <div className="flex items-center gap-2">
                            <span>Required Scopes</span>
                            <Badge
                              variant="secondary"
                              className="text-[10px] px-1.5 py-0"
                            >
                              {selectedTemplate.scopes.length}
                            </Badge>
                          </div>
                        </AccordionTrigger>
                        <AccordionContent>
                          <p className="text-[12px] text-muted-foreground mb-3">
                            Permissions required for the function to access
                            Appwrite resources.
                          </p>
                          {selectedTemplate.scopes.length > 0 ? (
                            <div className="flex flex-wrap gap-2">
                              {selectedTemplate.scopes.map((scope) => (
                                <Badge
                                  key={scope}
                                  variant="outline"
                                  className="text-[12px] font-mono"
                                >
                                  {scope}
                                </Badge>
                              ))}
                            </div>
                          ) : (
                            <p className="text-[12px] text-muted-foreground">
                              No scopes required for this template.
                            </p>
                          )}
                        </AccordionContent>
                      </AccordionItem>

                      {/* Trigger Events */}
                      <AccordionItem value="events">
                        <AccordionTrigger className="text-[13px] font-medium py-3 hover:no-underline">
                          <div className="flex items-center gap-2">
                            <span>Trigger Events</span>
                            <Badge
                              variant="secondary"
                              className="text-[10px] px-1.5 py-0"
                            >
                              {selectedTemplate.events.length}
                            </Badge>
                          </div>
                        </AccordionTrigger>
                        <AccordionContent>
                          <p className="text-[12px] text-muted-foreground mb-3">
                            Events that will trigger the function to execute
                            automatically.
                          </p>
                          {selectedTemplate.events.length > 0 ? (
                            <div className="flex flex-wrap gap-2">
                              {selectedTemplate.events.map((event) => (
                                <Badge
                                  key={event}
                                  variant="secondary"
                                  className="text-[12px] gap-1.5"
                                >
                                  <Bell className="h-3 w-3" />
                                  {event}
                                </Badge>
                              ))}
                            </div>
                          ) : (
                            <p className="text-[12px] text-muted-foreground">
                              No trigger events configured for this template.
                            </p>
                          )}
                        </AccordionContent>
                      </AccordionItem>

                      {/* Required Variables */}
                      <AccordionItem value="variables">
                        <AccordionTrigger className="text-[13px] font-medium py-3 hover:no-underline">
                          <div className="flex items-center gap-2">
                            <span>Required Variables</span>
                            <Badge
                              variant="secondary"
                              className="text-[10px] px-1.5 py-0"
                            >
                              {requiredVariables.length}
                            </Badge>
                          </div>
                        </AccordionTrigger>
                        <AccordionContent>
                          <p className="text-[12px] text-muted-foreground mb-3">
                            Environment variables that need to be configured for
                            the function to work properly.
                          </p>
                          {requiredVariables.length > 0 ? (
                            <div className="space-y-4">
                              {requiredVariables.map((variable, index) => (
                                <div key={variable.name}>
                                  {index > 0 && <Separator className="my-4" />}
                                  <div className="space-y-2">
                                    <div className="flex items-center gap-2 flex-wrap">
                                      <code className="text-[13px] font-mono font-semibold text-foreground">
                                        {variable.name}
                                      </code>
                                      {variable.required && (
                                        <Badge
                                          variant="outline"
                                          className="text-[10px]"
                                        >
                                          Required
                                        </Badge>
                                      )}
                                      {variable.secret && (
                                        <Badge
                                          variant="secondary"
                                          className="text-[10px] gap-1"
                                        >
                                          <Key className="h-2.5 w-2.5" />
                                          Secret
                                        </Badge>
                                      )}
                                      {variable.type && (
                                        <Badge
                                          variant="outline"
                                          className="text-[10px] font-mono"
                                        >
                                          {variable.type}
                                        </Badge>
                                      )}
                                    </div>
                                    {variable.description && (
                                      <div
                                        className="text-[12px] leading-relaxed text-muted-foreground [&_a]:text-primary [&_a]:underline [&_a]:font-semibold"
                                        dangerouslySetInnerHTML={{
                                          __html: variable.description,
                                        }}
                                      />
                                    )}
                                    {(variable.placeholder ||
                                      variable.value) && (
                                      <div className="flex items-center gap-2">
                                        <span className="text-[11px] text-muted-foreground">
                                          {variable.value
                                            ? 'Default:'
                                            : 'Placeholder:'}
                                        </span>
                                        <code className="text-[11px] font-mono text-foreground bg-muted px-2 py-1 rounded">
                                          {variable.value ||
                                            variable.placeholder}
                                        </code>
                                      </div>
                                    )}
                                  </div>
                                </div>
                              ))}
                            </div>
                          ) : (
                            <p className="text-[12px] text-muted-foreground">
                              No required variables for this template.
                            </p>
                          )}
                          {optionalVariables.length > 0 && (
                            <div className="mt-4 border-t border-border pt-4">
                              <h4 className="text-[13px] font-medium text-muted-foreground mb-3">
                                Optional variables ({optionalVariables.length})
                              </h4>
                              <div className="space-y-4 pt-0">
                                {optionalVariables.map((variable, index) => (
                                  <div key={variable.name}>
                                    {index > 0 && (
                                      <Separator className="my-4" />
                                    )}
                                    <div className="space-y-2">
                                      <div className="flex items-center gap-2 flex-wrap">
                                        <code className="text-[13px] font-mono font-semibold text-foreground">
                                          {variable.name}
                                        </code>
                                        {variable.secret && (
                                          <Badge
                                            variant="secondary"
                                            className="text-[10px] gap-1"
                                          >
                                            <Key className="h-2.5 w-2.5" />
                                            Secret
                                          </Badge>
                                        )}
                                        {variable.type && (
                                          <Badge
                                            variant="outline"
                                            className="text-[10px] font-mono"
                                          >
                                            {variable.type}
                                          </Badge>
                                        )}
                                      </div>
                                      {variable.description && (
                                        <div
                                          className="text-[12px] leading-relaxed text-muted-foreground [&_a]:text-primary [&_a]:underline [&_a]:font-semibold"
                                          dangerouslySetInnerHTML={{
                                            __html: variable.description,
                                          }}
                                        />
                                      )}
                                      {(variable.placeholder ||
                                        variable.value) && (
                                        <div className="flex items-center gap-2">
                                          <span className="text-[11px] text-muted-foreground">
                                            {variable.value
                                              ? 'Default:'
                                              : 'Placeholder:'}
                                          </span>
                                          <code className="text-[11px] font-mono text-foreground bg-muted px-2 py-1 rounded">
                                            {variable.value ||
                                              variable.placeholder}
                                          </code>
                                        </div>
                                      )}
                                    </div>
                                  </div>
                                ))}
                              </div>
                            </div>
                          )}
                        </AccordionContent>
                      </AccordionItem>
                    </Accordion>

                    {/* Supported runtimes */}
                    <div>
                      <h4 className="mb-3 text-[13px] font-medium text-foreground">
                        Supported Runtimes
                      </h4>
                      <div className="flex flex-wrap gap-2">
                        {selectedTemplate.runtimes.map((runtimeKey) => {
                          const runtime = runtimesMap[runtimeKey]
                          return (
                            <div
                              key={runtimeKey}
                              className="flex items-center gap-2 rounded-full bg-muted px-3 py-1.5"
                            >
                              <LanguageIcon
                                language={runtimeKey}
                                size="sm"
                                className="h-5 w-5"
                              />
                              <span className="text-[12px] font-medium text-foreground">
                                {runtime.name}
                              </span>
                            </div>
                          )
                        })}
                      </div>
                    </div>

                    {/* Action buttons */}
                    <div className="flex flex-col gap-3 pt-2">
                      <Button className="w-full gap-2" size="lg">
                        <Download className="h-4 w-4" />
                        Install Template
                      </Button>
                      <div className="flex gap-3">
                        <Button
                          variant="outline"
                          className="flex-1 gap-2"
                          asChild
                        >
                          <a
                            href={selectedTemplate.repoUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            <Github className="h-4 w-4" />
                            View Source
                          </a>
                        </Button>
                        <Button variant="outline" className="flex-1 gap-2">
                          <ExternalLink className="h-4 w-4" />
                          Documentation
                        </Button>
                      </div>
                    </div>

                    {/* Last updated */}
                    <p className="text-center text-[12px] text-muted-foreground">
                      Last updated:{' '}
                      {new Date(
                        selectedTemplate.lastUpdated,
                      ).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                      })}
                    </p>
                  </div>
                </>
              )
            })()}
        </SheetContent>
      </Sheet>
    </>
  )
}
