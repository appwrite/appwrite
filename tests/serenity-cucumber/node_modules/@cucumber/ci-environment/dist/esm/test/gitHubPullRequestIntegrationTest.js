import assert from 'assert';
import detectCiEnvironment from '../src/index.js';
describe('GitHub', () => {
    if (process.env.GITHUB_EVENT_NAME === 'pull_request') {
        it('detects the correct revision for pull requests', () => {
            const ciEnvironment = detectCiEnvironment(process.env);
            assert(ciEnvironment);
            console.log('Manually verify that the revision is correct');
            console.log(JSON.stringify(ciEnvironment, null, 2));
        });
    }
});
//# sourceMappingURL=gitHubPullRequestIntegrationTest.js.map