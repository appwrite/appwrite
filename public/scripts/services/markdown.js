(function (window) {
    "use strict";

    window.ls.container.set('markdown', function (window) {
        var md = window.markdownit();

        function renderEm (tokens, idx, opts, _, slf) {
            var token = tokens[idx];
            if (token.markup === '__') {
                token.tag = 'u';
            }
            return slf.renderToken(tokens, idx, opts);
        }

        md.renderer.rules.strong_open = renderEm;
        md.renderer.rules.strong_close = renderEm;

        return md;
    }, true);

})(window);