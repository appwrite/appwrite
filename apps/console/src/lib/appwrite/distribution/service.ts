import type { Client, Models } from '@appwrite.io/console'

import type {
  CreateDistributionAndroidSigningIdentityParams,
  CreateDistributionAppleConnectionParams,
  CreateDistributionAppParams,
  CreateDistributionArtifactParams,
  CreateDistributionBuildParams,
  CreateDistributionCheckParams,
  CreateDistributionGoogleConnectionParams,
  CreateDistributionIosSigningIdentityParams,
  CreateDistributionReleaseParams,
  CreateDistributionSubmissionParams,
  CreateDistributionTargetParams,
  CreateDistributionVariableParams,
  DistributionApp,
  DistributionAppList,
  DistributionArtifact,
  DistributionArtifactList,
  DistributionBuild,
  DistributionBuildList,
  DistributionCheck,
  DistributionConnection,
  DistributionConnectionList,
  DistributionListParams,
  DistributionLogList,
  DistributionRelease,
  DistributionReleaseList,
  DistributionSigningIdentity,
  DistributionSigningIdentityDownload,
  DistributionSigningIdentityList,
  DistributionSubmission,
  DistributionSubmissionList,
  DistributionTarget,
  DistributionTargetList,
  UpdateDistributionAppleConnectionParams,
  UpdateDistributionAppParams,
  UpdateDistributionBuildStatusParams,
  UpdateDistributionGoogleConnectionParams,
  UpdateDistributionReleaseParams,
  UpdateDistributionSigningIdentityParams,
  UpdateDistributionSubmissionParams,
  UpdateDistributionSubmissionStatusParams,
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

  createBuild({
    appId,
    ...params
  }: CreateDistributionBuildParams): Promise<DistributionBuild> {
    return this.send('post', ['apps', appId, 'builds'], params)
  }

  listBuilds({
    appId,
    ...params
  }: DistributionListParams & {
    appId: string
  }): Promise<DistributionBuildList> {
    return this.send('get', ['apps', appId, 'builds'], params)
  }

  getBuild({
    appId,
    buildId,
  }: {
    appId: string
    buildId: string
  }): Promise<DistributionBuild> {
    return this.send('get', ['apps', appId, 'builds', buildId])
  }

  updateBuildStatus({
    appId,
    buildId,
    ...params
  }: UpdateDistributionBuildStatusParams): Promise<DistributionBuild> {
    return this.send(
      'patch',
      ['apps', appId, 'builds', buildId, 'status'],
      params,
    )
  }

  deleteBuild({
    appId,
    buildId,
  }: {
    appId: string
    buildId: string
  }): Promise<unknown> {
    return this.send('delete', ['apps', appId, 'builds', buildId])
  }

  listBuildLogs({
    appId,
    buildId,
    ...params
  }: DistributionListParams & {
    appId: string
    buildId: string
  }): Promise<DistributionLogList> {
    return this.send('get', ['apps', appId, 'builds', buildId, 'logs'], params)
  }

  createArtifact({
    appId,
    file,
    artifactId,
    onProgress,
  }: CreateDistributionArtifactParams): Promise<DistributionArtifact> {
    return this.client.chunkedUpload(
      'post',
      this.url(['apps', appId, 'artifacts']),
      {
        'X-Appwrite-Project': this.client.config.project,
        'content-type': 'multipart/form-data',
        accept: 'application/json',
        ...(artifactId ? { 'x-appwrite-id': artifactId } : {}),
      },
      { file },
      onProgress ?? (() => {}),
    )
  }

  listArtifacts({
    appId,
    ...params
  }: DistributionListParams & {
    appId: string
  }): Promise<DistributionArtifactList> {
    return this.send('get', ['apps', appId, 'artifacts'], params)
  }

  getArtifact({
    appId,
    artifactId,
  }: {
    appId: string
    artifactId: string
  }): Promise<DistributionArtifact> {
    return this.send('get', ['apps', appId, 'artifacts', artifactId])
  }

  getArtifactDownload({
    appId,
    artifactId,
  }: {
    appId: string
    artifactId: string
  }): string {
    const url = this.url(['apps', appId, 'artifacts', artifactId, 'download'])
    url.searchParams.append('project', this.client.config.project)
    return url.toString()
  }

  deleteArtifact({
    appId,
    artifactId,
  }: {
    appId: string
    artifactId: string
  }): Promise<unknown> {
    return this.send('delete', ['apps', appId, 'artifacts', artifactId])
  }

  createRelease({
    appId,
    ...params
  }: CreateDistributionReleaseParams): Promise<DistributionRelease> {
    return this.send('post', ['apps', appId, 'releases'], params)
  }

  listReleases({
    appId,
    ...params
  }: DistributionListParams & {
    appId: string
  }): Promise<DistributionReleaseList> {
    return this.send('get', ['apps', appId, 'releases'], params)
  }

  getRelease({
    appId,
    releaseId,
  }: {
    appId: string
    releaseId: string
  }): Promise<DistributionRelease> {
    return this.send('get', ['apps', appId, 'releases', releaseId])
  }

  updateRelease({
    appId,
    releaseId,
    ...params
  }: UpdateDistributionReleaseParams): Promise<DistributionRelease> {
    return this.send('patch', ['apps', appId, 'releases', releaseId], params)
  }

  deleteRelease({
    appId,
    releaseId,
  }: {
    appId: string
    releaseId: string
  }): Promise<unknown> {
    return this.send('delete', ['apps', appId, 'releases', releaseId])
  }

  createSubmission({
    appId,
    ...params
  }: CreateDistributionSubmissionParams): Promise<DistributionSubmission> {
    return this.send('post', ['apps', appId, 'submissions'], params)
  }

  listSubmissions({
    appId,
    ...params
  }: DistributionListParams & {
    appId: string
  }): Promise<DistributionSubmissionList> {
    return this.send('get', ['apps', appId, 'submissions'], params)
  }

  getSubmission({
    appId,
    submissionId,
  }: {
    appId: string
    submissionId: string
  }): Promise<DistributionSubmission> {
    return this.send('get', ['apps', appId, 'submissions', submissionId])
  }

  updateSubmission({
    appId,
    submissionId,
    ...params
  }: UpdateDistributionSubmissionParams): Promise<DistributionSubmission> {
    return this.send(
      'patch',
      ['apps', appId, 'submissions', submissionId],
      params,
    )
  }

  updateSubmissionStatus({
    appId,
    submissionId,
    ...params
  }: UpdateDistributionSubmissionStatusParams): Promise<DistributionSubmission> {
    return this.send(
      'patch',
      ['apps', appId, 'submissions', submissionId, 'status'],
      params,
    )
  }

  createCheck({
    appId,
    ...params
  }: CreateDistributionCheckParams): Promise<DistributionCheck> {
    return this.send('post', ['apps', appId, 'checks'], params)
  }

  private url(segments: string[]): URL {
    const path = segments.map(encodeURIComponent).join('/')
    return new URL(`${this.client.config.endpoint}/distribution/${path}`)
  }

  private send<T>(
    method: Method,
    segments: string[],
    params: object = {},
  ): Promise<T> {
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
    return this.client.call(method, this.url(segments), headers, payload)
  }
}
