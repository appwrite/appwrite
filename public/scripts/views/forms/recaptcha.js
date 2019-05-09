(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-recaptcha',
            repeat: false,
            controller: function(element, document, window) {
                var form    = document.getElementById(element.dataset['formsRecaptcha'] || '');
                var captcha = document.createElement('input');

                captcha.type = 'hidden';
                captcha.name = 'g-recaptcha-response';
                element.parentNode.insertBefore(captcha, element.nextSibling);

                var render = function() {
                    window.grecaptcha.render(element, {
                        'sitekey': element.dataset['sitekey'] || '',
                        'size': 'invisible',
                        'badge': 'inline',
                        'callback': function (token) {
                            captcha.value = token;
                            form.submit();
                        }
                    });
                };

                if(window.grecaptchaReady) {
                    render();
                }
                else {
                    document.addEventListener('recaptcha-loaded', render);
                }

                form.addEventListener('submit', function () {
                    if('' === captcha.value) {
                        event.preventDefault(); //prevent form submit before captcha is completed
                        window.grecaptcha.execute();
                    }
                });
            }
        }
    );

})(window);