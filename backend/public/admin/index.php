<?php
/**
 * Точка входа админки. Вся логика — в app/admin/controller.php.
 */
declare(strict_types=1);

// app/ — сосед веб-корня: admin лежит на уровень глубже, поэтому ../../app.
require __DIR__ . '/../../app/bootstrap.php';

Security::enforceHttps();
Security::adminIpGate();        // заслон по IP, если включён в config
Security::sessionStart();

require dirname(__DIR__, 2) . '/app/admin/controller.php';
AdminController::handle();
