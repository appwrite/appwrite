import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Check, Copy, Download, ChevronDown, Loader2, AlertCircle, Search, X } from 'lucide-react'
import { toast } from 'sonner'
import { cn, truncateMiddle } from '@/lib/utils'
import { StartTruncatedText } from '@/components/global/shared/StartTruncatedText'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { formatDisplayKeys } from '@/lib/keyboard-shortcuts/display'
import { Badge } from '@/components/ui/badge'
import { getHttpMethodBadgeVariant as getHttpMethodVariant } from '@/lib/http-method-badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { CodeBlock, type CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { RateLimitDescription } from '@/components/global/shared/RateLimitDescription'
import {
  ExplorerColumnsResizableLayout,
  ExplorerResponseSplitResizableLayout,
} from './ApiExplorerResizableLayout'
import { MethodDescriptionMarkdown } from './MethodDescriptionMarkdown'
import { ApiExplorerAuthSection } from './ApiExplorerAuthSection'
import { ExplorerMethodActions } from './ExplorerMethodActions'
import { RequestBodySection } from './RequestBodySection'
import {
  RequestBuilderPanel,
  RequestBuilderSection,
  RequestFormFields,
} from './RequestFormFields'
import {
  executeApiRequest,
  executeApiMultipartRequest,
  filterAllowedServices,
  resolveExplorerSelection,
  generateSampleRequestBody,
  getProjectApiExplorerAllowedServices,
  getRequestBodyFormFields,
  getRequestBodyJsonSchema,
  groupMethodsByResource,
  groupServicesByProduct,
  isMultipartMethod,
  downloadOpenApiSpec,
  loadParsedApiSpec,
  buildDefaultBodyFormValues,
  buildInitialParamFormValues,
  buildMultipartFormData,
  buildCurlCommand,
  getMissingRequiredFormField,
  getMissingRequiredFieldInJsonBody,
  stripEmptyCreatableIdFieldsFromJson,
  hasRequestBodyForMethod,
  paramFormValuesToStrings,
  parameterToFormField,
  serializeBodyFromForm,
  createUserJwtForExplorer,
  getMethodRequiredScopes,
  methodRequiresSendConfirmation,
  getSendRequestConfirmationCopy,
  methodRequiresSessionAuthChoice,
  methodRequiresApiKey,
  methodSupportsServerApiKey,
  resolveServerAuthApiKey,
  useApiExplorerAuthPersistence,
  type ApiExplorerConfig,
  type ApiExplorerMethod,
  type ApiExplorerService,
  type ApiExplorerServiceProductGroup,
  type ApiExplorerProjectPlatform,
  type ApiExplorerClientAuthState,
  type ApiExplorerServerAuthState,
  type ExecuteApiRequestResult,
  type FormValue,
  type OpenApiParameter,
  type ParsedApiSpec,
  type RequestFormField,
} from '@/lib/api-explorer'
import {
  apiNavItemClassName,
  apiNavMethodItemClassName,
} from '@/lib/api-explorer/nav-styles'
import { API_EXPLORER_SEND_REQUEST_SHORTCUT_RAW } from '@/lib/api-explorer/shortcuts'
import { useApiExplorerShortcuts } from '@/lib/api-explorer/use-api-explorer-shortcuts'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  useApiExplorerColumnsLayout,
  useApiExplorerExpandedProductGroup,
  useApiExplorerResponseSplitLayout,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks'

const HANDLE_CLASS = cn(
  'relative z-[45] w-[0.5px] bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:w-2 after:left-1/2 after:-translate-x-1/2',
)

const VERTICAL_HANDLE_CLASS = cn(
  'relative z-[45] h-[0.5px] w-full bg-border',
  'before:pointer-events-none before:absolute before:inset-x-0 before:top-1/2 before:h-2 before:w-full before:-translate-y-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:h-2 after:top-1/2 after:w-full after:-translate-y-1/2',
)

/** Fixed height keeps Methods and Request column headers aligned. */
const COLUMN_HEADER_CLASS =
  'flex h-[62px] shrink-0 border-b border-border px-4'

const COLUMN_REQUEST_FOOTER_CLASS =
  'flex h-[62px] shrink-0 items-center justify-end border-t border-border bg-muted/30 px-4'

/** Scrollable methods list body (flex child must shrink below content height). */
const EXPLORER_METHODS_LIST_SCROLL_CLASS =
  'min-h-0 flex-1 basis-0 overflow-x-hidden overflow-y-auto'

export type ApiExplorerProps = {
  config: ApiExplorerConfig
  initialServiceId?: string
  initialOperationId?: string
  onSelectionChange?: (selection: {
    serviceId: string
    operationId: string
  }) => void
  /** @deprecated Toolbar controls live in the services column. */
  searchValue?: string
  /** @deprecated Toolbar controls live in the services column. */
  onSearchChange?: (value: string) => void
  platform?: ApiExplorerProjectPlatform
  onPlatformChange?: (platform: ApiExplorerProjectPlatform) => void
  /** @deprecated Toolbar controls live in the services column. */
  hideToolbar?: boolean
  className?: string
}

type ApiExplorerPlatformToggleProps = {
  value: ApiExplorerProjectPlatform
  onChange: (platform: ApiExplorerProjectPlatform) => void
  className?: string
}

export function ApiExplorerPlatformToggle({
  value,
  onChange,
  className,
}: ApiExplorerPlatformToggleProps) {
  return (
    <ToggleGroup
      type="single"
      variant="outline"
      size="sm"
      value={value}
      onValueChange={(next: string) => {
        if (next !== 'server' && next !== 'client') return
        onChange(next)
      }}
      className={cn('shrink-0', className)}
      aria-label="API platform"
    >
      <ToggleGroupItem
        value="client"
        className="h-9 px-3 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
      >
        Client API
      </ToggleGroupItem>
      <ToggleGroupItem
        value="server"
        className="h-9 px-3 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
      >
        Server API
      </ToggleGroupItem>
    </ToggleGroup>
  )
}

type ApiExplorerDownloadSpecButtonProps = {
  className?: string
}

const OPENAPI_SPEC_OPTIONS: {
  value: ApiExplorerProjectPlatform
  label: string
}[] = [
  { value: 'server', label: 'Server API' },
  { value: 'client', label: 'Client API' },
]

