"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
require("./styles.scss");
const react_components_1 = require("@cucumber/react-components");
const react_1 = __importDefault(require("react"));
const react_dom_1 = __importDefault(require("react-dom"));
const { CucumberReact } = react_components_1.components;
const { FilteredResults, EnvelopesWrapper, SearchWrapper } = react_components_1.components.app;
const app = (react_1.default.createElement(CucumberReact, { theme: "auto" },
    react_1.default.createElement(EnvelopesWrapper, { envelopes: window.CUCUMBER_MESSAGES },
        react_1.default.createElement(SearchWrapper, Object.assign({}, (0, react_components_1.searchFromURLParams)()),
            react_1.default.createElement(FilteredResults, { className: "html-formatter" })))));
react_dom_1.default.render(app, document.getElementById('content'));
//# sourceMappingURL=main.js.map