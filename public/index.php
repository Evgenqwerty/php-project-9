<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Hexlet\Code\Connection;
use Hexlet\Code\Query;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use DiDom\Document;
use Vlucas\Valitron\Validator;
use Carbon\Carbon;

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

$app->get('/', function (
    Slim\Http\ServerRequest $request,
    Slim\Http\Response $response,
) {
    $params = ['greeting' => 'Welcome'];
    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('home');

$app->post('/urls/{url_id:[0-9]+}/checks', function (
    Slim\Http\ServerRequest $request,
    Slim\Http\Response $response,
    array $args
) use ($router) {
    $check['url_id'] = $args['url_id'];
    $check['date'] = Carbon::now();
    $pdo = Connection::get()->connect();
    $stmt = $pdo->prepare("SELECT name FROM urls WHERE id = :id");
    $stmt->execute(['id' => $args['url_id']]);
    $checkedUrl = $stmt->fetchColumn();
    try {
        $client = new Client();
        $guzzleResponse = $client->request('GET', $checkedUrl);
        $check['status_code'] = $guzzleResponse->getStatusCode();
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('failure', 'Не удалось установить соединение');
    } catch (RequestException $e) {
        // HTTP ошибки
        if ($e->hasResponse()) {
            $statusCode = $e->getResponse();
            $this->get('flash')->addMessage('failure', "Ошибка HTTP");
        } else {
            $this->get('flash')->addMessage('failure', 'Ошибка запроса');
        }
    } catch (Exception $e) {
        $this->get('flash')->addMessage('failure', 'Произошла непредвиденная ошибка');
    }
    if (isset($guzzleResponse)) {
        $htmlContent = (string)$guzzleResponse->getBody();
        $document = new Document();
        $document->loadHtml($htmlContent);
    }
    if (isset($document)) {
        if ($document->has('h1')) {
            $h1Elements = $document->find('h1');
            if ($h1Elements[0] instanceof \DiDom\Element) {
                $check['h1'] = $h1Elements[0]->text();
            }
        }
        if ($document->has('title')) {
            $titleElements = $document->find('title');
            if ($titleElements[0] instanceof \DiDom\Element) {
                $check['title'] = $titleElements[0]->text();
            }
        }
        if ($document->has('meta[name=description]')) {
            $desc = $document->find('meta[name=description]');
            $check['description'] = $desc[0]->getAttribute('content');
        }
    }
    try {
            $query = new Query($pdo, 'url_checks');
            $query->insertValuesChecks($check);
    } catch (\PDOException $e) {
            echo $e->getMessage();
    }

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withRedirect($router->urlFor('show_url_info', ['id' => $args['url_id']]), 302);
})->setName('url_checks');

$app->post('/urls', function (
    Slim\Http\ServerRequest $request,
    Slim\Http\Response $response
) use ($router) {
    $formData = (array) $request->getParsedBody();

    $validator = new Valitron\Validator($formData);
    $validator->rule('required', ['url.name'])->message('URL не должен быть пустым');
    $validator->rule('url', ['url.name'])->message('Некорректный URL');
    $validator->labels(['url.name' => 'Url']);

    if ($validator->validate() && isset($formData['url']['name'])) {
        $urlName = $formData['url']['name'];
        $normalizedUrl = parse_url($urlName, PHP_URL_SCHEME) . "://" . parse_url($urlName, PHP_URL_HOST);
        $createdAt = Carbon::now();

        $pdo = Connection::get()->connect();

        // Проверяем существование URL
        $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = :name");
        $stmt->execute(['name' => $normalizedUrl]);
        $existingUrl = $stmt->fetch();

        if ($existingUrl) {
            $id = $existingUrl['id'];
            $this->get('flash')->addMessage('success', 'Страница уже существует');
        } else {
            $query = new Query($pdo, 'urls');
            $id = $query->insertValues($normalizedUrl, $createdAt);
            $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        }

        return $response->withRedirect($router->urlFor('show_url_info', ['id' => $id]), 302);
    }

    $errors = $validator->errors();
    $firstError = '';
    if (!empty($errors['url.name'])) {
        $firstError = is_array($errors['url.name']) ? $errors['url.name'][0] : $errors['url.name'];
    }

    $params = [
        'url' => $formData['url'] ?? [],
        'errors' => ['name' => $firstError]
    ];
    return $this->get('renderer')->render($response->withStatus(422), "main.phtml", $params);
})->setName('urls_create');

$app->get('/urls/{id:[0-9]+}', function (
    Slim\Http\ServerRequest $request,
    Slim\Http\Response $response,
    array $args
) {
    $pdo = Connection::get()->connect();

    // Один запрос для поиска конкретного URL по id
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->execute(['id' => (int)$args['id']]);
    $urlFound = $stmt->fetch();

    if (!$urlFound) {
        return $response->withStatus(404);
    }

    $stmt = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = :url_id ORDER BY created_at ASC ");
    $stmt->execute(['url_id' => (int)$args['id']]);
    $checks = $stmt->fetchAll();
    $flashes = $this->get('flash')->getMessages();
    $params = ['url' => $urlFound, 'checks' => array_reverse($checks), 'flash' => $flashes];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('show_url_info');

$app->get('/urls', function (
    Slim\Http\ServerRequest $request,
    Slim\Http\Response $response
) {
    $pdo = Connection::get()->connect();

    // Получаем все URL
    $urls = $pdo->query("
    SELECT 
        u.*,
        (
            SELECT uc.created_at 
            FROM url_checks uc 
            WHERE uc.url_id = u.id 
            ORDER BY uc.created_at DESC 
            LIMIT 1
        ) as last_check_time,
        (
            SELECT uc.status_code 
            FROM url_checks uc 
            WHERE uc.url_id = u.id 
            ORDER BY uc.created_at DESC 
            LIMIT 1
        ) as status_code
    FROM urls u
    ORDER BY u.created_at DESC
")->fetchAll();

    $params = ['urls' => $urls];
    return $this->get('renderer')->render($response, 'list.phtml', $params);
})->setName('list');
$app->run();