export function ApiExplorerDownloadSpecButton({
  className,
}: ApiExplorerDownloadSpecButtonProps) {
  const [isDownloading, setIsDownloading] = useState(false)

  const handleDownload = useCallback(
    async (platform: ApiExplorerProjectPlatform) => {
      setIsDownloading(true)
      try {
        await downloadOpenApiSpec(platform)
      } catch {
        toast.error('Failed to download OpenAPI spec')
      } finally {
        setIsDownloading(false)
      }
    },
    [],
  )

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={isDownloading}
          className={cn(
            'h-9 shrink-0 border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50',
            className,
          )}
        >
          {isDownloading ? (
            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
          ) : (
            <Download className="mr-1.5 h-4 w-4" />
          )}
          OpenAPI spec
          <ChevronDown className="ml-1.5 h-3.5 w-3.5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {OPENAPI_SPEC_OPTIONS.map((option) => (
          <DropdownMenuItem
            key={option.value}
            disabled={isDownloading}
            onClick={() => handleDownload(option.value)}
          >
            {option.label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

function ApiExplorerOpenApiSpecDownloadFooter({
  platform,
}: {
  platform: ApiExplorerProjectPlatform
}) {
  const [isDownloading, setIsDownloading] = useState(false)

  const handleDownload = useCallback(async () => {
    setIsDownloading(true)
    try {
      await downloadOpenApiSpec(platform)
    } catch {
      toast.error('Failed to download OpenAPI spec')
    } finally {
      setIsDownloading(false)
    }
  }, [platform])

  return (
    <div className="shrink-0 border-t border-border bg-background px-3 py-3">
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={isDownloading}
        onClick={() => void handleDownload()}
        className="h-9 w-full text-[13px] text-muted-foreground hover:text-foreground"
      >
        {isDownloading ? (
          <Loader2 className="mr-1.5 size-4 animate-spin" />
        ) : (
          <Download className="mr-1.5 size-4" />
        )}
        OpenAPI spec
      </Button>
    </div>
  )
}

function applyMethodFormState(
  method: ApiExplorerMethod,
  setters: {
    setPathFormValues: (values: Record<string, FormValue>) => void
    setQueryFormValues: (values: Record<string, FormValue>) => void
    setBodyFormValues: (values: Record<string, FormValue>) => void
    setBodyJsonValue: (value: string) => void
    setBodyInputMode: (mode: 'form' | 'json') => void
  },
) {
  const pathParameters = method.parameters.filter((param) => param.in === 'path')
  const queryParameters = method.parameters.filter((param) => param.in === 'query')

  setters.setPathFormValues(buildInitialParamFormValues(pathParameters, method))
  setters.setQueryFormValues(buildInitialParamFormValues(queryParameters, method))
  setters.setBodyFormValues(buildDefaultBodyFormValues(method))
  setters.setBodyJsonValue(
    generateSampleRequestBody(getRequestBodyJsonSchema(method)),
  )
  setters.setBodyInputMode('form')
}

export function ApiExplorer({
  config,
  initialServiceId,
  initialOperationId,
  onSelectionChange,
  platform: controlledPlatform,
  onPlatformChange,
  className,
}: ApiExplorerProps) {
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout: columnsLayout, persistLayout: persistColumnsLayout } =
    useApiExplorerColumnsLayout(consoleAccount)
  const {
    expandedProductGroupId,
    setExpandedProductGroup,
  } = useApiExplorerExpandedProductGroup(consoleAccount)
  const initialPlatform: ApiExplorerProjectPlatform = config.platform ?? 'server'
  const [parsedSpec, setParsedSpec] = useState<ParsedApiSpec | null>(null)
  const [specError, setSpecError] = useState<string | null>(null)
  const [specLoading, setSpecLoading] = useState(true)
  const [selectedServiceId, setSelectedServiceId] = useState<string | null>(
    initialServiceId ?? null,
  )
  const [selectedMethod, setSelectedMethod] = useState<
    ApiExplorerMethod | undefined
  >()
  const [pathFormValues, setPathFormValues] = useState<Record<string, FormValue>>({})
  const [queryFormValues, setQueryFormValues] = useState<Record<string, FormValue>>({})
  const [bodyFormValues, setBodyFormValues] = useState<Record<string, FormValue>>({})
  const [bodyJsonValue, setBodyJsonValue] = useState('')
  const [bodyInputMode, setBodyInputMode] = useState<'form' | 'json'>('form')
  const [response, setResponse] = useState<ExecuteApiRequestResult | null>(null)
  const [showResponsePanel, setShowResponsePanel] = useState(false)
  const [isExecuting, setIsExecuting] = useState(false)
  const [sendConfirmOpen, setSendConfirmOpen] = useState(false)
  const {
    internalPlatform,
    setInternalPlatform,
    clientAuth,
    setClientAuth,
    serverAuth,
    setServerAuth,
  } = useApiExplorerAuthPersistence(config.projectId, initialPlatform)
  const activePlatform = controlledPlatform ?? internalPlatform
  const setActivePlatform = onPlatformChange ?? setInternalPlatform
  const selectedOperationRef = useRef<string | undefined>(initialOperationId)
  const { features } = useConsoleProfile()

  const allowedServices = useMemo(
    () =>
      config.allowedServices ?? getProjectApiExplorerAllowedServices(features),
    [
      config.allowedServices,
      features.dedicatedDbsDocumentsDB,
      features.dedicatedDbsVectorsDB,
    ],
  )

  useEffect(() => {
    let cancelled = false
    setSpecLoading(true)
    setSpecError(null)

    loadParsedApiSpec(activePlatform)
      .then((parsed) => {
        if (cancelled) return
        setParsedSpec(parsed)
        setSpecLoading(false)
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setSpecError(getErrorMessage(error) || 'Failed to load API specification')
        setSpecLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [activePlatform])

  const visibleServices = useMemo(
    () =>
      filterAllowedServices(parsedSpec?.services ?? [], allowedServices),
    [parsedSpec?.services, allowedServices],
  )

  useEffect(() => {
    if (activePlatform !== 'server' || !selectedMethod) return
    const methodScopes = getMethodRequiredScopes(selectedMethod)
    setServerAuth((previous) => {
      if (previous.ephemeralDraftScopes.length > 0) return previous
      if (methodScopes.length === 0) return previous
      return { ...previous, ephemeralDraftScopes: methodScopes }
    })
  }, [activePlatform, selectedMethod?.id])

  const serviceProductGroups = useMemo(
    () => groupServicesByProduct(visibleServices),
    [visibleServices],
  )

  const resolvedExpandedProductGroupId = useMemo(() => {
    if (
      expandedProductGroupId &&
      serviceProductGroups.some((group) => group.id === expandedProductGroupId)
    ) {
      return expandedProductGroupId
    }
    if (selectedServiceId) {
      const selectedGroup = serviceProductGroups.find((group) =>
        group.services.some((service) => service.id === selectedServiceId),
      )
      if (selectedGroup) return selectedGroup.id
    }
    return serviceProductGroups.find((group) => group.services.length > 0)?.id ?? ''
  }, [expandedProductGroupId, selectedServiceId, serviceProductGroups])

  const selectedService = useMemo(() => {
    if (!selectedServiceId) return visibleServices[0]
    return (
      visibleServices.find((service) => service.id === selectedServiceId) ??
      visibleServices[0]
    )
  }, [visibleServices, selectedServiceId])

  const openProductGroupId =
    expandedProductGroupId &&
    serviceProductGroups.some((group) => group.id === expandedProductGroupId)
      ? expandedProductGroupId
      : resolvedExpandedProductGroupId

  const expandProductGroupForService = useCallback(
    (serviceId: string) => {
      const group = serviceProductGroups.find((productGroup) =>
        productGroup.services.some((service) => service.id === serviceId),
      )
      if (group) {
        setExpandedProductGroup(group.id)
      }
    },
    [serviceProductGroups, setExpandedProductGroup],
  )

  const applyExplorerSelection = useCallback(
    (method: ApiExplorerMethod, options?: { syncUrl?: boolean; clearResponse?: boolean }) => {
      selectedOperationRef.current = method.operationId
      setSelectedServiceId(method.service)
      setSelectedMethod(method)
      expandProductGroupForService(method.service)
      applyMethodFormState(method, {
        setPathFormValues,
        setQueryFormValues,
        setBodyFormValues,
        setBodyJsonValue,
        setBodyInputMode,
      })
      if (options?.clearResponse) {
        setResponse(null)
      }
      if (options?.syncUrl) {
        onSelectionChange?.({
          serviceId: method.service,
          operationId: method.operationId,
        })
      }
    },
    [expandProductGroupForService, onSelectionChange],
  )

  useEffect(() => {
    if (!parsedSpec || specLoading) return

    const { method, serviceId } = resolveExplorerSelection({
      visibleServices,
      initialServiceId,
      initialOperationId,
      preservedOperationId: selectedOperationRef.current,
    })

    if (!method) {
      if (serviceId && serviceId !== selectedServiceId) {
        setSelectedServiceId(serviceId)
      }
      return
    }

    const urlMatchesSelection =
      initialServiceId === method.service &&
      initialOperationId === method.operationId

    if (
      selectedMethod?.operationId === method.operationId &&
      selectedServiceId === method.service
    ) {
      if (!urlMatchesSelection) {
        onSelectionChange?.({
          serviceId: method.service,
          operationId: method.operationId,
        })
      }
      return
    }

    applyExplorerSelection(method, {
      syncUrl: !urlMatchesSelection,
      clearResponse: false,
    })
  }, [
    applyExplorerSelection,
    initialOperationId,
    initialServiceId,
    onSelectionChange,
    parsedSpec,
    selectedMethod?.operationId,
    selectedServiceId,
    specLoading,
    visibleServices,
  ])

  const handleSelectService = useCallback((service: ApiExplorerService) => {
    const firstMethod = service.methods[0]
    if (!firstMethod) {
      setSelectedServiceId(service.id)
      expandProductGroupForService(service.id)
      setResponse(null)
      return
    }

    applyExplorerSelection(firstMethod, {
      syncUrl: true,
      clearResponse: true,
    })
  }, [applyExplorerSelection, expandProductGroupForService])

  const handleSelectMethod = useCallback((method: ApiExplorerMethod) => {
    applyExplorerSelection(method, {
      syncUrl: true,
      clearResponse: true,
    })
  }, [applyExplorerSelection])

  const bodyFormFields = useMemo(
    () => (selectedMethod ? getRequestBodyFormFields(selectedMethod) : []),
    [selectedMethod],
  )

  const handleResetRequestForm = useCallback(() => {
    if (!selectedMethod) return

    applyMethodFormState(selectedMethod, {
      setPathFormValues,
      setQueryFormValues,
      setBodyFormValues,
      setBodyJsonValue,
      setBodyInputMode,
    })
  }, [selectedMethod])

  const runExecuteRequest = useCallback(async () => {
    if (!selectedMethod) return

    const pathParameters = selectedMethod.parameters.filter(
      (param) => param.in === 'path',
    )
    const queryParameters = selectedMethod.parameters.filter(
      (param) => param.in === 'query',
    )

    const pathParams = paramFormValuesToStrings(pathParameters, pathFormValues)
    const queryParams = paramFormValuesToStrings(queryParameters, queryFormValues)

    const multipart = isMultipartMethod(selectedMethod)
    let body = ''
    let formData: FormData | undefined

    if (hasRequestBodyForMethod(selectedMethod)) {
      if (multipart) {
        const missingField = getMissingRequiredFormField(
          bodyFormFields,
          bodyFormValues,
        )
        if (missingField) {
          toast.error(`Missing required field: ${missingField.label}`)
          return
        }
        formData = buildMultipartFormData(bodyFormFields, bodyFormValues)
      } else if (bodyInputMode === 'json') {
        const missingField = getMissingRequiredFieldInJsonBody(
          bodyFormFields,
          bodyJsonValue,
        )
        if (missingField) {
          toast.error(`Missing required field: ${missingField.label}`)
          return
        }
        if (bodyJsonValue.trim()) {
          try {
            body = stripEmptyCreatableIdFieldsFromJson(
              bodyFormFields,
              bodyJsonValue,
            )
          } catch {
            toast.error('Invalid JSON in request body.')
            return
          }
        } else {
          body = bodyJsonValue
        }
      } else {
        const missingField = getMissingRequiredFormField(
          bodyFormFields,
          bodyFormValues,
        )
        if (missingField) {
          toast.error(`Missing required field: ${missingField.label}`)
          return
        }
        try {
          body = serializeBodyFromForm(bodyFormFields, bodyFormValues)
        } catch (error: unknown) {
          toast.error(getErrorMessage(error) || 'Invalid request body')
          return
        }
      }
    }

    setIsExecuting(true)
    setShowResponsePanel(true)

    try {
      let requestAuth:
        | { mode: ApiExplorerClientAuthState['mode']; jwt?: string }
        | undefined
      let apiKey: string | undefined

      if (activePlatform === 'client') {
        if (methodRequiresSessionAuthChoice(selectedMethod, activePlatform)) {
          if (clientAuth.mode === 'user') {
            if (!clientAuth.userId.trim()) {
              toast.error('Select a user to act as, or choose Guest.')
              setIsExecuting(false)
              return
            }
            const jwt = await createUserJwtForExplorer(
              config.projectId,
              clientAuth.userId.trim(),
            )
            requestAuth = { mode: 'user', jwt }
          } else {
            requestAuth = { mode: 'guest' }
          }
        }
      } else if (methodSupportsServerApiKey(selectedMethod, activePlatform)) {
        apiKey = resolveServerAuthApiKey(serverAuth)
        if (methodRequiresApiKey(selectedMethod, activePlatform) && !apiKey) {
          toast.error('Provide an API key or generate an ephemeral key.')
          setIsExecuting(false)
          return
        }
      }

      const requestInput = {
        config: { ...config, platform: activePlatform, apiKey },
        method: selectedMethod,
        pathParams,
        queryParams,
        requestAuth,
      }

      const result = multipart
        ? await executeApiMultipartRequest({
            ...requestInput,
            formData: formData ?? new FormData(),
          })
        : await executeApiRequest({
            ...requestInput,
            body,
          })
      setResponse(result)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error) || 'Request failed')
    } finally {
      setIsExecuting(false)
    }
  }, [
    activePlatform,
    clientAuth,
    serverAuth,
    bodyFormFields,
    bodyFormValues,
    bodyInputMode,
    bodyJsonValue,
    config,
    pathFormValues,
    queryFormValues,
    selectedMethod,
  ])

  const handleExecute = useCallback(() => {
    if (!selectedMethod) return
    if (methodRequiresSendConfirmation(selectedMethod)) {
      setSendConfirmOpen(true)
      return
    }
    void runExecuteRequest()
  }, [runExecuteRequest, selectedMethod])

  const handleConfirmSendRequest = useCallback(() => {
    setSendConfirmOpen(false)
    void runExecuteRequest()
  }, [runExecuteRequest])

  const sendRequestConfirmation = useMemo(
    () =>
      selectedMethod
        ? getSendRequestConfirmationCopy(selectedMethod)
        : null,
    [selectedMethod],
  )

  const handleCopyCurl = useCallback(async () => {
    if (!selectedMethod) return

    const pathParameters = selectedMethod.parameters.filter(
      (param) => param.in === 'path',
    )
    const queryParameters = selectedMethod.parameters.filter(
      (param) => param.in === 'query',
    )

    const pathParams = paramFormValuesToStrings(pathParameters, pathFormValues)
    const queryParams = paramFormValuesToStrings(queryParameters, queryFormValues)

    const multipart = isMultipartMethod(selectedMethod)
    let body = ''
    let formData: FormData | undefined

    if (hasRequestBodyForMethod(selectedMethod)) {
      if (multipart) {
        const missingField = getMissingRequiredFormField(
          bodyFormFields,
          bodyFormValues,
        )
        if (missingField) {
          toast.error(`Missing required field: ${missingField.label}`)
          return
        }
        formData = buildMultipartFormData(bodyFormFields, bodyFormValues)
      } else if (bodyInputMode === 'json') {
        const missingField = getMissingRequiredFieldInJsonBody(
          bodyFormFields,
          bodyJsonValue,
        )
        if (missingField) {
          toast.error(`Missing required field: ${missingField.label}`)
          return
        }
        if (bodyJsonValue.trim()) {
          try {
            body = stripEmptyCreatableIdFieldsFromJson(
              bodyFormFields,
              bodyJsonValue,
            )
          } catch {
            toast.error('Invalid JSON in request body.')
            return
          }
        } else {
          body = bodyJsonValue
        }
      } else {
        const missingField = getMissingRequiredFormField(
          bodyFormFields,
          bodyFormValues,
        )
        if (missingField) {
          toast.error(`Missing required field: ${missingField.label}`)
          return
        }
        try {
          body = serializeBodyFromForm(bodyFormFields, bodyFormValues)
        } catch (error: unknown) {
          toast.error(getErrorMessage(error) || 'Invalid request body')
          return
        }
      }
    }

    try {
      let requestAuth:
        | { mode: ApiExplorerClientAuthState['mode']; jwt?: string }
        | undefined
      let apiKey: string | undefined

      if (activePlatform === 'client') {
        if (methodRequiresSessionAuthChoice(selectedMethod, activePlatform)) {
          if (clientAuth.mode === 'user') {
            if (!clientAuth.userId.trim()) {
              toast.error('Select a user to act as, or choose Guest.')
              return
            }
            const jwt = await createUserJwtForExplorer(
              config.projectId,
              clientAuth.userId.trim(),
            )
            requestAuth = { mode: 'user', jwt }
          } else {
            requestAuth = { mode: 'guest' }
          }
        }
      } else if (methodSupportsServerApiKey(selectedMethod, activePlatform)) {
        apiKey = resolveServerAuthApiKey(serverAuth)
        if (methodRequiresApiKey(selectedMethod, activePlatform) && !apiKey) {
          toast.error('Provide an API key or generate an ephemeral key.')
          return
        }
      }

      const curl = buildCurlCommand({
        config: { ...config, platform: activePlatform, apiKey },
        method: selectedMethod,
        pathParams,
        queryParams,
        body: formData ? undefined : body,
        formData,
        requestAuth,
      })

      const copied = await copyToClipboard('cURL', curl, { showToast: false })
      if (copied) {
        toast.success('cURL copied')
      }
    } catch (error: unknown) {
      toast.error(getErrorMessage(error) || 'Failed to copy cURL')
    }
  }, [
    activePlatform,
    clientAuth,
    serverAuth,
    bodyFormFields,
    bodyFormValues,
    bodyInputMode,
    bodyJsonValue,
    config,
    pathFormValues,
    queryFormValues,
    selectedMethod,
  ])

  const handlePlatformChange = useCallback(
    (platform: ApiExplorerProjectPlatform) => {
      setActivePlatform(platform)
    },
    [setActivePlatform],
  )

  const previousPlatformRef = useRef(activePlatform)
  useEffect(() => {
    if (previousPlatformRef.current === activePlatform) return
    previousPlatformRef.current = activePlatform
    setResponse(null)
  }, [activePlatform])

  const pathParameters = selectedMethod?.parameters.filter(
    (param) => param.in === 'path',
  )
  const queryParameters = selectedMethod?.parameters.filter(
    (param) => param.in === 'query',
  )
  const hasRequestBody = selectedMethod
    ? hasRequestBodyForMethod(selectedMethod)
    : false
  const hasJsonBodySchema = selectedMethod
    ? Boolean(getRequestBodyJsonSchema(selectedMethod)?.properties)
    : false

  const canSendRequest = Boolean(selectedMethod && !isExecuting)

  useApiExplorerShortcuts({
    onSendRequest: handleExecute,
    enabled: canSendRequest,
  })

  useEffect(() => {
    setSendConfirmOpen(false)
  }, [activePlatform, selectedMethod?.id])

  if (specLoading) {
    return (
      <div
        className={cn(
          'flex h-full min-h-0 flex-1 items-center justify-center text-[13px] text-muted-foreground',
          className,
        )}
      >
        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
        Loading API specification…
      </div>
    )
  }

  if (specError || !parsedSpec) {
    return (
      <div
        className={cn(
          'flex h-full min-h-0 flex-1 items-center justify-center px-6 text-center text-[13px] text-muted-foreground',
          className,
        )}
      >
        {specError ?? 'API specification unavailable.'}
      </div>
    )
  }

  return (
    <div
      data-api-explorer
      className={cn('flex h-full min-h-0 flex-1 flex-col', className)}
    >
      <div className="min-h-0 flex-1">
        <ExplorerColumnsResizableLayout
          layout={columnsLayout}
          persistLayout={persistColumnsLayout}
          handleClassName={HANDLE_CLASS}
          className="h-full min-h-0 overflow-hidden"
          services={
            <ServiceListPanel
              platform={activePlatform}
              onPlatformChange={handlePlatformChange}
              productGroups={serviceProductGroups}
              selectedServiceId={selectedService?.id}
              onSelectService={handleSelectService}
              expandedProductGroupId={openProductGroupId}
              onExpandedProductGroupChange={setExpandedProductGroup}
            />
          }
          methods={
            <MethodListPanel
              service={selectedService}
              selectedMethodId={selectedMethod?.id}
              onSelectMethod={handleSelectMethod}
            />
          }
          request={
            <RequestPanel
              endpoint={config.endpoint}
              projectId={config.projectId}
              platform={activePlatform}
              method={selectedMethod}
              clientAuth={clientAuth}
              serverAuth={serverAuth}
              pathFormValues={pathFormValues}
              queryFormValues={queryFormValues}
              bodyFormFields={bodyFormFields}
              bodyFormValues={bodyFormValues}
              bodyJsonValue={bodyJsonValue}
              bodyInputMode={bodyInputMode}
              pathParameters={pathParameters}
              queryParameters={queryParameters}
              hasRequestBody={hasRequestBody}
              hasJsonBodySchema={hasJsonBodySchema}
              isExecuting={isExecuting}
              showResponsePanel={showResponsePanel}
              response={response}
              onPathFormValuesChange={setPathFormValues}
              onQueryFormValuesChange={setQueryFormValues}
              onBodyFormValuesChange={setBodyFormValues}
              onBodyJsonValueChange={setBodyJsonValue}
              onBodyInputModeChange={setBodyInputMode}
              onClientAuthChange={setClientAuth}
              onServerAuthChange={setServerAuth}
              onExecute={handleExecute}
              onCopyCurl={handleCopyCurl}
              onResetRequestForm={handleResetRequestForm}
            />
          }
        />
      </div>
      {sendRequestConfirmation ? (
        <ConfirmActionDialog
          open={sendConfirmOpen}
          onOpenChange={setSendConfirmOpen}
          title={sendRequestConfirmation.title}
          description={sendRequestConfirmation.description}
          confirmLabel="Send request"
          confirmVariant={sendRequestConfirmation.confirmVariant}
          onConfirm={handleConfirmSendRequest}
          isConfirming={isExecuting}
        />
      ) : null}
    </div>
  )
}

type ServiceListPanelProps = {
  platform: ApiExplorerProjectPlatform
  onPlatformChange: (platform: ApiExplorerProjectPlatform) => void
  productGroups: ApiExplorerServiceProductGroup[]
  selectedServiceId?: string
  onSelectService: (service: ApiExplorerService) => void
  expandedProductGroupId: string
  onExpandedProductGroupChange: (groupId: string | undefined) => void
}

function ServiceListPanel({
  platform,
  onPlatformChange,
  productGroups,
  selectedServiceId,
  onSelectService,
  expandedProductGroupId,
  onExpandedProductGroupChange,
}: ServiceListPanelProps) {
  const hasServices = productGroups.some((group) => group.services.length > 0)

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-r border-border bg-background">
      <div className="min-h-0 flex-1 overflow-y-auto px-3 py-4">
        <div className="space-y-4">
          <div className="px-1">
            <ApiExplorerPlatformToggle
              value={platform}
              onChange={onPlatformChange}
              className="w-full [&>button]:flex-1"
            />
          </div>

          <nav aria-label="API services">
            <p className="mb-1.5 px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              APIs
            </p>
            {!hasServices ? (
              <p className="px-2 py-2 text-[13px] text-muted-foreground">
                No services available for this API.
              </p>
            ) : (
              <Accordion
                type="single"
                collapsible
                value={expandedProductGroupId}
                onValueChange={(value) =>
                  onExpandedProductGroupChange(value || undefined)
                }
                className="w-full space-y-1 px-2"
              >
                {productGroups.map((group) => (
                  <AccordionItem
                    key={group.id}
                    value={group.id}
                    className="border-b border-border/50 pb-1 last:border-b-0 last:pb-0"
                  >
                    <AccordionTrigger className="gap-1.5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70 hover:no-underline [&>svg]:size-3.5 [&>svg]:text-muted-foreground/70">
                      <span className="min-w-0 flex-1 truncate text-left">
                        {group.label}
                      </span>
                    </AccordionTrigger>
                    <AccordionContent className="pb-2 pt-0">
                      <ul className="space-y-0.5">
                        {group.services.map((service) => {
                          const isActive = service.id === selectedServiceId
                          return (
                            <li key={service.id}>
                              <button
                                type="button"
                                onClick={() => onSelectService(service)}
                                className={cn(
                                  'w-full text-left',
                                  apiNavItemClassName(isActive),
                                )}
                              >
                                <span className="truncate">{service.label}</span>
                              </button>
                            </li>
                          )
                        })}
                      </ul>
                    </AccordionContent>
                  </AccordionItem>
                ))}
              </Accordion>
            )}
          </nav>
        </div>
      </div>
      <ApiExplorerOpenApiSpecDownloadFooter platform={platform} />
    </div>
  )
}

type MethodListPanelProps = {
  service?: ApiExplorerService
  selectedMethodId?: string
  onSelectMethod: (method: ApiExplorerMethod) => void
}

function methodMatchesSearch(method: ApiExplorerMethod, query: string): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true
  return (
    method.summary.toLowerCase().includes(q) ||
    method.path.toLowerCase().includes(q) ||
    method.id.toLowerCase().includes(q) ||
    method.httpMethod.toLowerCase().includes(q) ||
    (method.resourceGroup?.toLowerCase().includes(q) ?? false)
  )
}

