import { COOKIE_CONFIGURATION_UPDATE } from 'src/plugin/cookie/cookie-configuration.plugin';
import CookieStorage from 'src/helper/storage/cookie-storage.helper';

document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, eventCallback);

function eventCallback(updatedCookies) {
    const cookieDetails = updatedCookies.detail;
    if (typeof cookieDetails['listrakTracking'] !== 'undefined') {
        let listrakTracking = updatedCookies.detail['listrakTracking'];

        if (!listrakTracking) {
            removeListrakCookie('listrakCartAbandonmentTracking');
        } else {
            enableListrakTracking();
        }
    }
}
function enableListrakTracking() {
    (function (d) {
        if (typeof _ltk == 'undefined') {
            if (document.addEventListener) {
                document.addEventListener('ltkAsyncListener', function () {
                    _ltk_util.ready(d);
                });
            } else {
                e = document.documentElement;
                e.ltkAsyncProperty = 0;
                e.attachEvent('onpropertychange', function (e) {
                    if (e.propertyName == 'ltkAsyncProperty') {
                        _ltk_util.ready(d);
                    }
                });
            }
        } else {
            _ltk_util.ready(d);
        }
    })(function () {
        _ltk.Session.setPersonalizedStatus(true);
    });
}
function removeListrakCookie(cookie) {
    (function (d) {
        if (typeof _ltk == 'undefined') {
            if (document.addEventListener) {
                document.addEventListener('ltkAsyncListener', function () {
                    _ltk_util.ready(d);
                });
            } else {
                e = document.documentElement;
                e.ltkAsyncProperty = 0;
                e.attachEvent('onpropertychange', function (e) {
                    if (e.propertyName == 'ltkAsyncProperty') {
                        _ltk_util.ready(d);
                    }
                });
            }
        } else {
            _ltk_util.ready(d);
        }
    })(function () {
        _ltk.Session.setPersonalizedStatus(false);
    });

    CookieStorage.removeItem(cookie);
}
