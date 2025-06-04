import type {
    GherkinDocument,
    Location,
    Pickle,
    TestCaseFinished,
    TestCaseStarted,
    TestStepFinished,
    TestStepResult,
    TestStepStarted
} from '@cucumber/messages';
import {
    TestStepResultStatus
} from '@cucumber/messages';
import type {
    Serenity} from '@serenity-js/core';
import {
    AssertionError,
    ErrorSerialiser,
    ImplementationPendingError,
    TestCompromisedError
} from '@serenity-js/core';
import type {
    DomainEvent} from '@serenity-js/core/lib/events';
import {
    BusinessRuleDetected,
    FeatureNarrativeDetected,
    RetryableSceneDetected,
    SceneDescriptionDetected,
    SceneFinished,
    SceneParametersDetected,
    SceneSequenceDetected,
    SceneStarts,
    SceneTagged,
    SceneTemplateDetected,
    TaskFinished,
    TaskStarts,
    TestRunnerDetected,
} from '@serenity-js/core/lib/events';
import { FileSystem, FileSystemLocation, Path } from '@serenity-js/core/lib/io';
import { RequirementsHierarchy } from '@serenity-js/core/lib/io';
import type {
    CorrelationId,
    Outcome,
    Tag} from '@serenity-js/core/lib/model';
import {
    ActivityDetails,
    ArbitraryTag,
    BusinessRule,
    Category,
    Description,
    ExecutionCompromised,
    ExecutionFailedWithAssertionError,
    ExecutionFailedWithError,
    ExecutionRetriedTag,
    ExecutionSkipped,
    ExecutionSuccessful,
    ImplementationPending,
    Name,
    ScenarioDetails,
    ScenarioParameters,
    Tags,
} from '@serenity-js/core/lib/model';

import type { EventDataCollector, IParsedTestStep, ITestCaseAttempt } from '../types/cucumber';
import { TestStepFormatter } from './TestStepFormatter';
import type { ExtractedScenario, ExtractedScenarioOutline } from './types';

/**
 * @package
 */
export class CucumberMessagesParser {
    private readonly testStepFormatter = new TestStepFormatter();

    private currentScenario: ScenarioDetails;
    private currentStepActivityId: CorrelationId;

    private readonly cwd: string;
    private readonly eventDataCollector: any;
    private readonly snippetBuilder: any;
    private readonly supportCodeLibrary: any;
    private readonly requirementsHierarchy: RequirementsHierarchy;

    constructor(
        private readonly serenity: Serenity,
        private readonly formatterHelpers: any,     // eslint-disable-line @typescript-eslint/explicit-module-boundary-types
        formatterOptionsAndDependencies: {
            cwd: string,
            eventDataCollector: EventDataCollector,
            snippetBuilder: any,
            supportCodeLibrary: any,
            parsedArgvOptions: { specDirectory?: string },
        },
        private readonly shouldReportStep: (parsedTestStep: IParsedTestStep) => boolean,
    ) {
        this.cwd                = formatterOptionsAndDependencies.cwd;
        this.eventDataCollector = formatterOptionsAndDependencies.eventDataCollector;
        this.snippetBuilder     = formatterOptionsAndDependencies.snippetBuilder;
        this.supportCodeLibrary = formatterOptionsAndDependencies.supportCodeLibrary;
        this.requirementsHierarchy = new RequirementsHierarchy(
            new FileSystem(Path.from(formatterOptionsAndDependencies.cwd)),
            formatterOptionsAndDependencies.parsedArgvOptions?.specDirectory && Path.from(formatterOptionsAndDependencies.parsedArgvOptions?.specDirectory)
        );
    }

    parseTestCaseStarted(message: TestCaseStarted): DomainEvent[] {
        const
            testCaseAttempt = this.eventDataCollector.getTestCaseAttempt(message.id),
            currentSceneId = this.serenity.assignNewSceneId();

        this.currentScenario = this.scenarioDetailsFor(
            testCaseAttempt.gherkinDocument,
            testCaseAttempt.pickle,
            this.formatterHelpers.PickleParser.getPickleLocation(testCaseAttempt),
        );

        return [
            ...this.extract(this.outlineFrom(testCaseAttempt), (outline: ExtractedScenarioOutline) => [
                new SceneSequenceDetected(currentSceneId, outline.details, this.serenity.currentTime()),
                new SceneTemplateDetected(currentSceneId, outline.template, this.serenity.currentTime()),
                new SceneParametersDetected(
                    currentSceneId,
                    this.currentScenario,
                    outline.parameters,
                    this.serenity.currentTime(),
                ),
            ]),

            ...this.extract(this.scenarioFrom(testCaseAttempt), ({ featureDescription, rule, scenarioDescription, tags, testRunnerName }) => [
                new SceneStarts(currentSceneId, this.currentScenario, this.serenity.currentTime()),
                featureDescription && new FeatureNarrativeDetected(currentSceneId, featureDescription, this.serenity.currentTime()),
                new TestRunnerDetected(currentSceneId, testRunnerName, this.serenity.currentTime()),
                !! scenarioDescription && new SceneDescriptionDetected(currentSceneId, scenarioDescription, this.serenity.currentTime()),
                !! rule && new BusinessRuleDetected(currentSceneId, this.currentScenario, rule, this.serenity.currentTime()),
                ...tags.map(tag => new SceneTagged(currentSceneId, tag, this.serenity.currentTime())),
            ]),
        ];
    }

