import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Check, Copy, Loader2, AlertCircle } from 'lucide-react'
import { toast } from 'sonner'
import { cn, truncateMiddle } from '@/lib/utils'
import { StartTruncatedText } from '@/components/global/shared/StartTruncatedText'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { Badge } from '@/components/ui/badge'
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
import { useAuth } from '@/components/global/auth/RequireAuth'
import { CodeBlock, type CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import {
  ExplorerColumnsResizableLayout,
  ExplorerResponseSplitResizableLayout,
} from './ApiExplorerResizableLayout'
import { MethodDescriptionMarkdown } from './MethodDescriptionMarkdown'
import { ApiExplorerAuthSection } from './ApiExplorerAuthSection'
import { RequestBodySection } from './RequestBodySection'
import {
  RequestBuilderPanel,
  RequestBuilderSection,
  RequestFormFields,
} from './RequestFormFields'
import {
  executeApiRequest,
  filterAllowedServices,
  filterServices,
  findMethodByOperationId,
  findServiceForMethod,
  generateSampleRequestBody,
  getProjectApiExplorerAllowedServices,
  getRequestBodyFormFields,
  getRequestBodyJsonSchema,
  groupMethodsByResource,
  groupServicesByProduct,
  isMultipartMethod,
  loadParsedApiSpec,
  buildDefaultBodyFormValues,
  buildInitialParamFormValues,
  paramFormValuesToStrings,
  parameterToFormField,
  serializeBodyFromForm,
  createUserJwtForExplorer,
  getMethodAuthKeys,
  methodRequiresSessionAuthChoice,
  type ApiExplorerConfig,
  type ApiExplorerMethod,
  type ApiExplorerService,
  type ApiExplorerServiceProductGroup,
  type ApiExplorerProjectPlatform,
  type ApiExplorerSessionAuthMode,
  type ExecuteApiRequestResult,
  type FormValue,
  type OpenApiParameter,
  type ParsedApiSpec,
  type RequestFormField,
} from '@/lib/api-explorer'
import { getErrorMessage } from '@/lib/utils/error-formatting'
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

const EXPLORER_SCROLL_AREA_CLASS = 'min-h-0 min-w-0 flex-1 overflow-hidden'

export type ApiExplorerProps = {
  config: ApiExplorerConfig
  initialServiceId?: string
  initialOperationId?: string
  onSelectionChange?: (selection: {
    serviceId: string
    operationId: string
  }) => void
  className?: string
}

function getHttpMethodVariant(
  method: string,
): 'info' | 'success' | 'warning' | 'error' | 'secondary' {
  switch (method.toLowerCase()) {
    case 'get':
      return 'info'
    case 'post':
      return 'success'
    case 'put':
    case 'patch':
      return 'warning'
    case 'delete':
      return 'error'
    default:
      return 'secondary'
  }
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

  setters.setPathFormValues(buildInitialParamFormValues(pathParameters))
  setters.setQueryFormValues(buildInitialParamFormValues(queryParameters))
  setters.setBodyFormValues(buildDefaultBodyFormValues(method))
  setters.setBodyJsonValue(
    generateSampleRequestBody(getRequestBodyJsonSchema(method)),
  )
  setters.setBodyInputMode('form')
}

function hasRequestBodyForMethod(method: ApiExplorerMethod): boolean {
  return Boolean(method.requestBody?.content?.['application/json']?.schema?.properties)
}

export function ApiExplorer({
  config,
  initialServiceId,
  initialOperationId,
  onSelectionChange,
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
  const [searchValue, setSearchValue] = useState('')
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
  const [isExecuting, setIsExecuting] = useState(false)
  const [activePlatform, setActivePlatform] =
    useState<ApiExplorerProjectPlatform>(initialPlatform)
  const [authMode, setAuthMode] = useState<ApiExplorerSessionAuthMode>('guest')
  const [authUserId, setAuthUserId] = useState('')
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

        const visibleServices = filterAllowedServices(
          parsed.services,
          allowedServices,
        )

        const preservedMethod = findMethodByOperationId(
          visibleServices,
          selectedOperationRef.current,
        )
        const initialMethod = findMethodByOperationId(
          visibleServices,
          initialOperationId,
        )
        const initialService =
          visibleServices.find((s) => s.id === initialServiceId) ??
          findServiceForMethod(visibleServices, preservedMethod ?? initialMethod) ??
          visibleServices[0]

        const method =
          preservedMethod ??
          initialMethod ??
          initialService?.methods[0] ??
          visibleServices[0]?.methods[0]

        if (initialService && !preservedMethod && !initialMethod) {
          setSelectedServiceId(initialService.id)
        } else if (method) {
          setSelectedServiceId(method.service)
        }
        if (method) {
          selectedOperationRef.current = method.operationId
          setSelectedMethod(method)
          applyMethodFormState(method, {
            setPathFormValues,
            setQueryFormValues,
            setBodyFormValues,
            setBodyJsonValue,
            setBodyInputMode,
          })
        }
      })
      .catch((error: unknown) => {
        if (cancelled) return
        setSpecError(getErrorMessage(error) || 'Failed to load API specification')
        setSpecLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [activePlatform, allowedServices, initialOperationId, initialServiceId])

  const visibleServices = useMemo(
    () =>
      filterAllowedServices(parsedSpec?.services ?? [], allowedServices),
    [parsedSpec?.services, allowedServices],
  )

  useEffect(() => {
    if (!parsedSpec || specLoading) return

    const currentMethod = findMethodByOperationId(
      visibleServices,
      selectedOperationRef.current,
    )
    if (currentMethod) return

    const fallbackMethod = visibleServices[0]?.methods[0]
    if (!fallbackMethod) return

    selectedOperationRef.current = fallbackMethod.operationId
    setSelectedServiceId(fallbackMethod.service)
    setSelectedMethod(fallbackMethod)
    applyMethodFormState(fallbackMethod, {
      setPathFormValues,
      setQueryFormValues,
      setBodyFormValues,
      setBodyJsonValue,
      setBodyInputMode,
    })
    setResponse(null)
  }, [allowedServices, parsedSpec, specLoading, visibleServices])

  useEffect(() => {
    setAuthMode('guest')
    setAuthUserId('')
  }, [selectedMethod?.id, activePlatform])

  const filteredServices = useMemo(
    () => filterServices(visibleServices, searchValue),
    [visibleServices, searchValue],
  )

  const serviceProductGroups = useMemo(
    () => groupServicesByProduct(filteredServices),
    [filteredServices],
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
    if (!selectedServiceId) return filteredServices[0]
    return (
      filteredServices.find((service) => service.id === selectedServiceId) ??
      filteredServices[0]
    )
  }, [filteredServices, selectedServiceId])

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

  const handleSelectService = useCallback((service: ApiExplorerService) => {
    setSelectedServiceId(service.id)
    expandProductGroupForService(service.id)
    const firstMethod = service.methods[0]
    if (!firstMethod) return
    selectedOperationRef.current = firstMethod.operationId
    setSelectedMethod(firstMethod)
    applyMethodFormState(firstMethod, {
      setPathFormValues,
      setQueryFormValues,
      setBodyFormValues,
      setBodyJsonValue,
      setBodyInputMode,
    })
    setResponse(null)
    onSelectionChange?.({
      serviceId: service.id,
      operationId: firstMethod.operationId,
    })
  }, [expandProductGroupForService, onSelectionChange])

  const handleSelectMethod = useCallback((method: ApiExplorerMethod) => {
    selectedOperationRef.current = method.operationId
    setSelectedMethod(method)
    setSelectedServiceId(method.service)
    expandProductGroupForService(method.service)
    applyMethodFormState(method, {
      setPathFormValues,
      setQueryFormValues,
      setBodyFormValues,
      setBodyJsonValue,
      setBodyInputMode,
    })
    setResponse(null)
    onSelectionChange?.({
      serviceId: method.service,
      operationId: method.operationId,
    })
  }, [expandProductGroupForService, onSelectionChange])

  useEffect(() => {
    if (!parsedSpec || specLoading || !initialOperationId) return
    if (selectedOperationRef.current === initialOperationId) return

    const method = findMethodByOperationId(visibleServices, initialOperationId)
    if (!method) return

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
    setResponse(null)
  }, [
    expandProductGroupForService,
    initialOperationId,
    parsedSpec,
    specLoading,
    visibleServices,
  ])

  const bodyFormFields = useMemo(
    () => (selectedMethod ? getRequestBodyFormFields(selectedMethod) : []),
    [selectedMethod],
  )

  const handleExecute = useCallback(async () => {
    if (!selectedMethod) return
    if (isMultipartMethod(selectedMethod)) {
      toast.error('Multipart requests are not supported in the explorer yet.')
      return
    }

    const pathParameters = selectedMethod.parameters.filter(
      (param) => param.in === 'path',
    )
    const queryParameters = selectedMethod.parameters.filter(
      (param) => param.in === 'query',
    )

    const pathParams = paramFormValuesToStrings(pathParameters, pathFormValues)
    const queryParams = paramFormValuesToStrings(queryParameters, queryFormValues)

    let body = ''
    if (hasRequestBodyForMethod(selectedMethod)) {
      if (bodyInputMode === 'json') {
        body = bodyJsonValue
        if (body.trim()) {
          try {
            JSON.parse(body)
          } catch {
            toast.error('Invalid JSON in request body.')
            return
          }
        }
      } else {
        try {
          body = serializeBodyFromForm(bodyFormFields, bodyFormValues)
        } catch (error: unknown) {
          toast.error(getErrorMessage(error) || 'Invalid request body')
          return
        }
      }
    }

    setIsExecuting(true)
    setResponse(null)

    try {
      let requestAuth:
        | { mode: ApiExplorerSessionAuthMode; jwt?: string }
        | undefined

      if (methodRequiresSessionAuthChoice(selectedMethod, activePlatform)) {
        if (authMode === 'user') {
          if (!authUserId.trim()) {
            toast.error('Select a user to act as, or choose Guest.')
            setIsExecuting(false)
            return
          }
          const jwt = await createUserJwtForExplorer(
            config.projectId,
            authUserId.trim(),
          )
          requestAuth = { mode: 'user', jwt }
        } else {
          requestAuth = { mode: 'guest' }
        }
      }

      const result = await executeApiRequest({
        config: { ...config, platform: activePlatform },
        method: selectedMethod,
        pathParams,
        queryParams,
        body,
        requestAuth,
      })
      setResponse(result)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error) || 'Request failed')
    } finally {
      setIsExecuting(false)
    }
  }, [
    activePlatform,
    authMode,
    authUserId,
    bodyFormFields,
    bodyFormValues,
    bodyInputMode,
    bodyJsonValue,
    config,
    pathFormValues,
    queryFormValues,
    selectedMethod,
  ])

  const pathParameters = selectedMethod?.parameters.filter(
    (param) => param.in === 'path',
  )
  const queryParameters = selectedMethod?.parameters.filter(
    (param) => param.in === 'query',
  )
  const hasRequestBody = selectedMethod
    ? hasRequestBodyForMethod(selectedMethod)
    : false
  const multipart = selectedMethod ? isMultipartMethod(selectedMethod) : false

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
    <div className={cn('flex h-full min-h-0 flex-1 flex-col', className)}>
      <div className="shrink-0 border-b border-border px-4 py-3 sm:px-6">
        <div className="flex flex-wrap items-center gap-3">
          <div className="min-w-0 flex-1">
            <Input
              value={searchValue}
              onChange={(event) => setSearchValue(event.target.value)}
              placeholder="Search services and methods…"
              className="h-9 max-w-md text-[13px]"
            />
          </div>
          <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={activePlatform}
            onValueChange={(value) => {
              if (value !== 'server' && value !== 'client') return
              setActivePlatform(value)
              setResponse(null)
            }}
            className="shrink-0"
            aria-label="API platform"
          >
            <ToggleGroupItem
              value="server"
              className="h-9 px-3 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
            >
              Server API
            </ToggleGroupItem>
            <ToggleGroupItem
              value="client"
              className="h-9 px-3 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
            >
              Client API
            </ToggleGroupItem>
          </ToggleGroup>
        </div>
      </div>

      <div className="min-h-0 flex-1">
        <ExplorerColumnsResizableLayout
          layout={columnsLayout}
          persistLayout={persistColumnsLayout}
          handleClassName={HANDLE_CLASS}
          className="h-full min-h-0 overflow-hidden"
          services={
            <ServiceListPanel
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
              authMode={authMode}
              authUserId={authUserId}
              pathFormValues={pathFormValues}
              queryFormValues={queryFormValues}
              bodyFormFields={bodyFormFields}
              bodyFormValues={bodyFormValues}
              bodyJsonValue={bodyJsonValue}
              bodyInputMode={bodyInputMode}
              pathParameters={pathParameters}
              queryParameters={queryParameters}
              hasRequestBody={hasRequestBody}
              multipart={multipart}
              isExecuting={isExecuting}
              response={response}
              onPathFormValuesChange={setPathFormValues}
              onQueryFormValuesChange={setQueryFormValues}
              onBodyFormValuesChange={setBodyFormValues}
              onBodyJsonValueChange={setBodyJsonValue}
              onBodyInputModeChange={setBodyInputMode}
              onAuthModeChange={setAuthMode}
              onAuthUserIdChange={setAuthUserId}
              onExecute={handleExecute}
            />
          }
        />
      </div>
    </div>
  )
}

