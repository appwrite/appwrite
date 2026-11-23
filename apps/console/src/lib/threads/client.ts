import { Client, Databases } from '@appwrite.io/console'
import { getThreadsAppwriteConfig } from './config'

let client: Client | null = null
let databases: Databases | null = null

export function getThreadsDatabases(): Databases {
  if (!databases) {
    const config = getThreadsAppwriteConfig()
    client = new Client()
    client.setEndpoint(config.endpoint).setProject(config.projectId)
    databases = new Databases(client)
  }
  return databases
}
