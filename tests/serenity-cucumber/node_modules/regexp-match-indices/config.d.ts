/*!
Copyright 2019 Ron Buckton

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/
declare const config: {
    /**
     * Indicates the evaluation mode:
     * - `"lazy"` - The `"indices"` property is intially defined as an accessor-property on a result and is
     *   converted into a data-property when first accessed. This avoids an up-front performance penalty for
     *   all existing calls to `RegExp.prototype.exec` at the cost of spec compliance. This is the default.
     * - `"spec-compliant"` - Uses a more spec-complaint behavior that evaluates and stores `"indices"`
     *   immediately as a data-property. This can result in a performance penalty for existing calls to
     *   `RegExp.prototype.exec` that may not be already accessing `"indices"`.
     */
    mode: "lazy" | "spec-compliant";
};
export = config;
