(function(window) {
    "use strict";
  
    window.ls.container.get("view").add({
      selector: "data-forms-nav",
      repeat: false,
      controller: function(element, view, container, document) {
            let titles = document.querySelectorAll('[data-forms-nav-anchor]');
            let links = element.querySelectorAll('[data-forms-nav-link]');
            let minLink = null;
            
            let check = function() {
                let minDistance = null;
                let minElement = null;
                
                for (let i = 0; i < titles.length; ++i) {
                    let title = titles[i];
                    let distance = title.getBoundingClientRect().top;

                    console.log(i);

                    if((minDistance === null || minDistance >= distance) && (distance >= 0)) {
                        if(minLink) {
                            minLink.classList.remove('selected');
                        }
                        console.log('old', minLink);
                        
                        minDistance = distance;
                        minElement = title;
                        minLink = links[i];

                        minLink.classList.add('selected');
                        console.log('new', minLink);
                    }
                }
            };

            window.addEventListener('scroll', check);

            check();
      }
    });
  })(window);
  