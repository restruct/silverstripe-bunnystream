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
    function notifyDisplayLogic(input) {
        if (!window.jQuery) return;
        var $dispatcher = window.jQuery(input).closest('.display-logic-dispatcher');
        // .notify() is an entwine method on .display-logic-dispatcher wrappers
        if ($dispatcher.length && typeof $dispatcher.notify === 'function') {
            $dispatcher.notify();
        }
    }

    function init(wrapper) {
        if (wrapper.dataset.bunnyInit) return;
        wrapper.dataset.bunnyInit = '1';

        var fieldId = wrapper.dataset.fieldId;
        var createUrl = wrapper.dataset.createUrl;
        if (!fieldId || !createUrl) return;

        // DisplayLogic auto-eval only fires off onmatch on text/email/number
        // inputs — hidden inputs are ignored, so wrappers depending on
        // BunnyVideoID don't evaluate on initial render. Trigger notify()
        // ourselves once, deferred so DL has already marked the dispatcher class.
        setTimeout(function() {
            var hidden = document.getElementById(fieldId);
            if (hidden) notifyDisplayLogic(hidden);
        }, 0);

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
        function setVideoId(val) {
            hiddenInput.value = val;
            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
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
