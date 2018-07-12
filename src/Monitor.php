<?php
namespace DcaDiluter;

class Monitor
{
    public function __construct($logger, $config, $pt, $activePairs, $dcaLog, $pairsLog, $pendingLog, $binance, $slicer)
    {
        $this->logger = $logger;
        $this->pt = $pt;
        $this->activePairs = $activePairs;
        $this->dcaLog = $dcaLog;
        $this->pendingLog = $pendingLog;
        $this->pairsLog = $pairsLog;
        $this->binance = $binance;
        $this->config = $config;
        $this->slicer = $slicer;
    }

    public function checkActivePairs()
    {
        foreach ($this->activePairs as $activePair) {

            if (isset($activePair->pauseUntil)) {
                if (time() < $activePair->pauseUntil) {
                    $this->logger->info(
                        "{$activePair->market} is paused until " .
                        date("Y-m-d H:i:s", $activePair->pauseUntil)
                    );
                    continue;
                }
            }
            unset($activePair->pauseUntil);


            $dcaPair = $this->getPair($activePair->market, $this->dcaLog);
            $stdPair = $this->getPair($activePair->market, $this->pairsLog);
            $pendingPair = $this->getPair($activePair->market, $this->pendingLog);

            // OK. Now this coin is under our control. Mark it so.
            if ($dcaPair && $dcaPair->boughtTimes != 99) {
                $activePair->originalDCALevel = $dcaPair->boughtTimes;
                $this->logger->info("Setting {$activePair->market} DCA level to 99");
                $this->pt->setDcaLevels($activePair->market, 99);
            }

            switch ($activePair->state) {
                case 'INITIATE_NEXT_SLICE':
                    $this->logger->info("Initiating slide {$activePair->activeSlice} for {$activePair->market} (1/2)");

                    if ($pendingPair) {
                        $this->logger->info("Can't initiate new slide for {$activePair->market} because it's still in pending log");
                        continue;
                    }

                    if (!$dcaPair) {
                        $this->logger->warning("Can't initiate new slide for {$activePair->market} because it's not visible in dca log");
                        $this->logger->warning("Did {$activePair->market} sell with profit?");
                        continue;
                    }

                    if ($this->adjustAveragePrice($dcaPair, $activePair)) {
                        continue;
                    }

                    $activePair->activeSlice++;
                    $slice = (object)[];
                    $slice->sliceNr = $activePair->activeSlice;

                    $slices = $this->slicer->slices(
                        $dcaPair->averageCalculator->totalAmount,
                        $dcaPair->averageCalculator->avgPrice,
                        $dcaPair->currentPrice,
                        $this->config->maxCost,
                        $this->config->targetLossLevel
                    );

                    $sliceSize = $dcaPair->averageCalculator->totalAmount/ $slices;
                    $this->logger->info("Splitting {$activePair->market} into $slices slices");

                    // Main work: figure amount and initiate sale order
                    $sellOrderAmount = $this->sigFig($dcaPair->averageCalculator->totalAmount - $sliceSize, 3);
                    $sellOrderPrice = $this->sigFig($activePair->orgAveragePrice * 1.015, 2);
                    $this->logger->info("{$activePair->market} Total amount: {$dcaPair->averageCalculator->totalAmount}");
                    $this->logger->info("{$activePair->market} Slice size amount: {$sliceSize}");
                    $this->logger->info("{$activePair->market} Sell order amount: {$sellOrderAmount}");

                    // Place the sell order
                    $sellOrder = (object)$this->binance->sell($activePair->market, $sellOrderAmount, $sellOrderPrice);
                    $slice->sellOrders = [$sellOrder];
                    $this->logger->info("Place sell order for {$activePair->market}");
                    $this->logger->info(json_encode($sellOrder));


                    $activePair->slices[] = $slice;
                    $activePair->state = 'SLICE_SALE_INITIATED';

                    break;
                case 'SLICE_SALE_INITIATED':
                    $this->logger->info("Initiating slide {$activePair->activeSlice} for {$activePair->market}  (2/2)");
                    $slice = $activePair->slices[$activePair->activeSlice - 1];

                    if (!$pendingPair) {
                        $this->logger->info("Can't create buy order for {$activePair->market} until it's visible in pending log");
                        continue;
                    }

                    if ($this->adjustAveragePrice($dcaPair, $activePair)) {
                        continue;
                    }

                    // Are indicators telling us it's time to buy?
                    $buy = true;
                    foreach ($dcaPair->buyStrategies as $buyStrategy) {
                        $name = $buyStrategy->name;
                        $name = explode('&', $name)[0];
                        $name = trim($name);
                        if (in_array($name, ['MAX BUY TIMES', 'ANDERSON', 'SOM ENABLED'])) {
                            continue;
                        }

                        $this->logger->info("{$activePair->market} buy strategy {$buyStrategy->name}: {$buyStrategy->positive}");
                        if ($buyStrategy->positive == 'false') {
                            $buy = false;
                        }
                    }

                    if (!$buy) {
                        $this->logger->info("{$activePair->market} at least one indicator signals false, hold of buy order");
                        continue;
                    }

                    $buyAmount = $this->sigFig($this->config->maxCost / $dcaPair->currentPrice, 3);
                    $newTotalAmount = $dcaPair->averageCalculator->totalAmount + $buyAmount;
                    $newTotalCost = $dcaPair->averageCalculator->totalCost + $this->config->maxCost;
                    $newAverage = $newTotalCost / $newTotalAmount;

                    // Next, place buy order (will make the coin visible in PAIRS or DCA log)
                    $buyOrder = (object)$this->binance->marketBuy($activePair->market, $buyAmount);
                    $slice->buyOrder = $buyOrder;
                    $this->logger->info("Place buy order for $buyAmount of {$activePair->market}");
                    $this->logger->info(json_encode($buyOrder));

                    $this->logger->info(" {$activePair->market} new expected amount: $newTotalAmount");
                    $this->logger->info(" {$activePair->market} new expected average: $newAverage");

                    $activePair->state = 'SLICE_INITIATED';
                    $activePair->pauseUntil = time() + 180; // Pause for 3 min to ensure coin shows up back in DCA

                    break;

                case 'SLICE_INITIATED':
                    $this->logger->info("Checking slice {$activePair->activeSlice} for {$activePair->market}");

                    if ($dcaPair !== false) {
                        $this->logger->info("{$activePair->market} found in DCA log");
                    }

                    if ($stdPair !== false) {
                        $this->logger->info("{$activePair->market} found in PAIRS log");
                    }

                    if ($pendingPair !== false) {
                        $this->logger->info("{$activePair->market} found in PENDING log");
                    }

                    if (!$dcaPair && !$stdPair) {
                        $this->logger->info("{$activePair->market} not found in DCA log or PAIRS log");
                        $this->logger->info("Slice for {$activePair->market} seems to be done");

                        // Remove all sale orders
                        $openOrders = $this->binance->openOrders($activePair->market);
                        foreach ($openOrders as $openOrder) {
                            $openOrder = (object)$openOrder;
                            $this->logger->info("Removing sell order {$openOrder->orderId} for {$activePair->market}");
                            $order = (object)$this->binance->cancel($activePair->market, $openOrder->orderId);
                            $this->logger->info(json_encode($order));
                        }

                        $activePair->state = 'INITIATE_NEXT_SLICE';
                        $activePair->pauseUntil = time() + 180; // Pause for 10 min
                        continue;
                    }

                    if ($dcaPair && $pendingPair) {
                        if ($dcaPair->buyProfit <  $this->config->sliceLossLimit) {
                            // OK, this is going the wrong way.
                            // 1. Add a little amount to the sale order to dilute even more
                            $openOrders = $this->binance->openOrders($activePair->market);
                            foreach ($openOrders as $openOrder) {
                                //print_r($openOrder);
                                //$order = $this->binance->cancel($activePair->market, $openOrder->orderId);
                            }
                            // 2. Recalculate the
                            $i = 0;
                        }
                    }

                    // IF we're still here, the pair is still in dca or pairs log
                    $this->logger->info("Current slice for {$activePair->market} is not sold yet");

                    break;

            }
        }
    }

