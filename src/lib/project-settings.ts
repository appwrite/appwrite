import {
  ProjectAuthMethodId,
  ProjectProtocolId,
  ProjectServiceId,
  ProjectPolicyId,
  Query,
  type Models,
} from '@appwrite.io/console'
import { queryOptions } from '@tanstack/react-query'
import { sdk, setProjectRegion } from '@/lib/appwrite/sdk'

type ProjectPolicy = Models.PolicyList['policies'][number]

export type ProjectAuthSecuritySnapshot = {
  authLimit: number
  authDuration: number
  authSessionsLimit: number
  authPasswordHistory: number
  authPasswordDictionary: boolean
  authPersonalDataCheck: boolean
  authSessionAlerts: boolean
  authInvalidateSessions: boolean
  authMockNumbers: Array<{ phone: string; otp: string }>
  membershipsPrivacy: {
    userName: boolean
    userEmail: boolean
    mfa: boolean
    userId: boolean
    userPhone: boolean
  }
}

const DEFAULT_AUTH_SECURITY: ProjectAuthSecuritySnapshot = {
  authLimit: 0,
  authDuration: 0,
  authSessionsLimit: 10,
  authPasswordHistory: 0,
  authPasswordDictionary: false,
  authPersonalDataCheck: false,
  authSessionAlerts: false,
  authInvalidateSessions: false,
  authMockNumbers: [],
  membershipsPrivacy: {
    userName: true,
    userEmail: true,
    mfa: true,
    userId: true,
    userPhone: true,
  },
}

function policyById(
  policies: ProjectPolicy[] | undefined,
  id: ProjectPolicyId,
): ProjectPolicy | undefined {
  return policies?.find((p) => p.$id === id)
}

/** Policies with `total`: API null/0 = disabled or unlimited; UI uses 0. */
function parsePolicyCountLimit(
  policy: ProjectPolicy | undefined,
  defaultWhenMissing: number,
): number {
  if (!policy || !('total' in policy)) return defaultWhenMissing
  const total = (policy as { total: number | null }).total
  if (total == null || total === 0) return 0
  return total
}

export function parseProjectAuthSecurity(
  policies: ProjectPolicy[] | undefined,
  mockNumbers: Models.MockNumber[] | undefined,
): ProjectAuthSecuritySnapshot {
  const userLimit = policyById(policies, ProjectPolicyId.Userlimit)
  const sessionDuration = policyById(
    policies,
    ProjectPolicyId.Sessionduration,
  )
  const sessionLimit = policyById(policies, ProjectPolicyId.Sessionlimit)
  const passwordHistory = policyById(
    policies,
    ProjectPolicyId.Passwordhistory,
  )
  const passwordDictionary = policyById(
    policies,
    ProjectPolicyId.Passworddictionary,
  )
  const passwordPersonalData = policyById(
    policies,
    ProjectPolicyId.Passwordpersonaldata,
  )
  const sessionAlert = policyById(policies, ProjectPolicyId.Sessionalert)
  const sessionInvalidation = policyById(
    policies,
    ProjectPolicyId.Sessioninvalidation,
  )
  const membershipPrivacy = policyById(
    policies,
    ProjectPolicyId.Membershipprivacy,
  )

  return {
    authLimit: parsePolicyCountLimit(userLimit, 0),
    authDuration:
      sessionDuration && 'duration' in sessionDuration
        ? (sessionDuration.duration ?? 0)
        : 0,
    authSessionsLimit: parsePolicyCountLimit(sessionLimit, 10),
    authPasswordHistory: parsePolicyCountLimit(passwordHistory, 0),
    authPasswordDictionary:
      passwordDictionary && 'enabled' in passwordDictionary
        ? (passwordDictionary.enabled ?? false)
        : false,
    authPersonalDataCheck:
      passwordPersonalData && 'enabled' in passwordPersonalData
        ? (passwordPersonalData.enabled ?? false)
        : false,
    authSessionAlerts:
      sessionAlert && 'enabled' in sessionAlert
        ? (sessionAlert.enabled ?? false)
        : false,
    authInvalidateSessions:
      sessionInvalidation && 'enabled' in sessionInvalidation
        ? (sessionInvalidation.enabled ?? false)
        : false,
    authMockNumbers: (mockNumbers ?? []).map((n) => ({
      phone: n.number,
      otp: n.otp,
    })),
    membershipsPrivacy: {
      userName:
        membershipPrivacy && 'userName' in membershipPrivacy
          ? (membershipPrivacy.userName ?? true)
          : true,
      userEmail:
        membershipPrivacy && 'userEmail' in membershipPrivacy
          ? (membershipPrivacy.userEmail ?? true)
          : true,
      mfa:
        membershipPrivacy && 'userMFA' in membershipPrivacy
          ? (membershipPrivacy.userMFA ?? true)
          : true,
      userId:
        membershipPrivacy && 'userId' in membershipPrivacy
          ? (membershipPrivacy.userId ?? true)
          : true,
      userPhone:
        membershipPrivacy && 'userPhone' in membershipPrivacy
          ? (membershipPrivacy.userPhone ?? true)
          : true,
    },
  }
}

