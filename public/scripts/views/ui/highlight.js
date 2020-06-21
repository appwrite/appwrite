(function (window) {
  window.ls.container.get("view").add({
    selector: "data-ui-highlight",
    controller: function (element, expression, document) {
      let check = function () {
        let links = element.getElementsByTagName("a");
        let selected = null;
        let list = [];
        
        for (let i = 0; i < links.length; i++) {
          list.push(links[i]);
        }

        list.sort(function (a, b) {
          return a.pathname.length - b.pathname.length;
        });


        if(selected && list[selected].dataset["selected"]) {
            let parent = element.querySelector("a[href='"+list[selected].dataset["selected"]+"']");

            if(parent) {
                parent.classList.remove("selected");
            }
        }

        for (let i = 0; i < list.length; i++) {
          let path = list[i].pathname;
          
          if (path === window.location.pathname.substring(0, path.length)) {
            list[i].classList.add("selected");

            if (selected !== null) {
                list[selected].classList.remove("selected");
            }
            selected = i;
          } else {
            list[i].classList.remove("selected");
          }
        }

        if(selected && list[selected].dataset["selected"]) {
            let parent = element.querySelector("a[href='"+list[selected].dataset["selected"]+"']");

            if(parent) {
                parent.classList.add("selected");
            }
        }
      };
      document.addEventListener("state-changed", check);
      check();
    },
  });
})(window);