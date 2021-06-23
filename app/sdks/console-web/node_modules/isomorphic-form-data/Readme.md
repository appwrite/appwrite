A library to create readable ```"multipart/form-data"``` isomorphically in node and the browser.

[xhr2-fd]: http://dev.w3.org/2006/webapi/XMLHttpRequest-2/Overview.html#the-formdata-interface
[streams2-thing]: http://nodejs.org/api/stream.html#stream_compatibility_with_older_node_versions

## Install

```
npm install isomorphic-form-data
```

## Usage

In this example we are constructing a form with 3 fields that contain a string,
a buffer and a file stream.

``` javascript
require('isomorphic-form-data');
var fs = require('fs');

var form = new FormData();
form.append('my_field', 'my value');
form.append('my_buffer', new Buffer(10));
form.append('my_file', fs.createReadStream('/foo/bar.jpg'));
```

#### node-fetch

You can submit a form using [node-fetch](https://github.com/matthew-andrews/isomorphic-fetch):

```javascript
var form = new FormData();

form.append('a', 1);

fetch('http://example.com', { method: 'POST', body: form })
    .then(function(res) {
        return res.json();
    }).then(function(json) {
        console.log(json);
    });
```

## License

Form-Data is licensed under the MIT license.
