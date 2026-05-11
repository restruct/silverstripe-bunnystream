<?php

namespace Restruct\BunnyStream\Model;

use Restruct\BunnyStream\Api\BunnyStreamClient;
use SilverStripe\Assets\Image;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
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
        'Description' => 'Text',
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
        'getThumbnailIMG' => 'Poster',
        'Title' => 'Titel',
        'StatusLabel' => 'Status',
        'DurationFormatted' => 'Duur',
        'StorageSizeFormatted' => 'Grootte',
        'DimensionsFormatted' => 'Afmetingen',
    ];

    private static $searchable_fields = [
        'Title',
        'VideoGuid',
    ];

    // -------------------------------------------------------------------------
    // Status / formatting helpers
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

    public function getStorageSizeFormatted(): string
    {
        if (!$this->StorageSize) return '';
        $mb = $this->StorageSize / (1024 * 1024);
        if ($mb >= 1024) {
            return sprintf('%.2f GB', $mb / 1024);
        }
        return sprintf('%.1f MB', $mb);
    }

    public function getDimensionsFormatted(): string
    {
        if (!$this->Width || !$this->Height) return '';
        return "{$this->Width} × {$this->Height}";
    }

    /**
     * Get an <img> tag with the Bunny thumbnail — used in summary_fields.
     */
    public function getThumbnailIMG(): DBHTMLText
    {
        $html = '';
        if ($this->VideoGuid) {
            $url = htmlspecialchars($this->getThumbnailUrl());
            $html = '<img src="' . $url . '" alt="" style="max-width:120px; max-height:68px; border-radius:3px; object-fit:cover;" onerror="this.style.visibility=\'hidden\'">';
        }
        return DBHTMLText::create()->setValue($html);
    }

    // -------------------------------------------------------------------------
    // Embed / player
    // -------------------------------------------------------------------------

    public function getPlayerURL(): string
    {
        $client = new BunnyStreamClient();
        return $client->getEmbedUrl($this->VideoGuid);
    }

    public function getThumbnailUrl(): string
    {
        $client = new BunnyStreamClient();
        return $client->getThumbnailUrl($this->VideoGuid);
    }

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
            # If URL already has query params (signed token), append with &, otherwise ?
            $url .= (str_contains($url, '?') ? '&' : '?') . implode('&', $params);
        }

        $safeUrl = htmlspecialchars($url);
        return '<div class="ratio ratio-16x9">'
            . '<iframe src="' . $safeUrl . '" loading="lazy" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture" frameborder="0"></iframe>'
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // Sync from API
    // -------------------------------------------------------------------------

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
    // Usages — generic discovery of records pointing to this video
    // -------------------------------------------------------------------------

    /**
     * Find all DataObject records that have a has_one relation to BunnyVideo.
     * Returns array of [{ClassName, Records: SS_List}].
     *
     * Generic discovery — module doesn't need to know about consumer classes.
     */
    public function getUsages(): array
    {
        if (!$this->ID) return [];

        $usages = [];
        $classes = ClassInfo::subclassesFor(DataObject::class);

        foreach ($classes as $class) {
            if ($class === DataObject::class || $class === self::class) continue;
            if (!class_exists($class)) continue;

            try {
                $hasOne = (array) $class::config()->get('has_one');
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($hasOne as $relName => $relClass) {
                # Normalise polymorphic shorthand like ['type' => DataObject::class]
                if (is_array($relClass)) $relClass = $relClass['class'] ?? null;
                if ($relClass !== self::class) continue;

                $records = $class::get()->filter("{$relName}ID", $this->ID);
                if ($records->count() > 0) {
                    $usages[] = [
                        'ClassName' => $class,
                        'RelationName' => $relName,
                        'Records' => $records,
                    ];
                }
            }
        }

        return $usages;
    }

    // -------------------------------------------------------------------------
    // CMS
    // -------------------------------------------------------------------------

    public function getCMSFields()
    {
        # Auto-sync metadata from Bunny when video isn't yet finished processing.
        # Bunny processes async (created → uploaded → processing → transcoding → finished),
        # so admin will see "Onbekend"/0 right after upload — refresh on every CMS open
        # until the video reaches a terminal state.
        if ($this->VideoGuid && !$this->isReady() && $this->Status !== BunnyStreamClient::STATUS_ERROR && $this->Status !== BunnyStreamClient::STATUS_UPLOAD_FAILED) {
            try {
                $this->refreshFromApi();
            } catch (\Throwable $e) {
                # API may be unreachable — don't break the CMS, just show stale data
            }
        }

        $fields = parent::getCMSFields();

        # Remove default scaffolded fields — we'll rebuild the form
        $fields->removeByName([
            'PosterImageID', 'VideoGuid', 'Status', 'Duration',
            'Width', 'Height', 'EncodeProgress', 'StorageSize',
            'Title', 'Description',
        ]);

        # Player preview (if ready)
        if ($this->VideoGuid && $this->isReady()) {
            $playerHtml = $this->getPlayerIframeHTML();
            $fields->addFieldToTab('Root.Main',
                LiteralField::create('VideoPreview',
                    '<div class="form-group field"><div class="form__field-holder" style="max-width:560px;">' . $playerHtml . '</div></div>'
                )
            );
        } elseif ($this->VideoGuid) {
            $fields->addFieldToTab('Root.Main',
                LiteralField::create('StatusBanner',
                    '<div class="alert alert-warning">Status: ' . htmlspecialchars($this->getStatusLabel())
                    . ($this->EncodeProgress ? " ({$this->EncodeProgress}%)" : '') . '</div>'
                )
            );
        }

        # Editable: title + description
        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Title', 'Titel'),
            TextareaField::create('Description', 'Beschrijving')->setRows(3),
        ]);

        # Read-only metadata (synced from Bunny API)
        $fields->addFieldToTab('Root.Main',
            CompositeField::create(
                HeaderField::create('MetaHeader', 'Metadata', 4),
                FieldGroup::create(
                    ReadonlyField::create('VideoGuid', 'Video GUID'),
                    ReadonlyField::create('StatusLabel', 'Status')
                ),
                FieldGroup::create(
                    ReadonlyField::create('DurationFormatted', 'Duur'),
                    ReadonlyField::create('StorageSizeFormatted', 'Grootte'),
                    ReadonlyField::create('DimensionsFormatted', 'Afmetingen')
                )
            )
        );

        # Poster image upload (custom thumbnail if Bunny's default isn't preferred)
        $posterField = $fields->dataFieldByName('PosterImage');
        if ($posterField) {
            $fields->addFieldToTab('Root.Main', $posterField);
        }

        # Usages — show records referencing this video
        $usages = $this->getUsages();
        if (!empty($usages)) {
            $usagesTab = $fields->findOrMakeTab('Root.Usages');
            $usagesTab->setTitle('Gebruikt door (' . array_sum(array_map(fn($u) => $u['Records']->count(), $usages)) . ')');

            foreach ($usages as $usage) {
                $shortName = (new \ReflectionClass($usage['ClassName']))->getShortName();
                $config = GridFieldConfig_RecordViewer::create();
                $gridField = GridField::create(
                    'Usages_' . str_replace('\\', '_', $usage['ClassName']),
                    $shortName,
                    $usage['Records'],
                    $config
                );
                $fields->addFieldToTab('Root.Usages', $gridField);
            }
        }

        return $fields;
    }

    public function getTitle(): string
    {
        return $this->getField('Title') ?: $this->VideoGuid ?: '(geen video)';
    }
}
