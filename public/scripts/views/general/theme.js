(function(window) {
  window.ls.container.get("view").add({
    selector: "data-general-theme",
    controller: function(element, router, document) {
      let setTheme = function (theme) {
        if (theme == "theme-dark") {
          if (document.body.classList.contains('theme-light')) {
            document.body.classList.remove('theme-light');
            document.body.classList.add('theme-dark');
          }
        } else {
          if (document.body.classList.contains('theme-dark')) {
            document.body.classList.remove('theme-dark');
            document.body.classList.add('theme-light');
          }
        }
      };

      lightThemeMq = window.matchMedia('(prefers-color-scheme: light)');
      lightThemeMq.addEventListener('change', event => {
          userTheme = window.localStorage.getItem('user-theme');
          if (userTheme == null || userTheme == 'theme-system') {
          if (event.matches) {
            setTheme('theme-light');
          } else {
            setTheme('theme-dark');
          }
        }
      });

      // Cycle system -> light -> dark -> repeat
      let cycle = function (c) {
        userTheme = window.localStorage.getItem('user-theme');
        if (userTheme == 'theme-system') {
          setTheme('theme-light');
          window.localStorage.setItem('user-theme', 'theme-light')
        } else if (userTheme == 'theme-light') {
          setTheme('theme-dark');
          window.localStorage.setItem('user-theme', 'theme-dark');
        } else {
          window.localStorage.setItem('user-theme', 'theme-system');
          if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
            setTheme('theme-light');
          } else {
            setTheme('theme-dark');
          }
        }
      };

      element.addEventListener("click", function() {
        cycle();
      });
    }
  });
})(window);
