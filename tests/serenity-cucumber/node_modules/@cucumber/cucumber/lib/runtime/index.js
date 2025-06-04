"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const assemble_test_cases_1 = require("./assemble_test_cases");
const helpers_1 = require("./helpers");
const run_test_run_hooks_1 = require("./run_test_run_hooks");
const stopwatch_1 = require("./stopwatch");
const test_case_runner_1 = __importDefault(require("./test_case_runner"));
class Runtime {
    constructor({ eventBroadcaster, eventDataCollector, newId, options, pickleIds, supportCodeLibrary, }) {
        this.eventBroadcaster = eventBroadcaster;
        this.eventDataCollector = eventDataCollector;
        this.stopwatch = (0, stopwatch_1.create)();
        this.newId = newId;
        this.options = options;
        this.pickleIds = pickleIds;
        this.supportCodeLibrary = supportCodeLibrary;
        this.success = true;
        this.runTestRunHooks = (0, run_test_run_hooks_1.makeRunTestRunHooks)(this.options.dryRun, this.supportCodeLibrary.defaultTimeout, (name, location) => `${name} hook errored, process exiting: ${location}`);
    }
    async runTestCase(pickleId, testCase) {
        const pickle = this.eventDataCollector.getPickle(pickleId);
        const retries = (0, helpers_1.retriesForPickle)(pickle, this.options);
        const skip = this.options.dryRun || (this.options.failFast && !this.success);
        const testCaseRunner = new test_case_runner_1.default({
            eventBroadcaster: this.eventBroadcaster,
            stopwatch: this.stopwatch,
            gherkinDocument: this.eventDataCollector.getGherkinDocument(pickle.uri),
            newId: this.newId,
            pickle,
            testCase,
            retries,
            skip,
            filterStackTraces: this.options.filterStacktraces,
            supportCodeLibrary: this.supportCodeLibrary,
            worldParameters: this.options.worldParameters,
        });
        const status = await testCaseRunner.run();
        if ((0, helpers_1.shouldCauseFailure)(status, this.options)) {
            this.success = false;
        }
    }
    async start() {
        const testRunStarted = {
            testRunStarted: {
                timestamp: this.stopwatch.timestamp(),
            },
        };
        this.eventBroadcaster.emit('envelope', testRunStarted);
        this.stopwatch.start();
        await this.runTestRunHooks(this.supportCodeLibrary.beforeTestRunHookDefinitions, 'a BeforeAll');
        const assembledTestCases = await (0, assemble_test_cases_1.assembleTestCases)({
            eventBroadcaster: this.eventBroadcaster,
            newId: this.newId,
            pickles: this.pickleIds.map((pickleId) => this.eventDataCollector.getPickle(pickleId)),
            supportCodeLibrary: this.supportCodeLibrary,
        });
        for (const pickleId of this.pickleIds) {
            await this.runTestCase(pickleId, assembledTestCases[pickleId]);
        }
        await this.runTestRunHooks(this.supportCodeLibrary.afterTestRunHookDefinitions.slice(0).reverse(), 'an AfterAll');
        this.stopwatch.stop();
        const testRunFinished = {
            testRunFinished: {
                timestamp: this.stopwatch.timestamp(),
                success: this.success,
            },
        };
        this.eventBroadcaster.emit('envelope', testRunFinished);
        return this.success;
    }
}
exports.default = Runtime;
//# sourceMappingURL=index.js.map