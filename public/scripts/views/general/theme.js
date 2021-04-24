(function(window) {
  darkThemeMq = window.matchMedia("(prefers-color-scheme: light)");
  darkThemeMq.addEventListener("change", event => {
    if (event.matches) {
      document.body.classList.remove('theme-dark');
      document.body.classList.add('theme-light');
      window.localStorage.setItem('user-theme', 'theme-light');
    } else {
      document.body.classList.remove('theme-light');
      document.body.classList.add('theme-dark');
      window.localStorage.setItem('user-theme', 'theme-dark')
    }
  });

  window.ls.container.get("view").add({
    selector: "data-general-theme",
    controller: function(element, router, document) {
      let toggle = function(c) {
        if(document.body.classList.contains('theme-light')) {
          document.body.classList.remove('theme-light');
          document.body.classList.add('theme-dark');
          window.localStorage.setItem('user-theme', 'theme-dark')
        }
        else {
          document.body.classList.remove('theme-dark');
          document.body.classList.add('theme-light');
          window.localStorage.setItem('user-theme', 'theme-light')
        }
      };

      element.addEventListener("click", function() {
        toggle();
      });
    }
  });
})(window);
