// Init
Raven.config('https://a9388b4e324f48f8afd4558cb8d3e8fc@sentry.io/1225344').install();

window.Litespeed = app(APP_ENV.VERSION);

window.Litespeed.error = function () {
    return function (error) {
        alert(error);
        console.error('ERROR-APP', error);
    }
};

window.addEventListener('error', function (event) {
    alert(error.error.message);
    console.error('ERROR-EVENT:', event.error.message, event.error.stack);
});

document.addEventListener('logout', function (event) {
    var state = window.Litespeed.container.get('state');

    if(state.getCurrent().view.scope === 'console') {
        state.change('/auth/signin');
    }
});

document.addEventListener('http-get-401', function() { /* on error */
    document.dispatchEvent(new CustomEvent('logout'));
}, true);