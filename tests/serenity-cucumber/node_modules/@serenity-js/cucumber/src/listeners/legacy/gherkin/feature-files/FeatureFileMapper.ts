import type { Path } from '@serenity-js/core/lib/io';
import { FileSystemLocation } from '@serenity-js/core/lib/io';
import type { Tag} from '@serenity-js/core/lib/model';
import { Description, Name, ScenarioParameters, Tags } from '@serenity-js/core/lib/model';

import { Background, Feature, Scenario, ScenarioOutline, Step } from '../model';
import type * as nodes from '../nodes';
import { FeatureFileMap } from './FeatureFileMap';

/**
 * @private
 */
export class FeatureFileMapper {
    map(document: nodes.GherkinDocument, path: Path): FeatureFileMap {

        const map = new FeatureFileMap();

        if (! (document && document.feature)) {
            return map;
        }

        let background: Background;

        document.feature.children.forEach(scenarioDefinition => {
            switch (scenarioDefinition.type) {

                case 'Background':

                    background = new Background(
                        new FileSystemLocation(
                            path,
                            scenarioDefinition.location.line,
                            scenarioDefinition.location.column,
                        ),
                        new Name(scenarioDefinition.name),
                        scenarioDefinition.description && new Description(scenarioDefinition.description),
                        scenarioDefinition.steps.map(step => this.asStep(path, step)),
                    );

                    map.set(background).onLine(scenarioDefinition.location.line);

                    break;

                case 'Scenario':

                    map.set(new Scenario(
                        new FileSystemLocation(
                            path,
                            scenarioDefinition.location.line,
                            scenarioDefinition.location.column,
                        ),
                        new Name(scenarioDefinition.name),
                        scenarioDefinition.description && new Description(scenarioDefinition.description),
                        (background ? background.steps : []).concat(scenarioDefinition.steps.map(step => this.asStep(path, step))),
                        this.tagsFrom(document.feature.tags, scenarioDefinition.tags),
                    )).onLine(scenarioDefinition.location.line);

                    break;

                case 'ScenarioOutline': {

                    const
                        outline = scenarioDefinition as nodes.ScenarioOutline,
                        parameters: { [line: number]: ScenarioParameters } = {};

                    // @see https://github.com/cucumber/gherkin-javascript/blob/v5.1.0/lib/gherkin/pickles/compiler.js#L45
                    outline.examples.filter(e => e.tableHeader !== undefined).forEach(examples => {

                        const
                            exampleSetName = new Name(examples.name),
                            exampleSetDescription = new Description(examples.description || ''),
                            variableCells = examples.tableHeader.cells;

                        examples.tableBody.forEach(values => {
                            const
                                valueCells = values.cells,
                                steps = background ? background.steps : [];

                            outline.steps.forEach(scenarioOutlineStep => {
                                const
                                    interpolatedStepText = this.interpolate(scenarioOutlineStep.text, variableCells, valueCells),
                                    interpolatedStepArgument = this.interpolateStepArgument(scenarioOutlineStep.argument, variableCells, valueCells);

                                steps.push(new Step(
                                    new FileSystemLocation(
                                        path,
                                        scenarioOutlineStep.location.line,
                                        scenarioOutlineStep.location.column,
                                    ),
                                    new Name([
                                        scenarioOutlineStep.keyword,
                                        interpolatedStepText,
                                        interpolatedStepArgument,
                                    ].filter(_ => !!_).join('')),
                                ));
                            });

                            const scenarioParameters = variableCells
                                .map((cell, i) => ({ [cell.value]: valueCells[i].value }))
                                .reduce((acc, current) => {
                                    return {...acc, ...current};
                                }, {});

                            parameters[values.location.line] = new ScenarioParameters(
                                exampleSetName,
                                exampleSetDescription,
                                scenarioParameters,
                            );

                            map.set(new Scenario(
                                new FileSystemLocation(
                                    path,
                                    values.location.line,
                                    values.location.column,
                                ),
                                new Name(outline.name),
                                outline.description && new Description(outline.description),
                                steps,
                                this.tagsFrom(document.feature.tags, outline.tags, examples.tags),
                                new FileSystemLocation(
                                    path,
                                    outline.location.line,
                                    outline.location.column,
                                ),
                            )).onLine(values.location.line);
                        });
                    });

                    map.set(new ScenarioOutline(
                        new FileSystemLocation(
                            path,
                            outline.location.line,
                            outline.location.column,
                        ),
                        new Name(outline.name),
                        outline.description && new Description(outline.description),
                        (background ? background.steps : []).concat(outline.steps.map(step => this.asStep(path, step, [], []))),
                        parameters,
                    )).onLine(scenarioDefinition.location.line);

                    break;
                }
            }
        });

        map.set(new Feature(
            new FileSystemLocation(
                path,
                document.feature.location.line,
                document.feature.location.column,
            ),
            new Name(document.feature.name),
            document.feature.description && new Description(document.feature.description),
            background,
        )).onLine(document.feature.location.line);

        return map;
    }

    private asStep(path: Path, step: nodes.Step, variableCells: nodes.TableCell[] = [], valueCells: nodes.TableCell[] = []): Step {
        return new Step(
            new FileSystemLocation(
                path,
                step.location.line,
                step.location.column,
            ),
            new Name([
                step.keyword,
                step.text,
                this.interpolateStepArgument(step.argument, variableCells, valueCells),
            ].filter(_ => !!_).join('')),
        );
    }

    private tagsFrom(...listsOfTags: nodes.Tag[][]): Tag[] {
        return flattened(
            flattened(listsOfTags).map(tag => Tags.from(tag.name)),
        );
    }

    private interpolateStepArgument(argument: nodes.StepArgument, variableCells: nodes.TableCell[], valueCells: nodes.TableCell[]): string {
        switch (true) {
            case argument && argument.type === 'DocString':
                return '\n' + this.interpolate((argument as nodes.DocString).content, variableCells, valueCells) ;
            case argument && argument.type === 'DataTable':
                return '\n' + this.interpolate(
                    (argument as nodes.DataTable).rows
                        .map(row => `| ${ row.cells.map(cell => cell.value).join(' | ') } |`)
                        .join('\n'),
                    variableCells,
                    valueCells,
                );
            default:
                return '';
        }
    }

    // @see https://github.com/cucumber/gherkin-javascript/blob/v5.1.0/lib/gherkin/pickles/compiler.js#L115
    private interpolate(text: string, variableCells: nodes.TableCell[], valueCells: nodes.TableCell[]) {
        variableCells.forEach((variableCell, n) => {
            const valueCell = valueCells[n];
            const search = new RegExp('<' + variableCell.value + '>', 'g');
            // JS Specific - dollar sign needs to be escaped with another dollar sign
            // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace#Specifying_a_string_as_a_parameter
            const replacement = valueCell.value.replaceAll(new RegExp('\\$', 'g'), '$$$$');
            text = text.replace(search, replacement);
        });
        return text;
    }
}

/**
 * @private
 */
function flattened<T>(listsOfLists: T[][]): T[] {
    return listsOfLists.reduce((acc, list) => acc.concat(list), []);
}
