import { Client, Storage } from '@appwrite.io/console'
import { getInitTicketStorageConfig } from '@/lib/init/init-ticket-storage-config'

let client: Client | null = null
let storage: Storage | null = null

export function getInitTicketStorageSdk(): Storage {
  if (!storage) {
    const config = getInitTicketStorageConfig()
    client = new Client()
    client.setEndpoint(config.endpoint).setProject(config.projectId)
    storage = new Storage(client)
  }
  return storage
}
