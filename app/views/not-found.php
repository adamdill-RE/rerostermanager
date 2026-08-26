<?php

declare(strict_types=1);

/**
 * 404. Deliberately incurious: it does not say whether the path exists and is
 * forbidden, or does not exist at all. /status with a wrong key lands here for
 * that reason.
 *
 * @var Rerm\App $app
 */
?>
<h1>Not found</h1>
<p class="lede">There is nothing at this address.</p>
<p><a href="<?= e($app->url()) ?>">Back to the start</a></p>
