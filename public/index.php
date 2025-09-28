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
use valitron\Validator;

session_start();

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
            $query->insertValuesChecks($check);
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
    }
    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withRedirect($router->urlFor('show_url_info', ['id' => $args['url_id']]), 302);
})->setName('url_checks');

$app->post('/urls', function (
    Psr\Http\Message\ServerRequestInterface $request,
    Psr\Http\Message\ResponseInterface $response
) use ($router) {
    $formData = $request->getParsedBody();
    $urlName = $formData['url']['name'] ?? '';

    $validator = new Valitron\Validator(['url' => ['name' => $urlName]]);
    $validator->rule('required', ['url.name'])->message('URL не должен быть пустым');
    $validator->rule('url', ['url.name'])->message('Некорректный URL');
    $validator->labels(['url.name' => 'URL']);

    // Дополнительная проверка схемы
    if (filter_var($urlName, FILTER_VALIDATE_URL) !== false) {
        $scheme = parse_url($urlName, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            $validator->error('url.name', 'URL должен иметь схему http или https');
        }
    }

    if ($validator->validate()) {
        // Нормализация URL
        $normalizedUrl = parse_url($urlName, PHP_URL_SCHEME) . "://" . parse_url($urlName, PHP_URL_HOST);
        $createdAt = date('Y-m-d H:i:s');

        $pdo = Connection::get()->connect();

        // Проверяем существование URL
        $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = :name");
        $stmt->execute(['name' => $normalizedUrl]);
        $existingUrl = $stmt->fetch();

        if ($existingUrl) {
            $id = $existingUrl['id'];
            $this->get('flash')->addMessage('success', 'Страница уже существует');
        } else {
            try {
                $query = new Query($pdo, 'urls');
                $id = $query->insertValues($normalizedUrl, $createdAt);
                $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
            } catch (\PDOException $e) {
                $this->get('flash')->addMessage('failure', 'Ошибка при сохранении URL');
                return $response->withRedirect($router->urlFor('home'), 302);
            }
        }

        return $response->withRedirect($router->urlFor('show_url_info', ['id' => $id]), 302);
    }

    $params = [
        'url' => ['name' => $urlName],
        'errors' => $validator->errors()
    ];
    return $this->get('renderer')->render($response->withStatus(422), "main.phtml", $params);
})->setName('urls_create');

$app->get('/urls/{id:[0-9]+}', function (
    Psr\Http\Message\ServerRequestInterface $request,
    Psr\Http\Message\ResponseInterface $response,
    array $args
) {
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
    $stmt = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = :url_id ORDER BY created_at DESC");
    $stmt->execute(['url_id' => (int)$args['id']]);
    $checks = $pdo->query("SELECT * FROM url_checks WHERE url_id = {$args['id']}")->fetchAll();
    $flashes = $this->get('flash')->getMessages();
    $params = ['url' => $urlFound, 'checks' => array_reverse($checks), 'flash' => $flashes];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('show_url_info');

$app->get('/urls', function ($request, $response) {
    $pdo = Connection::get()->connect();

    // Получаем все URL
    $urls = $pdo->query("
        SELECT 
            u.id,
            u.name,
            u.created_at,
            uc.status_code,
            uc.h1,
            uc.title,
            uc.description,
            uc.created_at as last_check_time
        FROM urls u
        LEFT JOIN LATERAL (
            SELECT *
            FROM url_checks 
            WHERE url_id = u.id 
            ORDER BY created_at DESC 
            LIMIT 1
        ) uc ON true
        ORDER BY u.created_at DESC
    ")->fetchAll();

    $params = ['urls' => $urls];
    return $this->get('renderer')->render($response, 'list.phtml', $params);
})->setName('list');
$app->run();
