<?php

namespace Restruct\BunnyStream\Forms;

use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Model\BunnyVideo;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FormField;
use SilverStripe\View\Requirements;

/**
 * CMS form field for direct-to-Bunny TUS video uploads.
 *
 * Flow:
 * 1. User picks a file
 * 2. JS calls our create_upload endpoint (creates video on Bunny, returns TUS credentials)
 * 3. Browser uploads directly to Bunny via tus-js-client (chunked, resumable)
 * 4. On completion: BunnyVideo record created/updated, ID stored in hidden input
 */
class BunnyUploadField extends FormField
{
    protected $schemaDataType = 'Custom';

    private static array $allowed_actions = [
        'createUpload',
    ];

    private static array $url_handlers = [
        'createUpload' => 'createUpload',
    ];

    /**
     * AJAX endpoint: create a video on Bunny and return TUS upload credentials.
     */
    public function createUpload()
    {
        $request = Controller::curr()->getRequest();
        $title = $request->getVar('title') ?: 'Untitled';

        $client = new BunnyStreamClient();

        # Step 1: Create video object on Bunny
        $video = $client->createVideo($title);
        $videoGuid = $video->guid ?? '';

        if (!$videoGuid) {
            return Controller::curr()->httpError(500, 'Failed to create video on Bunny');
        }

        # Step 2: Get TUS upload credentials
        $tusCredentials = $client->getTusUploadCredentials($videoGuid);

        # Step 3: Create local BunnyVideo record
        $BunnyVideo = BunnyVideo::create();
        $BunnyVideo->VideoGuid = $videoGuid;
        $BunnyVideo->Title = $title;
        $BunnyVideo->Status = BunnyStreamClient::STATUS_CREATED;
        $BunnyVideo->write();

        $response = Controller::curr()->getResponse();
        $response->addHeader('Content-Type', 'application/json');
        $response->setBody(json_encode([
            'videoGuid' => $videoGuid,
            'bunnyVideoId' => $BunnyVideo->ID,
            'tusEndpoint' => $tusCredentials['endpoint'],
            'tusHeaders' => $tusCredentials['headers'],
        ]));
        return $response;
    }

    public function Field($properties = [])
    {
        $fieldId = $this->ID();
        $name = $this->getName();
        $value = $this->Value();
        $createUrl = $this->Link('createUpload');

        # Show existing video info with poster thumbnail
        $existingVideoHtml = '';
        if ($value) {
            $BunnyVideo = BunnyVideo::get()->byID($value);
            if ($BunnyVideo && $BunnyVideo->exists()) {
                $title = htmlspecialchars($BunnyVideo->Title);
                $status = $BunnyVideo->getStatusLabel();
                $duration = $BunnyVideo->getDurationFormatted();
                $posterUrl = $BunnyVideo->VideoGuid ? htmlspecialchars($BunnyVideo->getThumbnailUrl()) : '';
                $statusClass = $BunnyVideo->isReady() ? 'text-success' : 'text-warning';

                $posterHtml = $posterUrl
                    ? '<img src="' . $posterUrl . '" alt="" style="max-width:160px; max-height:90px; border-radius:4px; object-fit:cover;" class="me-3" onerror="this.style.display=\'none\'">'
                    : '';

                $existingVideoHtml = <<<EXISTING
    <div class="d-flex align-items-center mb-3 p-2 border rounded" style="background:#f8f9fa;">
        {$posterHtml}
        <div>
            <div class="fw-semibold">{$title}</div>
            <small class="{$statusClass}">{$status}</small>
            <small class="text-muted ms-2">{$duration}</small>
        </div>
    </div>
EXISTING;
            }
        }

        # Include tus-js-client from CDN
        Requirements::javascript('https://cdn.jsdelivr.net/npm/tus-js-client@4/dist/tus.min.js');

        $html = <<<HTML
<div id="{$fieldId}_wrapper" class="bunny-upload-field">
    <input type="hidden" name="{$name}" id="{$fieldId}" value="{$value}" />
    {$existingVideoHtml}

    <div class="bunny-upload-controls">
        <input type="file" id="{$fieldId}_file" accept="video/*" class="form-control" style="max-width:400px; display:inline-block;" />
        <button type="button" id="{$fieldId}_btn" class="btn btn-outline-primary btn-sm ms-2" disabled>Video uploaden</button>
        <span id="{$fieldId}_status" class="ms-2 text-muted small"></span>
    </div>

    <div id="{$fieldId}_progress" class="progress mt-2" style="display:none; max-width:400px; height:8px;">
        <div class="progress-bar" role="progressbar" style="width: 0%"></div>
    </div>

    <div id="{$fieldId}_result" class="mt-2 text-success small" style="display:none;"></div>
</div>

<script>
(function() {
    var fieldId = '{$fieldId}';
    var createUrl = '{$createUrl}';

    var fileInput = document.getElementById(fieldId + '_file');
    var uploadBtn = document.getElementById(fieldId + '_btn');
    var statusEl = document.getElementById(fieldId + '_status');
    var progressEl = document.getElementById(fieldId + '_progress');
    var progressBar = progressEl.querySelector('.progress-bar');
    var resultEl = document.getElementById(fieldId + '_result');
    var hiddenInput = document.getElementById(fieldId);

    fileInput.addEventListener('change', function() {
        uploadBtn.disabled = !fileInput.files.length;
        statusEl.textContent = fileInput.files.length ? fileInput.files[0].name : '';
    });

    uploadBtn.addEventListener('click', function() {
        var file = fileInput.files[0];
        if (!file) return;

        uploadBtn.disabled = true;
        fileInput.disabled = true;
        statusEl.textContent = 'Voorbereiden...';
        progressEl.style.display = 'block';

        // Step 1: Create video + get TUS credentials from our server
        fetch(createUrl + '?title=' + encodeURIComponent(file.name), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.tusEndpoint) throw new Error('Geen upload endpoint ontvangen');

            statusEl.textContent = 'Uploaden...';

            // Step 2: Upload directly to Bunny via TUS
            var upload = new tus.Upload(file, {
                endpoint: data.tusEndpoint,
                retryDelays: [0, 1000, 3000, 5000],
                chunkSize: 25 * 1024 * 1024,
                headers: data.tusHeaders,
                metadata: {
                    filetype: file.type,
                    title: file.name,
                },
                onError: function(error) {
                    statusEl.textContent = 'Upload mislukt: ' + error.message;
                    uploadBtn.disabled = false;
                    fileInput.disabled = false;
                    progressEl.style.display = 'none';
                },
                onProgress: function(bytesUploaded, bytesTotal) {
                    var pct = Math.round(bytesUploaded / bytesTotal * 100);
                    progressBar.style.width = pct + '%';
                    progressBar.textContent = pct + '%';
                },
                onSuccess: function() {
                    hiddenInput.value = data.bunnyVideoId;
                    progressBar.style.width = '100%';
                    progressBar.textContent = '100%';
                    progressBar.classList.add('bg-success');
                    statusEl.textContent = '';
                    resultEl.textContent = 'Video geüpload — wordt verwerkt';
                    resultEl.style.display = 'block';
                }
            });

            upload.start();
        })
        .catch(function(err) {
            statusEl.textContent = 'Fout: ' + err.message;
            uploadBtn.disabled = false;
            fileInput.disabled = false;
            progressEl.style.display = 'none';
        });
    });
})();
</script>
HTML;

        return $html;
    }

    public function Type()
    {
        return 'bunny-upload';
    }
}
