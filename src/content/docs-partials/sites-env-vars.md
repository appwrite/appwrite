| Variable                             | Description                                                   | Available at Build and/or Run Time |
| ------------------------------------ | ------------------------------------------------------------- | ---------------------------------- |
| `APPWRITE_SITE_API_ENDPOINT`         | The API endpoint of the running site                          | Both                               |
| `APPWRITE_VERSION`                   | The Appwrite version used to run the site                     | Both                               |
| `APPWRITE_REGION`                    | The region where the site will run from                       | Both                               |
| `APPWRITE_DEPLOYMENT_TYPE`           | The deployment source type, such as `manual`, `cli`, or `vcs` | Both                               |
| `APPWRITE_SITE_API_KEY`              | The site API key used for server authentication               | Build time                         |
| `APPWRITE_SITE_ID`                   | The ID of the running site                                    | Both                               |
| `APPWRITE_SITE_NAME`                 | The name of the running site                                  | Both                               |
| `APPWRITE_SITE_DEPLOYMENT`           | The deployment ID of the running site                         | Both                               |
| `APPWRITE_SITE_PROJECT_ID`           | The project ID of the running site                            | Both                               |
| `APPWRITE_SITE_RUNTIME_NAME`         | The runtime name of the running site                          | Both                               |
| `APPWRITE_SITE_RUNTIME_VERSION`      | The runtime version of the running site                       | Both                               |
| `APPWRITE_SITE_CPUS`                 | The CPU (runtime) specification of the running site           | Both                               |
| `APPWRITE_SITE_MEMORY`               | The memory (runtime) specification of the running site        | Both                               |
| `APPWRITE_VCS_REPOSITORY_ID`         | The provider repository ID for VCS deployments                | Both                               |
| `APPWRITE_VCS_REPOSITORY_NAME`       | The provider repository name for VCS deployments              | Both                               |
| `APPWRITE_VCS_REPOSITORY_OWNER`      | The owner of the provider repository                          | Both                               |
| `APPWRITE_VCS_REPOSITORY_URL`        | The URL of the provider repository                            | Both                               |
| `APPWRITE_VCS_REPOSITORY_BRANCH`     | The branch used for the VCS deployment                        | Both                               |
| `APPWRITE_VCS_REPOSITORY_BRANCH_URL` | The URL of the branch used for the VCS deployment             | Both                               |
| `APPWRITE_VCS_COMMIT_HASH`           | The commit hash used for the VCS deployment                   | Both                               |
| `APPWRITE_VCS_COMMIT_MESSAGE`        | The commit message used for the VCS deployment                | Both                               |
| `APPWRITE_VCS_COMMIT_URL`            | The URL of the commit used for the VCS deployment             | Both                               |
| `APPWRITE_VCS_COMMIT_AUTHOR_NAME`    | The name of the VCS commit author                             | Both                               |
| `APPWRITE_VCS_COMMIT_AUTHOR_URL`     | The URL of the VCS commit author                              | Both                               |
| `APPWRITE_VCS_ROOT_DIRECTORY`        | The root directory configured for the VCS deployment          | Both                               |

VCS metadata variables are populated for Git deployments. For manual and CLI deployments, VCS fields may be empty.
