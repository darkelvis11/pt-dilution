<?php
namespace DcaDiluter;

class PairMapper
{
    public function __construct($logger, $config, $activePairs, $dcaLog, $slicer)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->activePairs = $activePairs;
        $this->dcaLog = $dcaLog;
        $this->slicer = $slicer;
    }

    public function populateActivePairs()
    {
        foreach ($this->dcaLog as $dcaPair) {
            if ($this->activePairs->count() >= $this->config->maxPairs) {
                $this->logger->info('No room for additional pairs');
                break;
            }

            $market = $dcaPair->market;
            $managedKey = $this->activePairs->search(function($item) use($market) {
                return $item->market == $market;
            });

            if ($dcaPair->profit < $this->config->lossLimit) {
                if ($managedKey === false && $dcaPair->buyProfit) {

                    $slices = $this->slicer->slices(
                        $dcaPair->averageCalculator->totalAmount,
                        $dcaPair->averageCalculator->avgPrice,
                        $dcaPair->currentPrice,
                        $this->config->maxCost,
                        $this->config->targetLossLevel
                    );

                    $this->activePairs->push((object)[
                        'market' => $dcaPair->market,
                        'orgAveragePrice' => $dcaPair->averageCalculator->avgPrice,
                        'orgAmount' => $dcaPair->averageCalculator->totalAmount,
                        'dcaLevel' => $dcaPair->boughtTimes,
                        'state' => 'INITIATE_NEXT_SLICE',
                        'sliceCount' => $slices,
                        'activeSlice' => 0,
                        'slices' => [],
                    ]);
                    $this->logger->info('Adding {market} to active pairs', ['market' => $market]);
                }
            }
        }

    }
}