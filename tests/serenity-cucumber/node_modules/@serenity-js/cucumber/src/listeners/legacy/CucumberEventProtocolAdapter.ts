import { RuntimeError } from '@serenity-js/core';
import { AssertionError, ErrorSerialiser, ImplementationPendingError, TestCompromisedError } from '@serenity-js/core/lib/errors';
import { FileSystemLocation, Path } from '@serenity-js/core/lib/io';
import type {
    Outcome} from '@serenity-js/core/lib/model';
import {
    ExecutionCompromised,
    ExecutionFailedWithAssertionError,
    ExecutionFailedWithError,
    ExecutionSkipped,
    ExecutionSuccessful,
    ImplementationPending,
    Name
} from '@serenity-js/core/lib/model';
import { ensure, isDefined } from 'tiny-types';

import { AmbiguousStepDefinitionError } from '../../errors';
import type { CucumberFormatterOptions } from './CucumberFormatterOptions';
import type { Dependencies } from './Dependencies';
import { Feature, Hook, Scenario, ScenarioOutline, Step } from './gherkin';

interface Location {
    uri: string;
    line: number;
}

interface StepLocations {
    actionLocation?: Location;
    sourceLocation?: Location;
}

/**
 * @private
 */
export function cucumberEventProtocolAdapter({ serenity, notifier, mapper, cache }: Dependencies) { // eslint-disable-line @typescript-eslint/explicit-module-boundary-types
    return class CucumberEventProtocolAdapter {

        // note: exported class expression can't have private properties
        public readonly log: any;

        constructor({ eventBroadcaster, log }: CucumberFormatterOptions) {
            this.log = log;

            eventBroadcaster.on('gherkin-document', ({ uri, document }) => {
                ensure('gherkin-document :: uri', uri, isDefined());
                ensure('gherkin-document :: document', document, isDefined());

                const path = new Path(uri);
                cache.set(path, mapper.map(document, path));    // eslint-disable-line unicorn/no-array-method-this-argument
            });

            eventBroadcaster.on('test-case-prepared', ({ steps, sourceLocation }: {
                steps: StepLocations[],
                sourceLocation: Location,
            }) => {
                ensure('test-case-prepared :: steps', steps, isDefined());
                ensure('test-case-prepared :: sourceLocation', sourceLocation, isDefined());

                const
                    path = new Path(sourceLocation.uri),
                    map = cache.get(path),
                    scenario = map.get(Scenario).onLine(sourceLocation.line);

                if (scenario.outline) {
                    const outline = map.get(ScenarioOutline).onLine(scenario.outline.line);

                    map.set(new ScenarioOutline(
                        outline.location,
                        outline.name,
                        outline.description,
                        outline.steps,
                        outline.parameters,
                    )).onLine(scenario.outline.line);
                }

                map.set(new Scenario(
                    scenario.location,
                    scenario.name,
                    scenario.description,
                    interleaveStepsAndHooks(scenario.steps, steps),
                    scenario.tags,
                    scenario.outline,
                )).onLine(sourceLocation.line);
            });

            eventBroadcaster.on('test-case-started', ({ sourceLocation }) => {
                ensure('test-case-started :: sourceLocation', sourceLocation, isDefined());

                const
                    map = cache.get(new Path(sourceLocation.uri)),
                    scenario = map.get(Scenario).onLine(sourceLocation.line),
                    sceneId = serenity.assignNewSceneId();

                if (scenario.outline) {
                    const outline = map.get(ScenarioOutline).onLine(scenario.outline.line);
                    notifier.outlineDetected(sceneId, scenario, outline, map.getFirst(Feature));
                }

                notifier.scenarioStarts(sceneId, scenario, map.getFirst(Feature));
            });

            eventBroadcaster.on('test-step-started', ({ index, testCase }) => {

                ensure('test-step-started :: index', index, isDefined());
                ensure('test-step-started :: testCase', testCase, isDefined());

                const
                    map      = cache.get(new Path(testCase.sourceLocation.uri)),
                    scenario = map.get(Scenario).onLine(testCase.sourceLocation.line),
                    step     = scenario.steps[index];

                if (step instanceof Step) { // ignore hooks
                    notifier.stepStarts(step);
                }
            });

            eventBroadcaster.on('test-step-finished', ({ index, result, testCase }) => {

                ensure('test-step-finished :: index', index, isDefined());
                ensure('test-step-finished :: result', result, isDefined());
                ensure('test-step-finished :: testCase', testCase, isDefined());

                const
                    map      = cache.get(new Path(testCase.sourceLocation.uri)),
                    scenario = map.get(Scenario).onLine(testCase.sourceLocation.line),
                    step     = scenario.steps[index];

                if (step instanceof Step) { // ignore hooks
                    notifier.stepFinished(step, this.outcomeFrom(result));
                }
            });

            eventBroadcaster.on('test-case-finished', ({ result, sourceLocation }) => {

                ensure('test-case-finished :: result', result, isDefined());
                ensure('test-case-finished :: sourceLocation', sourceLocation, isDefined());

                const
                    map             = cache.get(new Path(sourceLocation.uri)),
                    scenario        = map.get(Scenario).onLine(sourceLocation.line),
                    nonHookSteps    = scenario.steps.filter(step => step instanceof Step);

                const outcome: Outcome = nonHookSteps.length > 0
                    ? this.outcomeFrom(result)
                    : new ImplementationPending(new ImplementationPendingError(`"${ scenario.name.value }" has no test steps`));

                notifier.scenarioFinished(scenario, map.getFirst(Feature), outcome);
            });
        }

        outcomeFrom(result: { duration: number, exception: string | Error, status: string }): Outcome {
            const error = !! result.exception && this.errorFrom(result.exception);

            switch (result.status) {
                case 'undefined':
                    return new ImplementationPending(new ImplementationPendingError('Step not implemented'));

                case 'ambiguous':
                case 'failed':
                    switch (true) {
                        case error instanceof AssertionError:       return new ExecutionFailedWithAssertionError(error as AssertionError);
                        case error instanceof TestCompromisedError: return new ExecutionCompromised(error as TestCompromisedError);
                        default:                                    return new ExecutionFailedWithError(error);
                    }

                case 'pending':
                    return new ImplementationPending(new ImplementationPendingError('Step not implemented'));

                case 'skipped':
                    return new ExecutionSkipped();

                // case 'passed':
                default:
                    return new ExecutionSuccessful();
            }

        }

        errorFrom(maybeError: Error | string): Error {

            switch (true) {
                case maybeError instanceof RuntimeError:
                    return maybeError as Error;
                case maybeError instanceof Error && maybeError.name === 'AssertionError' && maybeError.message && hasOwnProperty(maybeError, 'expected') && hasOwnProperty(maybeError, 'actual'):
                    return serenity.createError(AssertionError, {
                        message: (maybeError as any).message,
                        diff: {
                            expected: (maybeError as any).expected,
                            actual: (maybeError as any).actual,
                        },
                        cause: maybeError as Error
                    });
                case typeof maybeError === 'string' && maybeError.startsWith('Multiple step definitions match'):
                    return new AmbiguousStepDefinitionError(maybeError as string);
                default:
                    return ErrorSerialiser.deserialiseFromStackTrace(maybeError as string);
            }
        }
    };
}

/**
 * @private
 */
function interleaveStepsAndHooks(steps: Step[], stepsLocations: StepLocations[]): Array<Step | Hook> {
    const
        isAHook = (stepLocations: StepLocations) =>
            stepLocations.actionLocation && ! stepLocations.sourceLocation,
        matching  = (location: StepLocations) =>
            (step: Step) =>
                step.location.path.equals(new Path(location.sourceLocation.uri)) &&
                step.location.line === location.sourceLocation.line;

    return stepsLocations.map(location =>
        isAHook(location)
            ?   new Hook(new FileSystemLocation(new Path(location.actionLocation.uri), location.actionLocation.line), new Name('Setup'))
            :   steps.find(matching(location)),
    );
}

/**
 * @private
 */
function hasOwnProperty(value: any, fieldName: string): boolean {
    return Object.prototype.hasOwnProperty.call(value, fieldName);
}
