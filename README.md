# pt-dilution
Experimental script to get out of Profit Trailer bags using dilution.

## Requirements

 - PHP 7.0 or higher
 - Working composer
 - Profit Trailer 2.0 or higher
 - Binance account

## Installation

Clone this repo. *NOTE!!* Do not clone this repo into a folder with public http access. The config file with your Binance keys will get exposed on the internet if you do. If you don't understand what this means, please don't continue.

Run composer 
```
composer install
```

Create a config.json
```
cp config.example.json config.json
```
Then enter your ProfitTrailer details. For setting up an api token, see https://wiki.profittrailer.com/doku.php?id=application.properties#serverapi_token

Create a folder for logs
```
mkdir logs/
```

Set up a schedule with crontab (make sure user has write access to logs/ folder)
```
* * * * *	php /path/to/your/installation/dcadiluter.php
```

## How does this work?

Once pt-dilution takes control over a coin, it will do the following:

### 1. Wait for big enough loss
pt-dilution will ignore all pairs with a fair chance of recovering using your normal strategy. But once a coin is below ```lossLimit```, the pair will get dca level = 99 to mark is as under pt-dilution control (and very few PT2 strategies has more than 99 DCA levels, so further buys made by PT2 are unlikely)

### 2. Calculate number of slices
Pt-dilution will calculate how many slices the pair needs to be divided into. The target is to use as few slices as possible that uses all your ```maxCost``` BTC that satisfies the ```targetLossLevel```.

### 3 Initate the sale
pt-dilution creates a sale order on Binance that is 1.5% higher than your original average price. Since this is probably a lot higher than the current bid, this order will just sit and wait on Binance. After a few minutes (2-7) PT2 will notice the sale order on Binance and the coin will be visible in PT2 pending log. But since the sale order isn't for the whole amount of your coin, one part (slice) of the coin will still sit in the DCA log. PT2 often screws up the pair average price, so pt-dilution also recalculates the average if needed.

### 4 Wait for and perform the buy
Once the pair is visible in the pending log. pt-dilution will wait for the right time to buy. It will use the same buy strategies that you already have for DCA coins but with no trailing. If you're just using Anderson, this means that pt-dilution will buy straight away. If you're using i.e RSI to time the buy, pt-dilution will wait for the RSI indicator to become true. Once the buy is made, a market order is sent to Binance.

### 5 Wait for PT2 to sell the coin
At this stage, the pair should sit in the DCA log with a very low los. Hopefully, it will turn green and sell pretty quickly. pt-dilution waits until the the pair is gone from the DCA log meaning that it was sold. As soon as the coin is sold, pt-dilution removes the pending sale order from Binance and starts the process all over again.

## Warnings and notes
 - You can follow what is happening via the PT2 web UI, but pt-dilutions main way of telling you what's going on is via the logfile (```logs/dcadiluter.log```) and state file (```state.json```)
 - Please note that if the coin goes south, pt-dilution does not offer a way out. All you can do is wait.
 - pt-dilution recalculates the number of slices needed for each round. 
 

## Config file

### serverUrl
The http url for your PT2 installation

### token
A valid API token to access your PT2 API. See https://wiki.profittrailer.com/doku.php?id=application.properties#serverapi_token for more information on how to set this up

### license
Your PT2 license key. This is needed for pt-dilution to make configuration changes, specifically to use HOTCONFIG to set average cost after placing orders

### binanceKey
A valid Binance API key with trading capability enabled. NOTE!!!! Please set up a separate API key for this, do not use the same key as your PT2 installation. By creating a specific key for pt-dilution, you can easily disable the key without affecting normal PT2 

### binanceSecret
The API key secret for the above key.

### lossLimit
At what loss level in the DCA log should pt-dilution take control over a coin. Note, once pt-dilution takes control over your coin, it will set DCA level to 99. Depending on your PT2 setup, this should mean that it's way above MAX-BOUGHT and PT2 will not try to DCA any further. Typical value: -15

### maxCost
How much of your base currency should pt-dilution spend on each slice/part.

### maxPairs
How many pairs should pt-dilution handle at any time

### targetLossLevel
What percentage loss should pt-dilution aim for when making the buy. This determines the number of slices a coin will be divided into. Note that targetLossLevel should be written as negative decimal number. -1% is written as -0.01.