function MethodListPanel({
  service,
  selectedMethodId,
  onSelectMethod,
}: MethodListPanelProps) {
  const selectedMethodRef = useRef<HTMLButtonElement | null>(null)
  const [searchValue, setSearchValue] = useState('')

  useEffect(() => {
    setSearchValue('')
  }, [service?.id])

  const resourceGroups = useMemo(
    () => groupMethodsByResource(service?.methods ?? []),
    [service?.methods],
  )

  const filteredGroups = useMemo(() => {
    const query = searchValue.trim()
    if (!query) return resourceGroups

    return resourceGroups
      .map((group) => ({
        ...group,
        methods: group.methods.filter((method) => methodMatchesSearch(method, query)),
      }))
      .filter((group) => group.methods.length > 0)
  }, [resourceGroups, searchValue])

  useEffect(() => {
    selectedMethodRef.current?.scrollIntoView({
      block: 'nearest',
      inline: 'nearest',
    })
  }, [service?.id, selectedMethodId, filteredGroups])

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-r border-border bg-muted/20">
      <div
        className={cn(
          COLUMN_HEADER_CLASS,
          'min-w-0 items-center overflow-hidden',
        )}
      >
        {service ? (
          <p className="truncate text-[13px] font-medium text-foreground">
            {service.label}
          </p>
        ) : (
          <span className="block h-[13px]" aria-hidden />
        )}
      </div>

      <div className="shrink-0 border-b border-border bg-muted/20 px-2 py-2">
        <div className="relative">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchValue}
            onChange={(event) => setSearchValue(event.target.value)}
            placeholder="Search methods…"
            className="h-8 border-border/60 bg-background pl-8 pr-8 text-[13px]"
            aria-label="Search methods"
            disabled={!service}
          />
          {searchValue ? (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="absolute right-0.5 top-1/2 h-7 w-7 -translate-y-1/2 text-muted-foreground"
              aria-label="Clear method search"
              onClick={() => setSearchValue('')}
            >
              <X className="size-3.5" />
            </Button>
          ) : null}
        </div>
      </div>

      <div className={EXPLORER_METHODS_LIST_SCROLL_CLASS}>
        <div className="box-border w-full max-w-full min-w-0 space-y-3 p-2">
          {!service || service.methods.length === 0 ? (
            <p className="px-2 py-4 text-[13px] text-muted-foreground">
              No methods available.
            </p>
          ) : filteredGroups.length === 0 ? (
            <p className="px-2 py-4 text-[13px] text-muted-foreground">
              No methods match your search.
            </p>
          ) : (
            filteredGroups.map((group) => (
              <div key={group.id || 'default'} className="space-y-1.5">
                  {group.label ? (
                    <p className="px-0.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70">
                      {group.label}
                    </p>
                  ) : null}
                  <ul className="space-y-0.5">
                    {group.methods.map((method) => {
                      const isActive = method.id === selectedMethodId
                      return (
                        <li key={method.id}>
                          <button
                            ref={
                              isActive
                                ? (node) => {
                                    selectedMethodRef.current = node
                                  }
                                : undefined
                            }
                            type="button"
                            onClick={() => onSelectMethod(method)}
                            className={apiNavMethodItemClassName(isActive)}
                          >
                            <span className="min-w-0 truncate text-[13px] font-medium">
                              {method.summary}
                            </span>
                            <div className="flex w-full min-w-0 max-w-full items-center gap-2">
                              <Badge
                                variant={getHttpMethodVariant(method.httpMethod)}
                                className="text-[10px] shrink-0 uppercase"
                              >
                                {method.httpMethod}
                              </Badge>
                              <StartTruncatedText
                                text={method.path}
                                className="min-w-0 flex-1 font-mono text-[11px] text-muted-foreground"
                              />
                            </div>
                          </button>
                        </li>
                      )
                    })}
                  </ul>
                </div>
              ))
          )}
        </div>
      </div>
    </div>
  )
}