type ServiceListPanelProps = {
  productGroups: ApiExplorerServiceProductGroup[]
  selectedServiceId?: string
  onSelectService: (service: ApiExplorerService) => void
  expandedProductGroupId: string
  onExpandedProductGroupChange: (groupId: string | undefined) => void
}

function ServiceListPanel({
  productGroups,
  selectedServiceId,
  onSelectService,
  expandedProductGroupId,
  onExpandedProductGroupChange,
}: ServiceListPanelProps) {
  const hasServices = productGroups.some((group) => group.services.length > 0)

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-r border-border bg-muted/20">
      <div className={cn(COLUMN_HEADER_CLASS, 'items-center')}>
        <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
          Services
        </p>
      </div>
      <ScrollArea className={EXPLORER_SCROLL_AREA_CLASS}>
        <div className="box-border w-full max-w-full min-w-0 p-2">
          {!hasServices ? (
            <p className="px-2 py-4 text-[13px] text-muted-foreground">
              No services match your search.
            </p>
          ) : (
            <Accordion
              type="single"
              collapsible
              value={expandedProductGroupId}
              onValueChange={(value) =>
                onExpandedProductGroupChange(value || undefined)
              }
              className="w-full space-y-2"
            >
              {productGroups.map((group) => (
                <AccordionItem
                  key={group.id}
                  value={group.id}
                  className="border-b border-border/50 pb-1 last:border-b-0 last:pb-0"
                >
                  <AccordionTrigger className="gap-1.5 px-2.5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70 hover:no-underline [&>svg]:size-3.5 [&>svg]:text-muted-foreground/70">
                    <span className="min-w-0 flex-1 truncate text-left">
                      {group.label}
                    </span>
                  </AccordionTrigger>
                  <AccordionContent className="pb-2 pt-0">
                    <div className="ml-2.5 space-y-0.5 border-l border-border/70 pl-2.5">
                      {group.services.map((service) => {
                        const isActive = service.id === selectedServiceId
                        return (
                          <button
                            key={service.id}
                            type="button"
                            onClick={() => onSelectService(service)}
                            className={cn(
                              'flex w-full cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-2 text-left text-[13px] transition-colors',
                              isActive
                                ? 'bg-accent text-foreground'
                                : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                            )}
                          >
                            <span className="truncate font-medium">{service.label}</span>
                            <Badge variant="secondary" className="text-[10px] shrink-0">
                              {service.methods.length}
                            </Badge>
                          </button>
                        )
                      })}
                    </div>
                  </AccordionContent>
                </AccordionItem>
              ))}
            </Accordion>
          )}
        </div>
      </ScrollArea>
    </div>
  )
}

