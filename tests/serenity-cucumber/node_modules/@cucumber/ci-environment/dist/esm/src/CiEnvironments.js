// This file is auto-generated using npm run build-ci-environments
export const CiEnvironments = [
    {
        name: 'Azure Pipelines',
        url: '${BUILD_BUILDURI}',
        buildNumber: '${BUILD_BUILDNUMBER}',
        git: {
            remote: '${BUILD_REPOSITORY_URI}',
            revision: '${BUILD_SOURCEVERSION}',
            branch: '${BUILD_SOURCEBRANCH/refs/heads/(.*)/\\1}',
            tag: '${BUILD_SOURCEBRANCH/refs/tags/(.*)/\\1}',
        },
    },
    {
        name: 'Bamboo',
        url: '${bamboo_buildResultsUrl}',
        buildNumber: '${bamboo_buildNumber}',
        git: {
            remote: '${bamboo_planRepository_repositoryUrl}',
            revision: '${bamboo_planRepository_revision}',
            branch: '${bamboo_planRepository_branch}',
        },
    },
    {
        name: 'Buddy',
        url: '${BUDDY_EXECUTION_URL}',
        buildNumber: '${BUDDY_EXECUTION_ID}',
        git: {
            remote: '${BUDDY_SCM_URL}',
            revision: '${BUDDY_EXECUTION_REVISION}',
            branch: '${BUDDY_EXECUTION_BRANCH}',
            tag: '${BUDDY_EXECUTION_TAG}',
        },
    },
    {
        name: 'Bitrise',
        url: '${BITRISE_BUILD_URL}',
        buildNumber: '${BITRISE_BUILD_NUMBER}',
        git: {
            remote: '${GIT_REPOSITORY_URL}',
            revision: '${BITRISE_GIT_COMMIT}',
            branch: '${BITRISE_GIT_BRANCH}',
            tag: '${BITRISE_GIT_TAG}',
        },
    },
    {
        name: 'CircleCI',
        url: '${CIRCLE_BUILD_URL}',
        buildNumber: '${CIRCLE_BUILD_NUM}',
        git: {
            remote: '${CIRCLE_REPOSITORY_URL}',
            revision: '${CIRCLE_SHA1}',
            branch: '${CIRCLE_BRANCH}',
            tag: '${CIRCLE_TAG}',
        },
    },
    {
        name: 'CodeFresh',
        url: '${CF_BUILD_URL}',
        buildNumber: '${CF_BUILD_ID}',
        git: {
            remote: '${CF_COMMIT_URL/(.*)\\/commit.+$/\\1}.git',
            revision: '${CF_REVISION}',
            branch: '${CF_BRANCH}',
        },
    },
    {
        name: 'CodeShip',
        url: '${CI_BUILD_URL}',
        buildNumber: '${CI_BUILD_NUMBER}',
        git: {
            remote: '${CI_PULL_REQUEST/(.*)\\/pull\\/\\d+/\\1.git}',
            revision: '${CI_COMMIT_ID}',
            branch: '${CI_BRANCH}',
        },
    },
    {
        name: 'GitHub Actions',
        url: '${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}',
        buildNumber: '${GITHUB_RUN_ID}',
        git: {
            remote: '${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}.git',
            revision: '${GITHUB_SHA}',
            branch: '${GITHUB_HEAD_REF}',
            tag: '${GITHUB_REF/refs/tags/(.*)/\\1}',
        },
    },
    {
        name: 'GitLab',
        url: '${CI_JOB_URL}',
        buildNumber: '${CI_JOB_ID}',
        git: {
            remote: '${CI_REPOSITORY_URL}',
            revision: '${CI_COMMIT_SHA}',
            branch: '${CI_COMMIT_BRANCH}',
            tag: '${CI_COMMIT_TAG}',
        },
    },
    {
        name: 'GoCD',
        url: '${GO_SERVER_URL}/pipelines/${GO_PIPELINE_NAME}/${GO_PIPELINE_COUNTER}/${GO_STAGE_NAME}/${GO_STAGE_COUNTER}',
        buildNumber: '${GO_PIPELINE_NAME}/${GO_PIPELINE_COUNTER}/${GO_STAGE_NAME}/${GO_STAGE_COUNTER}',
        git: {
            remote: '${GO_SCM_*_PR_URL/(.*)\\/pull\\/\\d+/\\1.git}',
            revision: '${GO_REVISION}',
            branch: '${GO_SCM_*_PR_BRANCH/.*:(.*)/\\1}',
        },
    },
    {
        name: 'Jenkins',
        url: '${BUILD_URL}',
        buildNumber: '${BUILD_NUMBER}',
        git: {
            remote: '${GIT_URL}',
            revision: '${GIT_COMMIT}',
            branch: '${GIT_LOCAL_BRANCH}',
        },
    },
    {
        name: 'JetBrains Space',
        url: '${JB_SPACE_EXECUTION_URL}',
        buildNumber: '${JB_SPACE_EXECUTION_NUMBER}',
        git: {
            remote: 'https://${JB_SPACE_API_URL}/p/${JB_SPACE_PROJECT_KEY}/repositories/${JB_SPACE_GIT_REPOSITORY_NAME}',
            revision: '${JB_SPACE_GIT_REVISION}',
            branch: '${JB_SPACE_GIT_BRANCH}',
        },
    },
    {
        name: 'Semaphore',
        url: '${SEMAPHORE_ORGANIZATION_URL}/jobs/${SEMAPHORE_JOB_ID}',
        buildNumber: '${SEMAPHORE_JOB_ID}',
        git: {
            remote: '${SEMAPHORE_GIT_URL}',
            revision: '${SEMAPHORE_GIT_SHA}',
            branch: '${SEMAPHORE_GIT_BRANCH}',
            tag: '${SEMAPHORE_GIT_TAG_NAME}',
        },
    },
    {
        name: 'Travis CI',
        url: '${TRAVIS_BUILD_WEB_URL}',
        buildNumber: '${TRAVIS_JOB_NUMBER}',
        git: {
            remote: 'https://github.com/${TRAVIS_REPO_SLUG}.git',
            revision: '${TRAVIS_COMMIT}',
            branch: '${TRAVIS_BRANCH}',
            tag: '${TRAVIS_TAG}',
        },
    },
    {
        name: 'Wercker',
        url: '${WERCKER_RUN_URL}',
        buildNumber: '${WERCKER_RUN_URL/.*\\/([^\\/]+)$/\\1}',
        git: {
            remote: 'https://${WERCKER_GIT_DOMAIN}/${WERCKER_GIT_OWNER}/${WERCKER_GIT_REPOSITORY}.git',
            revision: '${WERCKER_GIT_COMMIT}',
            branch: '${WERCKER_GIT_BRANCH}',
        },
    },
];
//# sourceMappingURL=CiEnvironments.js.map