type RequestPanelProps = {
  endpoint: string
  projectId: string
  platform: ApiExplorerProjectPlatform
  method?: ApiExplorerMethod
  clientAuth: ApiExplorerClientAuthState
  serverAuth: ApiExplorerServerAuthState
  pathFormValues: Record<string, FormValue>
  queryFormValues: Record<string, FormValue>
  bodyFormFields: RequestFormField[]
  bodyFormValues: Record<string, FormValue>
  bodyJsonValue: string
  bodyInputMode: 'form' | 'json'
  pathParameters?: OpenApiParameter[]
  queryParameters?: OpenApiParameter[]
  hasRequestBody: boolean
  hasJsonBodySchema: boolean
  isExecuting: boolean
  showResponsePanel: boolean
  response: ExecuteApiRequestResult | null
  onPathFormValuesChange: (values: Record<string, FormValue>) => void
  onQueryFormValuesChange: (values: Record<string, FormValue>) => void
  onBodyFormValuesChange: (values: Record<string, FormValue>) => void
  onBodyJsonValueChange: (value: string) => void
  onBodyInputModeChange: (mode: 'form' | 'json') => void
  onClientAuthChange: (state: ApiExplorerClientAuthState) => void
  onServerAuthChange: (state: ApiExplorerServerAuthState) => void
  onExecute: () => void
  onCopyCurl: () => void
  onResetRequestForm: () => void
}