type MethodListPanelProps = {
  service?: ApiExplorerService
  selectedMethodId?: string
  onSelectMethod: (method: ApiExplorerMethod) => void
}

function MethodListPanel({
  service,
  selectedMethodId,
  onSelectMethod,
}: MethodListPanelProps) {
  const methodsViewportRef = useRef<HTMLDivElement>(null)
  const resourceGroups = useMemo(
    () => groupMethodsByResource(service?.methods ?? []),
    [service?.methods],
  )

  useEffect(() => {
    methodsViewportRef.current?.scrollTo({ top: 0 })
  }, [service?.id])

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-r border-border">
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
      <ScrollArea
        className={EXPLORER_SCROLL_AREA_CLASS}
        viewportRef={methodsViewportRef}
      >
        <div className="box-border w-full max-w-full min-w-0 space-y-3 p-2">
          {!service || service.methods.length === 0 ? (
            <p className="px-2 py-4 text-[13px] text-muted-foreground">
              No methods available.
            </p>
          ) : (
            resourceGroups.map((group) => (
              <div key={group.id || 'default'} className="space-y-0.5">
                {group.label && (
                  <p className="px-2.5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70">
                    {group.label}
                  </p>
                )}
                {group.methods.map((method) => {
                  const isActive = method.id === selectedMethodId
                  return (
                    <button
                      key={method.id}
                      type="button"
                      onClick={() => onSelectMethod(method)}
                      className={cn(
                        'flex w-full max-w-full min-w-0 cursor-pointer flex-col gap-1 rounded-md px-2.5 py-2 text-left transition-colors',
                        isActive
                          ? 'bg-accent text-foreground'
                          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                      )}
                    >
                      <div className="flex w-full min-w-0 max-w-full items-center gap-2">
                        <Badge
                          variant={getHttpMethodVariant(method.httpMethod)}
                          className="text-[10px] shrink-0 uppercase"
                        >
                          {method.httpMethod}
                        </Badge>
                        <span className="min-w-0 flex-1 truncate text-[13px] font-medium">
                          {method.summary}
                        </span>
                      </div>
                      <StartTruncatedText
                        text={method.path}
                        className="font-mono text-[11px] text-muted-foreground"
                      />
                    </button>
                  )
                })}
              </div>
            ))
          )}
        </div>
      </ScrollArea>
    </div>
  )
}

