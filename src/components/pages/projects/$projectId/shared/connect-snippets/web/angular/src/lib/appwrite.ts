import { Client } from 'appwrite'
import { environment } from '../environments/environment'

const client = new Client()
  .setEndpoint(environment.appwriteEndpoint)
  .setProject(environment.appwriteProjectId)

export { client }
