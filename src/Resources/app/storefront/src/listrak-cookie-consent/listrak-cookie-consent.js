/** Run after the vendor SDK is ready; callers must recheck consent inside callback. */
export function whenListrakReady(callback) {
    const ready = () => window._ltk_util.ready(callback);
    if (window._ltk && window._ltk_util) {
        ready();
    } else {
        document.addEventListener('ltkAsyncListener', ready, { once: true });
    }
}
