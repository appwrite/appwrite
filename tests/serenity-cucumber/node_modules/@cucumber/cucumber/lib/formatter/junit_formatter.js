"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const xmlbuilder_1 = __importDefault(require("xmlbuilder"));
const _1 = __importDefault(require("./"));
const messages_1 = require("@cucumber/messages");
const value_checker_1 = require("../value_checker");
const gherkin_document_parser_1 = require("./helpers/gherkin_document_parser");
const pickle_parser_1 = require("./helpers/pickle_parser");
const value_checker_2 = require("../value_checker");
class JunitFormatter extends _1.default {
    constructor(options) {
        var _a;
        super(options);
        this.names = {};
        this.suiteName = (0, value_checker_2.valueOrDefault)((_a = options.parsedArgvOptions.junit) === null || _a === void 0 ? void 0 : _a.suiteName, 'cucumber-js');
        options.eventBroadcaster.on('envelope', (envelope) => {
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
                this.onTestRunFinished();
            }
        });
    }
    getTestCases() {
        return this.eventDataCollector
            .getTestCaseAttempts()
            .filter((attempt) => !attempt.willBeRetried);
    }
    getTestSteps(testCaseAttempt, gherkinStepMap, pickleStepMap) {
        return testCaseAttempt.testCase.testSteps.map((testStep) => {
            const isBeforeHook = !(0, value_checker_1.doesHaveValue)(testStep.pickleStepId);
            return this.getTestStep({
                isBeforeHook,
                gherkinStepMap,
                pickleStepMap,
                testStep,
                testStepAttachments: testCaseAttempt.stepAttachments[testStep.id],
                testStepResult: testCaseAttempt.stepResults[testStep.id],
            });
        });
    }
    getTestStep({ isBeforeHook, gherkinStepMap, pickleStepMap, testStep, testStepAttachments, testStepResult, }) {
        const data = {};
        if (testStep.pickleStepId) {
            const pickleStep = pickleStepMap[testStep.pickleStepId];
            data.keyword = (0, pickle_parser_1.getStepKeyword)({ pickleStep, gherkinStepMap });
            data.line = gherkinStepMap[pickleStep.astNodeIds[0]].location.line;
            data.name = pickleStep.text;
        }
        else {
            data.keyword = isBeforeHook ? 'Before' : 'After';
            data.hidden = true;
        }
        data.result = testStepResult;
        data.time = testStepResult.duration
            ? this.durationToSeconds(testStepResult.duration)
            : 0;
        data.attachments = testStepAttachments;
        return data;
    }
    getTestCaseResult(steps) {
        const { status, message, exception } = (0, messages_1.getWorstTestStepResult)(steps.map((step) => step.result));
        return {
            status,
            failure: message || exception
                ? {
                    type: exception === null || exception === void 0 ? void 0 : exception.type,
                    message: exception === null || exception === void 0 ? void 0 : exception.message,
                    detail: message,
                }
                : undefined,
        };
    }
    durationToSeconds(duration) {
        const NANOS_IN_SECOND = 1000000000;
        return ((duration.seconds * NANOS_IN_SECOND + duration.nanos) / NANOS_IN_SECOND);
    }
    nameOrDefault(name, fallbackSuffix) {
        if (!name) {
            return `(unnamed ${fallbackSuffix})`;
        }
        return name;
    }
    getTestCaseName(feature, rule, pickle) {
        const featureName = this.nameOrDefault(feature.name, 'feature');
        const pickleName = this.nameOrDefault(pickle.name, 'scenario');
        const testCaseName = rule
            ? this.nameOrDefault(rule.name, 'rule') + ': ' + pickleName
            : pickleName;
        if (!this.names[featureName]) {
            this.names[featureName] = [];
        }
        let index = 0;
        while (this.names[featureName].includes(index > 0 ? `${testCaseName} [${index}]` : testCaseName)) {
            index++;
        }
        const name = index > 0 ? `${testCaseName} [${index}]` : testCaseName;
        this.names[featureName].push(name);
        return name;
    }
    formatTestSteps(steps) {
        return steps
            .filter((step) => !step.hidden)
            .map((step) => {
            const statusText = step.result.status.toLowerCase();
            const maxLength = 80 - statusText.length - 3;
            const stepText = `${step.keyword}${step.name}`
                .padEnd(maxLength, '.')
                .substring(0, maxLength);
            return `${stepText}...${statusText}`;
        })
            .join('\n');
    }
    onTestRunFinished() {
        const testCases = this.getTestCases();
        const tests = testCases.map((testCaseAttempt) => {
            const { gherkinDocument, pickle } = testCaseAttempt;
            const { feature } = gherkinDocument;
            const gherkinExampleRuleMap = (0, gherkin_document_parser_1.getGherkinExampleRuleMap)(gherkinDocument);
            const rule = gherkinExampleRuleMap[pickle.astNodeIds[0]];
            const gherkinStepMap = (0, gherkin_document_parser_1.getGherkinStepMap)(gherkinDocument);
            const pickleStepMap = (0, pickle_parser_1.getPickleStepMap)(pickle);
            const steps = this.getTestSteps(testCaseAttempt, gherkinStepMap, pickleStepMap);
            const stepDuration = steps.reduce((total, step) => total + (step.time || 0), 0);
            return {
                classname: this.nameOrDefault(feature.name, 'feature'),
                name: this.getTestCaseName(feature, rule, pickle),
                time: stepDuration,
                result: this.getTestCaseResult(steps),
                systemOutput: this.formatTestSteps(steps),
                steps,
            };
        });
        const passed = tests.filter((item) => item.result.status === messages_1.TestStepResultStatus.PASSED).length;
        const skipped = tests.filter((item) => item.result.status === messages_1.TestStepResultStatus.SKIPPED).length;
        const failures = tests.length - passed - skipped;
        const testSuite = {
            name: this.suiteName,
            tests,
            failures,
            skipped,
            time: tests.reduce((total, test) => total + test.time, 0),
        };
        this.log(this.buildXmlReport(testSuite));
    }
    buildXmlReport(testSuite) {
        const xmlReport = xmlbuilder_1.default
            .create('testsuite', { invalidCharReplacement: '' })
            .att('failures', testSuite.failures)
            .att('skipped', testSuite.skipped)
            .att('name', testSuite.name)
            .att('time', testSuite.time)
            .att('tests', testSuite.tests.length);
        testSuite.tests.forEach((test) => {
            var _a, _b, _c;
            const xmlTestCase = xmlReport.ele('testcase', {
                classname: test.classname,
                name: test.name,
                time: test.time,
            });
            if (test.result.status === messages_1.TestStepResultStatus.SKIPPED) {
                xmlTestCase.ele('skipped');
            }
            else if (test.result.status !== messages_1.TestStepResultStatus.PASSED) {
                const xmlFailure = xmlTestCase.ele('failure', {
                    type: (_a = test.result.failure) === null || _a === void 0 ? void 0 : _a.type,
                    message: (_b = test.result.failure) === null || _b === void 0 ? void 0 : _b.message,
                });
                if ((_c = test.result) === null || _c === void 0 ? void 0 : _c.failure) {
                    xmlFailure.cdata(test.result.failure.detail);
                }
            }
            xmlTestCase.ele('system-out', {}).cdata(test.systemOutput);
        });
        return xmlReport.end({ pretty: true });
    }
}
exports.default = JunitFormatter;
JunitFormatter.documentation = 'Outputs JUnit report';
//# sourceMappingURL=junit_formatter.js.map