type RequestPanelProps = {
  endpoint: string
  projectId: string
  platform: ApiExplorerProjectPlatform
  method?: ApiExplorerMethod
  authMode: ApiExplorerSessionAuthMode
  authUserId: string
  pathFormValues: Record<string, FormValue>
  queryFormValues: Record<string, FormValue>
  bodyFormFields: RequestFormField[]
  bodyFormValues: Record<string, FormValue>
  bodyJsonValue: string
  bodyInputMode: 'form' | 'json'
  pathParameters?: OpenApiParameter[]
  queryParameters?: OpenApiParameter[]
  hasRequestBody: boolean
  multipart: boolean
  isExecuting: boolean
  response: ExecuteApiRequestResult | null
  onPathFormValuesChange: (values: Record<string, FormValue>) => void
  onQueryFormValuesChange: (values: Record<string, FormValue>) => void
  onBodyFormValuesChange: (values: Record<string, FormValue>) => void
  onBodyJsonValueChange: (value: string) => void
  onBodyInputModeChange: (mode: 'form' | 'json') => void
  onAuthModeChange: (mode: ApiExplorerSessionAuthMode) => void
  onAuthUserIdChange: (userId: string) => void
  onExecute: () => void
}

/** Character cap for middle truncation in the request details endpoint row. */
const ENDPOINT_URL_DISPLAY_MAX = 64

