<?php
declare(strict_types=1);

/**
 * Listen to the event: ContentBlocks_BeforeParse
 *
 * @var string $tpl
 * @var array<string, mixed> $phs
 */

/** @var \Boffinate\Twig\Twig $twig */
$twig = $this->modx->services->get('twigparser');
return $twig->renderString($tpl, $phs);
