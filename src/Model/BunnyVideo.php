<?php

namespace Restruct\BunnyStream\Model;

use Psr\Log\LoggerInterface;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
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
use SilverStripe\ORM\ValidationException;

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
        # Generic per-video player settings as a single JSON blob, so new
        # options can be added without a schema change. Read/write via
        # getPlayerOption()/setPlayerOption(); see KNOWN_PLAYER_OPTIONS for the
        # currently-handled keys and how each maps to embed behaviour.
        'PlayerOptions' => 'Text',
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
            $html = '<img src="' . $url . '" alt="" style="max-width:90px; max-height:52px; border-radius:3px; object-fit:cover;" onerror="this.style.visibility=\'hidden\'">';
        }
        return DBHTMLText::create()->setValue($html);
    }

    // -------------------------------------------------------------------------
    // Per-video player options (generic JSON store)
    // -------------------------------------------------------------------------

    /**
     * Currently-handled PlayerOptions keys. The JSON store is open-ended (any
     * key can be set), but only these are surfaced in the CMS and acted on
     * when building the embed:
     *
     *  - enforceFullWatch (bool): client-side seek-clamp — the player may not
     *    be skipped past the furthest point watched. Emitted as the
     *    data-enforce-full-watch iframe attribute; enforcement lives in
     *    video-player-mixin.js (Bunny has no native "disable seek" param).
     *  - rememberPosition (bool): real Bunny embed param — resume at last
     *    position. Default true (matches the library-level player config);
     *    set false per-video to override.
     *  - t (string): real Bunny embed param — start offset ("90s", "1h2m3s",
     *    "hh:mm:ss"). PREPARED for future use (e.g. several questions keyed to
     *    timestamps of one video); no dedicated UI emphasis yet.
     */
    private const KNOWN_PLAYER_OPTIONS = ['enforceFullWatch', 'rememberPosition', 't'];

    /**
     * Decoded PlayerOptions as an associative array (empty when unset/invalid).
     */
    public function getPlayerOptionsData(): array
    {
        $raw = $this->getField('PlayerOptions');
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read a single player option, with a fallback when the key is absent.
     */
    public function getPlayerOption(string $key, mixed $default = null): mixed
    {
        $data = $this->getPlayerOptionsData();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * Set (or, with null, unset) a single player option and re-encode the blob.
     */
    public function setPlayerOption(string $key, mixed $value): self
    {
        $data = $this->getPlayerOptionsData();
        if ($value === null) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }
        $this->setField('PlayerOptions', $data ? json_encode($data) : null);
        return $this;
    }

    # --- Typed accessors for the CMS-exposed options. Named get<Field>/
    #     set<Field> so SilverStripe form save/load (__get/__set) round-trips
    #     them through the JSON blob without dedicated DB columns. ---

    public function getEnforceFullWatch(): bool
    {
        return (bool) $this->getPlayerOption('enforceFullWatch', false);
    }

    public function setEnforceFullWatch($value): void
    {
        $this->setPlayerOption('enforceFullWatch', (bool) $value);
    }

    public function getRememberPosition(): bool
    {
        # Default true: the library-level Bunny player config has it enabled,
        # so an un-set video should behave the same.
        return (bool) $this->getPlayerOption('rememberPosition', true);
    }

    public function setRememberPosition($value): void
    {
        $this->setPlayerOption('rememberPosition', (bool) $value);
    }

    public function getStartTime(): string
    {
        return (string) $this->getPlayerOption('t', '');
    }

    public function setStartTime($value): void
    {
        $value = trim((string) $value);
        $this->setPlayerOption('t', $value !== '' ? $value : null);
    }

    /**
     * Bunny embed query params derived from PlayerOptions (provider-native
     * options only — NOT autoplay, which the embedding record controls, and
     * NOT enforceFullWatch, which is a client-side behaviour, not a param).
     *
     * @return array<string,string> e.g. ['rememberPosition' => 'true', 't' => '90s']
     */
    public function getPlayerQueryParams(): array
    {
        $params = [];
        # Always explicit so a per-video false overrides the library default
        $params['rememberPosition'] = $this->getRememberPosition() ? 'true' : 'false';
        if ($this->getStartTime() !== '') {
            $params['t'] = $this->getStartTime();
        }
        return $params;
    }

    /**
     * Behavioural flags emitted as iframe data-attributes (read by
     * video-player-mixin.js), not as embed query params.
     *
     * @return array<string,string> e.g. ['data-enforce-full-watch' => '1']
     */
    public function getPlayerDataAttributes(): array
    {
        $attrs = [];
        if ($this->getEnforceFullWatch()) {
            $attrs['data-enforce-full-watch'] = '1';
        }
        return $attrs;
    }

    // -------------------------------------------------------------------------
    // Embed / player
    // -------------------------------------------------------------------------

    public function getPlayerURL(): string
    {
        $client = BunnyStreamClient::create();
        return $client->getEmbedUrl($this->VideoGuid);
    }

    public function getThumbnailUrl(): string
    {
        $client = BunnyStreamClient::create();
        return $client->getThumbnailUrl($this->VideoGuid);
    }

    public function getPlayerIframeHTML(array $options = []): string
    {
        if (!$this->VideoGuid) return '';

        $url = $this->getPlayerURL();
        $params = [];
        # Always emit autoplay explicitly — defaults to false to override any library-level autoplay setting.
        $params[] = 'autoplay=' . (($options['autoplay'] ?? false) ? 'true' : 'false');
        if ($options['muted'] ?? false) $params[] = 'muted=true';
        if ($options['loop'] ?? false) $params[] = 'loop=true';
        if (!($options['controls'] ?? true)) $params[] = 'controls=false';
        # Per-video player options (rememberPosition, t, ...) from the JSON store
        foreach ($this->getPlayerQueryParams() as $k => $v) {
            $params[] = $k . '=' . rawurlencode($v);
        }

        if ($params) {
            # If URL already has query params (signed token), append with &, otherwise ?
            $url .= (str_contains($url, '?') ? '&' : '?') . implode('&', $params);
        }

        # Behavioural flags as data-attributes (e.g. data-enforce-full-watch)
        $dataAttrs = '';
        foreach ($this->getPlayerDataAttributes() as $attr => $val) {
            $dataAttrs .= ' ' . $attr . '="' . htmlspecialchars($val) . '"';
        }

        $safeUrl = htmlspecialchars($url);
        return '<div class="ratio ratio-16x9">'
            . '<iframe src="' . $safeUrl . '"' . $dataAttrs . ' loading="lazy" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture" frameborder="0"></iframe>'
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // Sync from API
    // -------------------------------------------------------------------------

    public function refreshFromApi(): void
    {
        if (!$this->VideoGuid) return;

        $client = BunnyStreamClient::create();
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

        # Per-video player options (stored in the PlayerOptions JSON blob via
        # the get/set<Field> accessors). 'PlayerOptions' itself is removed so
        # the raw JSON isn't shown.
        $fields->removeByName('PlayerOptions');
        $fields->addFieldToTab('Root.Main',
            CompositeField::create(
                HeaderField::create('PlayerOptionsHeader', 'Afspeelopties', 4),
                CheckboxField::create('EnforceFullWatch', 'Volledig bekijken afdwingen')
                    ->setDescription('Voorkomt vooruitspoelen — de kijker kan niet verder dan het verst bekeken punt (terugspoelen mag wel).'),
                CheckboxField::create('RememberPosition', 'Positie onthouden')
                    ->setDescription('Hervat de video op de laatst bekeken positie (standaard aan).'),
                TextField::create('StartTime', 'Starttijd (optioneel)')
                    ->setDescription('Begintijd, bijv. "90s" of "1m30s". Gereserveerd voor toekomstig gebruik (meerdere vragen op één video).')
            )
        );

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

        # If a previous delete attempt failed on the Bunny API, show a banner +
        # a force-local-delete checkbox. Checking + saving sets a session flag
        # that the next delete attempt reads to skip the API call.
        $lastError = $this->getLastDeleteErrorFromSession();
        if ($lastError !== null) {
            $fields->addFieldToTab('Root.Main',
                LiteralField::create('BunnyDeleteErrorAlert',
                    '<div class="alert alert-warning"><strong>Vorige verwijdering mislukte op Bunny Stream:</strong> '
                    . htmlspecialchars($lastError)
                    . '<br>Vink onderstaande optie aan en sla op om bij de volgende verwijderpoging de Bunny API over te slaan '
                    . '(de remote video blijft dan staan).</div>'
                ),
                'Title'
            );
            $fields->addFieldToTab('Root.Main',
                CheckboxField::create('ForceLocalDelete',
                    'Forceer lokale verwijdering bij volgende delete (Bunny API overslaan)'
                ),
                'Title'
            );
        }

        return $fields;
    }

    public function getTitle(): string
    {
        return $this->getField('Title') ?: $this->VideoGuid ?: '(geen video)';
    }

    // -------------------------------------------------------------------------
    // Delete lifecycle — propagate to Bunny Stream API (fail-closed by default)
    // -------------------------------------------------------------------------

    /**
     * Transient flag set by the CMS checkbox; persisted into the session via
     * onBeforeWrite so the next delete attempt reads it back.
     * @internal
     */
    public ?bool $ForceLocalDelete = null;

    /**
     * Persist the ForceLocalDelete checkbox state into the user's session so
     * the subsequent delete request can find it. Cleared when unchecked.
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if ($this->ID && $this->ForceLocalDelete !== null) {
            $this->setForceLocalDeleteSession((bool) $this->ForceLocalDelete);
        }
    }

    /**
     * Try to delete the remote video on Bunny Stream first. If that fails,
     * abort the local delete and stash the error on the session so the next
     * edit-form render shows a "force local delete" checkbox.
     *
     * Force-local-delete bypass: if the user ticked the checkbox and saved
     * (which set BunnyVideo.forceLocalDelete.<ID> in the session), skip the
     * API call entirely and proceed with local-only deletion. The remote
     * video remains on Bunny until cleaned up manually / by a reconciler.
     *
     * @throws ValidationException When the remote delete fails and the user
     *         has not opted into force-local-delete.
     */
    public function onBeforeDelete()
    {
        parent::onBeforeDelete();

        # Nothing to delete remotely — local-only delete is fine
        if (!$this->VideoGuid) {
            $this->clearDeleteSessionKeys();
            return;
        }

        # User explicitly opted in: skip API, log the orphaned remote video, proceed
        if ($this->getForceLocalDeleteFromSession()) {
            Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
                'BunnyVideo #%d (%s): forced local delete — remote video NOT deleted, may need manual cleanup',
                $this->ID,
                $this->VideoGuid
            ));
            $this->clearDeleteSessionKeys();
            return;
        }

        # Default path: fail-closed if Bunny API errors out
        try {
            BunnyStreamClient::create()->deleteVideo($this->VideoGuid);
            $this->clearDeleteSessionKeys();
        } catch (\Throwable $e) {
            $this->setLastDeleteErrorOnSession($e->getMessage());
            throw new ValidationException(
                "Verwijderen op Bunny Stream mislukt: {$e->getMessage()}. "
                . "Open de video in beheer en vink 'Forceer lokale verwijdering' aan om alleen lokaal te verwijderen."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Session helpers (per-user, per-record state for the force-delete flow)
    // -------------------------------------------------------------------------

    private function deleteSessionKey(string $type): string
    {
        return "BunnyVideo.{$type}." . (int) $this->ID;
    }

    private function getSession()
    {
        $controller = Controller::has_curr() ? Controller::curr() : null;
        return $controller && $controller->getRequest() ? $controller->getRequest()->getSession() : null;
    }

    private function setLastDeleteErrorOnSession(string $error): void
    {
        if ($session = $this->getSession()) {
            $session->set($this->deleteSessionKey('lastDeleteError'), $error);
        }
    }

    private function getLastDeleteErrorFromSession(): ?string
    {
        if (!$this->ID || !($session = $this->getSession())) {
            return null;
        }
        return $session->get($this->deleteSessionKey('lastDeleteError'));
    }

    private function setForceLocalDeleteSession(bool $force): void
    {
        if ($session = $this->getSession()) {
            $session->set($this->deleteSessionKey('forceLocalDelete'), $force);
        }
    }

    private function getForceLocalDeleteFromSession(): bool
    {
        if (!($session = $this->getSession())) {
            return false;
        }
        return (bool) $session->get($this->deleteSessionKey('forceLocalDelete'));
    }

    private function clearDeleteSessionKeys(): void
    {
        if (!($session = $this->getSession())) {
            return;
        }
        $session->clear($this->deleteSessionKey('lastDeleteError'));
        $session->clear($this->deleteSessionKey('forceLocalDelete'));
    }
}
