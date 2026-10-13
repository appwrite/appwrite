import type { Client, Models, Project } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export type ProjectVideoProfileInput = {
  name: string
  width: number
  height: number
  videoBitRate: number
  audioBitRate: number
  codec?: string
}

type ListProjectProfilesParams = {
  search?: string
  codec?: string
}

type ProjectProfilesApi = {
  listProfiles: (
    params?: ListProjectProfilesParams,
  ) => Promise<Models.VideoProfileList>
  createProfile: (
    params: ProjectVideoProfileInput,
  ) => Promise<Models.VideoProfile>
  updateProfile: (
    params: ProjectVideoProfileInput & { profileId: string },
  ) => Promise<Models.VideoProfile>
  deleteProfile: (params: { profileId: string }) => Promise<{}>
}

function projectProfilesViaClientCall(client: Client): ProjectProfilesApi {
  const projectHeaders = (): Record<string, string> => ({
    'X-Appwrite-Project': client.config.project,
    accept: 'application/json',
  })

  const jsonHeaders = (): Record<string, string> => ({
    ...projectHeaders(),
    'content-type': 'application/json',
  })

  return {
    listProfiles: async (params = {}) => {
      const apiPayload: Record<string, string> = {}
      if (params.search) {
        apiPayload.search = params.search
      }
      if (params.codec) {
        apiPayload.codec = params.codec
      }
      const uri = new URL(`${client.config.endpoint}/project/profiles`)
      return client.call('get', uri, projectHeaders(), apiPayload)
    },
    createProfile: async (params) => {
      const uri = new URL(`${client.config.endpoint}/project/profiles`)
      const apiPayload: Record<string, string | number> = {
        name: params.name,
        width: params.width,
        height: params.height,
        videoBitRate: params.videoBitRate,
        audioBitRate: params.audioBitRate,
      }
      if (params.codec) {
        apiPayload.codec = params.codec
      }
      return client.call('post', uri, jsonHeaders(), apiPayload)
    },
    updateProfile: async (params) => {
      const uri = new URL(
        `${client.config.endpoint}/project/profiles/${encodeURIComponent(params.profileId)}`,
      )
      const { profileId: _profileId, ...body } = params
      return client.call('patch', uri, jsonHeaders(), body)
    },
    deleteProfile: async ({ profileId }) => {
      const uri = new URL(
        `${client.config.endpoint}/project/profiles/${encodeURIComponent(profileId)}`,
      )
      return client.call('delete', uri, jsonHeaders(), {})
    },
  }
}

/** Project-scoped video encoding profiles (`/v1/project/profiles`). */
export function getProjectProfilesApi(projectId: string): ProjectProfilesApi {
  const project = sdk.forProject(projectId).project
  const withSdkMethods = project as Project & Partial<ProjectProfilesApi>

  if (typeof withSdkMethods.listProfiles === 'function') {
    return {
      listProfiles: (params) => withSdkMethods.listProfiles!(params ?? {}),
      createProfile: (params) => withSdkMethods.createProfile!(params),
      updateProfile: (params) => withSdkMethods.updateProfile!(params),
      deleteProfile: (params) => withSdkMethods.deleteProfile!(params),
    }
  }

  return projectProfilesViaClientCall(project.client)
}
