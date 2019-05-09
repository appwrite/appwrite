
let date        = window.ls.container.get('date');
let timezone    = window.ls.container.get('timezone');
let markdown    = window.ls.container.get('markdown');

window.ls.filter
    .add('lowerCase', function ($value) {
        return $value.toLowerCase();
    })
    .add('date', function ($value) {
        return date.format('Y-m-d', $value);
    })
    .add('date-time', function ($value) {
        return date.format('Y-m-d H:i', $value);
    })
    .add('date-text', function ($value) {
        return date.format('d M Y', $value);
    })
    .add('date-long', function ($value) {
        return date.format('l, j F, H:i', $value);
    })
    .add('min2hum', function ($value) {
        if($value >= 60) {
            if($value % 60 === 0) {
                return Math.ceil($value / 60) + ' hours';
            }
            else {
                return Math.ceil($value / 60) + ' hours and ' + ($value % 60) + ' minutes';
            }
        }

        return $value + ' minutes';
    })
    .add('ms2hum', function ($value) {
        let temp = $value;
        const years = Math.floor( temp / 31536000 ),
            days = Math.floor( ( temp %= 31536000 ) / 86400 ),
            hours = Math.floor( ( temp %= 86400 ) / 3600 ),
            minutes = Math.floor( ( temp %= 3600 ) / 60 ),
            seconds = temp % 60;

        if ( days || hours || seconds || minutes ) {
            return ( years ? years + "y " : "" ) +
                ( days ? days + "d " : "" ) +
                ( hours ? hours + "h " : ""  ) +
                ( minutes ? minutes + "m " : "" ) +
                Number.parseFloat( seconds ).toFixed(0) + "s";
        }

        return "< 1s";
    })
    .add('nl2p', function ($value) {
        let result = "<p>" + $value + "</p>";
        result = result.replace(/\r\n\r\n/g, "</p><p>").replace(/\n\n/g, "</p><p>");
        result = result.replace(/\r\n/g, "<br />").replace(/\n/g, "<br />");

        return result;
    })
    .add('markdown', function ($value) {
        return markdown.render($value);
    })
    .add('id2name', function ($value) {
        let members = container.get('members');

        if(members === null) {
            return '';
        }

        for (let y = 0; y < members.length; y++) {
            if(members[y]['$uid'] === $value) {
                $value = members[y].name;
            }
        }

        return $value;
    })
    .add('id2role', function ($value) {
        if(APP_ENV.ROLES[$value]) {
            return APP_ENV.ROLES[$value];
        }

        return '';
    })
    .add('humanFileSize', function (bytes) {
        if(!bytes) {
            return 0;
        }

        let thresh = 1000;

        if(Math.abs(bytes) < thresh) {
            return bytes + ' B';
        }

        let units = ['kB','MB','GB','TB','PB','EB','ZB','YB'];
        let u = -1;

        do {
            bytes /= thresh;
            ++u;
        } while(Math.abs(bytes) >= thresh && u < units.length - 1);

        return bytes.toFixed(1) + '<span class="text-size-small unit">' + units[u] + '</span>';
    })
    .add('statsTotal', function ($value) {
        if(!$value) {
            return 0;
        }

        $value = abbreviate($value, 1, false, false);

        return ($value === '0') ? 'N/A' : $value;
    });

function abbreviate(number, maxPlaces, forcePlaces, forceLetter) {
    number = Number(number);
    forceLetter = forceLetter || false;
    if(forceLetter !== false) {
        return annotate(number, maxPlaces, forcePlaces, forceLetter);
    }
    let abbr;
    if(number >= 1e12) {
        abbr = 'T';
    }
    else if(number >= 1e9) {
        abbr = 'B';
    }
    else if(number >= 1e6) {
        abbr = 'M';
    }
    else if(number >= 1e3) {
        abbr = 'K';
    }
    else {
        abbr = '';
    }
    return annotate(number, maxPlaces, forcePlaces, abbr);
}

function annotate(number, maxPlaces, forcePlaces, abbr) {
    // set places to false to not round
    let rounded = 0;
    switch(abbr) {
        case 'T':
            rounded = number / 1e12;
            break;
        case 'B':
            rounded = number / 1e9;
            break;
        case 'M':
            rounded = number / 1e6;
            break;
        case 'K':
            rounded = number / 1e3;
            break;
        case '':
            rounded = number;
            break
    }
    if(maxPlaces !== false) {
        let test = new RegExp('\\.\\d{' + (maxPlaces + 1) + ',}$')
        if(test.test(('' + rounded))) {
            rounded = rounded.toFixed(maxPlaces)
        }
    }
    if(forcePlaces !== false) {
        rounded = Number(rounded).toFixed(forcePlaces)
    }
    return rounded + abbr
}