    parseTestStepStarted(message: TestStepStarted): DomainEvent[] {
        return this.extract(this.stepFrom(message), (step): DomainEvent | void => {
            if (this.shouldReportStep(step)) {
                const activityDetails = this.activityDetailsFor(step);
                this.currentStepActivityId = this.serenity.assignNewActivityId(activityDetails);

                return new TaskStarts(
                    this.serenity.currentSceneId(),
                    this.currentStepActivityId,
                    this.activityDetailsFor(step),
                    this.serenity.currentTime()
                );
            }
        });
    }

    parseTestStepFinished(message: TestStepStarted): DomainEvent[] {
        return this.extract(this.stepFrom(message), (step): DomainEvent | void => {
            if (this.shouldReportStep(step)) {
                return new TaskFinished(
                    this.serenity.currentSceneId(),
                    this.currentStepActivityId,
                    this.activityDetailsFor(step),
                    this.outcomeFrom(step.result, step),
                    this.serenity.currentTime()
                );
            }
        })
    }

    parseTestCaseFinished(message: TestCaseFinished): DomainEvent[] {
        const
            testCaseAttempt = this.eventDataCollector.getTestCaseAttempt(message.testCaseStartedId),
            currentSceneId  = this.serenity.currentSceneId();

        return this.extract(this.scenarioOutcomeFrom(testCaseAttempt), ({ outcome, willBeRetried, tags }) => [
            willBeRetried ? new RetryableSceneDetected(currentSceneId, this.serenity.currentTime()) : undefined,
            ...tags.map(tag => new SceneTagged(currentSceneId, tag, this.serenity.currentTime())),
            new SceneFinished(
                currentSceneId,
                this.currentScenario,
                outcome,
                this.serenity.currentTime()
            ),
        ]);
    }

    // ---

    private extract<T>(maybeValue: T | undefined, fn: (value: T) => DomainEvent[] | DomainEvent | void): DomainEvent[] {
        return (maybeValue === undefined)
            ? []
            : [].concat(fn(maybeValue)).filter(item => !! item);
    }

    private scenarioDetailsFor(gherkinDocument: GherkinDocument, pickle: Pickle, location: Location): ScenarioDetails {
        return new ScenarioDetails(
            new Name(pickle.name),
            new Category(gherkinDocument.feature.name),
            new FileSystemLocation(
                this.absolutePathFrom(gherkinDocument.uri),
                location.line,
                location.column,
            ),
        );
    }

    private outlineFrom(testCaseAttempt: ITestCaseAttempt): ExtractedScenarioOutline | void {
        const
            { gherkinDocument, pickle } = testCaseAttempt,
            gherkinScenarioMap = this.formatterHelpers.GherkinDocumentParser.getGherkinScenarioMap(gherkinDocument);

        if (gherkinScenarioMap[pickle.astNodeIds[0]].examples.length === 0) {
            return; // this is not an outline, skip it
        }

        const outline   = gherkinScenarioMap[pickle.astNodeIds[0]];
        const details   = this.scenarioDetailsFor(gherkinDocument, outline, outline.location);
        const template  = new Description(outline.steps.map(step => this.testStepFormatter.format(step.keyword, step.text, step)).join('\n'));

        const examples = flatten(
            outline.examples.map(exampleSet =>
                exampleSet.tableBody.map(row => ({
                    header: exampleSet.tableHeader,
                    row,
                    name: exampleSet.name,
                    description: exampleSet.description,
                }))
            ),
        ).map((example: any) => ({
            rowId:          example.row.id,
            name:           example.name.trim(),
            description:    example.description.trim(),
            values:         example.header.cells
                .map(cell => cell.value)
                .reduce((values, header, i) => {
                    values[header] = example.row.cells[i].value;
                    return values;
                }, {}),
        }));

        const parameters = examples.find(example => example.rowId === pickle.astNodeIds.at(-1));

        return {
            details, template, parameters: new ScenarioParameters(new Name(parameters.name), new Description(parameters.description), parameters.values),
        };
    }

    private scenarioFrom({ gherkinDocument, pickle }: ITestCaseAttempt): ExtractedScenario {
        const
            gherkinScenarioMap      = this.formatterHelpers.GherkinDocumentParser.getGherkinScenarioMap(gherkinDocument),
            gherkinExampleRuleMap   = this.formatterHelpers.GherkinDocumentParser.getGherkinExampleRuleMap(gherkinDocument),
            scenarioDescription     = this.formatterHelpers.PickleParser.getScenarioDescription({ gherkinScenarioMap, pickle }),
            scenarioTags: Tag[]     = flatten<Tag>(pickle.tags.map(tag => Tags.from(tag.name))),
            rule                    = gherkinExampleRuleMap[pickle.astNodeIds[0]];

        return {
            featureDescription:     gherkinDocument.feature.description && new Description(gherkinDocument.feature.description),
            scenarioDescription:    scenarioDescription && new Description(scenarioDescription),
            rule:                   rule && new BusinessRule(new Name(rule.name), new Description(rule.description.trim())),
            testRunnerName:         new Name('JS'),
            tags:                   this.requirementsHierarchy.requirementTagsFor(Path.from(this.cwd).resolve(Path.from(gherkinDocument.uri)), gherkinDocument.feature.name).concat(scenarioTags),
        };
    }

