<?php

require __DIR__.'/vendor/autoload.php';

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\SessionCookieJar;
use KubAT\PhpSimple\HtmlDomParser;

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Initialize dotenv config
 */
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

/**
 * New database connection
 */
$db_config = new Configuration();
$db_params = array(
    'user' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD'],
    'host' => $_ENV['DB_HOST'],
    'dbname' => $_ENV['DB_DATABASE'],
    'port' => $_ENV['DB_PORT'],
    'charset' => 'utf8mb4',
    'driver' => 'pdo_mysql',
);
try {
    $conn = DriverManager::getConnection($db_params, $db_config);
} catch (\Doctrine\DBAL\DBALException $e) {
    echo 'Caught exception: ',  $e->getMessage(), "\n";
}

/**
 * Initialize slack client
 * https://my.slack.com/services/new/incoming-webhook
 */
$slack = new Maknz\Slack\Client($_ENV['SLACK_HOOK']);

/**
 * Initialize guzzle client with cookiejar
 */
$cookiejar = new SessionCookieJar('PHPSESSID', true);
$client = new Client(['cookies' => $cookiejar]);

// Get first response for session
$client->request('GET', 'https://www.ss.com/lv/transport/cars/today/');

// Post Filter data
$post_data = [
    'allow_redirects' => true,
    'form_params' => [
        'topt' => [
            '8' => [
                'min' => $_ENV['FILTER_PRICE_MIN'], // Minimala cena
                'max' => $_ENV['FILTER_PRICE_MAX'] // Maximala cena
            ],
            '18' => [
                'min' => $_ENV['FILTER_YEAR_FROM'], // Gads
                'max' => $_ENV['FILTER_YEAR_TO'],
            ],
            '15' => [
                'min' => $_ENV['FILTER_ENGINE_CAPACITY_FROM'], // Tilpums
                'max' => $_ENV['FILTER_ENGINE_CAPACITY_TO'],
            ],
        ],
        'opt' => [
            '17' => $_ENV['FILTER_COLOR'], // Krasa
            '32' => $_ENV['FILTER_BODY_TYPE'], // Virs. tips
            '34' => $_ENV['FILTER_FUEL'], // Dizelis
            '35' => $_ENV['FILTER_GEARBOX'], // Automats
        ],
        'sid' => '/lv/transport/cars/today/filter/', // Required
    ],
];
$response = $client->post(
    'https://www.ss.com/lv/transport/cars/today/filter/',
    $post_data
);
$body = $response->getBody();

// Initialize html parser
$html = HtmlDomParser::str_get_html((string)$body);

// SS adverts are displayed in table
$rows = $html->find('table tr');

$inserted_ids = [];
foreach ($rows as $row) {
    $data = array();
    $cells = $row->find('td');

    // Finds table with correct number of cells
    // TODO: make prettier
    if (count($cells) == 8) {

        //Save url
        $link = $cells[1]->find('a', 0);
        $data['adv_url'] = $link->href;

        //Save image
        $image = $cells[1]->find('img', 0);
        $data['adv_image'] = $image->src;

        //Get ad text
        $data['adv_description'] = $cells[2]->plaintext;

        //Get location
        $data['adv_type'] = strip_tags($cells[3]->innertext);

        //Get year
        $data['adv_year'] = strip_tags($cells[4]->innertext);

        //Get price
        $data['adv_price'] = filter_var(strip_tags($cells[7]->innertext), FILTER_SANITIZE_NUMBER_INT);

        // Check if this already exists in db
        $test = $conn->fetchColumn(
            'SELECT adv_id FROM advertisments WHERE adv_description = ? AND adv_type = ? AND adv_year = ? AND adv_status = \'1\'',
            array(
                $data['adv_description'],
                $data['adv_type'],
                $data['adv_year'],
            )
        );

        if (!$test) {
            $id = $conn->insert(
                'advertisments',
                array(
                    'adv_url' => $data['adv_url'],
                    'adv_image' => $data['adv_image'],
                    'adv_description' => $data['adv_description'],
                    'adv_type' => $data['adv_type'],
                    'adv_year' => $data['adv_year'],
                    'adv_price' => $data['adv_price'],
                )
            );
            $inserted_ids[] = $id;

            // Create and send slack message
            $attachment = new Maknz\Slack\Attachment(
                [
                    'title' => $data['adv_description'].'...',
                    'title_link' => 'https://www.ss.com'.$data['adv_url'],
                    'thumb_url' => $data['adv_image'],
                    'text' => $data['adv_price'].', '.$data['adv_type'].', '.$data['adv_year'],
                ]
            );

            $message = $slack->createMessage();
            $message->attach($attachment);
            $message->send();
        } else {
            $inserted_ids[] = $test;
        }
    }
}

//Set advertisment status to expired if not found
if (!empty($inserted_ids)) {
    $conn->executeUpdate(
        'UPDATE advertisments SET adv_status = ? WHERE adv_status = 1 AND adv_id NOT IN (?)',
        array(0, $inserted_ids),
        array(ParameterType::INTEGER, Connection::PARAM_INT_ARRAY)
    );
}
