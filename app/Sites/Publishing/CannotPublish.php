<?php

namespace App\Sites\Publishing;

use RuntimeException;

/**
 * Every reason a site cannot go live, together — so somebody fixes all of them
 * in one pass rather than discovering them one publish at a time.
 */
class CannotPublish extends RuntimeException
{
    /**
     * @param  array<int, string>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('This site cannot be published: '.implode('; ', $blockers).'.');
    }
}