/** Character cap for middle truncation in the request details endpoint row. */
const ENDPOINT_URL_DISPLAY_MAX = 64

function getDeprecatedWarningCopy(method: ApiExplorerMethod): {
  title: string
  description: string
} {
  const meta = method.xAppwrite?.deprecated
  const descriptionParts: string[] = []

  if (meta?.since) {
    descriptionParts.push(`Deprecated since ${meta.since}.`)
  }
  if (meta?.replaceWith) {
    descriptionParts.push(`Use ${meta.replaceWith} instead.`)
  }
  if (descriptionParts.length === 0) {
    descriptionParts.push(
      'This endpoint is deprecated and may be removed in a future version.',
    )
  }

  return {
    title: 'Deprecated endpoint',
    description: descriptionParts.join(' '),
  }
}

function MethodDeprecatedWarning({ method }: { method: ApiExplorerMethod }) {
  if (!method.deprecated) return null

  const { title, description } = getDeprecatedWarningCopy(method)

  return (
    <div className="shrink-0 border-b border-border bg-amber-500/5 px-4 py-3">
      <Alert variant="default" className="border-amber-500/30 bg-transparent">
        <AlertCircle className="h-4 w-4 text-amber-500" />
        <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
          {title}
        </AlertTitle>
        <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
          {description}
        </AlertDescription>
      </Alert>
    </div>
  )
}

