<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Hexlet\Code\Connection;
use Hexlet\Code\Query;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use DiDom\Document;
use Illuminate\Support\Arr;

session_start();

try {
    $pdo = Connection::get()->connect();
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    // Просто проверяем что таблицы существуют
    $urlsTableExists = $pdo->query("SELECT to_regclass('public.urls')")->fetchColumn();
    $checksTableExists = $pdo->query("SELECT to_regclass('public.url_checks')")->fetchColumn();

    if (!$urlsTableExists || !$checksTableExists) {
        throw new \RuntimeException('Database tables not found. Please run database.sql first.');
    }
} catch (\PDOException $e) {
    echo "Database error: " . $e->getMessage();
    exit;
} catch (\RuntimeException $e) {
    echo $e->getMessage();
    exit;
}

if (PHP_SAPI === 'cli-server' && $_SERVER['SCRIPT_FILENAME'] !== __FILE__) {
    return false;
}

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

AppFactory::setContainer($container);
$app = AppFactory::create();

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    $params = ['greeting' => 'Welcome'];
    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('home');

$app->post('/urls/{url_id}/checks', function ($request, $response, array $args) use ($router) {
    $check['url_id'] = $args['url_id'];
    $check['date'] = date('Y-m-d H:i:s');
    $pdo = Connection::get()->connect();
    $checkedUrl = $pdo->query("SELECT name FROM urls WHERE id = {$args['url_id']}")->fetchColumn();
    try {
        $client = new Client();
        $guzzleResponse = $client->request('GET', $checkedUrl);
        $check['status_code'] = $guzzleResponse->getStatusCode();
    } catch (TransferException $e) {
        $this->get('flash')->addMessage('failure', 'Произошла ошибка при проверке, не удалось подключиться');
    }
    $document = new Document($checkedUrl, true);
    if ($document->has('h1')) {
        $h1Elements = $document->find('h1');
        if (!empty($h1Elements) && $h1Elements[0] instanceof \DiDom\Element) {
            $check['h1'] = $h1Elements[0]->text();
        }
    }
    if ($document->has('title')) {
        $titleElements = $document->find('title');
        if (!empty($titleElements) && $titleElements[0] instanceof \DiDom\Element) {
            $check['title'] = $titleElements[0]->text();
        }
    }
    if ($document->has('meta[name=description]')) {
        $desc = $document->find('meta[name=description]');
        $check['description'] = $desc[0]->getAttribute('content');
    }
    if (isset($check['status_code']) && !empty($check['status_code'])) {
        try {
            $query = new Query($pdo, 'url_checks');
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
    }
    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withRedirect($router->urlFor('show_url_info', ['id' => $args['url_id']]), 302);
})->setName('url_checks');

$app->post('/urls', function ($request, $response) use ($router) {
    $url = $request->getParsedBodyParam('url');
    $url['date'] = date('Y-m-d H:i:s');
    $errors = [];
    if ((filter_var($url['name'], FILTER_VALIDATE_URL) === false) or (!in_array(parse_url($url['name'], PHP_URL_SCHEME), ['http', 'https']))) {  // phpcs:ignore
        $errors['name'] = 'Некорректный URL';
    }
    if (strlen($url['name']) < 1) {
        $errors['name'] = 'URL не должен быть пустым';
    }
    if (count($errors) === 0) {
        $url['name'] = parse_url($url['name'], PHP_URL_SCHEME) . "://" . parse_url($url['name'], PHP_URL_HOST);
        $pdo = Connection::get()->connect();
        $currentUrls = $pdo->query("SELECT * FROM urls")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($currentUrls as $item) {
            if ($item['name'] === $url['name']) {
                $urlFound = $item;
                $idFound = $item['id'];
            }
        }
        $newId = null;
        if (!isset($urlFound)) {
            try {
                $pdo = Connection::get()->connect();
                $query = new Query($pdo, 'urls');
                $newId = $query->insertValues($url['name'], $url['date']);
            } catch (\PDOException $e) {
                echo $e->getMessage();
            }
            $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        } else {
            $this->get('flash')->addMessage('success', 'Страница уже существует');
        }
        return $response->withRedirect($router->urlFor('show_url_info', ['id' => $idFound ?? $newId]), 302);
    }
    $params = ['url' => $url, 'errors' => $errors];
    return $this->get('renderer')->render($response->withStatus(422), "main.phtml", $params);
})->setName('urls_create');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $pdo = Connection::get()->connect();
    $allUrls = $pdo->query("SELECT * FROM urls")->fetchAll();
    foreach ($allUrls as $item) {
        if ($item['id'] == $args['id']) {
            $urlFound = $item;
        }
    }
    if (!isset($urlFound)) {
        return $response->withStatus(404);
    }
    $checks = $pdo->query("SELECT * FROM url_checks WHERE url_id = {$args['id']}")->fetchAll();
    $flashes = $this->get('flash')->getMessages();
    $params = ['url' => $urlFound, 'checks' => array_reverse($checks), 'flash' => $flashes];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('show_url_info');

$app->get('/urls', function ($request, $response) {
    $pdo = Connection::get()->connect();

    // Получаем все URL
    $urls = $pdo->query("SELECT * FROM urls ORDER BY created_at DESC")->fetchAll();

    // Получаем последние проверки для каждого URL (все поля)
    $checksStmt = $pdo->query("
        SELECT DISTINCT ON (url_id) *
        FROM url_checks
        ORDER BY url_id, created_at DESC
    ");
    $recentChecks = $checksStmt->fetchAll();

    // Собираем данные в ассоциативный массив
    $checksMap = Arr::keyBy($recentChecks, 'url_id');

    // Объединяем данные
    foreach ($urls as &$url) {
        $urlId = $url['id'];

        if (isset($checksMap[$urlId])) {
            $check = $checksMap[$urlId];
            $url['status_code'] = $check['status_code'];
            $url['h1'] = $check['h1'];
            $url['title'] = $check['title'];
            $url['description'] = $check['description'];
            $url['last_check_time'] = $check['created_at'];
        } else {
            $url['status_code'] = null;
            $url['h1'] = null;
            $url['title'] = null;
            $url['description'] = null;
            $url['last_check_time'] = null;
        }
    }

    $params = ['urls' => $urls];
    return $this->get('renderer')->render($response, 'list.phtml', $params);
})->setName('list');
$app->run();
