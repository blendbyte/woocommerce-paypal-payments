<?php
declare(strict_types=1);

define('TESTS_ROOT_DIR', dirname(__DIR__));
define('ROOT_DIR', dirname(TESTS_ROOT_DIR));

require_once ROOT_DIR . '/vendor/autoload.php';
require_once TESTS_ROOT_DIR . '/stubs/WC_Payment_Gateway.php';
require_once TESTS_ROOT_DIR . '/stubs/WC_Payment_Gateway_CC.php';
require_once TESTS_ROOT_DIR . '/stubs/WC_Ajax.php';

Hamcrest\Util::registerGlobalFunctions();
