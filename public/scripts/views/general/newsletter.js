(function (window) {
    window.ls.container.get("view").add({
        selector: "data-newsletter",
        controller: function (element, router, document) {
            let subscribe = function (c) {
                var subscribe = document.getElementById('newsletter').checked;
                if (subscribe) {
                    var formData = new FormData(element);
                    var name = formData.get('name')
                    var email = formData.get('email');
                    console.log(name, email);
                    fetch('https://appwrite.io/v1/newsletter/subscribe', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            name: name,
                            email: email,
                        }),
                    })
                        .then((res) => res.json())
                        .then(data => console.log(data))
                        .catch(erro => console.log(error))
                }
            };

            element.addEventListener("submit", function () {
                subscribe();
            });
        }
    });
})(window);
