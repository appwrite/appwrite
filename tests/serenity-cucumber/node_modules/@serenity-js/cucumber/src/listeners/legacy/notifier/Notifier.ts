import type {
    DomainEvent} from '@serenity-js/core/lib/events';
import {
    FeatureNarrativeDetected,
    SceneDescriptionDetected,
    SceneFinished,
    SceneFinishes,
    SceneParametersDetected,
    SceneSequenceDetected,
    SceneStarts,
    SceneTagged,
    SceneTemplateDetected,
    TaskFinished,
    TaskStarts,
    TestRunFinished,
    TestRunFinishes,
    TestRunnerDetected,
    TestRunStarts,
} from '@serenity-js/core/lib/events';
import type { CorrelationId, Outcome} from '@serenity-js/core/lib/model';
import { ActivityDetails, CapabilityTag, Category, Description, FeatureTag, Name, ScenarioDetails, Tag, ThemeTag } from '@serenity-js/core/lib/model';
import type { Serenity } from '@serenity-js/core/lib/Serenity';

import type { Feature, FeatureFileNode, Scenario, ScenarioOutline, Step } from '../gherkin';

function notEmpty<T>(list: T[]) {
    return list.filter(item => !! item);
}

/**
 * @private
 */
export class Notifier {
    private currentSceneId: CorrelationId;
    private currentScenario: ScenarioDetails;
    private currentStepActivityId: CorrelationId;

    constructor(private readonly serenity: Serenity) {
    }

    testRunStarts(): void {
        this.emit(
            new TestRunStarts(this.serenity.currentTime()),
        );
    }

    outlineDetected(sceneId: CorrelationId, scenario: Scenario, outline: ScenarioOutline, feature: Feature): void {
        const
            outlineDetails  = this.detailsOf(outline, feature),
            scenarioDetails = this.detailsOf(scenario, feature),
            template        = outline.steps.map(step => step.name.value).join('\n');

        this.emit(...notEmpty([
            new SceneSequenceDetected(sceneId, outlineDetails, this.serenity.currentTime()),
            new SceneTemplateDetected(sceneId, new Description(template), this.serenity.currentTime()),
            new SceneParametersDetected(
                sceneId,
                scenarioDetails,
                outline.parameters[ scenario.location.line ],
                this.serenity.currentTime(),
            ),
        ]));
    }

    scenarioStarts(sceneId: CorrelationId, scenario: Scenario, feature: Feature): void {
        this.currentSceneId = sceneId;

        const details = this.detailsOf(scenario, feature);

        this.currentScenario = details;

        // todo: emit SceneBackgroundDetected?

        this.emit(...notEmpty([
            new SceneStarts(this.currentSceneId, details, this.serenity.currentTime()),
            feature.description && new FeatureNarrativeDetected(this.currentSceneId, feature.description, this.serenity.currentTime()),
            new TestRunnerDetected(this.currentSceneId, new Name('JS'), this.serenity.currentTime()),
            ...this.scenarioHierarchyTagsFor(feature).map(tag => new SceneTagged(this.currentSceneId, tag, this.serenity.currentTime())),
            !! scenario.description && new SceneDescriptionDetected(this.currentSceneId, scenario.description, this.serenity.currentTime()),
            ...scenario.tags.map(tag => new SceneTagged(this.currentSceneId, tag, this.serenity.currentTime())),
        ]));
    }

    stepStarts(step: Step): void {
        const activityDetails = new ActivityDetails(step.name, step.location);

        this.currentStepActivityId = this.serenity.assignNewActivityId(activityDetails);

        this.emit(
            new TaskStarts(
                this.currentSceneId,
                this.currentStepActivityId,
                activityDetails,
                this.serenity.currentTime()
            ),
        );
    }

    stepFinished(step: Step, outcome: Outcome): void {
        this.emit(
            new TaskFinished(
                this.currentSceneId,
                this.currentStepActivityId,
                new ActivityDetails(
                    step.name,
                    step.location,
                ),
                outcome,
                this.serenity.currentTime(),
            ),
        );
    }

    scenarioFinishes(): void {
        this.emitSceneFinishes();
    }

    scenarioFinished(scenario: Scenario, feature: Feature, outcome: Outcome): void {
        const details = this.detailsOf(scenario, feature);

        this.emit(
            new SceneFinished(
                this.currentSceneId,
                details,
                outcome,
                this.serenity.currentTime(),
            ),
        );
    }

    testRunFinishes(): void {
        this.emit(
            new TestRunFinishes(this.serenity.currentTime()),
        );
    }

    testRunFinished(outcome: Outcome): void {
        this.emit(
            new TestRunFinished(outcome, this.serenity.currentTime()),
        );
    }

    private emitSceneFinishes(): void {
        this.emit(
            new SceneFinishes(
                this.currentSceneId,
                this.serenity.currentTime(),
            ),
        );
    }

    private detailsOf(scenario: FeatureFileNode, feature: Feature): ScenarioDetails {
        return new ScenarioDetails(
            scenario.name,
            new Category(feature.name.value),
            scenario.location,
        );
    }

    private scenarioHierarchyTagsFor(feature: Feature): Tag[] {
        const
            directories     = notEmpty(feature.location.path.directory().split()),
            featuresIndex   = directories.indexOf('features'),
            hierarchy       = [ ...directories.slice(featuresIndex + 1), feature.name.value ] as string[];

        const [ featureName, capabilityName, themeName ]: string[] = hierarchy.reverse();

        return notEmpty([
            themeName       && Tag.humanReadable(ThemeTag, themeName),
            capabilityName  && Tag.humanReadable(CapabilityTag, capabilityName),
            feature         && new FeatureTag(featureName),
        ]);
    }

    private emit(...events: DomainEvent[]) {
        events.forEach(event => this.serenity.announce(event));
    }
}
