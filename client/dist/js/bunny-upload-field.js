/**
 * BunnyUploadField client behaviour.
 *
 * Initialises each .bunny-upload-field wrapper:
 *  - Wires the Ontkoppelen button to clear the relation
 *  - Drives direct-to-Bunny TUS uploads (tus-js-client must be loaded by PHP)
 *  - Notifies UncleCheese DisplayLogic when the hidden BunnyVideoID value
 *    changes (its JS only listens to text/email/number inputs natively, so
 *    we trigger .notify() on the dispatcher wrapper ourselves)
 *
 * Loaded as a static file via Requirements::javascript() so SS admin's
 * React-driven AJAX form swaps pick it up cleanly; inline <script> tags in
 * the field's Field() output break script ordering and don't execute on
 * panel navigation anyway.
 */
(function() {
    // Minimal DisplayLogic evaluator. The CMS-loaded jquery.entwine isn't
    // reachable from our standalone script (window.jQuery has no entwine
    // methods — different jQuery instance under webpack), so we can't call
    // its $dispatcher.notify() / $wrapper.testLogic() to re-sync visibility
    // after a programmatic value change.
    //
    // Instead: find every .displaylogicwrapper in the form, evaluate its
    // `data-display-logic-eval` expression manually, and toggle display
    // accordingly. The eval strings use only `this.findHolder('X').evaluateY('Z')`
    // — we mirror just enough of that surface to keep the wrappers honest.
    function buildEvaluator(form) {
        return {
            findHolder: function (name) {
                // Holder ID convention: {FormID}_{FieldName}_Holder
                var formId = form.getAttribute('id') || '';
                var holder = form.querySelector('#' + formId + '_' + name + '_Holder');
                var input = holder ? holder.querySelector('[name="' + name + '"]') : null;
                if (!input) input = form.querySelector('[name="' + name + '"]');
                var val = input ? (input.value || '') : '';
                return {
                    evaluateEmpty: function () { return val.trim() === ''; },
                    evaluateNotEmpty: function () { return val.trim() !== ''; },
                    evaluateEqualTo: function (v) { return val === v; },
                    evaluateNotEqualTo: function (v) { return val !== v; },
                    evaluateGreaterThan: function (v) { return parseFloat(val) > parseFloat(v); },
                    evaluateLessThan: function (v) { return parseFloat(val) < parseFloat(v); },
                    evaluateContains: function (v) { return val.indexOf(v) !== -1; },
                    evaluateStartsWith: function (v) { return val.indexOf(v) === 0; },
                    evaluateEndsWith: function (v) { return val.indexOf(v, val.length - v.length) !== -1; },
                };
            }
        };
    }

    function resyncDisplayLogic(input) {
        var form = input.closest('form');
        if (!form) return;
        var evaluator = buildEvaluator(form);
        var wrappers = form.querySelectorAll('.displaylogicwrapper');
        wrappers.forEach(function (wrapper) {
            var evalStr = wrapper.getAttribute('data-display-logic-eval');
            if (!evalStr) return;
            var result;
            try {
                // eslint-disable-next-line no-new-func
                result = (new Function('return ' + evalStr)).call(evaluator);
            } catch (e) {
                return; // unknown criterion → leave wrapper alone
            }
            // .display-logic-hide → hide when criteria TRUE
            // .display-logic-display → show when criteria TRUE
            if (wrapper.classList.contains('display-logic-hide')) {
                wrapper.style.display = result ? 'none' : '';
            } else if (wrapper.classList.contains('display-logic-display')) {
                wrapper.style.display = result ? '' : 'none';
            }
        });
    }

    function notifyDisplayLogic(input) {
        // Try the standard entwine path first (works in some contexts).
        if (window.jQuery) {
            try {
                var $dispatcher = window.jQuery(input).closest('.display-logic-dispatcher');
                if ($dispatcher.length && typeof $dispatcher.notify === 'function') {
                    $dispatcher.notify();
                }
            } catch (e) { /* fall through */ }
        }
        // Always do our own resync — independent of whether entwine bound.
        resyncDisplayLogic(input);
    }

    function init(wrapper) {
        if (wrapper.dataset.bunnyInit) return;
        wrapper.dataset.bunnyInit = '1';

        var fieldId = wrapper.dataset.fieldId;
        var createUrl = wrapper.dataset.createUrl;
        if (!fieldId || !createUrl) return;

        var fileInput = document.getElementById(fieldId + '_file');
        var uploadBtn = document.getElementById(fieldId + '_btn');
        var statusEl = document.getElementById(fieldId + '_status');
        var progressEl = document.getElementById(fieldId + '_progress');
        var progressBar = progressEl ? progressEl.querySelector('.progress-bar') : null;
        var resultEl = document.getElementById(fieldId + '_result');
        var hiddenInput = document.getElementById(fieldId);
        var uploadWrap = document.getElementById(fieldId + '_upload');
        var previewWrap = document.getElementById(fieldId + '_preview');
        var removeBtn = document.getElementById(fieldId + '_remove');

        // Set the hidden value AND wake up DisplayLogic + any other listeners.
        // Native dispatchEvent reaches addEventListener handlers; jQuery .trigger()
        // is needed for jQuery-bound entwine handlers (SS DisplayLogic uses these
        // and won't see purely-native events on hidden inputs).
        function setVideoId(val) {
            hiddenInput.value = val;
            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            if (window.jQuery) {
                window.jQuery(hiddenInput).trigger('change');
            }
            notifyDisplayLogic(hiddenInput);
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                setVideoId('');
                if (previewWrap) previewWrap.style.setProperty('display', 'none', 'important');
                if (uploadWrap) uploadWrap.style.display = 'block';
            });
        }

        if (!fileInput || !uploadBtn) return;

        fileInput.addEventListener('change', function() {
            uploadBtn.disabled = !fileInput.files.length;
            if (statusEl) statusEl.textContent = fileInput.files.length ? fileInput.files[0].name : '';
        });

        uploadBtn.addEventListener('click', function() {
            var file = fileInput.files[0];
            if (!file) return;

            uploadBtn.disabled = true;
            fileInput.disabled = true;
            if (statusEl) statusEl.textContent = 'Voorbereiden...';
            if (progressEl) progressEl.style.display = 'block';

            fetch(createUrl + '?title=' + encodeURIComponent(file.name), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.tusEndpoint) throw new Error('Geen upload endpoint ontvangen');
                if (statusEl) statusEl.textContent = 'Uploaden...';

                var upload = new tus.Upload(file, {
                    endpoint: data.tusEndpoint,
                    retryDelays: [0, 1000, 3000, 5000],
                    chunkSize: 25 * 1024 * 1024,
                    headers: data.tusHeaders,
                    metadata: { filetype: file.type, title: file.name },
                    onError: function(error) {
                        if (statusEl) statusEl.textContent = 'Upload mislukt: ' + error.message;
                        uploadBtn.disabled = false;
                        fileInput.disabled = false;
                        if (progressEl) progressEl.style.display = 'none';
                    },
                    onProgress: function(bytesUploaded, bytesTotal) {
                        var pct = Math.round(bytesUploaded / bytesTotal * 100);
                        if (progressBar) {
                            progressBar.style.width = pct + '%';
                            progressBar.textContent = pct + '%';
                        }
                    },
                    onSuccess: function() {
                        setVideoId(data.bunnyVideoId);
                        if (progressBar) {
                            progressBar.style.width = '100%';
                            progressBar.textContent = '100%';
                            progressBar.classList.add('bg-success');
                        }
                        if (statusEl) statusEl.textContent = '';
                        if (resultEl) {
                            resultEl.textContent = 'Video geüpload — sla deze vraag op om de koppeling te bevestigen';
                            resultEl.style.display = 'block';
                        }
                        // Lock the upload UI so another file can't be picked while a video is pending.
                        fileInput.disabled = true;
                        uploadBtn.disabled = true;
                    }
                });

                upload.start();
            })
            .catch(function(err) {
                if (statusEl) statusEl.textContent = 'Fout: ' + err.message;
                uploadBtn.disabled = false;
                fileInput.disabled = false;
                if (progressEl) progressEl.style.display = 'none';
            });
        });
    }

    function scan() {
        document.querySelectorAll('.bunny-upload-field').forEach(init);
    }

    // Initial scan + observer so AJAX-loaded form fields get picked up.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
    new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
})();
