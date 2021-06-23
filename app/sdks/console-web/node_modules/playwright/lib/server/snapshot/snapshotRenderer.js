"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.SnapshotRenderer = void 0;
class SnapshotRenderer {
    constructor(contextResources, snapshots, index) {
        this._contextResources = contextResources;
        this._snapshots = snapshots;
        this._index = index;
        this.snapshotName = snapshots[index].snapshotName;
    }
    snapshot() {
        return this._snapshots[this._index];
    }
    render() {
        const visit = (n, snapshotIndex) => {
            // Text node.
            if (typeof n === 'string')
                return escapeText(n);
            if (!n._string) {
                if (Array.isArray(n[0])) {
                    // Node reference.
                    const referenceIndex = snapshotIndex - n[0][0];
                    if (referenceIndex >= 0 && referenceIndex < snapshotIndex) {
                        const nodes = snapshotNodes(this._snapshots[referenceIndex]);
                        const nodeIndex = n[0][1];
                        if (nodeIndex >= 0 && nodeIndex < nodes.length)
                            n._string = visit(nodes[nodeIndex], referenceIndex);
                    }
                }
                else if (typeof n[0] === 'string') {
                    // Element node.
                    const builder = [];
                    builder.push('<', n[0]);
                    for (const [attr, value] of Object.entries(n[1] || {}))
                        builder.push(' ', attr, '="', escapeAttribute(value), '"');
                    builder.push('>');
                    for (let i = 2; i < n.length; i++)
                        builder.push(visit(n[i], snapshotIndex));
                    if (!autoClosing.has(n[0]))
                        builder.push('</', n[0], '>');
                    n._string = builder.join('');
                }
                else {
                    // Why are we here? Let's not throw, just in case.
                    n._string = '';
                }
            }
            return n._string;
        };
        const snapshot = this._snapshots[this._index];
        let html = visit(snapshot.html, this._index);
        if (!html)
            return { html: '', resources: {} };
        if (snapshot.doctype)
            html = `<!DOCTYPE ${snapshot.doctype}>` + html;
        html += `
      <style>*[__playwright_target__="${this.snapshotName}"] { background-color: #6fa8dc7f; }</style>
      <script>${snapshotScript()}</script>
    `;
        const resources = {};
        for (const [url, contextResources] of this._contextResources) {
            const contextResource = contextResources.find(r => r.frameId === snapshot.frameId) || contextResources[0];
            if (contextResource)
                resources[url] = { resourceId: contextResource.resourceId };
        }
        for (const o of snapshot.resourceOverrides) {
            const resource = resources[o.url];
            resource.sha1 = o.sha1;
        }
        return { html, resources };
    }
}
exports.SnapshotRenderer = SnapshotRenderer;
const autoClosing = new Set(['AREA', 'BASE', 'BR', 'COL', 'COMMAND', 'EMBED', 'HR', 'IMG', 'INPUT', 'KEYGEN', 'LINK', 'MENUITEM', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR']);
const escaped = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' };
function escapeAttribute(s) {
    return s.replace(/[&<>"']/ug, char => escaped[char]);
}
function escapeText(s) {
    return s.replace(/[&<]/ug, char => escaped[char]);
}
function snapshotNodes(snapshot) {
    if (!snapshot._nodes) {
        const nodes = [];
        const visit = (n) => {
            if (typeof n === 'string') {
                nodes.push(n);
            }
            else if (typeof n[0] === 'string') {
                for (let i = 2; i < n.length; i++)
                    visit(n[i]);
                nodes.push(n);
            }
        };
        visit(snapshot.html);
        snapshot._nodes = nodes;
    }
    return snapshot._nodes;
}
function snapshotScript() {
    function applyPlaywrightAttributes(shadowAttribute, scrollTopAttribute, scrollLeftAttribute) {
        const scrollTops = [];
        const scrollLefts = [];
        const visit = (root) => {
            // Collect all scrolled elements for later use.
            for (const e of root.querySelectorAll(`[${scrollTopAttribute}]`))
                scrollTops.push(e);
            for (const e of root.querySelectorAll(`[${scrollLeftAttribute}]`))
                scrollLefts.push(e);
            for (const iframe of root.querySelectorAll('iframe')) {
                const src = iframe.getAttribute('src');
                if (!src) {
                    iframe.setAttribute('src', 'data:text/html,<body style="background: #ddd"></body>');
                }
                else {
                    // Append query parameters to inherit ?name= or ?time= values from parent.
                    iframe.setAttribute('src', window.location.origin + src + window.location.search);
                }
            }
            for (const element of root.querySelectorAll(`template[${shadowAttribute}]`)) {
                const template = element;
                const shadowRoot = template.parentElement.attachShadow({ mode: 'open' });
                shadowRoot.appendChild(template.content);
                template.remove();
                visit(shadowRoot);
            }
        };
        visit(document);
        const onLoad = () => {
            window.removeEventListener('load', onLoad);
            for (const element of scrollTops) {
                element.scrollTop = +element.getAttribute(scrollTopAttribute);
                element.removeAttribute(scrollTopAttribute);
            }
            for (const element of scrollLefts) {
                element.scrollLeft = +element.getAttribute(scrollLeftAttribute);
                element.removeAttribute(scrollLeftAttribute);
            }
        };
        window.addEventListener('load', onLoad);
    }
    const kShadowAttribute = '__playwright_shadow_root_';
    const kScrollTopAttribute = '__playwright_scroll_top_';
    const kScrollLeftAttribute = '__playwright_scroll_left_';
    return `\n(${applyPlaywrightAttributes.toString()})('${kShadowAttribute}', '${kScrollTopAttribute}', '${kScrollLeftAttribute}')`;
}
//# sourceMappingURL=snapshotRenderer.js.map