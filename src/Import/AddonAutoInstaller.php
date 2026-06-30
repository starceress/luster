<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Installs a Luster add-on's table on demand from inside an import run, so
 add-on-gated data (Wishlist, KIM, Updates, Adoption, Join Rules) is never
 silently dropped just because the matching add-on was never installed, or
 was uninstalled before the import ran. Add-ons are installed automatically
 by the Luster installer on a normal fresh install (see install/index.php),
 so this only matters for that uninstalled-or-pre-migration-27 edge case.
*/

declare(strict_types=1);

namespace Luster\Import;

use Luster\Core\AddonInterface;
use PDO;

final class AddonAutoInstaller
{
    /**
     * Ensures $addonClass's table(s) exist on $target, calling its install()
     * on demand if they do not, and best-effort registering it in lstr_addons
     * so it also shows as installed in the admin panel afterward.
     *
     * Returns true once the add-on's table(s) can be written to (whether they
     * already existed or were just created), false only if install() itself
     * failed. The lstr_addons registry update is best-effort and never causes
     * a false return — a pre-migration-27 install with no lstr_addons table
     * yet must not block writing the imported rows.
     *
     * @param class-string<AddonInterface> $addonClass
     */
    public static function ensureInstalled(PDO $target, string $addonClass): bool
    {
        try {
            if (!class_exists($addonClass)) {
                return false;
            }
            $addon = new $addonClass();
            if (!($addon instanceof AddonInterface)) {
                return false;
            }
            $addon->install($target);
        } catch (\Throwable) {
            return false;
        }

        try {
            $exists = $target->prepare('SELECT 1 FROM lstr_addons WHERE addon_key = :key LIMIT 1');
            $exists->execute(['key' => $addon->getKey()]);
            if ($exists->fetchColumn() === false) {
                $target->prepare('INSERT INTO lstr_addons (addon_key) VALUES (:key)')
                    ->execute(['key' => $addon->getKey()]);
            }
        } catch (\Throwable) {}

        return true;
    }
}
