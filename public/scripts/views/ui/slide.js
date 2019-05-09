(function (window) {
    window.Litespeed.container.get('view').add(
        {
            repeat: true,
            selector: 'data-ui-slide',
            controller: function(element, window) {
                var slides = element.getElementsByTagName('img');
                var paging = document.createElement('div');
                var interval = null;
                var auto = true;

                paging.className = 'paging';

                element.appendChild(paging);

                for (var i = 0; i < slides.length; i++) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.innerHTML = i.toString();
                    button.className = 'dot';
                    paging.appendChild(button);

                    button.addEventListener('click', (function (i) {
                        return function (event) {
                            auto = false;
                            window.clearTimeout(interval);
                            move(i);
                        }
                    })(i));
                }

                function move(index) {
                    for (var i = 0; i < slides.length; i++) {
                        if (index === i) {
                            slides[index].classList.add('visible-fade');
                            slides[index].classList.remove('hidden-fade');
                            paging.children[i].className = 'selected';
                        }
                        else {
                            slides[i].classList.remove('visible-fade');
                            slides[i].classList.add('hidden-fade');
                            paging.children[i].className = '';
                        }
                    }

                    index++;

                    if(index >= i) {
                        index = 0;
                    }

                    if(auto) {
                        interval = window.setTimeout(function () {
                            move(index++)
                        }, 7000); // Change image every 2 seconds
                    }
                }

                move(0);
            }
        }
    );

})(window);