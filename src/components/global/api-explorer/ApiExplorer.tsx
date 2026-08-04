import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Check, Copy, Download, ChevronDown, Loader2, AlertCircle, Search, X } from 'lucide-react'
import { toast } from 'sonner'
import { cn, truncateMiddle } from '@/lib/utils'
import { StartTruncatedText } from '@/components/global/shared/StartTruncatedText'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { formatDisplayKeys } from '@/lib/keyboard-shortcuts/display'
import { Badge } from '@/components/ui/badge'
import {
  getHttpMethodAccentClasses,
  getHttpMethodBadgeVariant as getHttpMethodVariant,
} from '@/lib/http-method-badge'
import { getHttpStatusCodeBadgeVariant } from '@/lib/http-status-code'
import {
  API_EXPLORER_PILL_CLASS,
  FORM_FIELD_TYPE_PILL_CLASS,
} from '@/lib/api-explorer/form-field-type-badge'
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
import { ApiExplorerMobileNav } from './ApiExplorerMobileNav'
import {
  API_EXPLORER_CONTAINER,
  API_EXPLORER_DESKTOP_ONLY_CLASS,
  API_EXPLORER_MOBILE_ONLY_CLASS,
} from './explorer-styles'
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
  methodRequiresApiKey,
  methodSupportsServerApiKey,
  methodUsesSessionAuthChoice,
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
  type RequestFormField,
} from '@/lib/api-explorer'
import {
  apiNavItemClassName,
  apiNavMethodItemClassName,
} from '@/lib/api-explorer/nav-styles'
import { API_EXPLORER_SEND_REQUEST_SHORTCUT_RAW } from '@/lib/api-explorer/shortcuts'
import { useApiExplorerShortcuts } from '@/lib/api-explorer/use-api-explorer-shortcuts'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  useApiExplorerColumnsLayout,
  useApiExplorerExpandedProductGroup,
  useApiExplorerResponseSplitLayout,
  useApiExplorerSpec,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks'
import { verticalPanelResizeHandleClass } from '@/lib/layout/horizontal-resize'

