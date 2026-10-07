<?php

namespace App\Domain\Notifications;

use App\Domain\Settings\SettingValue;

/**
 * Reads `settings.notified_events`.
 *
 * A missing or non-JSON value uses the default list. A JSON array, including
 * an empty one, is the stored selection. Unknown names are dropped.
 */
final class NotifiedEventSetting
{
    public function __construct(
        private readonly SettingValue $settings,
    ) {}

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        $stored = $this->settings->stringList(SettingValue::NOTIFIED_EVENTS);
        if ($stored === null) {
            return NotifiedEventCatalog::defaults();
        }

        $enabled = [];
        foreach ($stored as $name) {
            if (NotifiedEventCatalog::known($name) && ! in_array($name, $enabled, true)) {
                $enabled[] = $name;
            }
        }

        return $enabled;
    }

    public function allows(string $event): bool
    {
        return in_array($event, $this->enabled(), true);
    }
}