    private function getPair($market, $log)
    {
        $pairIdx = $log->search(function($item, $key) use($market) {
            return $item->market == $market;
        });
        if ($pairIdx !== false) {
            return $log->get($pairIdx);
        }

        return false;
    }

    private function sigFig($value, $digits)
    {
        if ($value == 0) {
            $decimalPlaces = $digits - 1;
        } elseif ($value < 0) {
            $decimalPlaces = $digits - floor(log10($value * -1)) - 1;
        } else {
            $decimalPlaces = $digits - floor(log10($value)) - 1;
        }

        $answer = round($value, $decimalPlaces);
        return $answer;
    }

    private function adjustAveragePrice($dcaPair, $activePair)
    {
        // Check if the dca_pair has zero bought cost:
        if ($dcaPair && $dcaPair->averageCalculator->totalCost == 0 || isset($activePair->reCalc)) {

            $this->logger->info("Recalculating bought price for {$activePair->market}");

            $history = $this->binance->history($activePair->market);
            $amount = 0;
            $totalCost = 0;
            foreach ($history as $arrTrade) {
                $trade = (object)$arrTrade;
                if ($trade->isBuyer) {
                    $amount += $trade->qty;
                    $totalCost += $trade->qty * $trade->price;
                }

                if (!$trade->isBuyer) {
                    $amount -= $trade->qty;
                    $totalCost -= $trade->qty * $trade->price;
                }
            }

            $averageCost = $totalCost / $amount;
            $this->logger->info("Setting bought price for {$activePair->market} to $averageCost");
            $this->pt->resetStoredAverage($activePair->market, $averageCost);

            unset($activePair->reCalc);

            return true;
        }
        return false;
    }
}