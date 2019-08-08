// Init

window.ls.error = function () {
    return function (error) {
        alert(error);
        console.error('ERROR-APP', error);
    }
};

window.addEventListener('error', function (event) {
    alert(event.error.message);
    console.error('ERROR-EVENT:', event.error.message, event.error.stack);
});

document.addEventListener('logout', function () {
    window.location = '/auth/signin';
});

document.addEventListener('http-get-401', function() { /* on error */
    document.dispatchEvent(new CustomEvent('logout'));
}, true);