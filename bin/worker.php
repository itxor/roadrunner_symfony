<?php
// Устанавливаем вывод ошибок в стандартный поток stderr
ini_set('display_errors', 'stderr');

use App\Kernel;
use Spiral\Goridge\StreamRelay;
use Spiral\RoadRunner\PSR7Client;
use Spiral\RoadRunner\Worker;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\Debug\Debug;
use Symfony\Component\HttpFoundation\Request;
use Zend\Diactoros\ResponseFactory;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Diactoros\StreamFactory;
use Zend\Diactoros\UploadedFileFactory;

// подключаем autoload.php и инициализируем переменные окружения
require __DIR__ . '/../config/bootstrap.php';

// определяем текущий режим запуска приложения: dev, test или prod
$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
// определяем, включать или не включать режим отладки, в зависимости от того, в dev или prod-режиме запускается приложение
$debug = (bool)($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ('prod' !== $env));

// если приложение запускается в dev-среде - инициализируем Debug, для вывода предупреждений и ошибок
if ($debug) {
    umask(0000);

    Debug::enable();
}

// если приложение находится за proxy, то пробрасываем в ответ реальный адрес пользователя, а не proxy-адрес
if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? false) {
    Request::setTrustedProxies(
        explode(',', $trustedProxies),
        Request::HEADER_X_FORWARDED_ALL ^ Request::HEADER_X_FORWARDED_HOST
    );
}

// аналогично пробрасываем доверенные хосты
if ($trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? $_ENV['TRUSTED_HOSTS'] ?? false) {
    Request::setTrustedHosts(explode(',', $trustedHosts));
}

// инициализируем ядро приложения
$kernel = new Kernel($env, $debug);
// создаём объект, позволяющий передавать данные воркерам
$relay = new StreamRelay(STDIN, STDOUT);
// инициализируем диспетчер процессов и балансировщик нагрузки
$psr7 = new PSR7Client(new Worker($relay));
// инициализируем фабрику, позволяющую создавать объекты Symfony-Request, для последующей передачи в них загруженных данных запроса
$httpFoundationFactory = new HttpFoundationFactory();
// фабрика, генерующая объект ответа
$psrHttpFactory = new PsrHttpFactory(
    new ServerRequestFactory,
    new StreamFactory,
    new UploadedFileFactory,
    new ResponseFactory
);
// вечный цикл, обрабатывающий запросы пользователя
while ($req = $psr7->acceptRequest()) {
    try {
        $request = $httpFoundationFactory->createRequest($req);
        $response = $kernel->handle($request);
        $psr7->respond($psrHttpFactory->createResponse($response));
        $kernel->terminate($request, $response);
        $kernel->reboot(null);
    } catch (\Throwable $e) {
        $psr7->getWorker()->error((string)$e);
    }
}
