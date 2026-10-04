<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class AllowedIps extends Value
{
    public function beforeSave()
    {
        $this->setValue($this->normalize((string) $this->getValue()));

        return parent::beforeSave();
    }

    public function normalize(string $raw): string
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        $invalid = [];
        foreach ($entries as $entry) {
            if ($entry !== '*' && filter_var($entry, FILTER_VALIDATE_IP) === false) {
                $invalid[] = $entry;
            }
        }
        if ($invalid !== []) {
            throw new LocalizedException(
                __(
                    'Allowed IP Addresses contains invalid entries: %1. Use comma-separated IPv4 or IPv6 addresses, or *.',
                    implode(', ', $invalid)
                )
            );
        }

        return implode(', ', array_unique($entries));
    }
}
