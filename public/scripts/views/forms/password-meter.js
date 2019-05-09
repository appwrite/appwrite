(function (window) {
    "use strict";

    window.Litespeed.container.get('view').add(
        {
            selector: 'data-forms-password-meter',
            repeat: false,
            controller: function(element, window) {
                var calc = function(password) {
                    var score = 0;
                    if (!password)
                        return score;

                    // award every unique letter until 5 repetitions
                    var letters = new window.Object();
                    for (var i=0; i<password.length; i++) {
                        letters[password[i]] = (letters[password[i]] || 0) + 1;
                        score += 5.0 / letters[password[i]];
                    }

                    // bonus points for mixing it up
                    var variations = {
                        digits: /\d/.test(password),
                        lower: /[a-z]/.test(password),
                        upper: /[A-Z]/.test(password),
                        nonWords: /\W/.test(password)
                    };

                    var variationCount = 0;

                    for (var check in variations) {
                        if (variations.hasOwnProperty(check)) {
                            variationCount += (variations[check] === true) ? 1 : 0;
                        }
                    }

                    score += (variationCount - 1) * 10;

                    return parseInt(score);
                };

                var callback = function() {
                    var score = calc(this.value);

                    if('' === this.value)
                        return meter.className = 'password-meter';
                    if (score > 60)
                        return meter.className = 'password-meter strong';
                    if (score > 30)
                        return meter.className = 'password-meter medium';
                    if (score >= 0)
                        return meter.className = 'password-meter weak';
                };

                var meter = window.document.createElement('div');

                meter.className = 'password-meter';

                element.parentNode.insertBefore(meter, element.nextSibling);

                element.addEventListener('change', callback);
                element.addEventListener('keypress', callback);
                element.addEventListener('keyup', callback);
                element.addEventListener('keydown', callback);
            }
        }
    );

})(window);