<?php
/** @var MODX\Revolution\modX $modx */

require_once MODX_CORE_PATH . 'components/twig/vendor/autoload.php';

// Add factories
$modx->services[Boffinate\Twig\Twig::class] = $modx->services->factory(function ($c) use ($modx) {
    $class = $modx->getOption('modxTwig.class', null, \Boffinate\Twig\Twig::class, true);
    $config = $c['twig_config'] ?? [];
    $c['twig_config'] = [];
    return new $class($modx, $config);
});
// Add services
$modx->services->add('twigparser', function ($c) use ($modx) {
    $class = $modx->getOption('modxTwig.class', null, \Boffinate\Twig\Twig::class, true);
    return new $class($modx, $c->get('pdotools'));
});
$modx->services->add('twig', function ($c) {
    return $c->get(\Boffinate\Twig\Twig::class);
});
