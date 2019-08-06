(function (window) {
    "use strict";

    window.ls.view.add({
        selector: 'data-general-oauth',
        repeat: false,
        controller: function (element, env, expression) {
            let provider = expression.parse(element.dataset['authOauth'] || '');
            let success = expression.parse(element.dataset['success'] || '');
            let failure = expression.parse(element.dataset['failure'] || '');

            element.href = env.API + '/oauth/' + provider + '?project=' + env.PROJECT
                + '&success=' + encodeURIComponent(success)
                + '&failure=' + encodeURIComponent(failure)
            ;
        }
    });

})(window);