    private stepFrom(message: TestStepStarted | TestStepFinished) {
        const { testCaseStartedId, testStepId } = message;

        const testCaseAttempt = this.eventDataCollector.getTestCaseAttempt(testCaseStartedId);

        const index = testCaseAttempt.testCase.testSteps.findIndex(step => step.id === testStepId);

        return this.parseTestCaseAttempt(testCaseAttempt).testSteps[index];
    }

    private parseTestCaseAttempt(testCaseAttempt: ITestCaseAttempt) {
        // workaround for a bug in Cucumber 7, that's fixed in Cucumber 8 by https://github.com/cucumber/cucumber-js/pull/1531
        testCaseAttempt.testCase.testSteps.forEach(step => {
            if (! testCaseAttempt.stepResults[step.id]) {
                testCaseAttempt.stepResults[step.id] = { duration: { seconds: 0, nanos: 0 }, status: TestStepResultStatus.UNKNOWN, willBeRetried: false } as TestStepResult;
            }
        });
        // ---

        return this.formatterHelpers.parseTestCaseAttempt({
            cwd: this.cwd,
            testCaseAttempt,
            snippetBuilder: this.snippetBuilder,
            supportCodeLibrary: this.supportCodeLibrary,
        });
    }

    private activityDetailsFor(parsedTestStep: IParsedTestStep): ActivityDetails {
        const location = parsedTestStep.sourceLocation || parsedTestStep.actionLocation;

        return new ActivityDetails(
            new Name(this.testStepFormatter.format(parsedTestStep.keyword, parsedTestStep.text || parsedTestStep.name , parsedTestStep.argument)),
            new FileSystemLocation(
                this.absolutePathFrom(location.uri),
                location.line,
            ),
        );
    }

    private outcomeFrom(worstResult: TestStepResult, ...steps: IParsedTestStep[]): Outcome {

        const Status = TestStepResultStatus;

        // todo: how does it treat failed but retryable scenarios?

        switch (worstResult.status) {
            case Status.SKIPPED:
                return new ExecutionSkipped();

            case Status.UNDEFINED: {
                const snippets = steps
                    .filter(step => step.result.status === Status.UNDEFINED)
                    .map(step => step.snippet);

                const message = snippets.length > 0
                    ? ['Step implementation missing:', ...snippets].join('\n\n')
                    : 'Step implementation missing';

                return new ImplementationPending(new ImplementationPendingError(message));
            }

            case Status.PENDING:
                return new ImplementationPending(new ImplementationPendingError('Step implementation pending'));

            case Status.AMBIGUOUS:
            case Status.FAILED: {
                const error = ErrorSerialiser.deserialiseFromStackTrace(worstResult.message);
                if (error instanceof AssertionError) {
                    return new ExecutionFailedWithAssertionError(error);
                }
                if (error instanceof TestCompromisedError) {
                    return new ExecutionCompromised(error);
                }
                return new ExecutionFailedWithError(error);
            }

            case Status.UNKNOWN:
                // ignore
            case Status.PASSED: // eslint-disable-line no-fallthrough
                return new ExecutionSuccessful();
        }
    }

    private scenarioOutcomeFrom(testCaseAttempt: ITestCaseAttempt): { outcome: Outcome, willBeRetried: boolean, tags: Tag[] } {
        const parsed = this.formatterHelpers.parseTestCaseAttempt({
            cwd: this.cwd,
            snippetBuilder: this.snippetBuilder,
            supportCodeLibrary: this.supportCodeLibrary,
            testCaseAttempt
        });

        const worstStepResult   = parsed.testCase.worstTestStepResult;
        const willBeRetried     = worstStepResult.willBeRetried ||      // Cucumber 7
                                  testCaseAttempt.willBeRetried         // Cucumber 8
        const outcome           = this.outcomeFrom(worstStepResult, ...parsed.testSteps);

        const tags = [];

        if (testCaseAttempt.attempt > 0 || willBeRetried) {
            tags.push(new ArbitraryTag('retried'));
        }

        if (testCaseAttempt.attempt > 0) {
            tags.push(new ExecutionRetriedTag(testCaseAttempt.attempt));
        }

        return { outcome, willBeRetried, tags };
    }

    private absolutePathFrom(relativePath: string): Path {
        return Path.from(this.cwd).resolve(Path.from(relativePath));
    }
}

function flatten<T>(listOfLists: T[][]): T[] {
    return listOfLists.reduce((acc, current) => acc.concat(current), []);
}