const HANDLE_CLASS = verticalPanelResizeHandleClass('z-[45]')

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
  'flex min-h-[62px] shrink-0 items-center justify-end border-t border-border bg-muted/30 px-4 py-2'

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
  const t = useT()
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
      aria-label={t('API platform')}
    >
      <ToggleGroupItem
        value="client"
        className="h-9 px-3 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
      >
        {t('Client API')}
      </ToggleGroupItem>
      <ToggleGroupItem
        value="server"
        className="h-9 px-3 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
      >
        {t('Server API')}
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
  const t = useT()
  const [isDownloading, setIsDownloading] = useState(false)

  const handleDownload = useCallback(
    async (platform: ApiExplorerProjectPlatform) => {
      setIsDownloading(true)
      try {
        await downloadOpenApiSpec(platform)
      } catch {
        toast.error(t('Failed to download OpenAPI spec'))
      } finally {
        setIsDownloading(false)
      }
    },
    [t],
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
            <Loader2 className="me-1.5 h-4 w-4 animate-spin" />
          ) : (
            <Download className="me-1.5 h-4 w-4" />
          )}
          {t('OpenAPI spec')}
          <ChevronDown className="ms-1.5 h-3.5 w-3.5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {OPENAPI_SPEC_OPTIONS.map((option) => (
          <DropdownMenuItem
            key={option.value}
            disabled={isDownloading}
            onClick={() => handleDownload(option.value)}
          >
            {t(option.label)}
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
  const t = useT()
  const [isDownloading, setIsDownloading] = useState(false)

  const handleDownload = useCallback(async () => {
    setIsDownloading(true)
    try {
      await downloadOpenApiSpec(platform)
    } catch {
      toast.error(t('Failed to download OpenAPI spec'))
    } finally {
      setIsDownloading(false)
    }
  }, [platform, t])

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
          <Loader2 className="me-1.5 size-4 animate-spin" />
        ) : (
          <Download className="me-1.5 size-4" />
        )}
        {t('OpenAPI spec')}
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
  const t = useT()
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout: columnsLayout, persistLayout: persistColumnsLayout } =
    useApiExplorerColumnsLayout(consoleAccount)
  const {
    expandedProductGroupId,
    setExpandedProductGroup,
  } = useApiExplorerExpandedProductGroup(consoleAccount)
  const initialPlatform: ApiExplorerProjectPlatform = config.platform ?? 'server'
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
  const [mobilePane, setMobilePane] = useState<'request' | 'response'>('request')
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
  const {
    data: parsedSpec,
    isLoading: specLoading,
    error: specQueryError,
  } = useApiExplorerSpec(activePlatform)
  const specError = specQueryError
    ? getErrorMessage(specQueryError) || t('Failed to load API specification')
    : null
  const selectedOperationRef = useRef<string | undefined>(initialOperationId)
  const { features } = useConsoleProfile()

  const allowedServices = useMemo(
    () =>
      config.allowedServices ?? getProjectApiExplorerAllowedServices(features),
    [
      config.allowedServices,
      features.dedicatedDbsDocumentsDB,
      features.dedicatedDbsVectorsDB,
      features.nativeDbsPostgres,
      features.nativeDbsMySQL,
      features.nativeDbsMongo,
    ],
  )

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
        setShowResponsePanel(false)
        setMobilePane('request')
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
      setShowResponsePanel(false)
      setMobilePane('request')
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
          toast.error(`${t('Missing required field:')} ${missingField.label}`)
          return
        }
        formData = buildMultipartFormData(bodyFormFields, bodyFormValues)
      } else if (bodyInputMode === 'json') {
        const missingField = getMissingRequiredFieldInJsonBody(
          bodyFormFields,
          bodyJsonValue,
        )
        if (missingField) {
          toast.error(`${t('Missing required field:')} ${missingField.label}`)
          return
        }
        if (bodyJsonValue.trim()) {
          try {
            body = stripEmptyCreatableIdFieldsFromJson(
              bodyFormFields,
              bodyJsonValue,
            )
          } catch {
            toast.error(t('Invalid JSON in request body.'))
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
          toast.error(`${t('Missing required field:')} ${missingField.label}`)
          return
        }
        try {
          body = serializeBodyFromForm(bodyFormFields, bodyFormValues)
        } catch (error: unknown) {
          toast.error(getErrorMessage(error) || t('Invalid request body'))
          return
        }
      }
    }

    setIsExecuting(true)
    setShowResponsePanel(true)
    setMobilePane('response')

    try {
      let requestAuth:
        | { mode: ApiExplorerClientAuthState['mode']; jwt?: string }
        | undefined
      let apiKey: string | undefined

      if (methodUsesSessionAuthChoice(selectedMethod, activePlatform)) {
        if (clientAuth.mode === 'user') {
          if (!clientAuth.userId.trim()) {
            toast.error(t('Select a user to act as, or choose Guest.'))
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
      } else if (methodSupportsServerApiKey(selectedMethod, activePlatform)) {
        apiKey = resolveServerAuthApiKey(serverAuth)
        if (methodRequiresApiKey(selectedMethod, activePlatform) && !apiKey) {
          toast.error(t('Provide an API key or generate an ephemeral key.'))
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
      toast.error(getErrorMessage(error) || t('Request failed'))
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
    t,
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
          toast.error(`${t('Missing required field:')} ${missingField.label}`)
          return
        }
        formData = buildMultipartFormData(bodyFormFields, bodyFormValues)
      } else if (bodyInputMode === 'json') {
        const missingField = getMissingRequiredFieldInJsonBody(
          bodyFormFields,
          bodyJsonValue,
        )
        if (missingField) {
          toast.error(`${t('Missing required field:')} ${missingField.label}`)
          return
        }
        if (bodyJsonValue.trim()) {
          try {
            body = stripEmptyCreatableIdFieldsFromJson(
              bodyFormFields,
              bodyJsonValue,
            )
          } catch {
            toast.error(t('Invalid JSON in request body.'))
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
          toast.error(`${t('Missing required field:')} ${missingField.label}`)
          return
        }
        try {
          body = serializeBodyFromForm(bodyFormFields, bodyFormValues)
        } catch (error: unknown) {
          toast.error(getErrorMessage(error) || t('Invalid request body'))
          return
        }
      }
    }

    try {
      let requestAuth:
        | { mode: ApiExplorerClientAuthState['mode']; jwt?: string }
        | undefined
      let apiKey: string | undefined

      if (methodUsesSessionAuthChoice(selectedMethod, activePlatform)) {
        if (clientAuth.mode === 'user') {
          if (!clientAuth.userId.trim()) {
            toast.error(t('Select a user to act as, or choose Guest.'))
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
      } else if (methodSupportsServerApiKey(selectedMethod, activePlatform)) {
        apiKey = resolveServerAuthApiKey(serverAuth)
        if (methodRequiresApiKey(selectedMethod, activePlatform) && !apiKey) {
          toast.error(t('Provide an API key or generate an ephemeral key.'))
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
        toast.success(t('cURL copied'))
      }
    } catch (error: unknown) {
      toast.error(getErrorMessage(error) || t('Failed to copy cURL'))
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
    t,
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
    setShowResponsePanel(false)
    setMobilePane('request')
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

  if (specLoading && !parsedSpec) {
    return (
      <div
        className={cn(
          'flex h-full min-h-0 flex-1 items-center justify-center text-[13px] text-muted-foreground',
          className,
        )}
      >
        <Loader2 className="me-2 h-4 w-4 animate-spin" />
        {t('Loading API specification…')}
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
        {specError ?? t('API specification unavailable.')}
      </div>
    )
  }

  const renderRequestPanel = () => (
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
      mobilePane={mobilePane}
      onMobilePaneChange={setMobilePane}
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
  )

  return (
    <div
      data-api-explorer
      className={cn(
        API_EXPLORER_CONTAINER,
        'flex h-full min-h-0 flex-1 flex-col',
        className,
      )}
    >
      <ApiExplorerMobileNav
        className={API_EXPLORER_MOBILE_ONLY_CLASS}
        selectedService={selectedService}
        selectedMethod={selectedMethod}
        servicesContent={(close) => (
          <ServiceListPanel
            platform={activePlatform}
            onPlatformChange={handlePlatformChange}
            productGroups={serviceProductGroups}
            selectedServiceId={selectedService?.id}
            onSelectService={(service) => {
              handleSelectService(service)
              close()
            }}
            expandedProductGroupId={openProductGroupId}
            onExpandedProductGroupChange={setExpandedProductGroup}
            embedded
          />
        )}
        methodsContent={(close) => (
          <MethodListPanel
            service={selectedService}
            selectedMethodId={selectedMethod?.id}
            onSelectMethod={(method) => {
              handleSelectMethod(method)
              close()
            }}
            embedded
          />
        )}
      />

      <div className={cn('min-h-0 flex-1', API_EXPLORER_MOBILE_ONLY_CLASS)}>
        {renderRequestPanel()}
      </div>

      <div className={cn('min-h-0 flex-1', API_EXPLORER_DESKTOP_ONLY_CLASS)}>
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
          request={renderRequestPanel()}
        />
      </div>
      {sendRequestConfirmation ? (
        <ConfirmActionDialog
          open={sendConfirmOpen}
          onOpenChange={setSendConfirmOpen}
          title={t(sendRequestConfirmation.title)}
          description={sendRequestConfirmation.description}
          confirmLabel={t('Send request')}
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
  /** Omit side border when rendered inside a mobile sheet. */
  embedded?: boolean
}

function ServiceListPanel({
  platform,
  onPlatformChange,
  productGroups,
  selectedServiceId,
  onSelectService,
  expandedProductGroupId,
  onExpandedProductGroupChange,
  embedded = false,
}: ServiceListPanelProps) {
  const t = useT()
  const hasServices = productGroups.some((group) => group.services.length > 0)

  return (
    <div
      className={cn(
        'flex h-full min-h-0 min-w-0 flex-col overflow-hidden bg-background',
        !embedded && 'border-e border-border',
      )}
    >
      <div className="min-h-0 flex-1 overflow-y-auto px-3 py-4">
        <div className="space-y-4">
          <div className="px-1">
            <ApiExplorerPlatformToggle
              value={platform}
              onChange={onPlatformChange}
              className="w-full [&>button]:flex-1"
            />
          </div>

          <nav aria-label={t('API services')}>
            <p className="mb-1.5 px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('APIs')}
            </p>
            {!hasServices ? (
              <p className="px-2 py-2 text-[13px] text-muted-foreground">
                {t('No services available for this API.')}
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
                      <span className="min-w-0 flex-1 truncate text-start">
                        {t(group.label)}
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
                                  'w-full text-start',
                                  apiNavItemClassName(isActive),
                                )}
                              >
                                <span className="truncate">{t(service.label)}</span>
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
  /** Omit side border / column header chrome when rendered inside a mobile sheet. */
  embedded?: boolean
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
  embedded = false,
}: MethodListPanelProps) {
  const t = useT()
  const selectedMethodRef = useRef<HTMLButtonElement | null>(null)
  const [searchValue, setSearchValue] = useState('')
  const [expandedGroupIds, setExpandedGroupIds] = useState<string[]>([])

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

  const collapsibleGroupIds = useMemo(
    () =>
      filteredGroups
        .filter((group) => group.label)
        .map((group) => group.id || '__ungrouped__'),
    [filteredGroups],
  )

  const collapsibleGroupIdsKey = collapsibleGroupIds.join('\0')

  // Expand all groups only when the service or search filter changes - not when
  // selecting a method (parent re-renders must not reset manual collapse state).
  useEffect(() => {
    setExpandedGroupIds(collapsibleGroupIds)
  }, [service?.id, searchValue, collapsibleGroupIdsKey])

  useEffect(() => {
    selectedMethodRef.current?.scrollIntoView({
      block: 'nearest',
      inline: 'nearest',
    })
  }, [service?.id, selectedMethodId, filteredGroups])

  const renderMethodList = (methods: ApiExplorerMethod[]) => (
    <ul className="space-y-0.5">
      {methods.map((method) => {
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
                  className={cn(
                    'text-[10px] uppercase',
                    API_EXPLORER_PILL_CLASS,
                  )}
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
  )

  return (
    <div
      className={cn(
        'flex h-full min-h-0 min-w-0 flex-col overflow-hidden bg-muted/20',
        !embedded && 'border-e border-border',
      )}
    >
      {!embedded ? (
        <div
          className={cn(
            COLUMN_HEADER_CLASS,
            'min-w-0 items-center overflow-hidden',
          )}
        >
          {service ? (
            <p className="truncate text-[13px] font-medium text-foreground">
              {t(service.label)}
            </p>
          ) : (
            <span className="block h-[13px]" aria-hidden />
          )}
        </div>
      ) : null}

      <div className="shrink-0 border-b border-border bg-muted/20 px-2 py-2">
        <div className="relative">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchValue}
            onChange={(event) => setSearchValue(event.target.value)}
            placeholder={t('Search methods...')}
            className="h-8 border-border/60 bg-background ps-8 pe-8 text-[13px]"
            aria-label={t('Search methods')}
            disabled={!service}
          />
          {searchValue ? (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="absolute end-0.5 top-1/2 h-7 w-7 -translate-y-1/2 text-muted-foreground"
              aria-label={t('Clear method search')}
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
              {t('No methods available.')}
            </p>
          ) : filteredGroups.length === 0 ? (
            <p className="px-2 py-4 text-[13px] text-muted-foreground">
              {t('No methods match your search.')}
            </p>
          ) : (
            <>
              {filteredGroups
                .filter((group) => !group.label)
                .map((group) => (
                  <div key={group.id || 'default'}>{renderMethodList(group.methods)}</div>
                ))}
              {filteredGroups.some((group) => group.label) ? (
                <Accordion
                  type="multiple"
                  value={expandedGroupIds}
                  onValueChange={setExpandedGroupIds}
                  className="w-full space-y-1"
                >
                  {filteredGroups
                    .filter((group) => group.label)
                    .map((group) => {
                      const groupKey = group.id || '__ungrouped__'
                      return (
                        <AccordionItem
                          key={groupKey}
                          value={groupKey}
                          className="border-b border-border/50 pb-1 last:border-b-0 last:pb-0"
                        >
                          <AccordionTrigger className="gap-1.5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70 hover:no-underline [&>svg]:size-3.5 [&>svg]:text-muted-foreground/70">
                            <span className="min-w-0 flex-1 truncate text-start">
                              {group.label}
                            </span>
                            <span className="shrink-0 text-[10px] font-medium normal-case tracking-normal text-muted-foreground/60">
                              {group.methods.length}
                            </span>
                          </AccordionTrigger>
                          <AccordionContent className="pb-2 pt-0">
                            {renderMethodList(group.methods)}
                          </AccordionContent>
                        </AccordionItem>
                      )
                    })}
                </Accordion>
              ) : null}
            </>
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
  mobilePane: 'request' | 'response'
  onMobilePaneChange: (pane: 'request' | 'response') => void
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
  const t = useT()
  if (!method.deprecated) return null

  const { title, description } = getDeprecatedWarningCopy(method)

  return (
    <div className="shrink-0 border-b border-border bg-amber-500/5 px-4 py-3">
      <Alert variant="default" className="border-amber-500/30 bg-transparent">
        <AlertCircle className="h-4 w-4 text-amber-500" />
        <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
          {t(title)}
        </AlertTitle>
        <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
          {t(description)}
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
  mobilePane,
  onMobilePaneChange,
}: {
  method: ApiExplorerMethod
  endpoint: string
  platform: ApiExplorerProjectPlatform
  projectId: string
  serviceId: string
  mobilePane?: 'request' | 'response'
  onMobilePaneChange?: (pane: 'request' | 'response') => void
}) {
  const t = useT()
  const showMobilePaneTabs = Boolean(mobilePane && onMobilePaneChange)

  return (
    <div className={cn(COLUMN_HEADER_CLASS, 'items-center gap-2.5')}>
      <div className="hidden min-w-0 flex-1 items-center gap-2.5 @[900px]/api-explorer:flex">
        <Badge
          variant={getHttpMethodVariant(method.httpMethod)}
          className={cn('text-[10px] uppercase', API_EXPLORER_PILL_CLASS)}
        >
          {method.httpMethod}
        </Badge>
        <p className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
          {method.summary}
        </p>
      </div>

      {showMobilePaneTabs ? (
        <div
          className={cn(
            'flex h-full min-w-0 flex-1 items-stretch gap-0',
            API_EXPLORER_MOBILE_ONLY_CLASS,
          )}
          role="tablist"
          aria-label={t('Request and response')}
        >
          {(
            [
              { id: 'request', label: 'Request' },
              { id: 'response', label: 'Response' },
            ] as const
          ).map((pane) => {
            const isActive = mobilePane === pane.id
            return (
              <button
                key={pane.id}
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => onMobilePaneChange?.(pane.id)}
                className={cn(
                  'relative flex h-full items-center px-3 text-[13px] font-medium transition-colors first:ps-0',
                  'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                  isActive
                    ? 'text-foreground'
                    : 'text-muted-foreground hover:text-foreground',
                )}
              >
                {t(pane.label)}
                {isActive ? (
                  <span className="absolute inset-x-0 bottom-0 h-[2px] bg-foreground" />
                ) : null}
              </button>
            )
          })}
        </div>
      ) : (
        <div className={cn('min-w-0 flex-1', API_EXPLORER_MOBILE_ONLY_CLASS)} />
      )}

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
  const t = useT()
  const { isMac } = usePlatform()
  const sendShortcut = formatDisplayKeys(
    API_EXPLORER_SEND_REQUEST_SHORTCUT_RAW,
    isMac,
  ).join('')
  const canSend = !isExecuting
  const sendTooltip = canSend
    ? `${t('Send request')} (${sendShortcut})`
    : t('Request is running.')

  return (
    <div className={COLUMN_REQUEST_FOOTER_CLASS}>
      <div className="flex w-full flex-wrap items-center justify-between gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          disabled={isExecuting}
          onClick={onResetRequestForm}
        >
          {t('Reset')}
        </Button>
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isExecuting}
            onClick={onCopyCurl}
          >
            {t('Copy as cURL')}
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
                {t('Send request')}
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
  const t = useT()
  const [copied, setCopied] = useState(false)
  const fullUrl = `${endpoint.replace(/\/$/, '')}${method.path}`
  const methodAccent = getHttpMethodAccentClasses(method.httpMethod)
  const rateLimit = method.xAppwrite?.['rate-limit']
  const hasMetadata = rateLimit !== undefined && rateLimit > 0

  const handleCopyEndpoint = async () => {
    try {
      await navigator.clipboard.writeText(fullUrl)
      setCopied(true)
      toast.success(t('Endpoint copied'))
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy endpoint'))
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="space-y-4 px-4 py-4 sm:px-6">
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-3">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Endpoint')}
            </p>
            <button
              type="button"
              onClick={handleCopyEndpoint}
              className="inline-flex shrink-0 items-center gap-1.5 text-[12px] text-muted-foreground transition-colors hover:text-foreground"
            >
              {copied ? (
                <Check className="h-3.5 w-3.5" />
              ) : (
                <Copy className="h-3.5 w-3.5" />
              )}
              {t('Copy')}
            </button>
          </div>
          <div
            className={cn(
              'overflow-hidden rounded-lg px-3 py-2.5',
              methodAccent.endpointBox,
            )}
          >
            <p className="flex min-w-0 items-start gap-2.5 font-mono text-[12px] leading-relaxed text-foreground sm:items-center">
              <Badge
                variant={getHttpMethodVariant(method.httpMethod)}
                className={cn(
                  'mt-0.5 shrink-0 font-mono text-[10px] uppercase sm:mt-0',
                  API_EXPLORER_PILL_CLASS,
                )}
              >
                {method.httpMethod}
              </Badge>
              <span
                className="min-w-0 flex-1 break-all sm:truncate sm:break-normal"
                title={fullUrl}
              >
                <span className="sm:hidden">{fullUrl}</span>
                <span className="hidden sm:inline">
                  {truncateMiddle(fullUrl, ENDPOINT_URL_DISPLAY_MAX)}
                </span>
              </span>
            </p>
          </div>
        </div>

        {method.description && (
          <div className="space-y-2">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Description')}
            </p>
            <MethodDescriptionMarkdown content={method.description} />
          </div>
        )}
      </div>

      {hasMetadata && (
        <>
          <div className="border-t border-border" />
          <div className="grid gap-4 px-4 py-4 sm:grid-cols-2 sm:px-6">
            {rateLimit !== undefined && rateLimit > 0 && (
              <div className="space-y-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Rate limit')}
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
  mobilePane,
  onMobilePaneChange,
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
  const t = useT()
  const { account } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { layout: responseSplitLayout, persistLayout: persistResponseSplitLayout } =
    useApiExplorerResponseSplitLayout(consoleAccount)

  if (!method) {
    return (
      <div className="flex h-full min-h-0 flex-col">
        <div
          className={cn(COLUMN_HEADER_CLASS, API_EXPLORER_DESKTOP_ONLY_CLASS)}
          aria-hidden
        />
        <div className="flex flex-1 items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
          {t('Select a method to inspect and send a request.')}
        </div>
      </div>
    )
  }

  const renderRequestPanelContent = () => (
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

  const renderResponseContent = (options?: { hideTitle?: boolean }) =>
    response ? (
      <ResponseSection
        response={response}
        isRefreshing={isExecuting}
        hideTitle={options?.hideTitle}
      />
    ) : (
      <div className="flex h-full min-h-0 items-center justify-center px-4 text-center text-[13px] text-muted-foreground/70">
        {t('Send a request to see the response here.')}
      </div>
    )

  return (
    <div className="flex h-full min-h-0 flex-col">
      <MethodRequestHeader
        method={method}
        endpoint={endpoint}
        platform={platform}
        projectId={projectId}
        serviceId={method.service}
        mobilePane={showResponsePanel ? mobilePane : undefined}
        onMobilePaneChange={
          showResponsePanel ? onMobilePaneChange : undefined
        }
      />
      <MethodDeprecatedWarning method={method} />
      <div className="min-h-0 flex-1 overflow-hidden">
        {showResponsePanel ? (
          <>
            <div
              className={cn(
                'h-full min-h-0',
                API_EXPLORER_MOBILE_ONLY_CLASS,
              )}
            >
              {mobilePane === 'request' ? (
                <ScrollArea className="h-full min-h-0">
                  {renderRequestPanelContent()}
                </ScrollArea>
              ) : (
                renderResponseContent({ hideTitle: true })
              )}
            </div>
            <div
              className={cn(
                'h-full min-h-0',
                API_EXPLORER_DESKTOP_ONLY_CLASS,
              )}
            >
              <ExplorerResponseSplitResizableLayout
                layout={responseSplitLayout}
                persistLayout={persistResponseSplitLayout}
                handleClassName={VERTICAL_HANDLE_CLASS}
                className="h-full min-h-0 overflow-hidden"
                request={
                  <ScrollArea className="h-full min-h-0">
                    {renderRequestPanelContent()}
                  </ScrollArea>
                }
                response={renderResponseContent()}
              />
            </div>
          </>
        ) : (
          <ScrollArea className="h-full min-h-0">
            {renderRequestPanelContent()}
          </ScrollArea>
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
  const t = useT()
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
    <div className="space-y-6 p-4 sm:p-6 @container/request-panel">
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
            <RequestBuilderSection title={t('Path')}>
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
              title={t('Query parameters')}
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
              title={hasPathParams || hasQueryParams ? t('Body') : t('Parameters')}
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
  /** Hide the "Response" label when a parent already shows Request/Response tabs. */
  hideTitle?: boolean
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
  const t = useT()
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
    toast.success(t('Copied headers'))
  }, [copyText, t])

  if (entries.length === 0) {
    return (
      <div className="flex h-full items-center justify-center p-4 text-[13px] text-muted-foreground">
        {t('No response headers')}
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
          {t('Copy')}
        </Button>
      </div>
      <ScrollArea className="min-h-0 flex-1">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="w-[200px] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Key')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Value')}
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

function ResponseSection({
  response,
  isRefreshing = false,
  hideTitle = false,
}: ResponseSectionProps) {
  const t = useT()
  const imagePreviewUrl = response.imagePreviewUrl
  const bodyTabLabel = imagePreviewUrl ? t('Preview') : t('Body')

  const { code, language } = useMemo(
    () => formatResponseDisplay(response.body),
    [response.body],
  )

  const statusVariant = getHttpStatusCodeBadgeVariant(response.status)

  return (
    <Tabs
      key={`${response.status}:${response.durationMs}:${response.responseByteSize}`}
      defaultValue="body"
      className="flex h-full min-h-0 flex-col gap-0"
    >
      <div className="flex min-h-10 shrink-0 items-center justify-between gap-2 border-b border-border bg-muted/30 px-3 py-1.5">
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 overflow-hidden">
          {hideTitle ? null : (
            <span className="shrink-0 text-[12px] font-semibold text-foreground">
              {t('Response')}
            </span>
          )}
          <Badge
            variant={statusVariant}
            className={cn(
              FORM_FIELD_TYPE_PILL_CLASS,
              'min-w-0 max-w-full shrink truncate',
            )}
            title={`${response.status} ${response.statusText}`.trim()}
          >
            {response.status} {response.statusText}
          </Badge>
          <Badge
            variant="inactive"
            className={cn(FORM_FIELD_TYPE_PILL_CLASS, 'shrink-0')}
          >
            {response.durationMs} ms
          </Badge>
          {response.responseContentType ? (
            <Badge
              variant="inactive"
              className={cn(
                FORM_FIELD_TYPE_PILL_CLASS,
                'min-w-0 max-w-[min(100%,14rem)] shrink truncate',
              )}
              title={response.responseContentType}
            >
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
            {t('Headers')}
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
                alt={t('Response preview')}
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
