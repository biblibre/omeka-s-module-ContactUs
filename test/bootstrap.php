<?php declare(strict_types=1);

require_once __DIR__ . '/Generic/TesterTrait.php';
require_once __DIR__ . '/Generic/ModuleTester.php';
require_once __DIR__ . '/ContactUsTest/ContactUsTestTrait.php';

$moduleName = basename(dirname(__DIR__));
$tester = new \Generic\ModuleTester($moduleName);
$tester->initModule();

file_put_contents('php://stdout', sprintf("%s: Running tests…\n\n", $moduleName));
