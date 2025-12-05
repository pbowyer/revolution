# Twig Tests

How to run only the Twig test suite without touching `_build/test/phpunit.xml`:

- From repo root with Composer (uses `_build/test/phpunit.xml` bootstrap):  
  `composer phpunit -- core/components/twig/tests`
- Single file:  
  `composer phpunit -- core/components/twig/tests/TwigParserTest.php`
- Direct phpunit binary:  
  `./core/vendor/bin/phpunit -c _build/test/phpunit.xml core/components/twig/tests/TwigParserTest.php`
- Filtered method/class example:  
  `composer phpunit -- --filter TwigParserTest core/components/twig/tests`