function MethodRequestHeader({
  method,
  endpoint,
  platform,
  projectId,
  serviceId,
}: {
  method: ApiExplorerMethod
  endpoint: string
  platform: ApiExplorerProjectPlatform
  projectId: string
  serviceId: string
}) {
  return (
    <div className={cn(COLUMN_HEADER_CLASS, 'items-center gap-2.5')}>
      <Badge
        variant={getHttpMethodVariant(method.httpMethod)}
        className="shrink-0 text-[10px] uppercase"
      >
        {method.httpMethod}
      </Badge>
      <p className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
        {method.summary}
      </p>
      <ExplorerMethodActions
        method={method}
        endpoint={endpoint}
        platform={platform}
        projectId={projectId}
        serviceId={serviceId}
      />
    </div>
  )
}

function MethodRequestFooter({
  isExecuting,
  onExecute,
  onCopyCurl,
  onResetRequestForm,
}: {
  isExecuting: boolean
  onExecute: () => void
  onCopyCurl: () => void
  onResetRequestForm: () => void
}) {
  const { isMac } = usePlatform()
  const sendShortcut = formatDisplayKeys(
    API_EXPLORER_SEND_REQUEST_SHORTCUT_RAW,
    isMac,
  ).join('')
  const canSend = !isExecuting
  const sendTooltip = canSend
    ? `Send request (${sendShortcut})`
    : 'Request is running.'

  return (
    <div className={COLUMN_REQUEST_FOOTER_CLASS}>
      <div className="flex w-full items-center justify-between gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          disabled={isExecuting}
          onClick={onResetRequestForm}
        >
          Reset
        </Button>
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isExecuting}
            onClick={onCopyCurl}
          >
            Copy as cURL
          </Button>
          <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex">
              <Button
                variant="brandCta"
                size="sm"
                className="h-9 min-w-[120px] text-[13px] font-medium"
                disabled={!canSend}
                onClick={onExecute}
              >
                Send request
              </Button>
            </span>
          </TooltipTrigger>
          <TooltipContent side="top" sideOffset={6} className="text-[12px]">
            {sendTooltip}
          </TooltipContent>
        </Tooltip>
        </div>
      </div>
    </div>
  )
}

