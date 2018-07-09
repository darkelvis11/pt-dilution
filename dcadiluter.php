<?php
require_once "vendor/autoload.php";

// Perhaps?

use Apix\Log\Logger\File;

define('MINIMUM_ORDER', 0.001);

@mkdir(__DIR__ . '/logs');
$appLogger = new File(__DIR__ . '/logs/dcadiluter.log');
$appLogger->setMinLevel('info')->setDeferred(false);

$config = json_decode(file_get_contents(__DIR__ . '/config.json'));

// Set up ProfitTrailer
$pt = new \DcaDiluter\ProfitTrailer($config);
$allData = $pt->getAllLogs();

// Get the logs
$pairsLog   = collect($allData->gainLogData);
$dcaLog     = collect($allData->dcaLogData);
$pendingLog = collect($allData->pendingLogData);

// Set up binance
$binance = new Binance\API($config->binanceKey, $config->binanceSecret);

// Set up slicer
$slicer = new DcaDiluter\Slicer();

$state = null;
if (file_exists(__DIR__ . '/state.json')) {
    $state = json_decode(file_get_contents(__DIR__ . '/state.json'));
}
$state = !$state ? [] : $state;
$activePairs = collect($state);

$appLogger->info(' ');
$appLogger->info('******************************************************');
$appLogger->info('*****         Dca diluter starting               *****');
$appLogger->info('******************************************************');
$appLogger->info('Active pairs: {paircount}', ['paircount' => $activePairs->count()]);

// Check if the data is valid
if ($dcaLog->count() == 0 && $pendingLog->count() == 0) {
    $appLogger->error('No relevant data retreived from Profit Trailer, aborting ');
    die();
}

// Get a list of active pairs or new candidates
$mapper = new DcaDiluter\PairMapper($appLogger, $config, $activePairs, $dcaLog, $slicer);
$mapper->populateActivePairs();

// Check status of existing active pairs
$monitor = new DcaDiluter\Monitor($appLogger, $config, $pt, $activePairs, $dcaLog, $pairsLog, $pendingLog, $binance, $slicer);
$monitor->checkActivePairs();

// Lastly, store the activeParis
file_put_contents(__DIR__ . '/state.json', json_encode($activePairs->toArray(), JSON_PRETTY_PRINT));