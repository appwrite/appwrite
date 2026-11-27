import type { Client, Models } from '@appwrite.io/console'

import type {
  CreateDistributionAndroidSigningIdentityParams,
  CreateDistributionAppleConnectionParams,
  CreateDistributionAppParams,
  CreateDistributionGoogleConnectionParams,
  CreateDistributionIosSigningIdentityParams,
  CreateDistributionTargetParams,
  CreateDistributionVariableParams,
  DistributionApp,
  DistributionAppList,
  DistributionConnection,
  DistributionConnectionList,
  DistributionListParams,
  DistributionSigningIdentity,
  DistributionSigningIdentityDownload,
  DistributionSigningIdentityList,
  DistributionTarget,
  DistributionTargetList,
  UpdateDistributionAppleConnectionParams,
  UpdateDistributionAppParams,
  UpdateDistributionGoogleConnectionParams,
  UpdateDistributionSigningIdentityParams,
  UpdateDistributionTargetParams,
  UpdateDistributionVariableParams,
} from './models'

type Method = 'get' | 'post' | 'patch' | 'delete'

export class Distribution {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  createApp(params: CreateDistributionAppParams): Promise<DistributionApp> {
    return this.send('post', ['apps'], params)
  }

  listApps(params: DistributionListParams = {}): Promise<DistributionAppList> {
    return this.send('get', ['apps'], params)
  }

  getApp({ appId }: { appId: string }): Promise<DistributionApp> {
    return this.send('get', ['apps', appId])
  }

  updateApp({
    appId,
    ...params
  }: UpdateDistributionAppParams): Promise<DistributionApp> {
    return this.send('patch', ['apps', appId], params)
  }

  deleteApp({ appId }: { appId: string }): Promise<unknown> {
    return this.send('delete', ['apps', appId])
  }

  createTarget({
    appId,
    ...params
  }: CreateDistributionTargetParams): Promise<DistributionTarget> {
    return this.send('post', ['apps', appId, 'targets'], params)
  }

  listTargets({
    appId,
    ...params
  }: DistributionListParams & {
    appId: string
  }): Promise<DistributionTargetList> {
    return this.send('get', ['apps', appId, 'targets'], params)
  }

  getTarget({
    appId,
    targetId,
  }: {
    appId: string
    targetId: string
  }): Promise<DistributionTarget> {
    return this.send('get', ['apps', appId, 'targets', targetId])
  }

  updateTarget({
    appId,
    targetId,
    ...params
  }: UpdateDistributionTargetParams): Promise<DistributionTarget> {
    return this.send('patch', ['apps', appId, 'targets', targetId], params)
  }

  deleteTarget({
    appId,
    targetId,
  }: {
    appId: string
    targetId: string
  }): Promise<unknown> {
    return this.send('delete', ['apps', appId, 'targets', targetId])
  }

  createVariable({
    appId,
    ...params
  }: CreateDistributionVariableParams): Promise<Models.Variable> {
    return this.send('post', ['apps', appId, 'variables'], params)
  }

  listVariables({
    appId,
    ...params
  }: DistributionListParams & { appId: string }): Promise<Models.VariableList> {
    return this.send('get', ['apps', appId, 'variables'], params)
  }

  getVariable({
    appId,
    variableId,
  }: {
    appId: string
    variableId: string
  }): Promise<Models.Variable> {
    return this.send('get', ['apps', appId, 'variables', variableId])
  }

  updateVariable({
    appId,
    variableId,
    ...params
  }: UpdateDistributionVariableParams): Promise<Models.Variable> {
    return this.send('patch', ['apps', appId, 'variables', variableId], params)
  }

  deleteVariable({
    appId,
    variableId,
  }: {
    appId: string
    variableId: string
  }): Promise<unknown> {
    return this.send('delete', ['apps', appId, 'variables', variableId])
  }

  createAppleConnection(
    params: CreateDistributionAppleConnectionParams,
  ): Promise<DistributionConnection> {
    return this.send('post', ['connections', 'apple'], params)
  }

  updateAppleConnection({
    connectionId,
    ...params
  }: UpdateDistributionAppleConnectionParams): Promise<DistributionConnection> {
    return this.send('patch', ['connections', 'apple', connectionId], params)
  }

  createGoogleConnection(
    params: CreateDistributionGoogleConnectionParams,
  ): Promise<DistributionConnection> {
    return this.send('post', ['connections', 'google'], params)
  }

  updateGoogleConnection({
    connectionId,
    ...params
  }: UpdateDistributionGoogleConnectionParams): Promise<DistributionConnection> {
    return this.send('patch', ['connections', 'google', connectionId], params)
  }

  listConnections(
    params: DistributionListParams = {},
  ): Promise<DistributionConnectionList> {
    return this.send('get', ['connections'], params)
  }

  getConnection({
    connectionId,
  }: {
    connectionId: string
  }): Promise<DistributionConnection> {
    return this.send('get', ['connections', connectionId])
  }

  deleteConnection({
    connectionId,
  }: {
    connectionId: string
  }): Promise<unknown> {
    return this.send('delete', ['connections', connectionId])
  }

  createIosSigningIdentity(
    params: CreateDistributionIosSigningIdentityParams,
  ): Promise<DistributionSigningIdentity> {
    return this.send('post', ['signing-identities', 'ios'], params)
  }

  createAndroidSigningIdentity(
    params: CreateDistributionAndroidSigningIdentityParams,
  ): Promise<DistributionSigningIdentity> {
    return this.send('post', ['signing-identities', 'android'], params)
  }

  listSigningIdentities(
    params: DistributionListParams = {},
  ): Promise<DistributionSigningIdentityList> {
    return this.send('get', ['signing-identities'], params)
  }

  getSigningIdentity({
    signingIdentityId,
  }: {
    signingIdentityId: string
  }): Promise<DistributionSigningIdentity> {
    return this.send('get', ['signing-identities', signingIdentityId])
  }

  updateSigningIdentity({
    signingIdentityId,
    ...params
  }: UpdateDistributionSigningIdentityParams): Promise<DistributionSigningIdentity> {
    return this.send('patch', ['signing-identities', signingIdentityId], params)
  }

  deleteSigningIdentity({
    signingIdentityId,
  }: {
    signingIdentityId: string
  }): Promise<unknown> {
    return this.send('delete', ['signing-identities', signingIdentityId])
  }

  getSigningIdentityDownload({
    signingIdentityId,
  }: {
    signingIdentityId: string
  }): Promise<DistributionSigningIdentityDownload> {
    return this.send('get', [
      'signing-identities',
      signingIdentityId,
      'download',
    ])
  }

  private send<T>(
    method: Method,
    segments: string[],
    params: object = {},
  ): Promise<T> {
    const path = segments.map(encodeURIComponent).join('/')
    const payload = Object.fromEntries(
      Object.entries(params).filter(([, value]) => value !== undefined),
    )
    const headers: Record<string, string> = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }
    if (method !== 'get') {
      headers['content-type'] = 'application/json'
    }
    return this.client.call(
      method,
      new URL(`${this.client.config.endpoint}/distribution/${path}`),
      headers,
      payload,
    )
  }
}