function MethodDetailsCard({
  endpoint,
  method,
}: {
  endpoint: string
  method: ApiExplorerMethod
}) {
  const [copied, setCopied] = useState(false)
  const fullUrl = `${endpoint.replace(/\/$/, '')}${method.path}`
  const rateLimit = method.xAppwrite?.['rate-limit']
  const hasMetadata = rateLimit !== undefined && rateLimit > 0

  const handleCopyEndpoint = async () => {
    try {
      await navigator.clipboard.writeText(fullUrl)
      setCopied(true)
      toast.success('Endpoint copied')
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error('Failed to copy endpoint')
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="space-y-4 px-6 py-4">
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-3">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Endpoint
            </p>
            <button
              type="button"
              onClick={handleCopyEndpoint}
              className="inline-flex items-center gap-1.5 text-[12px] text-muted-foreground transition-colors hover:text-foreground"
            >
              {copied ? (
                <Check className="h-3.5 w-3.5" />
              ) : (
                <Copy className="h-3.5 w-3.5" />
              )}
              Copy
            </button>
          </div>
          <div className="overflow-hidden rounded-lg border border-border bg-muted/30 px-3 py-2.5">
            <p className="flex min-w-0 items-center gap-2 font-mono text-[12px] leading-relaxed text-foreground">
              <span
                className={cn(
                  'shrink-0 font-semibold uppercase',
                  method.httpMethod === 'get' && 'text-blue-600 dark:text-blue-400',
                  method.httpMethod === 'post' &&
                    'text-emerald-600 dark:text-emerald-400',
                  (method.httpMethod === 'put' ||
                    method.httpMethod === 'patch') &&
                    'text-amber-600 dark:text-amber-400',
                  method.httpMethod === 'delete' &&
                    'text-red-600 dark:text-red-400',
                )}
              >
                {method.httpMethod}
              </span>
              <span className="min-w-0 flex-1 truncate" title={fullUrl}>
                {truncateMiddle(fullUrl, ENDPOINT_URL_DISPLAY_MAX)}
              </span>
            </p>
          </div>
        </div>

        {method.description && (
          <div className="space-y-2">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Description
            </p>
            <MethodDescriptionMarkdown content={method.description} />
          </div>
        )}
      </div>

      {hasMetadata && (
        <>
          <div className="border-t border-border" />
          <div className="grid gap-4 px-6 py-4 sm:grid-cols-2">
            {rateLimit !== undefined && rateLimit > 0 && (
              <div className="space-y-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Rate limit
                </p>
                <RateLimitDescription
                  limit={rateLimit}
                  windowSeconds={method.xAppwrite?.['rate-time'] ?? 3600}
                  rateKey={method.xAppwrite?.['rate-key']}
                />
              </div>
            )}

          </div>
        </>
      )}
    </div>
  )
}

function RequestPanel({
  endpoint,
  projectId,
  platform,
  method,
  clientAuth,
  serverAuth,
  pathFormValues,
  queryFormValues,
  bodyFormFields,
  bodyFormValues,
  bodyJsonValue,
  bodyInputMode,
  pathParameters,
  queryParameters,
  hasRequestBody,
  hasJsonBodySchema,
  isExecuting,
  showResponsePanel,
  response,
  onPathFormValuesChange,
  onQueryFormValuesChange,
  onBodyFormValuesChange,
  onBodyJsonValueChange,
  onBodyInputModeChange,
  onClientAuthChange,
  onServerAuthChange,
  onExecute,
  onCopyCurl,
  onResetRequestForm,
}: RequestPanelProps) {
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout: responseSplitLayout, persistLayout: persistResponseSplitLayout } =
    useApiExplorerResponseSplitLayout(consoleAccount)

  if (!method) {
    return (
      <div className="flex h-full min-h-0 flex-col">
        <div className={COLUMN_HEADER_CLASS} aria-hidden />
        <div className="flex flex-1 items-center justify-center text-[13px] text-muted-foreground">
          Select a method to inspect and send a request.
        </div>
      </div>
    )
  }

  const requestPanelContent = (
    <RequestPanelContent
      endpoint={endpoint}
      projectId={projectId}
      platform={platform}
      method={method}
      clientAuth={clientAuth}
      serverAuth={serverAuth}
      pathFormValues={pathFormValues}
      queryFormValues={queryFormValues}
      bodyFormFields={bodyFormFields}
      bodyFormValues={bodyFormValues}
      bodyJsonValue={bodyJsonValue}
      bodyInputMode={bodyInputMode}
      pathParameters={pathParameters}
      queryParameters={queryParameters}
      hasRequestBody={hasRequestBody}
      hasJsonBodySchema={hasJsonBodySchema}
      onPathFormValuesChange={onPathFormValuesChange}
      onQueryFormValuesChange={onQueryFormValuesChange}
      onBodyFormValuesChange={onBodyFormValuesChange}
      onBodyJsonValueChange={onBodyJsonValueChange}
      onBodyInputModeChange={onBodyInputModeChange}
      onClientAuthChange={onClientAuthChange}
      onServerAuthChange={onServerAuthChange}
    />
  )

  return (
    <div className="flex h-full min-h-0 flex-col">
      <MethodRequestHeader
        method={method}
        endpoint={endpoint}
        platform={platform}
        projectId={projectId}
        serviceId={method.service}
      />
      <MethodDeprecatedWarning method={method} />
      <div className="min-h-0 flex-1 overflow-hidden">
        {showResponsePanel ? (
          <ExplorerResponseSplitResizableLayout
            layout={responseSplitLayout}
            persistLayout={persistResponseSplitLayout}
            handleClassName={VERTICAL_HANDLE_CLASS}
            className="h-full min-h-0 overflow-hidden"
            request={
              <ScrollArea className="h-full min-h-0">
                {requestPanelContent}
              </ScrollArea>
            }
            response={
              response ? (
                <ResponseSection
                  response={response}
                  isRefreshing={isExecuting}
                />
              ) : (
                <div className="flex h-full min-h-0 items-center justify-center px-4 text-center text-[13px] text-muted-foreground/70">
                  Send a request to see the response here.
                </div>
              )
            }
          />
        ) : (
          <ScrollArea className="h-full min-h-0">{requestPanelContent}</ScrollArea>
        )}
      </div>
      <MethodRequestFooter
        isExecuting={isExecuting}
        onExecute={onExecute}
        onCopyCurl={onCopyCurl}
        onResetRequestForm={onResetRequestForm}
      />
    </div>
  )
}

type RequestPanelContentProps = Pick<
  RequestPanelProps,
  | 'endpoint'
  | 'projectId'
  | 'platform'
  | 'clientAuth'
  | 'serverAuth'
  | 'pathFormValues'
  | 'queryFormValues'
  | 'bodyFormFields'
  | 'bodyFormValues'
  | 'bodyJsonValue'
  | 'bodyInputMode'
  | 'pathParameters'
  | 'queryParameters'
  | 'hasRequestBody'
  | 'hasJsonBodySchema'
  | 'onPathFormValuesChange'
  | 'onQueryFormValuesChange'
  | 'onBodyFormValuesChange'
  | 'onBodyJsonValueChange'
  | 'onBodyInputModeChange'
  | 'onClientAuthChange'
  | 'onServerAuthChange'
> & {
  method: ApiExplorerMethod
}