function splitMetadataList(value?: string): string[] {
  if (!value?.trim()) return []
  return value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

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
  isExecuting,
  multipart,
  onExecute,
}: {
  method: ApiExplorerMethod
  isExecuting: boolean
  multipart: boolean
  onExecute: () => void
}) {
  return (
    <div className={cn(COLUMN_HEADER_CLASS, 'items-center')}>
      <div className="flex w-full items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-2">
          <p className="truncate text-[13px] font-medium text-foreground">
            {method.summary}
          </p>
        </div>
        <Button
          variant="brandCta"
          size="sm"
          className="h-8 shrink-0 text-[13px] font-medium"
          disabled={isExecuting || multipart}
          onClick={onExecute}
        >
          Send request
        </Button>
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
  const scopes = splitMetadataList(method.scope)
  const authMethods = getMethodAuthKeys(method)
  const rateLimit = method.xAppwrite?.['rate-limit']
  const hasMetadata =
    scopes.length > 0 ||
    authMethods.length > 0 ||
    (rateLimit !== undefined && rateLimit > 0)

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
            {scopes.length > 0 && (
              <div className="space-y-2 sm:col-span-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Required scopes
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {scopes.map((scope) => (
                    <Badge
                      key={scope}
                      variant="info"
                      className="text-[10px] shrink-0 font-mono"
                    >
                      {scope}
                    </Badge>
                  ))}
                </div>
              </div>
            )}

            {authMethods.length > 0 && (
              <div className="space-y-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Required auth
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {authMethods.map((auth) => (
                    <Badge
                      key={auth}
                      variant="inactive"
                      className="text-[10px] shrink-0"
                    >
                      {auth}
                    </Badge>
                  ))}
                </div>
              </div>
            )}

            {rateLimit !== undefined && rateLimit > 0 && (
              <div className="space-y-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Rate limit
                </p>
                <p className="text-[13px] text-foreground">
                  {rateLimit} requests per{' '}
                  {method.xAppwrite?.['rate-time'] ?? 3600}s
                </p>
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
  authMode,
  authUserId,
  pathFormValues,
  queryFormValues,
  bodyFormFields,
  bodyFormValues,
  bodyJsonValue,
  bodyInputMode,
  pathParameters,
  queryParameters,
  hasRequestBody,
  multipart,
  isExecuting,
  response,
  onPathFormValuesChange,
  onQueryFormValuesChange,
  onBodyFormValuesChange,
  onBodyJsonValueChange,
  onBodyInputModeChange,
  onAuthModeChange,
  onAuthUserIdChange,
  onExecute,
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

  return (
    <div className="flex h-full min-h-0 flex-col">
      <MethodRequestHeader
        method={method}
        isExecuting={isExecuting}
        multipart={multipart}
        onExecute={onExecute}
      />
      <MethodDeprecatedWarning method={method} />
      {response ? (
        <ExplorerResponseSplitResizableLayout
          layout={responseSplitLayout}
          persistLayout={persistResponseSplitLayout}
          handleClassName={VERTICAL_HANDLE_CLASS}
          className="min-h-0 flex-1 overflow-hidden"
          request={
            <ScrollArea className="h-full min-h-0">
              <RequestPanelContent
                endpoint={endpoint}
                projectId={projectId}
                platform={platform}
                method={method}
                authMode={authMode}
                authUserId={authUserId}
                pathFormValues={pathFormValues}
                queryFormValues={queryFormValues}
                bodyFormFields={bodyFormFields}
                bodyFormValues={bodyFormValues}
                bodyJsonValue={bodyJsonValue}
                bodyInputMode={bodyInputMode}
                pathParameters={pathParameters}
                queryParameters={queryParameters}
                hasRequestBody={hasRequestBody}
                multipart={multipart}
                onPathFormValuesChange={onPathFormValuesChange}
                onQueryFormValuesChange={onQueryFormValuesChange}
                onBodyFormValuesChange={onBodyFormValuesChange}
                onBodyJsonValueChange={onBodyJsonValueChange}
                onBodyInputModeChange={onBodyInputModeChange}
                onAuthModeChange={onAuthModeChange}
                onAuthUserIdChange={onAuthUserIdChange}
              />
            </ScrollArea>
          }
          response={<ResponseSection response={response} />}
        />
      ) : (
        <ScrollArea className="min-h-0 flex-1">
          <RequestPanelContent
            endpoint={endpoint}
            projectId={projectId}
            platform={platform}
            method={method}
            authMode={authMode}
            authUserId={authUserId}
            pathFormValues={pathFormValues}
            queryFormValues={queryFormValues}
            bodyFormFields={bodyFormFields}
            bodyFormValues={bodyFormValues}
            bodyJsonValue={bodyJsonValue}
            bodyInputMode={bodyInputMode}
            pathParameters={pathParameters}
            queryParameters={queryParameters}
            hasRequestBody={hasRequestBody}
            multipart={multipart}
            onPathFormValuesChange={onPathFormValuesChange}
            onQueryFormValuesChange={onQueryFormValuesChange}
            onBodyFormValuesChange={onBodyFormValuesChange}
            onBodyJsonValueChange={onBodyJsonValueChange}
            onBodyInputModeChange={onBodyInputModeChange}
            onAuthModeChange={onAuthModeChange}
            onAuthUserIdChange={onAuthUserIdChange}
          />
        </ScrollArea>
      )}
    </div>
  )
}

type RequestPanelContentProps = Pick<
  RequestPanelProps,
  | 'endpoint'
  | 'projectId'
  | 'platform'
  | 'authMode'
  | 'authUserId'
  | 'pathFormValues'
  | 'queryFormValues'
  | 'bodyFormFields'
  | 'bodyFormValues'
  | 'bodyJsonValue'
  | 'bodyInputMode'
  | 'pathParameters'
  | 'queryParameters'
  | 'hasRequestBody'
  | 'multipart'
  | 'onPathFormValuesChange'
  | 'onQueryFormValuesChange'
  | 'onBodyFormValuesChange'
  | 'onBodyJsonValueChange'
  | 'onBodyInputModeChange'
  | 'onAuthModeChange'
  | 'onAuthUserIdChange'
> & {
  method: ApiExplorerMethod
}

function RequestPanelContent({
  endpoint,
  projectId,
  platform,
  method,
  authMode,
  authUserId,
  pathFormValues,
  queryFormValues,
  bodyFormFields,
  bodyFormValues,
  bodyJsonValue,
  bodyInputMode,
  pathParameters,
  queryParameters,
  hasRequestBody,
  multipart,
  onPathFormValuesChange,
  onQueryFormValuesChange,
  onBodyFormValuesChange,
  onBodyJsonValueChange,
  onBodyInputModeChange,
  onAuthModeChange,
  onAuthUserIdChange,
}: RequestPanelContentProps) {
  const pathFields = useMemo(
    () => (pathParameters ?? []).map((param) => parameterToFormField(param)),
    [pathParameters],
  )
  const queryFields = useMemo(
    () => (queryParameters ?? []).map((param) => parameterToFormField(param)),
    [queryParameters],
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
        authMode={authMode}
        authUserId={authUserId}
        onAuthModeChange={onAuthModeChange}
        onAuthUserIdChange={onAuthUserIdChange}
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
              embedded
              showTopBorder={hasPathParams || hasQueryParams}
            />
          )}
        </RequestBuilderPanel>
      )}

      {multipart && (
        <p className="text-[13px] text-muted-foreground">
          This endpoint uses multipart form data. File upload support is coming
          soon.
        </p>
      )}
    </div>
  )
}

type ResponseSectionProps = {
  response: ExecuteApiRequestResult
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

function ResponseSection({ response }: ResponseSectionProps) {
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
    <div className="flex h-full min-h-0 flex-col overflow-hidden bg-background">
      <div className="flex h-10 shrink-0 items-center gap-2 border-b border-border bg-muted/30 px-3">
        <span className="text-[12px] font-semibold text-foreground">Response</span>
        <Badge variant={statusVariant} className="text-[10px] shrink-0">
          {response.status} {response.statusText}
        </Badge>
        <Badge variant="secondary" className="text-[10px] shrink-0">
          {response.durationMs} ms
        </Badge>
      </div>
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
    </div>
  )
}
