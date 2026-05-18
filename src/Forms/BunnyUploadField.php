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

        # Show existing video info with poster thumbnail + a remove button to clear the relation
        $existingVideoHtml = '';
        $hasVideo = false;
        if ($value) {
            $BunnyVideo = BunnyVideo::get()->byID($value);
            if ($BunnyVideo && $BunnyVideo->exists()) {
                $hasVideo = true;
                $title = htmlspecialchars($BunnyVideo->Title);
                $status = $BunnyVideo->getStatusLabel();
                $duration = $BunnyVideo->getDurationFormatted();
                $posterUrl = $BunnyVideo->VideoGuid ? htmlspecialchars($BunnyVideo->getThumbnailUrl()) : '';
                $statusClass = $BunnyVideo->isReady() ? 'text-success' : 'text-warning';

                # Hybrid BS4/5 classes (mr-/me-, ml-/ms-, font-weight-bold/fw-semibold) so this
                # module renders correctly on both SS5 (BS4) and SS6 (BS5) without changes.
                $posterHtml = $posterUrl
                    ? '<img src="' . $posterUrl . '" alt="" style="max-width:160px; max-height:90px; border-radius:4px; object-fit:cover;" class="me-3 mr-3" onerror="this.style.display=\'none\'">'
                    : '';

                $existingVideoHtml = <<<EXISTING
    <div id="{$fieldId}_preview" class="d-flex align-items-center p-2 border rounded" style="background:#f8f9fa;">
        {$posterHtml}
        <div class="flex-grow-1">
            <div class="fw-semibold font-weight-bold">{$title}</div>
            <small class="{$statusClass}">{$status}</small>
            <small class="text-muted ms-2 ml-2">{$duration}</small>
        </div>
        <button type="button" id="{$fieldId}_remove" class="btn btn-sm btn-outline-danger ms-2 ml-2" title="Video ontkoppelen — andere vragen die naar deze video verwijzen blijven werken">
            <i class="font-icon-cancel"></i> Ontkoppelen
        </button>
    </div>
EXISTING;
            }
        }

        # Initial display state: if a video is attached, hide the upload UI behind the preview
        $uploadDisplay = $hasVideo ? 'none' : 'block';

        # Pull the field's own description into the upload block so it sits with the inputs
        # (and disappears together when a video is attached). Blank the FormField-level description
        # so the SS wrapping template doesn't render it a second time below.
        $descriptionText = (string) $this->getDescription();
        $descriptionHtml = $descriptionText !== '' ? '<div class="form__field-description small text-muted mt-1">' . $descriptionText . '</div>' : '';
        $this->setDescription('');

        # Behaviour scripts via the Requirements API — never inline <script> tags
        # in Field() output (those break SS admin's script ordering on initial load
        # AND don't execute on React-driven AJAX form swaps).
        Requirements::javascript('https://cdn.jsdelivr.net/npm/tus-js-client@4/dist/tus.min.js');
        Requirements::javascript('restruct/silverstripe-bunnystream:client/dist/js/bunny-upload-field.js');

        # Render-time config travels via data-* attributes; the static JS reads them
        # off the .bunny-upload-field wrapper and initialises each instance.
        $safeCreateUrl = htmlspecialchars($createUrl);
        $safeFieldId = htmlspecialchars($fieldId);

        $html = <<<HTML
<div id="{$fieldId}_wrapper" class="bunny-upload-field" data-field-id="{$safeFieldId}" data-create-url="{$safeCreateUrl}">
    <input type="hidden" name="{$name}" id="{$fieldId}" value="{$value}" />
    {$existingVideoHtml}

    <div id="{$fieldId}_upload" style="display:{$uploadDisplay};">
        <div class="input-group bunny-upload-controls" style="max-width:560px;">
            <input type="file" id="{$fieldId}_file" accept="video/*" class="form-control" aria-describedby="{$fieldId}_btn" style="padding:.35rem;" />
            <!-- SS CMS bundles Bootstrap 4: input-group children need the input-group-append wrapper to flush -->
            <div class="input-group-append">
                <button type="button" id="{$fieldId}_btn" class="btn btn-outline-info" disabled>Video uploaden</button>
            </div>
        </div>
        <div id="{$fieldId}_status" class="text-muted small mt-1"></div>

        <div id="{$fieldId}_progress" class="progress mt-2" style="display:none; max-width:560px; height:8px;">
            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
        </div>

        <div id="{$fieldId}_result" class="mt-2 text-success small" style="display:none;"></div>
        {$descriptionHtml}
    </div>
</div>
HTML;

        return $html;
    }

    public function Type()
    {
        return 'bunny-upload';
    }
}