function RequestPanelContent({
  endpoint,
  projectId,
  platform,
  method,
  clientAuth,
  serverAuth,
  pathFormValues,
  queryFormValues,
  bodyFormFields,
  bodyFormValues,
  bodyJsonValue,
  bodyInputMode,
  pathParameters,
  queryParameters,
  hasRequestBody,
  hasJsonBodySchema,
  onPathFormValuesChange,
  onQueryFormValuesChange,
  onBodyFormValuesChange,
  onBodyJsonValueChange,
  onBodyInputModeChange,
  onClientAuthChange,
  onServerAuthChange,
}: RequestPanelContentProps) {
  const pathFields = useMemo(
    () =>
      (pathParameters ?? []).map((param) => parameterToFormField(param, method)),
    [pathParameters, method],
  )
  const queryFields = useMemo(
    () =>
      (queryParameters ?? []).map((param) => parameterToFormField(param, method)),
    [queryParameters, method],
  )
  const allFormValues = useMemo(
    () => ({
      ...pathFormValues,
      ...queryFormValues,
      ...bodyFormValues,
    }),
    [pathFormValues, queryFormValues, bodyFormValues],
  )

  const hasPathParams = pathFields.length > 0
  const hasQueryParams = queryFields.length > 0
  const hasRequestSections = hasPathParams || hasQueryParams || hasRequestBody

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <MethodDetailsCard endpoint={endpoint} method={method} />

      <ApiExplorerAuthSection
        projectId={projectId}
        platform={platform}
        method={method}
        clientAuth={clientAuth}
        serverAuth={serverAuth}
        onClientAuthChange={onClientAuthChange}
        onServerAuthChange={onServerAuthChange}
      />

      {hasRequestSections && (
        <RequestBuilderPanel>
          {hasPathParams && (
            <RequestBuilderSection title="Path">
              <RequestFormFields
                fields={pathFields}
                values={pathFormValues}
                onChange={(name, value) =>
                  onPathFormValuesChange({ ...pathFormValues, [name]: value })
                }
                idPrefix="path"
                projectId={projectId}
                formValues={allFormValues}
                method={method}
              />
            </RequestBuilderSection>
          )}

          {hasQueryParams && (
            <RequestBuilderSection
              title="Query parameters"
              showTopBorder={hasPathParams}
            >
              <RequestFormFields
                fields={queryFields}
                values={queryFormValues}
                onChange={(name, value) =>
                  onQueryFormValuesChange({ ...queryFormValues, [name]: value })
                }
                idPrefix="query"
                projectId={projectId}
                formValues={allFormValues}
                method={method}
              />
            </RequestBuilderSection>
          )}

          {hasRequestBody && (
            <RequestBodySection
              title={hasPathParams || hasQueryParams ? 'Body' : 'Parameters'}
              fields={bodyFormFields}
              formValues={bodyFormValues}
              jsonValue={bodyJsonValue}
              inputMode={bodyInputMode}
              onFormValuesChange={onBodyFormValuesChange}
              onJsonValueChange={onBodyJsonValueChange}
              onInputModeChange={onBodyInputModeChange}
              showJsonToggle={hasJsonBodySchema}
              embedded
              showTopBorder={hasPathParams || hasQueryParams}
              projectId={projectId}
              allFormValues={allFormValues}
              method={method}
            />
          )}
        </RequestBuilderPanel>
      )}
    </div>
  )
}

type ResponseSectionProps = {
  response: ExecuteApiRequestResult
  isRefreshing?: boolean
}

function formatResponseDisplay(body: string): {
  code: string
  language: CodeBlockLanguage
} {
  const trimmed = body?.trim()
  if (!trimmed) {
    return { code: '(empty response)', language: 'plaintext' }
  }

  try {
    return {
      code: JSON.stringify(JSON.parse(trimmed), null, 2),
      language: 'json',
    }
  } catch {
    return { code: body, language: 'plaintext' }
  }
}

function ResponseHeadersPanel({ headers }: { headers: Record<string, string> }) {
  const entries = useMemo(
    () =>
      Object.entries(headers).sort(([a], [b]) =>
        a.localeCompare(b, undefined, { sensitivity: 'base' }),
      ),
    [headers],
  )

  const copyText = useMemo(
    () => entries.map(([name, value]) => `${name}: ${value}`).join('\n'),
    [entries],
  )

  const handleCopy = useCallback(async () => {
    await navigator.clipboard.writeText(copyText)
    toast.success('Copied headers')
  }, [copyText])

  if (entries.length === 0) {
    return (
      <div className="flex h-full items-center justify-center p-4 text-[13px] text-muted-foreground">
        No response headers
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden bg-background">
      <div className="flex shrink-0 items-center justify-end border-b border-border px-3 py-1.5">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 gap-1.5 px-2 text-[12px]"
          onClick={handleCopy}
        >
          <Copy className="h-3.5 w-3.5" />
          Copy
        </Button>
      </div>
      <ScrollArea className="min-h-0 flex-1">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="w-[200px] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                Key
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                Value
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {entries.map(([name, value]) => (
              <TableRow key={name}>
                <TableCell className="px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
                  {name}
                </TableCell>
                <TableCell className="min-w-0 px-4 py-3 align-top font-mono text-[13px] break-all whitespace-normal">
                  {value}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ScrollArea>
    </div>
  )
}

function ResponseSizeFooter({ byteSize }: { byteSize: number }) {
  return (
    <div className="shrink-0 border-t-2 border-border bg-muted/30 px-3 py-2 text-[12px] text-muted-foreground">
      {formatBytes(byteSize)}
    </div>
  )
}

function ResponseSection({ response, isRefreshing = false }: ResponseSectionProps) {
  const imagePreviewUrl = response.imagePreviewUrl
  const bodyTabLabel = imagePreviewUrl ? 'Preview' : 'Body'

  const { code, language } = useMemo(
    () => formatResponseDisplay(response.body),
    [response.body],
  )

  const statusVariant = response.ok
    ? 'success'
    : response.status >= 500
      ? 'error'
      : 'warning'

  return (
    <Tabs
      key={`${response.status}:${response.durationMs}:${response.responseByteSize}`}
      defaultValue="body"
      className="flex h-full min-h-0 flex-col gap-0"
    >
      <div className="flex h-10 shrink-0 items-center justify-between gap-2 border-b border-border bg-muted/30 px-3">
        <div className="flex min-w-0 items-center gap-2">
          <span className="text-[12px] font-semibold text-foreground">Response</span>
          <Badge variant={statusVariant} className="text-[10px] shrink-0">
            {response.status} {response.statusText}
          </Badge>
          <Badge variant="secondary" className="text-[10px] shrink-0">
            {response.durationMs} ms
          </Badge>
          {response.responseContentType ? (
            <Badge variant="secondary" className="text-[10px] shrink-0">
              {response.responseContentType}
            </Badge>
          ) : null}
          {isRefreshing ? (
            <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground" />
          ) : null}
        </div>
        <TabsList className="h-7 shrink-0">
          <TabsTrigger value="body" className="h-6 px-2.5 text-[11px]">
            {bodyTabLabel}
          </TabsTrigger>
          <TabsTrigger value="headers" className="h-6 px-2.5 text-[11px]">
            Headers
          </TabsTrigger>
        </TabsList>
      </div>
      <TabsContent
        value="body"
        className="mt-0 flex min-h-0 flex-1 flex-col overflow-hidden"
      >
        {imagePreviewUrl ? (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden border-b-2 border-border">
            <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-muted/20 p-4">
              <img
                src={imagePreviewUrl}
                alt="Response preview"
                className="max-h-full max-w-full rounded-lg border border-border object-contain"
              />
            </div>
            <ResponseSizeFooter byteSize={response.responseByteSize} />
          </div>
        ) : (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden border-b-2 border-border">
            <div className="min-h-0 flex-1 overflow-hidden">
              <CodeBlock
                code={code}
                language={language}
                variant="headless"
                copyInside
                showCopy
                showFullscreen
                wrapLines
                fixedHeight="100%"
                className="flex h-full min-h-0 flex-col"
              />
            </div>
            <ResponseSizeFooter byteSize={response.responseByteSize} />
          </div>
        )}
      </TabsContent>
      <TabsContent
        value="headers"
        className="mt-0 flex min-h-0 flex-1 flex-col overflow-hidden"
      >
        <ResponseHeadersPanel headers={response.headers} />
      </TabsContent>
    </Tabs>
  )
}
