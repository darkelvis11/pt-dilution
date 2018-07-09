<?php
namespace DcaDiluter;

class Slicer
{
    public function construct()
    {

    }

    public function slices($oldAmount, $oldPrice, $currentPrice, $betSize, $targetPercentLoss)
    {
        for ($slices = 2; $slices < 50; $slices++) {

            $projectedLoss = $this->loss($oldAmount, $oldPrice, $currentPrice, $betSize, $slices);

            if ($projectedLoss < $targetPercentLoss) {
                return $slices;
            }

            // We didn't reach the target loss with this amount of slices.
            // But before checking again, we need to make sure the cost of
            // new position isn't lower than Binance order sice limit, if so
            // we need to accept a bigger loss

            $sliceSizeBtc = $oldAmount / $slices * $currentPrice;
            if ($sliceSizeBtc < MINIMUM_ORDER) {
                return  $slices -1;
            }
        }

        return $slices;
    }

    public function loss($oldAmount, $oldPrice, $currentPrice, $betSize, $slices)
    {
        $slice = 1 / $slices;
        $costOfNewPosistion = $slice * ($oldAmount * $oldPrice) + $betSize;
        $valueOfNewPosistion = (($betSize / $currentPrice) + ($slice * $oldAmount)) * $currentPrice;
        $loss = 1 - $valueOfNewPosistion / $costOfNewPosistion;

        return $loss;
    }
}