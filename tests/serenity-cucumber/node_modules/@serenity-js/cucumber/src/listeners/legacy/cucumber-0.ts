import { Path } from '@serenity-js/core/lib/io';
import { ExecutionFailedWithError, ExecutionSuccessful } from '@serenity-js/core/lib/model';

import { AmbiguousStepDefinitionError } from '../../errors';
import type { Dependencies } from './Dependencies';
import type { FeatureFileMap, Step } from './gherkin';
import { Feature, Scenario, ScenarioOutline } from './gherkin';

export = function ({ serenity, notifier, resultMapper, loader, cache }: Dependencies) {
    return function (): void {
        this.registerHandler('BeforeFeatures', () => {
            notifier.testRunStarts();
        });

        this.registerHandler('BeforeFeature', function (feature, callback) {
            loader.load(get(feature, 'uri').as(Path))
                .then(_ => callback(), error => callback(error));
        });

        this.registerHandler('BeforeScenario', function (scenario) {
            const
                path  = get(scenario, 'uri').as(Path),
                line  = get(scenario, 'line').value() as number,
                lines = get(scenario, 'lines').value() as number[],
                isOutline = lines.length === 2;

            const
                sceneId = serenity.assignNewSceneId(),
                map = cache.get(path);

            if (isOutline) {
                notifier.outlineDetected(sceneId, map.get(Scenario).onLine(line), map.get(ScenarioOutline).onLine(lines[ 1 ]), map.getFirst(Feature));
            }

            notifier.scenarioStarts(sceneId, map.get(Scenario).onLine(line), map.getFirst(Feature));
        });

        this.registerHandler('BeforeStep', function (step) {
            if (shouldIgnore(step)) {
                return void 0;
            }

            const
                scenario = get(step, 'scenario').value(),
                path     = get(scenario, 'uri').as(Path);

            notifier.stepStarts(findStepMatching(step, cache.get(path)));
        });

        this.registerHandler('StepResult', function (result) {
            const
                step     = get(result, 'step').value(),
                scenario = get(step, 'scenario').value(),
                path     = get(scenario, 'uri').as(Path);

            if (shouldIgnore(step)) {
                return void 0;
            }

            notifier.stepFinished(findStepMatching(step, cache.get(path)), resultMapper.outcomeFor(
                get(result, 'status').value(),
                get(result, 'failureException').value() || ambiguousStepsDetectedIn(result),
            ));
        });

        this.registerHandler('ScenarioResult', function (result, callback) {

            const
                scenario = get(result, 'scenario').value(),
                path     = get(scenario, 'uri').as(Path),
                line     = get(scenario, 'line').value() as number,
                outcome  = resultMapper.outcomeFor(
                    get(result, 'status').value(),
                    get(result, 'failureException').value()
                );

            const map = cache.get(path);

            notifier.scenarioFinishes();

            serenity.waitForNextCue()
                .then(
                    () => {
                        notifier.scenarioFinished(map.get(Scenario).onLine(line), map.getFirst(Feature), outcome);
                        callback();
                    },
                    error => {
                        notifier.scenarioFinished(map.get(Scenario).onLine(line), map.getFirst(Feature), outcome);
                        callback(error);
                    });
        });

        this.registerHandler('AfterFeatures', (features, callback) => {
            notifier.testRunFinishes();

            serenity.waitForNextCue()
                .then(
                    () => {
                        notifier.testRunFinished(new ExecutionSuccessful());
                        return callback();
                    },
                    error => {
                        notifier.testRunFinished(new ExecutionFailedWithError(error));
                        return callback(error);
                    }
                );
        });
    };
};

function get(object, property) {
    const getter = 'get' + property.charAt(0).toUpperCase() + property.slice(1);

    const value = object[getter]
        ? object[getter]()
        : object[property];

    return ({
        as: <T>(type: new (v: any) => T): T => new type(value),
        value: () => value,
    });
}

function is(object, property): boolean {
    const getter = 'is' + property.charAt(0).toUpperCase() + property.slice(1);
    return object[getter] ? object[getter]() : object[getter];
}

function findStepMatching(step, map: FeatureFileMap): Step {
    const
        stepLine     = get(step, 'line').value() as number,
        scenario     = get(step, 'scenario').value(),
        path         = get(scenario, 'uri').as(Path),
        scenarioLine = get(scenario, 'line').value() as number;

    const matchedStep = map.get(Scenario).onLine(scenarioLine).steps.find(s => s.location.line === stepLine);

    if (! matchedStep) {
        throw new Error(`No step was found in ${ path } on line ${ stepLine }. This looks like a bug.`);
    }

    return matchedStep;
}

function ambiguousStepsDetectedIn(result): Error | undefined {
    const ambiguousStepDefinitions = get(result, 'ambiguousStepDefinitions').value() || [];

    if (ambiguousStepDefinitions.length === 0) {
        return void 0;
    }

    return ambiguousStepDefinitions
        .map(step => `${ get(step, 'pattern').value().toString() } - ${ get(step, 'uri').value() }:${ get(step, 'line').value() }`)
        .reduce((error: Error, issue) => {
            error.message += `\n${issue}`;
            return error;
        }, new AmbiguousStepDefinitionError('Multiple step definitions match:'));
}

function shouldIgnore(step): boolean {
    return is(step, 'hidden')                                       // cucumber 0-1
        || (step.constructor && step.constructor.name === 'Hook');  // cucumber 2
}
