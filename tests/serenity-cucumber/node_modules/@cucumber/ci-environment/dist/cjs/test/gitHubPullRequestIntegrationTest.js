"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert_1 = __importDefault(require("assert"));
var index_js_1 = __importDefault(require("../src/index.js"));
describe('GitHub', function () {
    if (process.env.GITHUB_EVENT_NAME === 'pull_request') {
        it('detects the correct revision for pull requests', function () {
            var ciEnvironment = (0, index_js_1.default)(process.env);
            (0, assert_1.default)(ciEnvironment);
            console.log('Manually verify that the revision is correct');
            console.log(JSON.stringify(ciEnvironment, null, 2));
        });
    }
});
//# sourceMappingURL=gitHubPullRequestIntegrationTest.js.map