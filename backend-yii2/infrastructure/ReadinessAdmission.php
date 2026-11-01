<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Per-container, cross-FPM-process admission. Never creates or writes files. */
final class ReadinessAdmission
{
    public function __construct(private readonly string $directory=__DIR__.'/readiness-slots') {}

    /** @return resource|null An owned lease, or null when all slots are busy. */
    public function acquire()
    {
        for ($slot=0;$slot<2;$slot++) {
            // Local Linux/BSD flock permits LOCK_EX on a read-only descriptor.
            // Precreated immutable inodes avoid CLI/root creation/permission races.
            $file=fopen($this->directory.'/'.$slot.'.lock','rb');
            if ($file===false) { throw new \RuntimeException('Readiness admission unavailable'); }
            try {
                if (flock($file,LOCK_EX|LOCK_NB)) { return $file; }
            } catch (\Throwable $error) {
                fclose($file);throw $error;
            }
            fclose($file);
        }
        return null;
    }
}
