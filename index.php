<?php
declare(strict_types=1);

define('ROOT', __DIR__);
define('APP_ENV', getenv('APP_ENV') ?: 'production');

require ROOT . '/config/bootstrap.php';
require ROOT . '/routes/router.php';
