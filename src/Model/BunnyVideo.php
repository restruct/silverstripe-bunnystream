<?php

namespace Restruct\BunnyStream\Model;

use Restruct\BunnyStream\Api\BunnyStreamClient;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Stores a reference to a video on Bunny Stream.
 *
 * The video file lives on Bunny's CDN — this record holds the GUID,
 * metadata (synced from API), and provides embed/player helpers.
 */
class BunnyVideo extends DataObject
{
    private static $table_name = 'BunnyVideo';
    private static $singular_name = 'Video';
    private static $plural_name = 'Video\'s';

    private static $db = [
        'VideoGuid' => 'Varchar(100)', # Bunny video GUID
        'Title' => 'Varchar(255)',
        'Status' => 'Int',             # Bunny status code (0-6)
        'Duration' => 'Int',           # Seconds
        'Width' => 'Int',
        'Height' => 'Int',
        'EncodeProgress' => 'Int',     # 0-100
        'StorageSize' => 'Int',        # Bytes
    ];

    private static $has_one = [
        'PosterImage' => Image::class,
    ];

    private static $summary_fields = [
        'Title' => 'Titel',
        'VideoGuid' => 'GUID',
        'StatusLabel' => 'Status',
        'DurationFormatted' => 'Duur',
    ];

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isReady(): bool
    {
        return $this->Status === BunnyStreamClient::STATUS_FINISHED;
    }

    public function getStatusLabel(): string
    {
        return match ($this->Status) {
            BunnyStreamClient::STATUS_CREATED => 'Aangemaakt',
            BunnyStreamClient::STATUS_UPLOADED => 'Geüpload',
            BunnyStreamClient::STATUS_PROCESSING => 'Verwerken...',
            BunnyStreamClient::STATUS_TRANSCODING => 'Transcoding...',
            BunnyStreamClient::STATUS_FINISHED => 'Gereed',
            BunnyStreamClient::STATUS_ERROR => 'Fout',
            BunnyStreamClient::STATUS_UPLOAD_FAILED => 'Upload mislukt',
            default => 'Onbekend',
        };
    }

    public function getDurationFormatted(): string
    {
        if (!$this->Duration) return '';
        $m = floor($this->Duration / 60);
        $s = $this->Duration % 60;
        return sprintf('%d:%02d', $m, $s);
    }

    // -------------------------------------------------------------------------
    // Embed / player
    // -------------------------------------------------------------------------

    /**
     * Get the embed/player URL.
     */
    public function getPlayerURL(): string
    {
        $client = new BunnyStreamClient();
        return $client->getEmbedUrl($this->VideoGuid);
    }

    /**
     * Get the thumbnail/poster URL.
     */
    public function getThumbnailUrl(): string
    {
        $client = new BunnyStreamClient();
        return $client->getThumbnailUrl($this->VideoGuid);
    }

    /**
     * Get responsive player iframe HTML.
     */
    public function getPlayerIframeHTML(array $options = []): string
    {
        if (!$this->VideoGuid) return '';

        $url = $this->getPlayerURL();
        $params = [];
        if ($options['autoplay'] ?? false) $params[] = 'autoplay=true';
        if ($options['muted'] ?? false) $params[] = 'muted=true';
        if ($options['loop'] ?? false) $params[] = 'loop=true';
        if (!($options['controls'] ?? true)) $params[] = 'controls=false';

        if ($params) {
            $url .= '?' . implode('&', $params);
        }

        $safeUrl = htmlspecialchars($url);
        return '<div class="ratio ratio-16x9">'
            . '<iframe src="' . $safeUrl . '" loading="lazy" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture" frameborder="0"></iframe>'
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // Sync from API
    // -------------------------------------------------------------------------

    /**
     * Refresh metadata from Bunny Stream API.
     */
    public function refreshFromApi(): void
    {
        if (!$this->VideoGuid) return;

        $client = new BunnyStreamClient();
        $data = $client->getVideo($this->VideoGuid);

        $this->Title = $data->title ?? $this->Title;
        $this->Status = $data->status ?? $this->Status;
        $this->Duration = $data->length ?? $this->Duration;
        $this->Width = $data->width ?? $this->Width;
        $this->Height = $data->height ?? $this->Height;
        $this->EncodeProgress = $data->encodeProgress ?? $this->EncodeProgress;
        $this->StorageSize = $data->storageSize ?? $this->StorageSize;
        $this->write();
    }

    // -------------------------------------------------------------------------
    // CMS
    // -------------------------------------------------------------------------

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->removeByName(['PosterImageID']);

        if ($this->VideoGuid && $this->isReady()) {
            $playerHtml = $this->getPlayerIframeHTML();
            $fields->addFieldToTab('Root.Main',
                LiteralField::create('VideoPreview',
                    '<div class="form-group field"><label class="form__field-label">Preview</label>'
                    . '<div class="form__field-holder" style="max-width:480px;">' . $playerHtml . '</div></div>'
                ),
                'Title'
            );
        } elseif ($this->VideoGuid) {
            $fields->addFieldToTab('Root.Main',
                ReadonlyField::create('StatusInfo', 'Status', $this->getStatusLabel() . " ({$this->EncodeProgress}%)"),
                'Title'
            );
        }

        return $fields;
    }

    public function getTitle(): string
    {
        return $this->getField('Title') ?: $this->VideoGuid ?: '(geen video)';
    }
}
