"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const assert_1 = __importDefault(require("assert"));
const GherkinDocumentWalker_1 = __importStar(require("../src/GherkinDocumentWalker"));
const pretty_1 = __importDefault(require("../src/pretty"));
const parse_1 = __importDefault(require("./parse"));
describe('GherkinDocumentWalker', () => {
    let walker;
    beforeEach(() => {
        walker = new GherkinDocumentWalker_1.default();
    });
    function assertCopy(copy, source) {
        assert_1.default.deepEqual(copy, source);
        assert_1.default.notEqual(copy, source);
    }
    it('returns a deep copy', () => {
        const gherkinDocument = (0, parse_1.default)(`@featureTag
Feature: hello
  This feature has a description

  Background: Base Background
    This is a described background
    Given a passed step

  @scenarioTag
  Scenario: salut
    Yes, there is a description here too

  @ruleTag
  Rule: roule
    Can we describe a Rule ?

    Background: poupidou
    Scenario: pouet
`);
        const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
        assertCopy(newGherkinDocument, gherkinDocument);
        assertCopy(newGherkinDocument.feature, gherkinDocument.feature);
        assertCopy(newGherkinDocument.feature.children[0].background, gherkinDocument.feature.children[0].background);
        assertCopy(newGherkinDocument.feature.children[1].scenario, gherkinDocument.feature.children[1].scenario);
        assertCopy(newGherkinDocument.feature.children[2].rule, gherkinDocument.feature.children[2].rule);
        assertCopy(newGherkinDocument.feature.children[2].rule.children[1], gherkinDocument.feature.children[2].rule.children[1]);
        assertCopy(newGherkinDocument.feature.children[0].background.steps, gherkinDocument.feature.children[0].background.steps);
    });
    context('filtering objects', () => {
        it('filters one scenario', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptScenario: (scenario) => scenario.name === 'Earth' }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Scenario: Earth
    Given is a planet with liquid water
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        it('keeps scenario with search hit in step', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptStep: (step) => step.text.includes('liquid') }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Scenario: Earth
    Given is a planet with liquid water
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        it('does not leave null object as a feature child', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptScenario: (scenario) => scenario.name === 'Earth' }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            assert_1.default.deepStrictEqual(newGherkinDocument.feature.children.filter((child) => child === null), []);
        });
        it('keeps a hit scenario even when no steps match', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptScenario: (scenario) => scenario.name === 'Saturn' }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        // TODO before merging https://github.com/cucumber/cucumber/pull/1419
        xit('keeps a hit background', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy
    Background: Milky Way
      Given it contains our system

  Rule: Black Hole
    Background: TON 618
      Given it exists
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), {
                acceptBackground: (background) => background.name === 'Milky Way',
            }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy

    Background: Milky Way
      Given it contains our system
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        it('keeps a hit in background step', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy
    Background: Milky Way
      Given it contains our system

  Rule: Black Hole
    Background: TON 618
      Given it exists
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptStep: (step) => step.text.includes('space') }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Background: Space
    Given space is real

  Rule: Galaxy

    Background: Milky Way
      Given it contains our system

  Rule: Black Hole

    Background: TON 618
      Given it exists
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        // TODO before merging https://github.com/cucumber/cucumber/pull/1419
        xit('keeps scenario in rule', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Milky Way
      Given it contains our system

    Scenario: Andromeda
      Given it exists
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptScenario: (scenario) => scenario.name === 'Andromeda' }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Andromeda
      Given it exists
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        it('keeps scenario and background in rule', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Milky Way
      Given it contains our system

    Scenario: Andromeda
      Given it exists
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptRule: (rule) => rule.name === 'Galaxy' }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Rule: Galaxy

    Background: TON 618
      Given it's a black hole

    Scenario: Milky Way
      Given it contains our system

    Scenario: Andromeda
      Given it exists
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        it('only keeps rule and its content', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Scenario: Milky Way
    Given it contains our system

  Rule: Galaxy

    Scenario: Andromeda
      Given it exists
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptRule: () => true }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Rule: Galaxy

    Scenario: Andromeda
      Given it exists
`;
            assert_1.default.strictEqual(newSource, expectedNewSource);
        });
        it('return a feature and keep scenario', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`);
            const walker = new GherkinDocumentWalker_1.default(Object.assign(Object.assign({}, GherkinDocumentWalker_1.rejectAllFilters), { acceptFeature: (feature) => feature.name === 'Solar System' }));
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            const newSource = (0, pretty_1.default)(newGherkinDocument, 'gherkin');
            const expectedNewSource = `Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`;
            assert_1.default.deepStrictEqual(newSource, expectedNewSource);
        });
        it('returns null when no hit found', () => {
            const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

  Scenario: Saturn
    Given is the sixth planet from the Sun

  Scenario: Earth
    Given is a planet with liquid water
`);
            const walker = new GherkinDocumentWalker_1.default(GherkinDocumentWalker_1.rejectAllFilters);
            const newGherkinDocument = walker.walkGherkinDocument(gherkinDocument);
            assert_1.default.deepEqual(newGherkinDocument, null);
        });
    });
    context('handling objects', () => {
        describe('handleStep', () => {
            it('is called for each steps', () => {
                const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

        Scenario: Earth
          Given it is a planet
`);
                const stepText = [];
                const astWalker = new GherkinDocumentWalker_1.default({}, {
                    handleStep: (step) => stepText.push(step.text),
                });
                astWalker.walkGherkinDocument(gherkinDocument);
                assert_1.default.deepEqual(stepText, ['it is a planet']);
            });
        });
        describe('handleScenario', () => {
            it('is called for each scenarios', () => {
                const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

        Scenario: Earth
          Given it is a planet

        Scenario: Saturn
          Given it's not a liquid planet
`);
                const scenarioName = [];
                const astWalker = new GherkinDocumentWalker_1.default({}, {
                    handleScenario: (scenario) => scenarioName.push(scenario.name),
                });
                astWalker.walkGherkinDocument(gherkinDocument);
                assert_1.default.deepEqual(scenarioName, ['Earth', 'Saturn']);
            });
        });
        describe('handleBackground', () => {
            it('is called for each backgrounds', () => {
                const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

        Background: Milky Way
          Scenario: Earth
            Given it is our galaxy
`);
                const backgroundName = [];
                const astWalker = new GherkinDocumentWalker_1.default({}, {
                    handleBackground: (background) => backgroundName.push(background.name),
                });
                astWalker.walkGherkinDocument(gherkinDocument);
                assert_1.default.deepEqual(backgroundName, ['Milky Way']);
            });
        });
        describe('handleRule', () => {
            it('is called for each rules', () => {
                const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

        Rule: On a planet
          Scenario: There is life
            Given there is water

        Rule: On an exoplanet
          Scenario: There is extraterrestrial life
            Given there is a non-humanoid form of life
`);
                const ruleName = [];
                const astWalker = new GherkinDocumentWalker_1.default({}, {
                    handleRule: (rule) => ruleName.push(rule.name),
                });
                astWalker.walkGherkinDocument(gherkinDocument);
                assert_1.default.deepEqual(ruleName, ['On a planet', 'On an exoplanet']);
            });
        });
        describe('handleFeature', () => {
            it('is called for each features', () => {
                const gherkinDocument = (0, parse_1.default)(`Feature: Solar System

        Rule: On a planet
          Scenario: There is life
            Given there is water

        Rule: On an exoplanet
          Scenario: There is extraterrestrial life
            Given there is a non-humanoid form of life
`);
                const featureName = [];
                const astWalker = new GherkinDocumentWalker_1.default({}, {
                    handleFeature: (feature) => featureName.push(feature.name),
                });
                astWalker.walkGherkinDocument(gherkinDocument);
                assert_1.default.deepEqual(featureName, ['Solar System']);
            });
        });
    });
    describe('regression tests', () => {
        it('does not fail with empty/commented documents', () => {
            const gherkinDocument = (0, parse_1.default)('# Feature: Solar System');
            const astWalker = new GherkinDocumentWalker_1.default();
            astWalker.walkGherkinDocument(gherkinDocument);
        });
    });
});
//# sourceMappingURL=GherkinDocumentWalkerTest.js.map