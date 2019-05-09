// https://github.com/Fyrd/purejs-datalist-polyfill
// license: MIT
(function(document) {
    var IE_SELECT_ATTRIBUTE = 'data-datalist';
    var LIST_CLASS = 'datalist-polyfill';
    var ACTIVE_CLASS = 'datalist-polyfill__active';

    var datalistSupported = !!(document.createElement('datalist') && window.HTMLDataListElement);
    var ua = navigator.userAgent;

    // Android does not have actual support
    var isAndroidBrowser = ua.match(/Android/) && !ua.match(/(Firefox|Chrome|Opera|OPR)/);
    if( datalistSupported && !isAndroidBrowser ) {
        return;
    }

    var inputs = document.querySelectorAll('input[list]');

    var triggerEvent = function(elem, eventType) {
        var event;
        if (document.createEvent) {
            event = document.createEvent("HTMLEvents");
            event.initEvent(eventType, true, true);
            elem.dispatchEvent(event);
        } else {
            event = document.createEventObject();
            event.eventType = eventType;
            elem.fireEvent("on" + eventType, event);
        }
    };

    for( var i = 0; i < inputs.length; i++ ) {
        var input = inputs[i];
        var listId = input.getAttribute('list');
        var datalist = document.getElementById(listId);
        if( !datalist ) {
            console.error('No datalist found for input: ' + listId);
            return;
        }

        // Only visible to <= IE9
        var childSelect = document.querySelector('select[' + IE_SELECT_ATTRIBUTE + '="' + listId + '"]');
        var parent = childSelect || datalist;
        var listItems = parent.getElementsByTagName('option');
        convert(input, datalist, listItems);
        if( childSelect ) {
            childSelect.parentNode.removeChild( childSelect );
        }
    }

    function convert(input, datalist, listItems) {
        var fakeList = document.createElement('ul');
        var visibleItems = null;
        fakeList.id = listId;
        fakeList.className = LIST_CLASS;
        document.body.appendChild( fakeList );

        var scrollValue = 0;

        // Used to prevent reflow
        var tempItems = document.createDocumentFragment();

        for( var i = 0; i < listItems.length; i++ ) {
            var item = listItems[i];
            var li = document.createElement('li');
            li.innerText = item.value;
            tempItems.appendChild( li );
        }
        fakeList.appendChild( tempItems );
        var fakeItems = fakeList.childNodes;
        var eachItem = function(callback) {
            for( var i = 0; i < fakeItems.length; i++ ) {
                callback(fakeItems[i]);
            }
        };
        var listen = function(elem, event, func) {
            if( elem.addEventListener ) {
                elem.addEventListener(event, func, false);
            } else {
                elem.attachEvent('on' + event, func);
            }
        };

        datalist.parentNode.removeChild( datalist );

        listen(input, 'focus', function() {
            // Reset scroll
            fakeList.scrollTop = 0;
            scrollValue = 0;
        });

        listen(input, 'blur', function(evt) {
            // If this fires immediately, it prevents click-to-select from working
            setTimeout(function() {
                fakeList.style.display = 'none';
                eachItem( function(item) {
                    // Note: removes all, not just ACTIVE_CLASS, but should be safe
                    item.className = '';
                });
            }, 100);
        });

        var positionList = function() {
            fakeList.style.top = input.offsetTop + input.offsetHeight + 'px';
            fakeList.style.left = input.offsetLeft + 'px';
            fakeList.style.width = input.offsetWidth + 'px';
        };

        var itemSelected = function(item) {
            input.value = item.innerText;
            triggerEvent(input, 'change');
            setTimeout(function() {
                fakeList.style.display = 'none';
            }, 100);
        };

        var buildList = function(e) {
            // Build datalist
            fakeList.style.display = 'block';
            positionList();
            visibleItems = [];
            eachItem( function(item) {
                // Note: removes all, not just ACTIVE_CLASS, but should be safe
                var query = input.value.toLowerCase();
                var isFound = query.length && item.innerText.toLowerCase().indexOf( query ) > -1;
                if( isFound ) {
                    visibleItems.push( item );
                }
                item.style.display = isFound ? 'block' : 'none';
            } );
        };

        listen(input, 'keyup', buildList);
        listen(input, 'focus', buildList);

        // Don't want to use :hover in CSS so doing this instead
        // really helps with arrow key navigation
        eachItem( function(item) {
            // Note: removes all, not just ACTIVE_CLASS, but should be safe
            listen(item, 'mouseover', function(evt) {
                eachItem( function(_item) {
                    _item.className = item == _item ? ACTIVE_CLASS : '';
                });
            });
            listen(item, 'mouseout', function(evt) {
                item.className = '';
            });
            // Mousedown fires before native 'change' event is triggered
            // So we use this instead of click so only the new value is passed to 'change'
            listen(item, 'mousedown', function(evt) {
                itemSelected(item);
            });
        });

        listen(window, 'resize', positionList);

        listen(input, 'keydown', function(e) {
            var activeItem = fakeList.querySelector("." + ACTIVE_CLASS);
            if( !visibleItems.length ) {
                return;
            }

            var lastVisible = visibleItems[ visibleItems.length-1 ];
            var datalistItemsHeight = lastVisible.offsetTop + lastVisible.offsetHeight;

            // up/down arrows
            var isUp = e.keyCode == 38;
            var isDown = e.keyCode == 40;
            if ( (isUp || isDown) ) {
                if( isDown && !activeItem ) {
                    visibleItems[0].className = ACTIVE_CLASS;
                } else if (activeItem) {
                    var prevVisible = null;
                    var nextVisible = null;
                    for( var i = 0; i < visibleItems.length; i++ ) {
                        var visItem = visibleItems[i];
                        if( visItem == activeItem ) {
                            prevVisible = visibleItems[i-1];
                            nextVisible = visibleItems[i+1];
                            break;
                        }
                    }

                    activeItem.className = '';
                    if ( isUp ) {
                        if( prevVisible ) {
                            prevVisible.className = ACTIVE_CLASS;
                            if ( prevVisible.offsetTop < fakeList.scrollTop ) {
                                fakeList.scrollTop -= prevVisible.offsetHeight;
                            }
                        } else {
                            visibleItems[visibleItems.length - 1].className = ACTIVE_CLASS;
                        }
                    }
                    if ( isDown ) {
                        if( nextVisible ) {
                            nextVisible.className = ACTIVE_CLASS;
                            if( nextVisible.offsetTop + nextVisible.offsetHeight > fakeList.scrollTop + fakeList.offsetHeight ) {
                                fakeList.scrollTop += nextVisible.offsetHeight;
                            }
                        } else {
                            visibleItems[0].className = ACTIVE_CLASS;
                        }
                    }
                }
            }

            // return or tab key
            if ( activeItem && (e.keyCode == 13 || e.keyCode == 9) ){
                itemSelected(activeItem);
            }
        });
    }
}(document));