export async function fetchProjectAuthSecurity(
  projectId: string,
  region?: string,
): Promise<ProjectAuthSecuritySnapshot> {
  const projectSdk = sdk.forProject(projectId, region)
  const [policiesRes, mockRes] = await Promise.all([
    projectSdk.project.listPolicies({ total: true }).catch(() => null),
    projectSdk.project.listMockPhones({ total: true }).catch(() => null),
  ])
  return parseProjectAuthSecurity(
    policiesRes?.policies,
    mockRes?.mockNumbers,
  )
}

/**
 * Query options for auth security settings (policies + mock phones).
 * Prefetch in the auth/security route loader so the tab renders without a loading flash.
 */
export function projectAuthSecurityQueryOptions(
  projectId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: ['project-auth-security', projectId],
    queryFn: () => fetchProjectAuthSecurity(projectId!, region),
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/** Fetch a project via the project-scoped API (replaces console `projects.get`). */
export async function fetchProjectById(projectId: string): Promise<Models.Project> {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  try {
    const response = await sdk.forProject(projectId).project.get()
    if (response?.region) {
      setProjectRegion(projectId, response.region)
    }
    return response
  } catch (error) {
    const list = await sdk.forConsole.projects.list({
      queries: [Query.equal('$id', projectId), Query.limit(1)],
      total: false,
    })
    const stub = list.projects?.[0]
    if (!stub) {
      throw error
    }
    if (stub.region) {
      setProjectRegion(projectId, stub.region)
    }
    const response = await sdk.forProject(projectId, stub.region).project.get()
    if (response?.region) {
      setProjectRegion(projectId, response.region)
    }
    return response
  }
}

export function authMethodsRecordFromProject(
  project: Models.Project | null | undefined,
): Record<ProjectAuthMethodId, boolean> {
  const defaults: Record<ProjectAuthMethodId, boolean> = {
    [ProjectAuthMethodId.Emailpassword]: false,
    [ProjectAuthMethodId.Phone]: false,
    [ProjectAuthMethodId.Magicurl]: false,
    [ProjectAuthMethodId.Emailotp]: false,
    [ProjectAuthMethodId.Anonymous]: false,
    [ProjectAuthMethodId.Invites]: false,
    [ProjectAuthMethodId.Jwt]: false,
  }
  if (!project?.authMethods?.length) {
    return defaults
  }
  for (const method of project.authMethods) {
    defaults[method.$id] = method.enabled
  }
  return defaults
}

export function servicesRecordFromProject(
  project: Models.Project | null | undefined,
  serviceIds: readonly string[],
): Record<string, boolean> {
  return Object.fromEntries(
    serviceIds.map((id) => {
      const entry = project?.services?.find((s) => s.$id === id)
      return [id, entry?.enabled ?? true]
    }),
  )
}

export function protocolsRecordFromProject(
  project: Models.Project | null | undefined,
): Record<ProjectProtocolId, boolean> {
  const defaults: Record<ProjectProtocolId, boolean> = {
    [ProjectProtocolId.Rest]: true,
    [ProjectProtocolId.Graphql]: true,
    [ProjectProtocolId.Websocket]: true,
  }
  if (!project?.protocols?.length) {
    return defaults
  }
  for (const protocol of project.protocols) {
    defaults[protocol.$id] = protocol.enabled
  }
  return defaults
}

export function patchProjectServicesInCache(
  project: Models.Project,
  serviceId: string,
  enabled: boolean,
): Models.Project {
  const services = [...(project.services ?? [])]
  const idx = services.findIndex((s) => s.$id === serviceId)
  const entry = {
    $id: serviceId as ProjectServiceId,
    enabled,
  }
  if (idx >= 0) {
    services[idx] = entry
  } else {
    services.push(entry)
  }
  return { ...project, services }
}

export function patchProjectProtocolsInCache(
  project: Models.Project,
  protocolId: ProjectProtocolId,
  enabled: boolean,
): Models.Project {
  const protocols = [...(project.protocols ?? [])]
  const idx = protocols.findIndex((p) => p.$id === protocolId)
  const entry = { $id: protocolId, enabled }
  if (idx >= 0) {
    protocols[idx] = entry
  } else {
    protocols.push(entry)
  }
  return { ...project, protocols }
}

export { DEFAULT_AUTH_SECURITY }
